<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePermissions migration
 *
 * Creates permissions and role_permissions tables for RBAC.
 */
class CreatePermissions
{
    public static function up(Database $db): void
    {
        // Create permissions table
        $db->exec('
            CREATE TABLE IF NOT EXISTS permissions (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Create role_permissions junction table
        $db->exec('
            CREATE TABLE IF NOT EXISTS role_permissions (
                id SERIAL PRIMARY KEY,
                role_id INTEGER NOT NULL REFERENCES roles(id),
                permission_id INTEGER NOT NULL REFERENCES permissions(id),
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(role_id, permission_id)
            )
        ');

        // Create indexes
        $db->exec('CREATE INDEX IF NOT EXISTS idx_role_permissions_role_id ON role_permissions(role_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_role_permissions_permission_id ON role_permissions(permission_id)');

        // Insert default permissions using the `resource:action` notation
        // mandated by the RBAC permission model (see CorePermissions / issue #55).
        $permissions = [
            'users:read' => 'Read users',
            'users:create' => 'Create users',
            'users:update' => 'Update users',
            'users:delete' => 'Delete users',
            'roles:read' => 'Read roles',
            'roles:create' => 'Create roles',
            'roles:update' => 'Update roles',
            'roles:delete' => 'Delete roles',
            'tenants:read' => 'Read tenants',
            'tenants:create' => 'Create tenants',
            'tenants:update' => 'Update tenants',
            'tenants:delete' => 'Delete tenants',
        ];

        foreach ($permissions as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at) VALUES (:name, :description, NOW()) ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS role_permissions CASCADE');
        $db->exec('DROP TABLE IF EXISTS permissions CASCADE');
    }
}
