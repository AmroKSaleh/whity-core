<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Sdk\Rbac\PermissionResolver;

/**
 * Single-tenant, single-device implementation of the SDK's PermissionResolver
 * contract — the offline equivalent of production's RoleChecker, deliberately
 * narrowed: no OU hierarchy, no resource-scoped or delegated grants (a single
 * desktop user has no organizational structure to walk). `$resourceType`/
 * `$resourceId` are accepted for interface compliance but always answer the
 * tenant-wide question — a documented limitation (this narrows rather than
 * widens authority, so it fails safe), not a silently dropped feature.
 *
 * Gated by PermissionRegistry exactly like production: an unregistered
 * permission slug is never granted, even if a stale role_permissions row
 * exists.
 */
final class DeviceRoleChecker implements PermissionResolver
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly PermissionRegistry $registry,
        private readonly string $deviceRole,
    ) {
    }

    public function hasPermission(
        int $profileId,
        int $tenantId,
        string $permission,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): bool {
        if (!$this->registry->exists($permission)) {
            return false;
        }

        return in_array($permission, $this->effectivePermissions($profileId, $tenantId), true);
    }

    public function hasRole(int $profileId, int $tenantId, string $role): bool
    {
        if ($role !== $this->deviceRole) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM roles WHERE name = :name');
        $stmt->execute([':name' => $this->deviceRole]);

        return $stmt->fetchColumn() !== false;
    }

    public function effectivePermissions(
        int $profileId,
        int $tenantId,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT p.name FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.name = :role'
        );
        $stmt->execute([':role' => $this->deviceRole]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
