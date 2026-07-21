<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantSecurityManageToAdmin migration (WC-525 PR-3).
 *
 * Registers the `security:manage` permission (admin-enforced 2FA policy CRUD +
 * status) and grants it to the seeded `admin` role, so a tenant admin can
 * manage 2FA policies out of the box.
 *
 * Mirrors the established catalogue-upsert + grant pattern (migrations
 * 058/060). down() reverses only what up() added.
 */
class GrantSecurityManageToAdmin
{
    public static function up(Database $db): void
    {
        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [
                ':name' => CorePermissions::SECURITY_MANAGE,
                ':description' => 'Manage admin-enforced 2FA policies (tenant-wide, per-OU, per-user)',
            ]
        );

        $adminRoleId = self::adminRoleId($db);
        if ($adminRoleId === null) {
            return;
        }

        $permissionId = self::permissionId($db, CorePermissions::SECURITY_MANAGE);
        if ($permissionId === null) {
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
        $adminRoleId = self::adminRoleId($db);
        $permissionId = self::permissionId($db, CorePermissions::SECURITY_MANAGE);

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
            [':name' => CorePermissions::SECURITY_MANAGE]
        );
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
