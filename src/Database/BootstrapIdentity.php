<?php

declare(strict_types=1);

namespace Whity\Database;

use PDO;

/**
 * Resolves the address of the bootstrap (first) administrator, and reconciles
 * an existing install with it.
 *
 * Why this exists (WC-779)
 * ────────────────────────
 * Migrations 010 and 036 hardcoded `system@whity.local`. Because the account
 * arrives with the SCHEMA rather than from a seeder, every install — production
 * ones included — got a live administrator credential at a vendor-named
 * `.local` address that an operator could not change without editing core. The
 * address is unroutable, so it can never receive a password reset or a
 * verification mail, and it names the vendor on an install fronted by a real
 * organisation.
 *
 * {@see INITIAL_SYSTEM_ADMIN_EMAIL} now names that address. It DEFAULTS to the
 * historical value, so an install that never sets it is bit-for-bit unaffected.
 *
 * How the rename reaches an install
 * ─────────────────────────────────
 * Migrations 010/036 have already run everywhere; editing them would change
 * nothing for any existing database (they will never re-execute) while risking
 * a mismatch against the fingerprinted schema. So the rename is a SEPARATE,
 * idempotent reconciliation — {@see applyConfiguredEmail()} — invoked from
 * migration 095 (so `migrate run` alone honours the variable, which matters
 * because `migrate run` alone is what produces the bootstrap account) and again
 * from {@see Seeder::seed()} (so an operator who decides to rename LATER can
 * set the variable and re-seed rather than being told to edit rows by hand).
 *
 * Scope, deliberately narrow: this reconciler only ever moves the account off
 * the HISTORICAL default. It is a bootstrap-time convenience, not an identity
 * management API — renaming an administrator that has already been renamed is
 * `PATCH /api/users/{id}`'s job, and re-pointing the variable a second time is
 * therefore a no-op by construction rather than a surprise UPDATE.
 */
final class BootstrapIdentity
{
    /**
     * The address migrations 010/036 hardcoded, and the default when
     * INITIAL_SYSTEM_ADMIN_EMAIL is unset. Every existing install is at this
     * address, so it is also the only address {@see applyConfiguredEmail()}
     * will ever move an account AWAY from.
     */
    public const DEFAULT_EMAIL = 'system@whity.local';

    /** Environment variable naming the bootstrap administrator's address. */
    public const EMAIL_ENV_VAR = 'INITIAL_SYSTEM_ADMIN_EMAIL';

    /**
     * The resolved bootstrap administrator address, normalised the same way
     * every other email in the identity layer is (trimmed, lowercased).
     *
     * A syntactically invalid value falls back to the default rather than
     * failing the migration: a typo in one environment variable must not brick
     * `migrate run` on an upgrade. It is announced loudly instead, because a
     * silently ignored value is exactly the failure mode this whole change
     * exists to remove.
     */
    public static function email(): string
    {
        $configured = $_ENV[self::EMAIL_ENV_VAR] ?? getenv(self::EMAIL_ENV_VAR);
        if (!is_string($configured)) {
            return self::DEFAULT_EMAIL;
        }

        $normalised = strtolower(trim($configured));
        if ($normalised === '') {
            return self::DEFAULT_EMAIL;
        }

        if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            self::announce(sprintf(
                '%s is not a valid email address (%s); falling back to %s.',
                self::EMAIL_ENV_VAR,
                $normalised,
                self::DEFAULT_EMAIL
            ));

            return self::DEFAULT_EMAIL;
        }

        return $normalised;
    }

    /**
     * Move the bootstrap administrator from the historical default address to
     * the configured one, if and only if that is unambiguously safe.
     *
     * Idempotent and safe to call on every migration run and every seed. It
     * does nothing at all when: the variable is unset (or names the default);
     * the default address is no longer present (already renamed, or an install
     * that never had it); or the configured address is already taken by some
     * other identity — colliding two accounts to honour an environment
     * variable would be a far worse outcome than leaving the rename undone, so
     * that case is reported and skipped.
     *
     * @return string The address the bootstrap administrator is at afterwards.
     */
    public static function applyConfiguredEmail(Database $db): string
    {
        $configured = self::email();
        if ($configured === self::DEFAULT_EMAIL) {
            return self::DEFAULT_EMAIL;
        }

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $current = $db->query(
            'SELECT profile_id FROM profile_emails WHERE email = :email',
            [':email' => self::DEFAULT_EMAIL]
        )->fetch(PDO::FETCH_ASSOC);

        if ($current === false) {
            // Nothing sits at the historical default: either this migration
            // already moved it (and $configured is exactly where it went), or
            // the account has not been created yet — in which case $configured
            // is where the caller should create it.
            return $configured;
        }

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $collision = $db->query(
            'SELECT profile_id FROM profile_emails WHERE email = :email',
            [':email' => $configured]
        )->fetch(PDO::FETCH_ASSOC);

        if ($collision !== false) {
            self::announce(sprintf(
                '%s names %s, which already belongs to another account; the bootstrap administrator stays at %s.',
                self::EMAIL_ENV_VAR,
                $configured,
                self::DEFAULT_EMAIL
            ));

            return self::DEFAULT_EMAIL;
        }

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $db->query(
            'UPDATE profile_emails SET email = :new WHERE email = :old',
            [':new' => $configured, ':old' => self::DEFAULT_EMAIL]
        );

        return $configured;
    }

    /**
     * Undo {@see applyConfiguredEmail()} — the reversal half of migration 095.
     *
     * Moves the bootstrap administrator back to the historical default, and
     * only when that address is free, so rolling the migration back can never
     * collide with an account that legitimately holds `system@whity.local`.
     */
    public static function revertConfiguredEmail(Database $db): void
    {
        $configured = self::email();
        if ($configured === self::DEFAULT_EMAIL) {
            return;
        }

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $current = $db->query(
            'SELECT profile_id FROM profile_emails WHERE email = :email',
            [':email' => $configured]
        )->fetch(PDO::FETCH_ASSOC);

        if ($current === false) {
            return;
        }

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $collision = $db->query(
            'SELECT profile_id FROM profile_emails WHERE email = :email',
            [':email' => self::DEFAULT_EMAIL]
        )->fetch(PDO::FETCH_ASSOC);

        if ($collision !== false) {
            return;
        }

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $db->query(
            'UPDATE profile_emails SET email = :new WHERE email = :old',
            [':new' => self::DEFAULT_EMAIL, ':old' => $configured]
        );
    }

    /**
     * Report to the operator running the migration or seed.
     *
     * stdout so an interactive `migrate run` / `seed` shows it inline, and
     * error_log so it also reaches the container's stderr — the same two
     * channels {@see InitialPassword} announces generated passwords on.
     */
    private static function announce(string $message): void
    {
        $line = '[whity] ' . $message;

        echo $line . "\n";
        error_log($line);
    }
}
