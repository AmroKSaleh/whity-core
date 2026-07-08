<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-235 email-verification: single-use, time-boxed verification tokens.
 *
 * Backs the request/confirm flow for verifying a `profile_emails` address. Each
 * row is one outstanding verification token for one email address:
 *   - token_hash: SHA-256 hex of the raw token (64 chars). The raw token travels
 *     only in the emailed link; NEVER stored. Confirm hashes the presented token
 *     and looks it up here — a DB compromise cannot mint working links.
 *   - expires_at: hard expiry (default issuance TTL 24h); an expired row is dead.
 *   - consumed_at: single-use marker. Set on a successful confirm so the same
 *     link cannot be replayed. NULL means outstanding.
 *
 * GLOBAL (non-tenant-scoped), like the `profile_emails` it points at (ADR 0005):
 * an email address is platform-unique across all tenants, so a tenant_id
 * predicate would be meaningless. Enumerated in SanctionedGlobalTables. The
 * profile_email_id FK is ON DELETE CASCADE, so deleting a profile (or one of its
 * emails) drops any outstanding tokens with it.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateEmailVerifications
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS email_verifications (
                id               BIGSERIAL    NOT NULL PRIMARY KEY,
                profile_email_id INTEGER      NOT NULL REFERENCES profile_emails(id) ON DELETE CASCADE,
                token_hash       VARCHAR(64)  NOT NULL,
                expires_at       TIMESTAMP    NOT NULL,
                consumed_at      TIMESTAMP,
                created_at       TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE(token_hash)
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_email_verifications_profile_email_id
                ON email_verifications (profile_email_id)
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_email_verifications_expires_at
                ON email_verifications (expires_at)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS email_verifications CASCADE');
    }
}
