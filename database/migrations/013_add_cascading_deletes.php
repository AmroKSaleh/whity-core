<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add Cascading Deletes
 *
 * Updates foreign key constraints to use ON DELETE CASCADE for relationships
 * where child entities cannot exist without their parent.
 *
 * This ensures consistent deletion behavior:
 * - Deleting a tenant cascades to delete all its users, roles, permissions, deployments, etc.
 * - Deleting a role cascades to delete role-permission mappings and revokes it from users/OUs.
 * - Deleting permissions cascades to remove from role-permission mappings.
 *
 * Previously, deleting a parent entity with children would fail with a foreign key
 * constraint violation. This migration allows clean deletion while preserving referential integrity.
 */
class AddCascadingDeletes
{
    public static function up(Database $db): void
    {
        $db->exec('
            ALTER TABLE users
            DROP CONSTRAINT IF EXISTS users_tenant_id_fkey,
            DROP CONSTRAINT IF EXISTS users_role_id_fkey,
            ADD CONSTRAINT users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
            ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ');

        $db->exec('
            ALTER TABLE role_permissions
            DROP CONSTRAINT IF EXISTS role_permissions_role_id_fkey,
            DROP CONSTRAINT IF EXISTS role_permissions_permission_id_fkey,
            ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ');

        $db->exec('
            ALTER TABLE deployments
            DROP CONSTRAINT IF EXISTS deployments_tenant_id_fkey,
            ADD CONSTRAINT deployments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        ');

        $db->exec('
            ALTER TABLE deployment_steps
            DROP CONSTRAINT IF EXISTS deployment_steps_tenant_id_fkey,
            ADD CONSTRAINT deployment_steps_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('
            ALTER TABLE users
            DROP CONSTRAINT IF EXISTS users_tenant_id_fkey,
            DROP CONSTRAINT IF EXISTS users_role_id_fkey,
            ADD CONSTRAINT users_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenants(id),
            ADD CONSTRAINT users_role_id_fkey FOREIGN KEY (role_id) REFERENCES roles(id)
        ');

        $db->exec('
            ALTER TABLE role_permissions
            DROP CONSTRAINT IF EXISTS role_permissions_role_id_fkey,
            DROP CONSTRAINT IF EXISTS role_permissions_permission_id_fkey,
            ADD CONSTRAINT role_permissions_role_id_fkey FOREIGN KEY (role_id) REFERENCES roles(id),
            ADD CONSTRAINT role_permissions_permission_id_fkey FOREIGN KEY (permission_id) REFERENCES permissions(id)
        ');

        $db->exec('
            ALTER TABLE deployments
            DROP CONSTRAINT IF EXISTS deployments_tenant_id_fkey,
            ADD CONSTRAINT deployments_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ');

        $db->exec('
            ALTER TABLE deployment_steps
            DROP CONSTRAINT IF EXISTS deployment_steps_tenant_id_fkey,
            ADD CONSTRAINT deployment_steps_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ');
    }
}
