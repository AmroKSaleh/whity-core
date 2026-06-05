<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateOrganizationalUnits migration
 *
 * Creates organizational_units table for hierarchical tenant organization.
 * Supports parent-child relationships via self-referencing foreign key.
 *
 * IMPORTANT: The parent_id foreign key does NOT enforce tenant_id consistency.
 * API layer MUST validate that parent.tenant_id == child.tenant_id to prevent
 * cross-tenant hierarchy injection attacks.
 *
 * IMPORTANT: The database allows a node to be its own parent (cyclic reference).
 * API layer MUST implement cycle detection before allowing parent updates.
 */
class CreateOrganizationalUnits
{
    public static function up(Database $db): void
    {
        // Create organizational_units table
        $db->exec('
            CREATE TABLE IF NOT EXISTS organizational_units (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                parent_id INTEGER REFERENCES organizational_units(id) ON DELETE SET NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                description TEXT DEFAULT \'\',
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(tenant_id, name),
                UNIQUE(tenant_id, slug)
            )
        ');

        // Create indexes for performance
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_tenant_id ON organizational_units(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_ou_parent_id ON organizational_units(parent_id)');

        // Insert OU management permissions (idempotent with ON CONFLICT) using
        // the `resource:action` notation mandated by the RBAC model (issue #55).
        $permissions = [
            'ous:read' => 'Read organizational units',
            'ous:create' => 'Create organizational units',
            'ous:update' => 'Update organizational units',
            'ous:delete' => 'Delete organizational units',
            'ous:assign' => 'Assign roles to organizational units',
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
        $db->exec('DROP TABLE IF EXISTS organizational_units CASCADE');
    }
}
