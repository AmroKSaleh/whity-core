<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
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
     */
    public function __construct(PDO $db, ?PermissionRegistry $registry = null)
    {
        $this->db = $db;
        $this->registry = $registry;
    }

    /**
     * GET /api/permissions - List all permissions.
     *
     * @param Request $request The incoming request.
     * @return Response JSON list of permissions under the `data` key.
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

            return Response::json(['data' => $permissions], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch permissions: ' . $e->getMessage(), 500);
        }
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
