<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateProfileEmails migration (WC-99 — Phase B, migration 029).
 *
 * Creates `profile_emails` — the globally-unique email address registry that
 * links one or more verified email addresses to a `profiles` row (ADR 0005).
 *
 * Design notes
 * ------------
 *  - UNIQUE(email): a given email address may belong to at most one profile,
 *    globally across ALL tenants. This is the structural fix for issue #181:
 *    login-by-email is now unambiguous because no two profiles can share an
 *    email, so the query `SELECT profile_id FROM profile_emails WHERE email = ?`
 *    returns exactly zero or one row.
 *  - profile_id FK: ON DELETE CASCADE — removing a profile removes all its
 *    associated email addresses. profile_emails never outlives its profile.
 *  - No `tenant_id` column: this table is NOT tenant-scoped. It joins only to
 *    the global `profiles` table and is therefore enumerated in
 *    SanctionedGlobalTables so the tenant-predicate guard treats it as exempt.
 *  - verified: FALSE until a verification token round-trip confirms the address.
 *  - is_primary: at most one row per profile should have is_primary=TRUE; the
 *    repository's setPrimary() manages this atomically (unset old, set new).
 *  - Indexes: idx_profile_emails_profile_id backs findByProfileId() and
 *    countForProfile(); idx_profile_emails_email backs the login lookup and is
 *    NOT redundant with the UNIQUE constraint — the UNIQUE index serves
 *    constraint enforcement, the explicit index name makes monitoring/EXPLAIN
 *    output readable and matches the index name referenced in ADR 0005.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateProfileEmails
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS profile_emails (
                id         SERIAL PRIMARY KEY,
                profile_id INTEGER       NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                email      VARCHAR(255)  NOT NULL,
                verified   BOOLEAN       NOT NULL DEFAULT FALSE,
                is_primary BOOLEAN       NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (email)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_profile_emails_profile_id ON profile_emails(profile_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_profile_emails_email      ON profile_emails(email)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS profile_emails CASCADE');
    }
}
