<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateProfiles migration (WC-96 — Phase B, migration 028).
 *
 * Creates `profiles` — the global identity anchor for Phase B of the
 * identity/membership model (ADR 0005). A profile holds a person's credentials
 * and 2FA state once, regardless of how many tenants they belong to.
 *
 * Design notes
 * ------------
 *  - No `tenant_id` column: profiles are NOT tenant-scoped. This table is
 *    therefore enumerated in SanctionedGlobalTables so the tenant-predicate
 *    guard treats it as intentionally exempt.
 *  - `password_hash`: bcrypt or Argon2id digest. The raw password never touches
 *    this table. NOT NULL enforces that every profile has a credential on create.
 *  - `two_factor_secret`: nullable; set only after TOTP setup is confirmed.
 *  - `two_factor_backup_codes_version`: monotonically-increasing counter that
 *    invalidates the current backup-code set when incremented (WC-95 pattern,
 *    adapted from `backup_codes` table which currently FKs to `users.user_id`
 *    and will re-FK to `profiles.id` once `users` is dropped in Phase B).
 *  - `token_epoch`: integer epoch; rotating it invalidates ALL tokens for this
 *    profile across ALL of their tenant memberships simultaneously (WC-185).
 *  - `display_name`: defaults to '' (empty string); populated by the login /
 *    profile-setup flow, not at account creation time.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateProfiles
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS profiles (
                id            SERIAL PRIMARY KEY,
                display_name  VARCHAR(255) NOT NULL DEFAULT '',
                password_hash VARCHAR(255) NOT NULL,
                two_factor_enabled              BOOLEAN NOT NULL DEFAULT FALSE,
                two_factor_secret               VARCHAR(512),
                two_factor_backup_codes_version INTEGER NOT NULL DEFAULT 0,
                token_epoch   INTEGER   NOT NULL DEFAULT 0,
                created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS profiles CASCADE');
    }
}
