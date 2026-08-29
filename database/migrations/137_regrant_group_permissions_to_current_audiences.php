<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * RegrantGroupPermissionsToCurrentAudiences (#1040).
 *
 * THE DEFECT
 * ----------
 * Migration 116 grants `groups:read` to every role holding `documents:route`,
 * and `groups:write` to every role holding `roles:write` — evaluated AT
 * MIGRATION TIME. On a fresh install the demo roles receive `documents:route`
 * AFTERWARDS, so they never receive `groups:read`:
 *
 *     documents:route  ->  admin + 3 demo roles
 *     groups:read      ->  admin ONLY
 *
 * Three of the four route-capable roles can compose a route and then take a 403
 * listing the groups they need to route TO. The 403 correctly names
 * `groups:read`, so the failure is legible — but it is still a wall, and it is
 * on by default.
 *
 * WHAT THIS FIXES, AND WHAT IT DOES NOT
 * -------------------------------------
 * This re-runs 116's grant step against the audiences AS THEY STAND NOW. Every
 * install that has the hole today comes out of it with the grants 116 intended.
 *
 * It does NOT remove the class, and pretending otherwise is the thing worth not
 * doing. **A capability-based grant evaluated at migration time does not cover
 * anyone who acquires the capability later.** Any deployment that creates a
 * custom role, or grants `documents:route` to an existing one, after this
 * migration has run gets the same hole again — silently, because nothing
 * re-evaluates the grant. This migration is the forward repair; #1040 tracks
 * the two candidate answers that would close it for good, and both are
 * decisions about what `documents:route` MEANS rather than about data:
 *
 *  - make `groups:read` a companion granted wherever `documents:route` is
 *    granted, so acquisition order stops mattering; or
 *  - stop requiring a separate slug for the picker at all, letting
 *    `documents:route` cover listing groups and leaving `groups:read` to gate
 *    the standalone groups admin.
 *
 * Recording that here rather than only in the issue, because the next person to
 * find this hole will find it from a 403 and land on this file.
 *
 * WHY BOTH SLUGS, NOT JUST THE ONE THAT BIT
 * -----------------------------------------
 * `groups:write` has exactly the same timing hole against its `roles:write`
 * audience; it simply has not been reported, because a role granted
 * `roles:write` after 116 is rarer than the demo seeder running after it. Fixing
 * only the observed half would leave a defect of identical shape one line away,
 * to be rediscovered later as though it were new.
 *
 * DIRECT GRANTS ONLY
 * ------------------
 * The audience is measured by DIRECT `role_permissions` rows, the rule migration
 * 110 states and 116 repeats: effective resolution walks role inheritance,
 * organizational units and delegations, and a migration that followed those
 * paths would write grant rows onto roles that hold the capability transitively
 * — turning an inherited permission into an independent one and quietly
 * changing what revoking the parent does.
 *
 * Additive and idempotent (ON CONFLICT DO NOTHING). A database whose audience
 * permission is held by nobody gets no grants, which is correct rather than an
 * error.
 */
final class RegrantGroupPermissionsToCurrentAudiences
{
    /**
     * Which existing capability identifies the audience for each slug.
     *
     * Deliberately a VERBATIM copy of migration 116's `AUDIENCES`, not an import
     * of it. A migration is a record of what was done to a database on a given
     * day; reaching into another migration's constant would make this one's
     * behaviour change retroactively if 116 were ever edited, and 116 has
     * already run everywhere.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        CorePermissions::GROUPS_WRITE => [CorePermissions::ROLES_WRITE],
        CorePermissions::GROUPS_READ => [CorePermissions::ROLES_WRITE, CorePermissions::DOCUMENTS_ROUTE],
    ];

    public static function up(Database $db): void
    {
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                // 013 seeds the catalogue and 116 backfills it. A database
                // missing the row has a bigger problem than this grant, and
                // inventing the row here would hide it.
                continue;
            }

            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    /**
     * Deliberately a no-op.
     *
     * This migration adds grant rows that are INDISTINGUISHABLE from the ones
     * migration 116 added — same role, same permission, same table, and
     * `ON CONFLICT DO NOTHING` means the row a re-grant "added" may well be
     * 116's own. A down() that deleted the audience's grants would therefore
     * revoke permissions this migration never granted, taking `groups:read`
     * away from the administrator who has held it since 116 and breaking the
     * group picker for everybody — the exact failure being repaired here,
     * reintroduced by the rollback.
     *
     * Migration 116's down() remains the way to remove these grants, and it
     * still resolves the audience the same way. Rolling this one back leaves the
     * database in a state 116 already describes as correct.
     */
    public static function down(Database $db): void
    {
        // Intentionally empty; see the docblock.
    }

    /**
     * The ids of every role holding ANY of the given permissions DIRECTLY.
     *
     * De-duplicated: the two audiences for `groups:read` overlap on every
     * ordinary install (one `admin` role holds both), and inserting twice would
     * lean on the conflict clause to hide a bug in this method.
     *
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();

            if ($rows === false) {
                continue;
            }
            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
