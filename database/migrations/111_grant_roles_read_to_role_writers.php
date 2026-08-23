<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantRolesReadToRoleWriters — forward migration (#977, migration 111).
 *
 * `roles:read` has been in the permission catalogue since 013 and **no role has
 * ever held it**. Verified against a freshly migrated database: the slug exists,
 * and the query for who holds it returns nothing at all.
 *
 * It went unnoticed because nothing consulted it. Every roles route in
 * `public/index.php` was gated on the literal role string `admin`, so the
 * catalogue entry was decoration — a slug a deployment could grant and which
 * would then do nothing.
 *
 * WHY THIS MIGRATION MUST LAND WITH THE RE-GATE, NOT AFTER IT
 * -----------------------------------------------------------
 * #977 moves those routes onto permission slugs, which is what makes #975's
 * per-region machinery reachable. `GET /api/roles` becomes `roles:read` — and
 * on the day that ships, a slug nobody holds is a roles list nobody can open.
 * The seeded `admin` role holds `roles:write`, `roles:delete`, `roles:manage`
 * and `permissions:read`, so it survives every other gate in that change and
 * would have been locked out of exactly one: the most-used of the nine.
 *
 * That is the failure the issue named as the one way this goes badly, and it is
 * why the grant is a prerequisite of the re-gate rather than a follow-up.
 *
 * WHO IT GRANTS TO, AND WHY NOT `admin`
 * -------------------------------------
 * Every role that already holds `roles:write` — the audience and the reasoning
 * migration 110 established one migration earlier, for the same reason:
 *
 *   > The ~20 `grant_*_to_admin` migrations before this one all target `admin`
 *   > [by name, so] a deployment running a custom administrative role silently
 *   > LOSES a capability on upgrade.
 *
 * A role that may create and update roles must be able to list them; there is
 * no coherent deployment in which it may write a role it cannot see. Naming the
 * capability rather than the role means a tenant that renamed or restructured
 * `admin` is covered without this migration having to guess.
 *
 * It deliberately does NOT invent read-only role viewers. A deployment that
 * wants "may see roles, may not change them" now has a slug that works and can
 * grant it; that is a choice for an operator, not something an upgrade should
 * decide on their behalf.
 *
 * WHAT THIS DOES NOT RESTORE
 * --------------------------
 * A role literally NAMED `admin` that holds no roles permissions could reach
 * these routes before, because the gate compared the name. After #977 it cannot,
 * and this migration does not give it access back. That access was accidental —
 * the role never held any roles capability — and preserving it would mean
 * re-introducing the name coupling the change exists to remove.
 */
final class GrantRolesReadToRoleWriters
{
    /**
     * The slug being granted, with the description 013 gave it.
     *
     * Upserted rather than assumed: the migration should stand on its own
     * against a database whose catalogue drifted, and `ON CONFLICT DO NOTHING`
     * means it can never overwrite a description someone wrote by hand.
     */
    private const PERMISSIONS = [
        CorePermissions::ROLES_READ => 'View roles and their assignments',
    ];

    /**
     * The capability that identifies the roles this migration grants to.
     *
     * Same audience as migration 110, deliberately: "whoever may already create
     * and update roles" is a capability the deployment granted, not a role name
     * this migration would have to guess at.
     */
    private const AUDIENCE_PERMISSION = CorePermissions::ROLES_WRITE;

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
            // A database where nobody may write roles has nobody to grant to.
            // Not an error: on a fresh install `up()` runs before whatever seeds
            // that grant, and the catalogue row above is all this migration owes
            // such a database.
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
     * The catalogue row stays. It predates this migration — 013 seeded it — and
     * removing it would delete a slug a deployment may since have granted
     * deliberately, which is not this migration's to take.
     */
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
        }
    }

    /**
     * Role ids holding a given permission.
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
        $row = $db->query(
            'SELECT id FROM permissions WHERE name = :name',
            [':name' => $name]
        )->fetch();

        return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
    }
}
