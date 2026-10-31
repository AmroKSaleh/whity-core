<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\PaginationParams;
use PDO;

/**
 * Permissions API Handler
 *
 * Returns available permissions for role assignment. Permissions persisted in
 * the database are returned as-is. When a {@see PermissionRegistry} is supplied,
 * registry-defined permissions (core + plugin, tagged by source) that are not
 * already present in the database are merged in so the catalogue reflects every
 * permission the system actually enforces (issue #55).
 */
class PermissionsApiHandler
{
    private PDO $db;

    /**
     * Optional permission registry used to surface core and plugin permissions.
     */
    private ?PermissionRegistry $registry;

    /**
     * Constructor.
     *
     * @param PDO $db Database connection.
     * @param PermissionRegistry|null $registry Optional permission registry. When
     *        omitted, the handler returns only database-backed permissions,
     *        preserving the original behaviour.
     * @param TenantPluginRepository|null $tenantPlugins When given, permissions
     *        that only OTHER workspaces' own plugins declare are left out
     *        (WHIT-760): their names describe another workspace's logic.
     */
    public function __construct(
        PDO $db,
        ?PermissionRegistry $registry = null,
        private readonly ?TenantPluginRepository $tenantPlugins = null,
    ) {
        $this->db = $db;
        $this->registry = $registry;
    }

    /**
     * GET /api/permissions - List all permissions (paginated).
     *
     * Registry-defined permissions are merged after the DB fetch so the total
     * count and page slice both reflect the full merged catalogue.
     *
     * @param Request $request The incoming request.
     * @return Response JSON list of permissions with pagination envelope.
     */
    public function list(Request $request): Response
    {
        try {
            $stmt = $this->db->prepare('
                SELECT id, name, description
                FROM permissions
                ORDER BY name
            ');
            $stmt->execute();
            /** @var array<int, array<string, mixed>> $permissions */
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $permissions = $this->mergeRegistryPermissions($permissions);
            $permissions = $this->withoutOtherWorkspacesPlugins($permissions);

            $p     = PaginationParams::fromPath($request->getPath());
            $total = count($permissions);
            $page  = array_slice($permissions, $p->offset, $p->perPage);

            return Response::json(['data' => $page, 'pagination' => $p->meta($total)], 200);
        } catch (\Exception $e) {
            error_log('[PermissionsApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch permissions', 500);
        }
    }

    /**
     * Drop permissions only other workspaces' own plugins declare.
     *
     * @param array<int, array<string, mixed>> $permissions
     * @return array<int, array<string, mixed>>
     */
    private function withoutOtherWorkspacesPlugins(array $permissions): array
    {
        $tenantId = TenantContext::getTenantId();
        if ($this->tenantPlugins === null || $tenantId === null) {
            return $permissions;
        }
        $hidden = array_flip($this->tenantPlugins->permissionsOfOtherTenants($tenantId));
        if ($hidden === []) {
            return $permissions;
        }

        return array_values(array_filter(
            $permissions,
            static fn (array $p): bool => !isset($hidden[(string) ($p['name'] ?? '')]),
        ));
    }

    /**
     * Merge registry-defined permissions that are absent from the database rows.
     *
     * Registry permissions carry no database id; they are tagged with their
     * `source` instead so consumers can distinguish core from plugin permissions.
     *
     * @param array<int, array<string, mixed>> $dbPermissions Permissions from the database.
     * @return array<int, array<string, mixed>> Combined list of permissions.
     */
    private function mergeRegistryPermissions(array $dbPermissions): array
    {
        if ($this->registry === null) {
            return $dbPermissions;
        }

        $known = [];
        foreach ($dbPermissions as $permission) {
            if (isset($permission['name']) && is_string($permission['name'])) {
                $known[$permission['name']] = true;
            }
        }

        $merged = $dbPermissions;
        foreach ($this->registry->getAll() as $name => $source) {
            if (isset($known[$name])) {
                continue;
            }

            $merged[] = [
                'id' => null,
                'name' => $name,
                'description' => null,
                'source' => $source,
            ];
        }

        return $merged;
    }
}
