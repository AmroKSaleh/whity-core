<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDeploymentTables migration
 *
 * Creates the deployments and migration_rollbacks tracking tables in their
 * FINAL form. Both carry an ON DELETE CASCADE tenant_id foreign key (previously
 * applied by a later constraint-rewrite patch) so removing a tenant cleans up
 * its deployment and rollback history.
 */
class CreateDeploymentTables
{
    public static function up(Database $db): void
    {
        // Create deployments table — tenant_id cascades with its tenant.
        $db->exec('
            CREATE TABLE IF NOT EXISTS deployments (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                status VARCHAR(50) NOT NULL,
                current_version VARCHAR(50) NOT NULL,
                previous_version VARCHAR(50),
                applied_at TIMESTAMP,
                rolled_back_at TIMESTAMP,
                UNIQUE(tenant_id, current_version)
            )
        ');

        // Create migration_rollbacks table — tenant_id cascades with its tenant.
        $db->exec('
            CREATE TABLE IF NOT EXISTS migration_rollbacks (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
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
