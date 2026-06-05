<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantPluginsManageToAdmin migration (WC-54)
 *
 * Reconciles the persisted permission catalogue with the in-memory
 * {@see CorePermissions} registry and grants the seeded `admin` role the one
 * permission an existing core route actually enforces through the permission
 * pipeline.
 *
 * This runs LAST in the sequence on purpose: the earlier seed migrations
 * (002 for users/roles/tenants, 005 for OUs) insert their permissions with
 * human-readable descriptions, and this catalogue catch-up only fills in the
 * remaining canonical core strings (e.g. `*:write`, `roles:manage`,
 * `permissions:read`, `plugins:manage`) via ON CONFLICT DO NOTHING — so the
 * human descriptions are never overwritten.
 *
 * Why this migration exists
 * -------------------------
 * Since WC-4 (PR #130) wired route-level `requiredPermission` forwarding into the
 * live request pipeline, the five plugin-admin endpoints are gated by
 * `plugins:manage` ({@see CorePermissions::PLUGINS_MANAGE}). That permission is
 * defined only in the in-memory {@see CorePermissions} registry — it is not part
 * of the smaller human-described set the earlier seed migrations (002, 005) insert
 * and was never granted to the `admin` role. As a result an authenticated admin
 * reaching a plugin endpoint passes the registry gate but finds no matching
 * `role_permissions` row and is denied (403) even though they are the platform
 * administrator. This migration closes that gap.
 *
 * What it does (two idempotent, additive steps)
 * --------------------------------------------
 *  1. Catalogue catch-up: INSERT any {@see CorePermissions::all()} string missing
 *     from the `permissions` table (ON CONFLICT (name) DO NOTHING). This brings
 *     the persisted catalogue in line with the canonical core registry so a grant
 *     can reference a real `permissions.id`. No existing permission is modified.
 *  2. Conservative grant: grant `plugins:manage` to the seeded `admin` role via
 *     `role_permissions` (ON CONFLICT (role_id, permission_id) DO NOTHING).
 *
 * Policy decision — why only `plugins:manage`
 * -------------------------------------------
 * `plugins:manage` is the ONLY permission an existing core route enforces through
 * the {@see \Whity\Http\RbacMiddleware} permission path (the five `/api/plugins*`
 * routes in public/index.php). Every other gated core route enforces a
 * `requiredRole` of `admin` — resolved by role name, not by a permission grant —
 * so granting the remaining `CorePermissions` strings to `admin` would be
 * speculative and is intentionally deferred as a separate policy decision rather
 * than baked in here. Step 1 still seeds the FULL catalogue so those permissions
 * exist and can be granted (to any role) later without another schema migration.
 *
 * Reversibility
 * -------------
 * down() removes exactly what up() added and nothing else:
 *  - the `plugins:manage` grant on the `admin` role, and
 *  - the catalogue rows for the core permissions that did NOT already exist
 *    before this migration. Rows seeded by earlier migrations (002 for
 *    users/roles/tenants, 005 for OUs — see {@see self::PRE_SEEDED}) are
 *    deliberately left in place, and any row still referenced by a
 *    `role_permissions` grant is kept, so down() never orphans a grant nor
 *    deletes a permission another migration owns.
 *
 * Additive, idempotent forward, and a precise reversible down().
 */
class GrantPluginsManageToAdmin
{
    /**
     * The single permission this migration grants to the seeded admin role.
     */
    private const GRANTED_PERMISSION = CorePermissions::PLUGINS_MANAGE;

    /**
     * Core permission names that earlier migrations already seed into the
     * `permissions` catalogue (002 for users/roles/tenants; 005 for OUs). down()
     * must NOT remove these — they predate this migration and are owned by their
     * seeding migrations. Only the genuinely new {@see CorePermissions::all()}
     * strings (those NOT in this list) are this migration's to remove on rollback.
     *
     * @var array<int, string>
     */
    private const PRE_SEEDED = [
        'users:read', 'users:create', 'users:update', 'users:delete',
        'roles:read', 'roles:create', 'roles:update', 'roles:delete',
        'tenants:read', 'tenants:create', 'tenants:update', 'tenants:delete',
        'ous:read', 'ous:create', 'ous:update', 'ous:delete', 'ous:assign',
    ];

    public static function up(Database $db): void
    {
        // Step 1: catalogue catch-up. Insert every canonical core permission that
        // is not yet present so the persisted catalogue matches CorePermissions.
        // Idempotent via ON CONFLICT (name).
        foreach (CorePermissions::all() as $permission) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [
                    ':name' => $permission,
                    ':description' => 'Core permission (' . $permission . ')',
                ]
            );
        }

        // Step 2: conservative grant. Grant plugins:manage to the seeded admin
        // role. Resolve ids defensively; if either is missing the grant is a
        // no-op rather than an error (e.g. a partially-seeded database).
        $adminRoleId = self::adminRoleId($db);
        $permissionId = self::permissionId($db, self::GRANTED_PERMISSION);

        if ($adminRoleId === null || $permissionId === null) {
            return;
        }

        $db->query(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             VALUES (:role_id, :permission_id, NOW())
             ON CONFLICT (role_id, permission_id) DO NOTHING',
            [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
        );
    }

    public static function down(Database $db): void
    {
        // Reverse step 2: drop the plugins:manage grant on the admin role.
        $adminRoleId = self::adminRoleId($db);
        $permissionId = self::permissionId($db, self::GRANTED_PERMISSION);

        if ($adminRoleId !== null && $permissionId !== null) {
            $db->query(
                'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
            );
        }

        // Reverse step 1: remove ONLY the catalogue rows this migration added —
        // the core permissions not already seeded by an earlier migration. A row
        // still referenced by any grant is kept so down() never orphans a grant.
        foreach (CorePermissions::all() as $permission) {
            if (in_array($permission, self::PRE_SEEDED, true)) {
                continue;
            }

            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp
                       WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $permission]
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
