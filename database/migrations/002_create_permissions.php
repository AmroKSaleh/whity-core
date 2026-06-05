<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePermissions migration
 *
 * Creates the permissions catalogue and the role_permissions junction in their
 * FINAL form, including the ON DELETE CASCADE foreign keys that were previously
 * applied by a later constraint-rewrite patch. Removing a role or a permission
 * therefore cleans up the corresponding grants automatically.
 *
 * Permission names use the mandated `resource:action` (colon) notation so a
 * fresh database matches the RBAC registry (see CorePermissions). The broader
 * CorePermissions catalogue (e.g. `*:write`, `roles:manage`, `permissions:read`,
 * `plugins:manage`) is reconciled — and `plugins:manage` granted to admin — by
 * the final 012_grant_plugins_manage_to_admin migration, which runs after the OU
 * permissions are seeded so existing human-readable descriptions win.
 */
class CreatePermissions
{
    public static function up(Database $db): void
    {
        // Permissions catalogue.
        $db->exec('
            CREATE TABLE IF NOT EXISTS permissions (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // role_permissions junction — final form with ON DELETE CASCADE on both
        // foreign keys so a deleted role or permission takes its grants with it.
        $db->exec('
            CREATE TABLE IF NOT EXISTS role_permissions (
                id SERIAL PRIMARY KEY,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(role_id, permission_id)
            )
        ');

        // Indexes.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_role_permissions_role_id ON role_permissions(role_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_role_permissions_permission_id ON role_permissions(permission_id)');

        // Seed core permissions in `resource:action` (colon) notation, with
        // human-readable descriptions. Idempotent via ON CONFLICT (name).
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
