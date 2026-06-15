<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantUsersPermissionsToAdmin migration (WC-203)
 *
 * Seeds the `users:read`, `users:write`, and `users:delete` permissions into
 * the `permissions` catalogue (idempotent) and grants all three to the seeded
 * `admin` role so a fresh database has the Users admin area usable by
 * administrators out of the box.
 *
 * Background
 * ----------
 * Prior to WC-203 the users API routes (`GET/POST/PATCH/DELETE /api/users`)
 * were gated only by a bare `admin` role check — any user carrying the `admin`
 * role was implicitly allowed, regardless of whether a more fine-grained
 * permission was held. WC-203 replaces those role checks with permission-based
 * checks (`users:read`, `users:write`, `users:delete`), exactly following the
 * `relations:*` pattern introduced in migration 020.
 *
 * The three permission strings are already part of {@see CorePermissions::all()}
 * (and therefore already exist in the `permissions` catalogue after migration 013
 * ran its catalogue catch-up), but were never GRANTED to the `admin` role. This
 * migration closes that gap.
 *
 * What it does (additive, idempotent, reversible)
 * ------------------------------------------------
 *  1. Catalogue upsert: INSERT each of the three permission strings (ON CONFLICT
 *     DO NOTHING) so the rows exist even on a database that skipped migration 013.
 *  2. Grant: INSERT into `role_permissions` for each permission × admin role
 *     (ON CONFLICT DO NOTHING).
 *
 * down() reverses only what up() added: it removes the three grants and then
 * removes the catalogue rows ONLY when no other `role_permissions` row still
 * references them, so it never orphans a grant or removes a row owned by an
 * earlier migration.
 */
class GrantUsersPermissionsToAdmin
{
    /**
     * The permissions this migration seeds and grants to admin.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::USERS_READ   => 'List and view users',
        CorePermissions::USERS_WRITE  => 'Create and update users',
        CorePermissions::USERS_DELETE => 'Delete users',
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

        // Step 2: grant each permission to the admin role. Resolve ids
        // defensively — skip rather than fail on a partially-seeded database.
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

            // Reverse the grant first.
            if ($adminRoleId !== null && $permissionId !== null) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
                );
            }

            // Remove the catalogue row only when no grant still references it
            // (defensive: never orphan a grant, never remove a row another
            // migration owns that still has references).
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

    /**
     * Resolve the seeded admin role id, or null when it is absent.
     */
    private static function adminRoleId(Database $db): ?int
    {
        $result = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin'])->fetch();

        return $result === false ? null : (int) $result['id'];
    }

    /**
     * Resolve a permission id by name, or null when it is absent.
     */
    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
