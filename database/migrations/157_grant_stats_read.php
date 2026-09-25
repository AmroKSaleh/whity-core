<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantStatsRead migration (#990, the stats group).
 *
 * Registers `stats:read` and grants it to everyone who can already list the
 * people it counts, so `GET /api/v1/admin/stats` and the Dashboard nav item can
 * stop being gated on the `admin` ROLE NAME.
 *
 * WHY A DEDICATED SLUG RATHER THAN REUSING `users:read`
 * -----------------------------------------------------
 * The route could simply be gated on `users:read` and no new slug minted. It is
 * not, for one reason: the NAV ITEM has to gate on the same thing as the route
 * it points at, and `/admin` redirects to `/admin/stats`, so this pair decides
 * whether the admin console has a landing page at all. That deserves a name an
 * operator can see and reason about in the role editor, rather than being a
 * side effect of a permission about users.
 *
 * WHO IT GRANTS TO, AND WHY THAT IS NOT A WIDENING
 * ------------------------------------------------
 * Every role already holding `users:read`, by capability rather than by name —
 * the pattern migration 110 established and `ci-grant-by-role-name-guard`
 * enforces, after #834 found that granting to `admin` literally makes a
 * deployment with a renamed administrative role silently LOSE the capability on
 * upgrade.
 *
 * `users:read` is the right anchor because this endpoint hands back an
 * AGGREGATE of rows that permission already exposes individually: active
 * membership counts, a role breakdown, and a signup trend. A caller who may
 * enumerate the memberships can already count them; receiving the total costs
 * them nothing they did not have. The one figure that is not derivable that way
 * — the platform-wide tenant count — is already restricted separately, since
 * the handler returns it only to the system tenant (`$isSystemUser`), and that
 * check is unchanged by this migration.
 *
 * A 403 HERE IS NOT A BROKEN PAGE
 * -------------------------------
 * Worth recording because it is what makes this safe to narrow: the stats page
 * treats every failure path, 403 included, as "not shown" rather than as an
 * error — its own comment says "WHY EVERY FAILURE IS SILENCE. A 403 here is the
 * endpoint working." So a role that ends up without `stats:read` lands on a
 * page with figures hidden, not a crash or a redirect loop.
 */
class GrantStatsRead
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::STATS_READ =>
            'Read the admin dashboard aggregates for the current tenant (counts, role breakdown, trends)',
    ];

    /** Grant to every role that already holds this, never to a role by name. */
    private const AUDIENCE_PERMISSION = CorePermissions::USERS_READ;

    public static function up(Database $db): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        if ($audience === []) {
            // Nobody may list users, so nobody has a claim on the aggregate of
            // them. The catalogue row above is all this migration owes such a
            // database; a later grant of `users:read` does not retroactively
            // confer this, which is the conservative direction.
            return;
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            foreach ($audience as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            foreach ($audience as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
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

    /**
     * Role ids holding a permission, by slug.
     *
     * @return list<int>
     */
    private static function rolesHolding(Database $db, string $permission): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => $permission]
        )->fetchAll();

        if ($rows === false) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
