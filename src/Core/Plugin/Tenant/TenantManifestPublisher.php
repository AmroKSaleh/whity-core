<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use PDO;
use Throwable;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\PluginRoleSeeder;
use Whity\Sdk\Manifest\InvalidManifestException;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Publishes a workspace's own plugin (Whity Builder, WHIT-760).
 *
 * The one write path for workspace plugins. The manifest versions API
 * (WHIT-764) puts validation previews, impact, tests and proposals in front of
 * it; this class is what finally makes a version live:
 *
 *  1. validate the manifest as a whole, then check it against what a workspace
 *     plugin may declare ({@see TenantManifestPolicy});
 *  2. store it as a new immutable version and point the plugin at it;
 *  3. make its permission slugs grantable: a row in the global `permissions`
 *     catalogue (role grants reference it), recorded as this workspace's;
 *  4. seed its suggested roles into THIS workspace only;
 *  5. audit it.
 *
 * It takes effect on the next request in every worker
 * ({@see TenantManifestHost}); nothing is reloaded.
 */
final class TenantManifestPublisher
{
    public const AUDIT_ACTION = 'tenant_plugin.published';
    public const AUDIT_ACTIVATED = 'tenant_plugin.activated';

    /**
     * @param list<string> $installedPluginNames Install-wide plugin names, which
     *        a workspace plugin may not reuse.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly TenantPluginRepository $repository,
        private readonly PluginRoleSeeder $roles,
        private readonly array $installedPluginNames,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @param array<mixed> $manifest The decoded manifest.
     * @return array{version_id: int, plugin: string, version: string, hash: string}
     * @throws InvalidManifestException When it doesn't validate or isn't
     *         allowed for a workspace plugin; carries every error.
     */
    public function publish(
        int $tenantId,
        array $manifest,
        ?int $publishedBy = null,
        ?int $actingFor = null,
        ?int $approvedBy = null,
        ?string $notes = null,
    ): array {
        $parsed = $this->check($tenantId, $manifest);

        $json = (string) json_encode($parsed->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $permissions = $parsed->permissions();
        $labels = $parsed->permissionLabels();

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->catalogue($permissions, $labels);

            $versionId = $this->repository->publish(
                $tenantId,
                $parsed->name(),
                $parsed->version(),
                $json,
                $hash,
                $permissions,
                $publishedBy,
                $actingFor,
                $approvedBy,
                $notes,
            );

            $this->roles->seed(new TenantManifestPlugin($parsed), $tenantId);

            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit?->record(self::AUDIT_ACTION, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $publishedBy,
            'target_type' => 'tenant_plugin',
            'target_id' => $versionId,
            'metadata' => [
                'plugin' => $parsed->name(),
                'version' => $parsed->version(),
                'hash' => $hash,
                'acting_for' => $actingFor,
                'approved_by' => $approvedBy,
            ],
        ]);

        return ['version_id' => $versionId, 'plugin' => $parsed->name(), 'version' => $parsed->version(), 'hash' => $hash];
    }

    /**
     * Validate a manifest and check it is one this workspace may publish. The
     * validate route and publish share it.
     *
     * @param array<mixed> $manifest
     * @throws InvalidManifestException With every error.
     */
    public function check(int $tenantId, array $manifest): PluginManifest
    {
        if ($tenantId <= 0) {
            // The system tenant's roles are global, so seeding a workspace
            // plugin's roles there would hand them to every workspace.
            throw new InvalidManifestException('workspace plugin', ['a workspace plugin belongs to one workspace, not the system tenant']);
        }
        $parsed = PluginManifest::fromArray($manifest, 'workspace plugin');
        $errors = TenantManifestPolicy::check($parsed, $this->installedPluginNames);
        if ($errors !== []) {
            throw new InvalidManifestException('workspace plugin', $errors);
        }

        return $parsed;
    }

    /**
     * Make one of the plugin's published versions live again (a rollback, or
     * forward again after one). The version is checked as if it were being
     * published, since what a workspace plugin may declare can have tightened
     * since; its permission slugs become the plugin's again and its roles are
     * seeded where missing. Nothing is deleted: the version stays in history.
     *
     * @return array{version_id: int, plugin: string, version: string, hash: string}|null Null when the
     *         version isn't one of this workspace's versions of that plugin.
     * @throws InvalidManifestException When the version no longer validates.
     */
    public function activate(int $tenantId, string $pluginKey, int $versionId, ?int $actor = null): ?array
    {
        $data = $this->repository->manifest($tenantId, $versionId);
        if ($data === null) {
            return null;
        }
        $parsed = $this->check($tenantId, $data);
        if ($parsed->name() !== $pluginKey) {
            return null;
        }
        $hash = hash('sha256', (string) json_encode($parsed->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->catalogue($parsed->permissions(), $parsed->permissionLabels());
            if (!$this->repository->point($tenantId, $pluginKey, $versionId)) {
                throw new \RuntimeException('The version could not be made live');
            }
            $this->repository->replacePermissions($tenantId, $pluginKey, $parsed->permissions());
            $this->roles->seed(new TenantManifestPlugin($parsed), $tenantId);
            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->audit?->record(self::AUDIT_ACTIVATED, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor,
            'target_type' => 'tenant_plugin',
            'target_id' => $versionId,
            'metadata' => ['plugin' => $pluginKey, 'version' => $parsed->version(), 'hash' => $hash],
        ]);

        return ['version_id' => $versionId, 'plugin' => $pluginKey, 'version' => $parsed->version(), 'hash' => $hash];
    }

    /**
     * Make slugs grantable: a row in the global `permissions` catalogue, which
     * role grants reference.
     *
     * @param list<string> $permissions
     * @param array<string, array{en: string, ar: string}> $labels
     */
    private function catalogue(array $permissions, array $labels): void
    {
        $catalogue = $this->db->prepare(
            'INSERT INTO permissions (name, description) VALUES (:name, :description)
             ON CONFLICT (name) DO NOTHING'
        );
        foreach ($permissions as $slug) {
            $catalogue->execute([':name' => $slug, ':description' => $labels[$slug]['en'] ?? $slug]);
        }
    }
}
