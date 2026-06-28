<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantMcpTokensManageToAdmin migration (WC-149b2fc9).
 *
 * Registers the mcp:tokens:manage permission in the `permissions` catalogue
 * and grants it to the seeded `admin` role so a fresh database has the MCP
 * credential management surface available to administrators out of the box.
 *
 * Mirrors the established core seeding pattern (migrations 022/016/026):
 * catalogue upsert (ON CONFLICT DO NOTHING) then grant (ON CONFLICT DO NOTHING),
 * resolving ids defensively so a partially-seeded database is skipped rather
 * than errored.
 *
 * down() reverses only what up() added: it removes the grant, then removes the
 * catalogue row ONLY when no other role_permissions row still references it.
 */
class GrantMcpTokensManageToAdmin
{
    public static function up(Database $db): void
    {
        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [
                ':name'        => CorePermissions::MCP_TOKENS_MANAGE,
                ':description' => 'Issue and revoke MCP bearer credentials for AI clients',
            ]
        );

        $adminRoleId = self::adminRoleId($db);
        if ($adminRoleId === null) {
            return;
        }

        $permissionId = self::permissionId($db, CorePermissions::MCP_TOKENS_MANAGE);
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
        $adminRoleId  = self::adminRoleId($db);
        $permissionId = self::permissionId($db, CorePermissions::MCP_TOKENS_MANAGE);

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
            [':name' => CorePermissions::MCP_TOKENS_MANAGE]
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
