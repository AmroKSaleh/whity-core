<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantAdminWritePermissions migration (WC-204)
 *
 * Seeds and grants the five "write" permission strings that the useCapabilities
 * hook requires to show write controls in the Roles, Tenants, and OUs admin
 * pages: `roles:write`, `roles:delete`, `tenants:write`, `tenants:delete`, and
 * `ous:write`.
 *
 * Background
 * ----------
 * Prior to WC-204 the Roles, Tenants, and OUs API routes were gated only by a
 * bare `admin` role check, so no fine-grained permission grant was ever needed.
 * WC-204 adds a `useCapabilities` hook that calls `GET /api/me/capabilities` and
 * hides write controls when the caller lacks the relevant permission slug. Without
 * this migration the admin role holds none of those five slugs and all write
 * controls are hidden (fail-closed), breaking every E2E test that tries to create
 * or mutate a Role, Tenant, or OU.
 *
 * Previously granted by other migrations (NOT duplicated here):
 *   - `ous:delete`       — migration 009
 *   - `relations:manage` — migration 020
 *   - `users:write/delete` — migration 022
 *
 * What it does (additive, idempotent, reversible)
 * ------------------------------------------------
 *  1. Catalogue upsert: INSERT each permission string (ON CONFLICT DO NOTHING).
 *  2. Grant: INSERT into `role_permissions` for each permission × admin role
 *     (ON CONFLICT DO NOTHING).
 *
 * down() reverses only what up() added: removes the five grants and then removes
 * catalogue rows only when no other `role_permissions` row still references them.
 */
class GrantAdminWritePermissions
{
    /**
     * The permissions this migration seeds and grants to admin.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::ROLES_WRITE    => 'Create and update roles',
        CorePermissions::ROLES_DELETE   => 'Delete roles',
        CorePermissions::TENANTS_WRITE  => 'Create and update tenants',
        CorePermissions::TENANTS_DELETE => 'Delete tenants',
        CorePermissions::OUS_WRITE      => 'Create and update organisational units',
    ];

    public static function up(Database $db): void
    {
        // Step 1: catalogue upsert — idempotent via ON CONFLICT (name).
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        // Step 2: grant each permission to the admin role.
        $adminRoleId = self::adminRoleId($db);
        if ($adminRoleId === null) {
            return;
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            $db->query(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 VALUES (:role_id, :permission_id, NOW())
                 ON CONFLICT (role_id, permission_id) DO NOTHING',
                [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
            );
        }
    }

    public static function down(Database $db): void
    {
        $adminRoleId = self::adminRoleId($db);

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);

            if ($adminRoleId !== null && $permissionId !== null) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
                );
            }

            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $name]
            );
        }
    }

    private static function adminRoleId(Database $db): ?int
    {
        $result = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin'])->fetch();

        return $result === false ? null : (int) $result['id'];
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
