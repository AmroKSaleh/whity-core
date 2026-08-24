<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * AddServiceAuthMethod — forward migration (#928, migration 106).
 *
 * Widens migration 104's `profiles_auth_method_check` to admit a fourth value,
 * `'service'`, for principals that exist so authorization has something real to
 * check against and that NOTHING may ever authenticate as.
 *
 * WHY A FOURTH VALUE RATHER THAN A STRANDED 'local'
 * -------------------------------------------------
 * Migration 104 folds "no credential and no external identity" into `'local'`,
 * on the reasoning that it reads as "no external authority governs this
 * account" and so an administrator is free to give it a password. That is the
 * right answer for a stranded human account and the wrong one here: the CLI
 * service principal holds `admin` in the SYSTEM tenant, so an administrator
 * giving it a password creates a deployment-wide login out of an internal
 * fixture. The distinction being recorded is not "which credentials exist" but
 * "may this ever become a login at all", and that is a different fact, so it
 * gets its own value rather than being inferred from an empty `password_hash`.
 *
 * This is the same argument 104 made for holding `auth_method` at all: an
 * authority-relevant fact inferred from an adjacent value is the defect; the
 * cure is to hold the fact.
 *
 * `'service'` is TERMINAL. {@see \Whity\Core\Identity\AuthMethod} refuses every
 * transition out of it — including the `$override` path that legitimately moves
 * an IdP-backed account to `'both'`, and including the link/unlink recomputation
 * that would otherwise walk a service principal to `'idp'` and then to `'both'`.
 *
 * Structural only: the principal itself is seeded by migration 107, keeping the
 * schema change and the data change separable.
 */
final class AddServiceAuthMethod
{
    private const CHECK_CONSTRAINT = 'profiles_auth_method_check';

    /** The values the column may hold once this migration has run. */
    private const PERMITTED = ['local', 'idp', 'both', 'service'];

    public static function up(Database $db): void
    {
        if (!self::isPostgres($db)) {
            // SQLite cannot take the CHECK at all (104 does not add one there),
            // so widening it is a no-op rather than a skipped step.
            return;
        }

        $quoted = "'" . implode("', '", self::PERMITTED) . "'";

        // ADD CONSTRAINT has no IF NOT EXISTS, so drop-then-add keeps this
        // re-runnable — the same shape migration 104 uses.
        $db->exec('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS ' . self::CHECK_CONSTRAINT);
        $db->exec(
            'ALTER TABLE profiles ADD CONSTRAINT ' . self::CHECK_CONSTRAINT
            . ' CHECK (auth_method IN (' . $quoted . '))'
        );
    }

    /**
     * Narrow the constraint back to migration 104's three values.
     *
     * Any `'service'` row is moved to `'idp'` first, NOT to `'local'`. `'idp'`
     * is 104's refusing state, so a rolled-back deployment keeps refusing to
     * give the principal a password; rolling back to `'local'` would hand an
     * administrator exactly the login this migration exists to prevent. Reversal
     * should not be the moment a security property quietly inverts.
     */
    public static function down(Database $db): void
    {
        if (!self::isPostgres($db)) {
            return;
        }

        $db->exec("UPDATE profiles SET auth_method = 'idp' WHERE auth_method = 'service'");

        $db->exec('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS ' . self::CHECK_CONSTRAINT);
        $db->exec(
            'ALTER TABLE profiles ADD CONSTRAINT ' . self::CHECK_CONSTRAINT
            . " CHECK (auth_method IN ('local', 'idp', 'both'))"
        );
    }

    private static function isPostgres(Database $db): bool
    {
        return $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
