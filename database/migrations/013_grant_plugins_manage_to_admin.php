<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantPluginsManageToAdmin migration (WC-54, rewritten for WC-218)
 *
 * NOTE ON NAMING: the class name is derived from the filename by the migration
 * runner ({@see \Whity\Cli\Commands\MigrationsCommand} and the test
 * {@see \Tests\Support\SchemaFromMigrations}), so the historical filename
 * `013_grant_plugins_manage_to_admin.php` pins the class name
 * `GrantPluginsManageToAdmin`. The filename is kept to preserve migration-name
 * tracking; the BEHAVIOUR was rewritten for WC-218.
 *
 * Reconciles the persisted permission catalogue with the in-memory
 * {@see CorePermissions} registry and grants the seeded `admin` role the plugin
 * lifecycle permissions the core `/api/plugins*` routes actually enforce through
 * the permission pipeline.
 *
 * WC-218 — granular plugin RBAC
 * -----------------------------
 * The single coarse `plugins:manage` permission was retired and replaced by six
 * per-action permissions, so each plugin operation can be delegated
 * independently:
 *  - `plugins:read`      — GET /api/plugins
 *  - `plugins:enable`    — POST /api/plugins/{name}/enable, .../{id}/re-enable
 *  - `plugins:disable`   — POST /api/plugins/{name}/disable
 *  - `plugins:upload`    — (route added in a later slice; permission seeded now)
 *  - `plugins:uninstall` — POST /api/plugins/{id}/uninstall
 *  - `plugins:reload`    — POST /api/plugins/reload
 *
 * Whity-Core has no production deployments, so the no-legacy stance applies:
 * `plugins:manage` is removed everywhere rather than kept as a compatibility
 * umbrella, and this migration NEVER grants it.
 *
 * This runs early in the catalogue catch-up sequence: the earlier seed
 * migrations (002 for users/roles/tenants, 005 for OUs) insert their permissions
 * with human-readable descriptions, and this catalogue catch-up only fills in the
 * remaining canonical core strings (e.g. `*:write`, `roles:manage`,
 * `permissions:read`, the six `plugins:*`) via ON CONFLICT DO NOTHING — so the
 * human descriptions are never overwritten.
 *
 * What it does (two idempotent, additive steps)
 * --------------------------------------------
 *  1. Catalogue catch-up: INSERT any {@see CorePermissions::all()} string missing
 *     from the `permissions` table (ON CONFLICT (name) DO NOTHING). This brings
 *     the persisted catalogue in line with the canonical core registry so a grant
 *     can reference a real `permissions.id`. No existing permission is modified.
 *  2. Conservative grant: grant the SIX `plugins:*` permissions to the seeded
 *     `admin` role via `role_permissions` (ON CONFLICT (role_id, permission_id)
 *     DO NOTHING). The retired `plugins:manage` is never referenced.
 *
 * Policy decision — why only the plugin permissions
 * -------------------------------------------------
 * The six `plugins:*` permissions are the ones the core `/api/plugins*` routes
 * enforce through the {@see \Whity\Http\RbacMiddleware} permission path. Every
 * other gated core route enforces a `requiredRole` of `admin` — resolved by role
 * name, not by a permission grant — so granting the remaining `CorePermissions`
 * strings to `admin` would be speculative and is intentionally deferred as a
 * separate policy decision rather than baked in here. Step 1 still seeds the FULL
 * catalogue so those permissions exist and can be granted (to any role) later
 * without another schema migration.
 *
 * Reversibility
 * -------------
 * down() removes exactly what up() added and nothing else:
 *  - the six `plugins:*` grants on the `admin` role, and
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
     * The six per-action plugin permissions this migration grants to the seeded
     * admin role (WC-218). `plugins:upload` has no route yet — its route lands in
     * a later slice — but the permission is seeded and granted now so it can be
     * delegated ahead of the feature.
     *
     * @var array<int, string>
     */
    private const GRANTED_PERMISSIONS = [
        CorePermissions::PLUGINS_READ,
        CorePermissions::PLUGINS_ENABLE,
        CorePermissions::PLUGINS_DISABLE,
        CorePermissions::PLUGINS_UPLOAD,
        CorePermissions::PLUGINS_UNINSTALL,
        CorePermissions::PLUGINS_RELOAD,
    ];

    /**
     * Human-readable descriptions for the six plugin permissions, applied only
     * when the catalogue row is created (ON CONFLICT (name) DO NOTHING keeps any
     * existing description). Permissions not listed here fall back to a generic
     * "Core permission (name)" description.
     *
     * @var array<string, string>
     */
    private const DESCRIPTIONS = [
        CorePermissions::PLUGINS_READ      => 'List installed plugins and their lifecycle state',
        CorePermissions::PLUGINS_ENABLE    => 'Enable or re-enable a plugin',
        CorePermissions::PLUGINS_DISABLE   => 'Disable an active plugin',
        CorePermissions::PLUGINS_UPLOAD    => 'Upload and install a new plugin',
        CorePermissions::PLUGINS_UNINSTALL => 'Uninstall a plugin (disable, roll back migrations, remove files)',
        CorePermissions::PLUGINS_RELOAD    => 'Reload the plugin registry',
    ];

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
                    ':description' => self::DESCRIPTIONS[$permission]
                        ?? 'Core permission (' . $permission . ')',
                ]
            );
        }

        // Step 2: conservative grant. Grant the six plugins:* permissions to the
        // seeded admin role. Resolve ids defensively; if the role or a permission
        // is missing the grant is a no-op rather than an error (e.g. a
        // partially-seeded database).
        $adminRoleId = self::adminRoleId($db);
        if ($adminRoleId === null) {
            return;
        }

        foreach (self::GRANTED_PERMISSIONS as $permission) {
            $permissionId = self::permissionId($db, $permission);
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
        // Reverse step 2: drop the six plugins:* grants on the admin role.
        $adminRoleId = self::adminRoleId($db);

        if ($adminRoleId !== null) {
            foreach (self::GRANTED_PERMISSIONS as $permission) {
                $permissionId = self::permissionId($db, $permission);
                if ($permissionId === null) {
                    continue;
                }

                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
                );
            }
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
