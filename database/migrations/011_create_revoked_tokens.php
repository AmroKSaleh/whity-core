<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateRevokedTokens migration
 *
 * Creates a table for tracking revoked JWT tokens.
 * This is used to implement JWT revocation by storing JTIs (JSON Token IDs)
 * of tokens that have been explicitly revoked before expiration.
 */
class CreateRevokedTokens
{
    public static function up(Database $db): void
    {
        // Create revoked_tokens table
        $db->exec('
            CREATE TABLE IF NOT EXISTS revoked_tokens (
                id BIGSERIAL PRIMARY KEY,
                jti VARCHAR(255) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // Create index on jti for fast lookups during token validation
        $db->exec('CREATE INDEX IF NOT EXISTS idx_revoked_tokens_jti ON revoked_tokens(jti)');

        // Create index on expires_at for cleanup queries
        $db->exec('CREATE INDEX IF NOT EXISTS idx_revoked_tokens_expires_at ON revoked_tokens(expires_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS revoked_tokens CASCADE');
    }
}
