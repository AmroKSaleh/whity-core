<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantTenantsReadToTenantAdministrators — forward migration (#990, migration 138).
 *
 * `tenants:read` has been in the permission catalogue since migration 002 and
 * **no role has ever held it**. Measured on a freshly migrated and seeded
 * database immediately before this file was written:
 *
 *     slug              holders  held by
 *     permissions:read        1  admin
 *     tenants:delete          1  admin
 *     tenants:write           1  admin
 *     tenants:read            0  -- NOBODY --
 *
 * It is the second orphan of exactly the shape #977 found in `roles:read`, one
 * route group over, and it went unnoticed for the same reason: nothing consulted
 * it. Every tenants route in `public/index.php` was gated on the literal role
 * string `admin`, so the catalogue entry was decoration — a slug a deployment
 * could grant and which would then do nothing.
 *
 * WHY THIS MIGRATION MUST LAND WITH THE RE-GATE, NOT AFTER IT
 * -----------------------------------------------------------
 * #990 moves those routes onto permission slugs. `GET /api/tenants` becomes
 * `tenants:read` — and on the day that ships, a slug nobody holds is a tenant
 * list nobody can open. The seeded `admin` role holds `tenants:write` and
 * `tenants:delete`, so it survives the other three gates in that change and
 * would have been locked out of exactly one: the only one anybody reaches by
 * clicking, and the one every other tenants screen starts from.
 *
 * That is the failure #977 named as the single way this goes badly, and it is
 * why the grant is a prerequisite of the re-gate rather than a follow-up.
 *
 * WHO IT GRANTS TO, AND WHY NOT `admin`
 * -------------------------------------
 * Every role that already holds `tenants:write` or `tenants:delete` — a
 * capability the deployment granted, not a name this migration would have to
 * guess at. Migration 110 recorded why (#834):
 *
 *   > The ~20 `grant_*_to_admin` migrations before this one all target `admin`
 *   > [by name, so] a deployment running a custom administrative role silently
 *   > LOSES a capability on upgrade.
 *
 * `scripts/ci-grant-by-role-name-guard.php` now enforces that, so a by-name
 * grant here would not merely be wrong, it would fail the build.
 *
 * TWO ANCHOR CAPABILITIES, NOT ONE — this is where it differs from 111.
 * Migration 111 anchored `roles:read` on `roles:write` alone, because the roles
 * group has no route a delete-only role could reach. The tenants group does:
 * `DELETE /api/tenants/{id}` is gated on `tenants:delete`, which is a separate
 * slug from `tenants:write` and separately grantable. A role holding only
 * `tenants:delete` may delete a tenant it would have no way to find, since the
 * id it needs comes from the list this migration is unlocking. Anchoring on one
 * capability would have built that lockout on purpose, for a deployment nobody
 * here can see.
 *
 * There is no coherent deployment in which a role may write or delete a tenant
 * it cannot see. That is the whole argument, and it is deliberately no wider:
 * read-only tenant VIEWERS are not invented here. A deployment that wants "may
 * see tenants, may not change them" now has a slug that works and can grant it;
 * that is a choice for an operator, not something an upgrade should decide on
 * their behalf.
 *
 * WHAT THIS DOES NOT RESTORE
 * --------------------------
 * A role literally NAMED `admin` that holds no tenants permissions could reach
 * these routes before, because the gate compared the name. After #990 it cannot,
 * and this migration does not give that access back. It was accidental — the
 * role never held any tenants capability — and preserving it would mean
 * re-introducing the name coupling the change exists to remove.
 */
final class GrantTenantsReadToTenantAdministrators
{
    /**
     * The slug being granted, with the description migration 002 gave it.
     *
     * Upserted rather than assumed: the migration should stand on its own
     * against a database whose catalogue drifted, and `ON CONFLICT DO NOTHING`
     * means it can never overwrite a description someone wrote by hand.
     */
    private const PERMISSIONS = [
        CorePermissions::TENANTS_READ => 'Read tenants',
    ];

    /**
     * The capabilities that identify the roles this migration grants to.
     *
     * A role holding EITHER is in the audience — see the class docblock on why
     * this is two anchors where migration 111 needed one.
     *
     * @var list<string>
     */
    private const AUDIENCE_PERMISSIONS = [
        CorePermissions::TENANTS_WRITE,
        CorePermissions::TENANTS_DELETE,
    ];

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

        $audience = self::rolesHoldingAny($db, self::AUDIENCE_PERMISSIONS);
        if ($audience === []) {
            // A database where nobody may administer tenants has nobody to grant
            // to. Not an error: on a fresh install `up()` runs before whatever
            // seeds that grant, and the catalogue row above is all this migration
            // owes such a database.
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

    /**
     * Take the grant back from the same audience `up()` gave it to.
     *
     * The catalogue row stays. It predates this migration — 002 seeded it — and
     * removing it would delete a slug a deployment may since have granted
     * deliberately, which is not this migration's to take.
     */
    public static function down(Database $db): void
    {
        $audience = self::rolesHoldingAny($db, self::AUDIENCE_PERMISSIONS);

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
        }
    }

    /**
     * Role ids holding at least one of the given permissions.
     *
     * @param  list<string> $permissions
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

        return array_keys($roleIds);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $row = $db->query(
            'SELECT id FROM permissions WHERE name = :name',
            [':name' => $name]
        )->fetch();

        return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
    }
}
