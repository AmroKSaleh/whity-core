<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * AddAuthMethodToProfiles (#916) — makes "which authority holds this account's
 * credentials" a fact the platform STORES instead of one it guesses.
 *
 * WHY THIS EXISTS
 * ---------------
 * Migration 028 declared `password_hash VARCHAR(255) NOT NULL`. A profile that
 * signs in only through an identity provider therefore stores the EMPTY STRING
 * — {@see \Whity\Core\Identity\FederatedIdentityLinker} writes `''` on the
 * just-in-time provisioning path — and "this account has no local password" and
 * "this account has an empty local password" became the same row. Neither state
 * is distinguishable through the API, because the column is populated for every
 * account.
 *
 * Nothing on any password-write path consulted `external_identities`, so
 * `PATCH /api/users/{id}` with a `password` against an IdP-backed profile
 * answered 200 and minted a working local credential. That credential outlives
 * the IdP's deprovisioning of the account, is not subject to the IdP's MFA
 * policy, appears in no SSO audit trail, and is invisible to anyone reviewing
 * the account afterwards. It could be created by accident — and was.
 *
 * This is the same shape as #895: an authority-relevant fact inferred from an
 * adjacent value rather than held. The cure is the same — hold the fact.
 *
 * THE VOCABULARY
 * --------------
 * `auth_method` summarises two independent booleans — does a local credential
 * exist, and does an external identity — as one of three values:
 *
 *   'local'  the platform holds the credential; no external identity is linked
 *   'idp'    an external identity is linked and there is NO local credential
 *   'both'   an external identity is linked AND a local credential exists
 *
 * The fourth combination (no credential, no identity) is a stranded account
 * that can sign in by no means at all; it folds into 'local', which reads as
 * "no external authority governs this account" and leaves an administrator free
 * to give it a password without an override. That is the correct outcome: once
 * the last IdP link is gone there is no external authority left to contradict.
 *
 * Only 'idp' refuses a local-password write. Changing the password of a 'both'
 * account changes a credential that already exists; the defect was the SILENT
 * CREATION of a second authentication method, not its continued existence.
 * {@see \Whity\Core\Identity\AuthMethod} owns every transition between these
 * values and is the only writer of `profiles.password_hash` in the codebase.
 *
 * WHY NOT MAKE `password_hash` NULLABLE INSTEAD
 * ---------------------------------------------
 * That was the reporter's third suggestion and it is deliberately NOT taken.
 * Nullability would make absence representable a SECOND time, in a second
 * place, and the two representations can disagree: a row with
 * `auth_method = 'idp'` and a non-null hash, or `'local'` with a null one, is a
 * contradiction the schema would then permit and something would eventually
 * have to arbitrate. One held fact answers the question completely, so a second
 * one adds a consistency burden and no information. `password_hash` therefore
 * stays `NOT NULL`, and `''` keeps meaning exactly what it has always meant at
 * the storage layer — no verifiable credential — while `auth_method` is what
 * anything ABOVE the storage layer is required to consult.
 *
 * THE BACKFILL
 * ------------
 * Existing rows are classified from the two signals that were previously being
 * inferred at each call site, once, here, where doing so is legitimate: whether
 * the profile owns any `external_identities` row (migration 047) and whether
 * its `password_hash` is non-empty. Every row is therefore stamped with the
 * value the old inference would have produced, so no account changes behaviour
 * on the day this lands — except that the IdP-only ones can no longer be given
 * a local password by accident.
 *
 * The correlated `EXISTS` runs on both engines. It is written against
 * `profiles.id` rather than an alias because SQLite does not accept an alias on
 * an UPDATE target.
 *
 * ENGINE NOTES
 * ------------
 * PostgreSQL additionally gets a CHECK constraint pinning the vocabulary, so a
 * value outside the three is rejected by the database and not merely by the
 * class above it. SQLite — the test-schema engine, see
 * {@see \Tests\Support\SchemaFromMigrations} — has no `ALTER TABLE … ADD
 * CONSTRAINT`, and the only way to attach one is to rebuild the whole table.
 * `profiles` is the FK target of a dozen others, so rebuilding it to gain a
 * constraint that only ever guards a test database is a poor trade; the PHP
 * writer is the guarantee there, and a unit test asserts the vocabulary
 * directly. This asymmetry is why the constraint is added in a driver branch
 * rather than inline in the ADD COLUMN.
 *
 * `profiles` is a sanctioned GLOBAL table (ADR 0005 §1) — this column carries no
 * tenant_id and needs none. Idempotent (IF NOT EXISTS) and fully reversible.
 */
class AddAuthMethodToProfiles
{
    /** PostgreSQL's name for the vocabulary constraint. */
    private const CHECK_CONSTRAINT = 'profiles_auth_method_check';

    public static function up(Database $db): void
    {
        $db->exec(
            "ALTER TABLE profiles ADD COLUMN IF NOT EXISTS auth_method VARCHAR(32) NOT NULL DEFAULT 'local'"
        );

        // Classify every pre-existing row from the signals the call sites used
        // to infer. Runs unconditionally: it is idempotent (the CASE is a total
        // function of the current state) and a re-run after a partial upgrade
        // must not leave rows on the DEFAULT.
        $db->exec(
            "UPDATE profiles
                SET auth_method = CASE
                    WHEN EXISTS (
                        SELECT 1 FROM external_identities ei WHERE ei.profile_id = profiles.id
                    ) THEN CASE WHEN password_hash <> '' THEN 'both' ELSE 'idp' END
                    ELSE 'local'
                END"
        );

        if (self::isPostgres($db)) {
            // ADD CONSTRAINT has no IF NOT EXISTS, so drop-then-add keeps the
            // migration re-runnable.
            $db->exec('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS ' . self::CHECK_CONSTRAINT);
            $db->exec(
                'ALTER TABLE profiles ADD CONSTRAINT ' . self::CHECK_CONSTRAINT
                . " CHECK (auth_method IN ('local', 'idp', 'both'))"
            );
        }

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_profiles_auth_method ON profiles (auth_method)'
        );
    }

    /**
     * Drop the column. Reversing loses the held fact and returns the deployment
     * to inferring it from `password_hash`, which is the defect — but a
     * migration that cannot be rolled back is worse than one that can, and
     * nothing is destroyed that {@see up()} cannot reconstruct: the backfill is
     * derived entirely from data this migration never touches.
     */
    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_profiles_auth_method');

        if (self::isPostgres($db)) {
            $db->exec('ALTER TABLE profiles DROP CONSTRAINT IF EXISTS ' . self::CHECK_CONSTRAINT);
        }

        $db->exec('ALTER TABLE profiles DROP COLUMN IF EXISTS auth_method');
    }

    private static function isPostgres(Database $db): bool
    {
        return (string) $db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
}
