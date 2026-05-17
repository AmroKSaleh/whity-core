<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddDescriptionToRoles migration
 *
 * Adds description column to roles table.
 */
class AddDescriptionToRoles
{
    public static function up(Database $db): void
    {
        // Check if description column already exists
        $result = $db->query(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.columns 
                WHERE table_name='roles' AND column_name='description'
            )"
        );

        if (!$result->fetch(\PDO::FETCH_COLUMN)) {
            // Add description column
            $db->exec('ALTER TABLE roles ADD COLUMN description TEXT DEFAULT \'\'');
        }
    }

    public static function down(Database $db): void
    {
        // This is a data-affecting migration, so we won't drop the column
    }
}
