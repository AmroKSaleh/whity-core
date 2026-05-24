<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add Two-Factor Authentication Support
 *
 * Adds database schema for 2FA:
 * - Columns to users table: two_factor_secret, two_factor_enabled, two_factor_backup_codes_version
 * - New backup_codes table for storing hashed backup codes
 * - Indexes for efficient lookups by user_id, version, and used status
 */
class AddTwoFactorSupport
{
    public static function up(Database $db): void
    {
        // Add 2FA columns to users table
        $db->exec('
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS two_factor_secret VARCHAR(255),
            ADD COLUMN IF NOT EXISTS two_factor_enabled BOOLEAN DEFAULT false,
            ADD COLUMN IF NOT EXISTS two_factor_backup_codes_version INTEGER DEFAULT 0
        ');

        // Create backup_codes table for storing hashed backup codes
        $db->exec('
            CREATE TABLE IF NOT EXISTS backup_codes (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                code VARCHAR(60) NOT NULL,
                used BOOLEAN DEFAULT false,
                used_at TIMESTAMP NULL,
                version INTEGER,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Create indexes for efficient queries
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id ON backup_codes(user_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id_version ON backup_codes(user_id, version)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id_used ON backup_codes(user_id, used)');
    }

    public static function down(Database $db): void
    {
        // Drop backup_codes table
        $db->exec('DROP TABLE IF EXISTS backup_codes CASCADE');

        // Remove 2FA columns from users table
        $db->exec('
            ALTER TABLE users
            DROP COLUMN IF EXISTS two_factor_secret,
            DROP COLUMN IF EXISTS two_factor_enabled,
            DROP COLUMN IF EXISTS two_factor_backup_codes_version
        ');
    }
}
