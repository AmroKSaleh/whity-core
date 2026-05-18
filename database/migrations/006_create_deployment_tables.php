<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDeploymentTables migration
 *
 * Creates tables for tracking deployments and migration rollbacks.
 */
class CreateDeploymentTables
{
    public static function up(Database $db): void
    {
        // Create deployments table
        $db->exec('
            CREATE TABLE IF NOT EXISTS deployments (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id),
                status VARCHAR(50) NOT NULL,
                current_version VARCHAR(50) NOT NULL,
                previous_version VARCHAR(50),
                applied_at TIMESTAMP,
                rolled_back_at TIMESTAMP,
                UNIQUE(tenant_id, current_version)
            )
        ');

        // Create migration_rollbacks table
        $db->exec('
            CREATE TABLE IF NOT EXISTS migration_rollbacks (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id),
                migration_name VARCHAR(255) NOT NULL,
                rolled_back_at TIMESTAMP NOT NULL DEFAULT NOW(),
                reason TEXT
            )
        ');

        // Add indices for performance
        $db->exec('CREATE INDEX IF NOT EXISTS idx_deployments_tenant_id ON deployments(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_migration_rollbacks_tenant_id ON migration_rollbacks(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS migration_rollbacks CASCADE');
        $db->exec('DROP TABLE IF EXISTS deployments CASCADE');
    }
}
