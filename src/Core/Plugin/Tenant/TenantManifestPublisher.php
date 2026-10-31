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

        $json = (string) json_encode($parsed->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        $permissions = $parsed->permissions();
        $labels = $parsed->permissionLabels();

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $catalogue = $this->db->prepare(
                'INSERT INTO permissions (name, description) VALUES (:name, :description)
                 ON CONFLICT (name) DO NOTHING'
            );
            foreach ($permissions as $slug) {
                $catalogue->execute([':name' => $slug, ':description' => $labels[$slug]['en'] ?? $slug]);
            }

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
}
