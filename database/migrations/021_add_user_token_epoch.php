<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add per-user token epoch (WC-185 — access-token revocation + session invalidation).
 *
 * Adds a single additive column to the users table:
 *   - token_epoch INTEGER NOT NULL DEFAULT 0
 *
 * Every access and refresh token minted for a user carries that user's CURRENT
 * `token_epoch` as a claim. Token validation rejects any token whose embedded
 * epoch is LESS than the user's stored epoch, so bumping a user's epoch (on a
 * password change) instantly invalidates ALL of that user's previously-issued
 * tokens across every device — closing the window where a stolen/old access
 * token stayed valid until expiry.
 *
 * Backward compatible by construction: the column defaults to 0, and the
 * validator treats a MISSING `token_epoch` claim (pre-migration tokens) as 0, so
 * existing sessions map to the default user epoch and are not needlessly broken.
 *
 * This is kept as its own additive migration (rather than folded into the users
 * CREATE in 001) because the consolidated create migrations are a fixed,
 * already-shipped baseline; new structural surface is added forward, like the
 * 2FA columns in 007. The up() is idempotent (ADD COLUMN IF NOT EXISTS) so the
 * postgres-integration CI job can run `migrate run` twice, and down() reverses
 * exactly this migration (DROP COLUMN IF EXISTS).
 */
class AddUserTokenEpoch
{
    public static function up(Database $db): void
    {
        // Additive, idempotent column. NOT NULL DEFAULT 0 means every existing
        // row is backfilled to epoch 0 in a single statement, matching the
        // validator's missing-claim=0 convention.
        $db->exec('
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS token_epoch INTEGER NOT NULL DEFAULT 0
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('
            ALTER TABLE users
            DROP COLUMN IF EXISTS token_epoch
        ');
    }
}
