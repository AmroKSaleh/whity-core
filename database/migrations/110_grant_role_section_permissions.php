<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantRoleSectionPermissions migration (#910).
 *
 * The role record page gates its REGIONS separately — who may SEE a role's
 * permission set and who may CHANGE it are different questions from who may
 * rename the role — and the server resolves both against real permission slugs.
 * The two slugs it resolves against, `permissions:read` and `roles:manage`, have
 * been in the catalogue since migration 013 and were never granted to anybody:
 * migration 013 seeded the whole `CorePermissions` list but deliberately granted
 * only the six `plugins:*` strings, because every other gated core route
 * enforced a `requiredRole` of `admin` and speculative grants were left as a
 * later policy decision. This is that decision, taken for these two.
 *
 * WITHOUT THIS MIGRATION THE FEATURE IS A REGRESSION, not a no-op: an existing
 * administrator who can edit roles today would open the record page and find the
 * permissions region hidden, because the resolver would correctly answer that
 * they hold neither slug.
 *
 * WHO IT GRANTS TO, AND WHY NOT JUST `admin`
 * ------------------------------------------
 * Every role that already holds `roles:write`, not the `admin` role by name.
 *
 * The ~20 `grant_*_to_admin` migrations before this one all target `admin`
 * literally, and that is the recorded hazard of the pattern (#834): a deployment
 * running a custom administrative role silently LOSES a capability on upgrade,
 * because the migration that introduced the gate reached only the seeded role.
 * Here the right audience is nameable without guessing. A role that may already
 * create and update roles is exactly the role that should keep being able to see
 * and set what those roles authorise; anything narrower takes a working
 * capability away from an operator who configured their deployment as the
 * product invited them to.
 *
 * It is deliberately not "every role holding the `admin` ROLE NAME" either:
 * role-name inheritance is a deployment's private vocabulary, and a grant keyed
 * on a capability the deployment actually granted is the one that survives
 * whatever they called it.
 *
 * Additive and idempotent (`ON CONFLICT DO NOTHING` on both steps). down()
 * removes exactly the grants up() added, and removes the catalogue rows only if
 * nothing else still references them — which for these two is never, since
 * migration 013 seeded them and 013's own down() owns them.
 */
class GrantRoleSectionPermissions
{
    /**
     * The two slugs the role record's regions resolve against.
     *
     * Descriptions are written for the person reading a permission picker, so
     * they say what the permission LETS SOMEBODY DO rather than restating the
     * slug: "permissions:read" on its own does not tell an operator that it is
     * what reveals a role's permission list.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::PERMISSIONS_READ => 'See the permission catalogue and which permissions a role holds',
        CorePermissions::ROLES_MANAGE     => "Change which permissions a role grants",
    ];

    /**
     * The capability that identifies the roles this migration grants to.
     *
     * See the class docblock: the audience is "whoever may already create and
     * update roles", which is a capability the deployment granted rather than a
     * role name this migration would have to guess.
     */
    private const AUDIENCE_PERMISSION = CorePermissions::ROLES_WRITE;

    public static function up(Database $db): void
    {
        // Step 1: catalogue upsert. 013 seeded both already; this is here so the
        // migration stands on its own against a database whose catalogue drifted,
        // and it can never overwrite a human-written description (ON CONFLICT).
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
            // A database where nobody may write roles has nobody to grant to.
            // Not an error: `up()` on a fresh install runs before whatever seeds
            // that grant, and the catalogue rows above are all this migration
            // owes such a database.
            return;
        }

        // Step 2: grant both slugs to every role in that audience.
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
        // Resolved the same way up() did. A role granted `roles:write` AFTER this
        // migration ran never received these two, so it has nothing to take back;
        // a role that lost `roles:write` in between keeps them, which is the
        // conservative direction for a down(): it leaves an operator with a
        // permission they may not need rather than removing one they may.
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

            // Only when nothing else holds it. Migration 013 seeded these rows
            // and owns their removal; this clause is the safety net for a
            // database where 013's catalogue step did not run.
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
     * The ids of every role holding a permission DIRECTLY.
     *
     * Direct grants only, deliberately. Effective resolution walks role
     * inheritance, organisational units and delegations, and a migration that
     * followed those paths would write grant rows onto roles that hold the
     * capability transitively — turning an inherited permission into an
     * independent one and quietly changing what revoking the parent does.
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
