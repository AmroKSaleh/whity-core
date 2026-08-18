<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * RevokeOusReadFromUserRole migration.
 *
 * Removes the base `user` role's `ous:read` grant, seeded by migration 009 under
 * the heading "User role gets read-only OU permissions".
 *
 * Why now
 * -------
 * That grant has never been enforceable: the OU routes were gated on the bare
 * `admin` role and consulted no permission at all, so `ous:read` decided nothing.
 * Wiring the OU routes onto their seeded `ous:*` slugs makes every OU grant live,
 * and this one would go live as a SILENT WIDENING — every plain user would gain
 * the tenant's OU tree, its OU role assignments, and, through
 * GET /api/ous/{id}/members, the login email and display name of every active
 * member. The product has since settled the opposite way: the OU admin area is
 * admin-only, pinned by web/e2e/matrix-core-areas.spec.ts and regular-user.spec.ts,
 * which both assert the nav link is absent for the `user` role.
 *
 * Dropping the grant rather than keeping a belt-and-braces role check on the
 * routes is what lets the permission be the WHOLE truth of the gate, so a plugin
 * aliasing OU management can reuse `ous:read` and land on exactly core's answer.
 *
 * Scope: the seeded `user` role only. An OPERATOR-DEFINED role holding `ous:*` is
 * left alone — those grants becoming effective is the point of the change, not a
 * side effect of it.
 *
 * down() restores the grant, so the pair is reversible.
 */
class RevokeOusReadFromUserRole
{
    private const ROLE = 'user';
    private const PERMISSION = CorePermissions::OUS_READ;

    public static function up(Database $db): void
    {
        $roleId = self::roleId($db, self::ROLE);
        $permissionId = self::permissionId($db, self::PERMISSION);

        if ($roleId === null || $permissionId === null) {
            return;
        }

        $db->query(
            'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
            [':role_id' => $roleId, ':permission_id' => $permissionId]
        );
    }

    public static function down(Database $db): void
    {
        $roleId = self::roleId($db, self::ROLE);
        $permissionId = self::permissionId($db, self::PERMISSION);

        if ($roleId === null || $permissionId === null) {
            return;
        }

        $db->query(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             VALUES (:role_id, :permission_id, NOW())
             ON CONFLICT (role_id, permission_id) DO NOTHING',
            [':role_id' => $roleId, ':permission_id' => $permissionId]
        );
    }

    private static function roleId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
