<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateOuRoleAssignments migration
 *
 * Creates ou_role_assignments junction table for assigning roles to organizational units.
 * Each OU can have multiple roles, and role assignments are tenant-scoped.
 */
class CreateOuRoleAssignments
{
    public static function up(Database $db): void
    {
        // Create ou_role_assignments table
        $db->exec('
            CREATE TABLE IF NOT EXISTS ou_role_assignments (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                ou_id INTEGER NOT NULL REFERENCES organizational_units(id) ON DELETE CASCADE,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(ou_id, role_id)
            )
        ');

        // Create indexes for performance
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_role_assignments_tenant_id ON ou_role_assignments(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_role_assignments_ou_id ON ou_role_assignments(ou_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_role_assignments_role_id ON ou_role_assignments(role_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS ou_role_assignments CASCADE');
    }
}
