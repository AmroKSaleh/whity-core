<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateCoreSchemaMigrations migration
 *
 * Creates a separate migration tracking table for core engine updates.
 * This prevents collision errors when plugins use standard migration naming.
 */
class CreateCoreSchemaMigrations
{
    public static function up(Database $db): void
    {
        // Create core_schema_migrations table
        $db->exec('
            CREATE TABLE IF NOT EXISTS core_schema_migrations (
                id SERIAL PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                execution_time_ms INTEGER
            )
        ');

        // Create index on migration_name for fast lookups
        $db->exec('CREATE INDEX IF NOT EXISTS idx_core_migrations_name ON core_schema_migrations(migration_name)');
    }

    public static function down(Database $db): void
    {
        // Intentionally a no-op.
        //
        // The core_schema_migrations table is migration-tracking infrastructure
        // that the migration runner itself depends on for the entire rollback
        // sequence. Dropping it here would remove the tracking rows of every
        // migration ordered before this one (001-004), leaving them impossible
        // to roll back. The runner provisions this table independently, so its
        // lifecycle is not tied to this migration's reversal.
    }
}
