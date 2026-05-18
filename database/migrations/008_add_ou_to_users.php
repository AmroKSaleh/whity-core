<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddOuToUsers migration
 *
 * Adds ou_id column to users table for organizational unit assignment.
 * Users with NULL ou_id are considered "root" organization unit members.
 */
class AddOuToUsers
{
    public static function up(Database $db): void
    {
        // Add ou_id column if it doesn't exist
        $db->exec('
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS ou_id INTEGER REFERENCES organizational_units(id) ON DELETE SET NULL
        ');

        // Create index for performance
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_ou_id ON users(ou_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_users_ou_id');
        $db->exec('ALTER TABLE users DROP COLUMN IF EXISTS ou_id');
    }
}
