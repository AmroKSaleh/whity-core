<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissionDescriptions;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Registers `plugins:build` and `plugins:publish` (Whity Builder, WHIT-764):
 * drafting a workspace's own plugins, and putting a version live or rolling
 * back to one.
 *
 * Granted to every role that can already switch plugins on (`plugins:enable`),
 * by capability rather than by role name (the rule migration 110 set and
 * `ci-grant-by-role-name-guard` enforces). That is not a widening: whoever may
 * turn a plugin's behaviour on for the workspace may already change what it
 * does, and the split lets an operator later hand drafting to someone who
 * can't publish.
 */
class GrantWorkspacePluginPermissions
{
    /** @var list<string> */
    private const PERMISSIONS = [CorePermissions::PLUGINS_BUILD, CorePermissions::PLUGINS_PUBLISH];

    /** Grant to every role that already holds this, never to a role by name. */
    private const AUDIENCE_PERMISSION = CorePermissions::PLUGINS_ENABLE;

    public static function up(Database $db): void
    {
        $descriptions = CorePermissionDescriptions::all();
        foreach (self::PERMISSIONS as $name) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $descriptions[$name]]
            );
        }

        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        foreach (self::PERMISSIONS as $name) {
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
        foreach (self::PERMISSIONS as $name) {
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

        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['role_id'], $rows)));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
