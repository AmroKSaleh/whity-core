<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add Two-Factor Authentication Support
 *
 * Adds the database schema for 2FA:
 *  - Columns on the users table: two_factor_secret, two_factor_enabled,
 *    two_factor_backup_codes_version.
 *  - A new backup_codes table for storing hashed backup codes.
 *  - Indexes for efficient lookups by user_id, version and used status.
 *
 * This is kept as its own migration (rather than folded into the users CREATE)
 * for two reasons: it introduces an entirely new table (backup_codes), and the
 * users columns must be appended AFTER users.ou_id (added by 006) so the final
 * users column order matches a fresh build exactly.
 */
class AddTwoFactorSupport
{
    public static function up(Database $db): void
    {
        // Add 2FA columns to the users table.
        $db->exec('
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS two_factor_secret VARCHAR(255),
            ADD COLUMN IF NOT EXISTS two_factor_enabled BOOLEAN DEFAULT false,
            ADD COLUMN IF NOT EXISTS two_factor_backup_codes_version INTEGER DEFAULT 0
        ');

        // Create backup_codes table for storing hashed backup codes.
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

        // Indexes for efficient queries.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id ON backup_codes(user_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id_version ON backup_codes(user_id, version)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id_used ON backup_codes(user_id, used)');
    }

    public static function down(Database $db): void
    {
        // Drop backup_codes table.
        $db->exec('DROP TABLE IF EXISTS backup_codes CASCADE');

        // Remove 2FA columns from the users table.
        $db->exec('
            ALTER TABLE users
            DROP COLUMN IF EXISTS two_factor_secret,
            DROP COLUMN IF EXISTS two_factor_enabled,
            DROP COLUMN IF EXISTS two_factor_backup_codes_version
        ');
    }
}
