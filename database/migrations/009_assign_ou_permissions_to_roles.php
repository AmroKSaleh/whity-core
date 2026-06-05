<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AssignOuPermissionsToRoles migration
 *
 * Assigns organizational unit (OU) permissions to default roles.
 * Admin role gets full OU management permissions.
 * User role gets read-only OU permissions.
 */
class AssignOuPermissionsToRoles
{
    public static function up(Database $db): void
    {
        // Get admin and user role IDs
        $adminRoleStmt = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin']);
        $adminRole = $adminRoleStmt->fetch();
        $adminRoleId = $adminRole['id'] ?? 1;

        $userRoleStmt = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'user']);
        $userRole = $userRoleStmt->fetch();
        $userRoleId = $userRole['id'] ?? 2;

        // Get OU permission IDs (resource:action notation; see issue #55)
        $ouPermissions = [
            'ous:read' => 'ous:read',
            'ous:create' => 'ous:create',
            'ous:update' => 'ous:update',
            'ous:delete' => 'ous:delete',
            'ous:assign' => 'ous:assign',
        ];

        $permissionIds = [];
        foreach ($ouPermissions as $name => $key) {
            $stmt = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name]);
            $permission = $stmt->fetch();
            if ($permission) {
                $permissionIds[$key] = $permission['id'];
            }
        }

        // Assign all OU permissions to admin role
        foreach ($permissionIds as $permName => $permId) {
            $db->query(
                'INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (:role_id, :permission_id, NOW()) ON CONFLICT (role_id, permission_id) DO NOTHING',
                [':role_id' => $adminRoleId, ':permission_id' => $permId]
            );
        }

        // Assign only read permission to user role
        if (isset($permissionIds['ous:read'])) {
            $db->query(
                'INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (:role_id, :permission_id, NOW()) ON CONFLICT (role_id, permission_id) DO NOTHING',
                [':role_id' => $userRoleId, ':permission_id' => $permissionIds['ous:read']]
            );
        }
    }

    public static function down(Database $db): void
    {
        // Get admin and user role IDs
        $adminRoleStmt = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin']);
        $adminRole = $adminRoleStmt->fetch();
        $adminRoleId = $adminRole['id'] ?? 1;

        $userRoleStmt = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'user']);
        $userRole = $userRoleStmt->fetch();
        $userRoleId = $userRole['id'] ?? 2;

        // Get OU permission IDs (resource:action notation; see issue #55)
        $ouPermissions = ['ous:read', 'ous:create', 'ous:update', 'ous:delete', 'ous:assign'];

        foreach ($ouPermissions as $name) {
            $stmt = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name]);
            $permission = $stmt->fetch();
            if ($permission) {
                $permId = $permission['id'];
                // Remove from admin role
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $adminRoleId, ':permission_id' => $permId]
                );
                // Remove from user role
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $userRoleId, ':permission_id' => $permId]
                );
            }
        }
    }
}
