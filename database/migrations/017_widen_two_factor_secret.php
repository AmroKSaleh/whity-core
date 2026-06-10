<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * WidenTwoFactorSecret migration (WC-158)
 *
 * Widens `users.two_factor_secret` from VARCHAR(255) to TEXT. The authenticated-
 * encryption scheme adopted in WC-158 (defuse/php-encryption, AES-256-CTR + HMAC)
 * produces a longer ciphertext token than the legacy unauthenticated AES-256-CBC
 * base64 blob, overflowing the 255-character column on PostgreSQL (SQLSTATE 22001).
 *
 * PostgreSQL is the only engine affected: SQLite does not enforce VARCHAR length,
 * so on SQLite (used by the data-layer test suite) this migration is a no-op.
 *
 * Additive and reversible. `down()` restores VARCHAR(255) — the literal inverse,
 * safe only when no stored value exceeds 255 characters (a fresh or legacy-format
 * schema); it is intended for the migration-cycle rollback, not for reverting a
 * database that already holds new-format secrets.
 */
class WidenTwoFactorSecret
{
    public static function up(Database $db): void
    {
        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            // SQLite and other engines do not enforce VARCHAR length — nothing to widen.
            return;
        }

        $db->exec('ALTER TABLE users ALTER COLUMN two_factor_secret TYPE TEXT');
    }

    public static function down(Database $db): void
    {
        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }

        $db->exec('ALTER TABLE users ALTER COLUMN two_factor_secret TYPE VARCHAR(255)');
    }
}
