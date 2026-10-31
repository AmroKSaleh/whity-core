<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Psr\Log\LoggerInterface;
use Throwable;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Sdk\Manifest\InvalidManifestException;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Serves each workspace its own plugins, live (Whity Builder, WHIT-760;
 * docs/design/WHITY-BUILDER.md 2.3).
 *
 * WHY READ, NOT REGISTER. Install-wide plugins are registered once per worker
 * at boot, and some catalogues are then sealed, because a registration made in
 * one of eight workers mid-request would make access depend on which worker
 * answered. A workspace plugin changes while the workers run. So it is never
 * registered into a worker's routing or features; core's fixed entry points
 * (the features list, the navigation, and in WHIT-761 the record routes and
 * MCP operations) ask this host for the current workspace's plugins on each
 * request.
 *
 * HOW EVERY WORKER CONVERGES. Each request makes one cheap read: the live
 * version and hash of each of the workspace's plugins. Parsed manifests are
 * cached per worker by hash, so a publish (a new hash) is picked up by every
 * worker on its next request for that workspace, and nothing stale is served.
 * The per-request memo is cleared at the end of each request, alongside the
 * RoleChecker's.
 *
 * PERMISSIONS. A role check asks the PermissionRegistry whether a slug exists
 * before it reads the grants, so a workspace plugin's slugs are added to the
 * worker's registry when its manifest is first parsed. That is safe to share:
 * the registry only says a name exists; who holds it is decided by the
 * workspace's own role grants.
 */
final class TenantManifestHost
{
    /** Parsed manifests kept per worker, by hash; oldest dropped beyond this. */
    private const CACHE_LIMIT = 512;

    /** @var array<string, PluginManifest> */
    private array $byHash = [];

    /** @var array<int, list<PluginManifest>> */
    private array $request = [];

    public function __construct(
        private readonly TenantPluginRepository $repository,
        private readonly PermissionRegistry $permissions,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The workspace's live, switched-on plugins.
     *
     * @return list<PluginManifest>
     */
    public function manifestsFor(int $tenantId): array
    {
        if (isset($this->request[$tenantId])) {
            return $this->request[$tenantId];
        }

        $manifests = [];
        try {
            foreach ($this->repository->active($tenantId) as $row) {
                $manifest = $this->byHash[$row['hash']] ?? $this->load($tenantId, $row['version_id'], $row['hash']);
                if ($manifest !== null) {
                    $manifests[] = $manifest;
                }
            }
        } catch (Throwable $e) {
            // A failure here must not take the workspace's core screens down
            // with it: serve no workspace plugins and say why in the log.
            $this->logger?->error('Workspace plugins could not be read', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->request[$tenantId] = $manifests;
    }

    /**
     * Frontend feature descriptors for the workspace's plugins, normalised the
     * way the plugin loader normalises a code plugin's, plus `tenantPlugin`.
     *
     * @return list<array<string, mixed>>
     */
    public function frontendFeatures(int $tenantId): array
    {
        $features = [];
        foreach ($this->manifestsFor($tenantId) as $manifest) {
            foreach ($manifest->frontendFeatures() as $feature) {
                $features[] = [
                    'id' => $feature['id'],
                    'plugin' => $manifest->name(),
                    'label' => $feature['label'],
                    'labelAr' => $feature['labelAr'],
                    'icon' => $feature['icon'] ?? null,
                    'group' => $feature['group'] ?? null,
                    'order' => $feature['order'] ?? null,
                    'screen' => 'blocks',
                    'resource' => null,
                    'action' => null,
                    'embed' => null,
                    'requiredPermission' => $feature['requiredPermission'],
                    'blocks' => $feature['blocks'],
                    'tenantPlugin' => true,
                ];
            }
        }

        return $features;
    }

    /** Forget this request's reads. Called at the end of every request. */
    public function resetRequest(): void
    {
        $this->request = [];
    }

    private function load(int $tenantId, int $versionId, string $hash): ?PluginManifest
    {
        $data = $this->repository->manifest($tenantId, $versionId);
        if ($data === null) {
            return null;
        }
        try {
            $manifest = PluginManifest::fromArray($data, "workspace {$tenantId} plugin version {$versionId}");
        } catch (InvalidManifestException $e) {
            // Validated when published; this only happens if the format
            // tightened since. Skip the plugin rather than serve half of it.
            $this->logger?->warning('A published workspace plugin no longer validates and is not served', [
                'tenant_id' => $tenantId,
                'version_id' => $versionId,
                'errors' => $e->getErrors(),
            ]);
            return null;
        }

        $this->permissions->register(self::permissionSource($tenantId, $manifest->name()), $manifest->permissions());

        if (count($this->byHash) >= self::CACHE_LIMIT) {
            array_shift($this->byHash);
        }

        return $this->byHash[$hash] = $manifest;
    }

    /**
     * The registry source a workspace plugin's slugs are filed under: one per
     * workspace and plugin, because registering a source replaces its set, and
     * two workspaces may have plugins of the same name with different slugs.
     */
    public static function permissionSource(int $tenantId, string $pluginName): string
    {
        return 'tenant-plugin:' . $tenantId . ':' . $pluginName;
    }
}
