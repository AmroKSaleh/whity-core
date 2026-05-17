<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddSlugToTenants migration
 *
 * Adds slug column to tenants table for URL-friendly identifiers.
 */
class AddSlugToTenants
{
    public static function up(Database $db): void
    {
        // Check if slug column already exists
        $result = $db->query(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.columns 
                WHERE table_name='tenants' AND column_name='slug'
            )"
        );

        if (!$result->fetch(\PDO::FETCH_COLUMN)) {
            // Add slug column
            $db->exec('ALTER TABLE tenants ADD COLUMN slug VARCHAR(255) UNIQUE');
        }
    }

    public static function down(Database $db): void
    {
        // This is a data-affecting migration, so we won't drop the column
        // In production, you'd need to handle this carefully
    }
}
