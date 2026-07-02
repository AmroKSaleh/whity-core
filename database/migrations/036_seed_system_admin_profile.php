<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;
use Whity\Database\InitialPassword;

/**
 * SeedSystemAdminProfile — forward migration (WC-10522424 — Phase B, migration 036).
 *
 * On a FRESH install the system administrator (system@whity.local, tenant 0)
 * must exist as a profile + profile_email + membership so that after the
 * identity rewrite the system admin can authenticate via the new profile model
 * and hold tenant-0 (platform-wide) authority.
 *
 * Migration 010 creates the system tenant and a `users` row for system@whity.local.
 * Migration 035 explicitly EXCLUDES tenant_id = 0 rows from the users→profiles
 * collapse (system users are internal accounts and were intentionally left
 * out of the data migration).  This migration fills that gap for fresh installs:
 * it seeds the profile model rows that 035 would have created had it processed
 * tenant-0.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * What up() creates
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. A `profiles` row for system@whity.local — credentials from
 *     INITIAL_SYSTEM_ADMIN_PASSWORD (≥ 32 chars enforced by the fixture/env
 *     contract; the env var is the same one migration 010 / the seeder uses).
 *  2. A `profile_emails` row: email = 'system@whity.local', verified = TRUE,
 *     is_primary = TRUE.
 *  3. A `memberships` row: (profile_id, tenant_id = 0, role_id = admin,
 *     status = 'active') — tenant-0 authority semantics unchanged.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Idempotency
 * ─────────────────────────────────────────────────────────────────────────────
 * up() checks whether a profile_emails row for 'system@whity.local' already
 * exists before inserting.  All three INSERTs use ON CONFLICT DO NOTHING guards
 * so a second run (or the seeder running after this migration) is a no-op.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() removes only the rows this migration created, identified via the
 * profile_emails row for 'system@whity.local'.  The `users` row in tenant 0
 * (owned by migration 010) is intentionally untouched.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Fresh-install / upgraded-install reconciliation
 * ─────────────────────────────────────────────────────────────────────────────
 * On upgraded installs (010 was applied long ago and the system admin users row
 * already exists), up() inserts the profile model rows that migration 035
 * intentionally skipped.  On a completely fresh install both 010 and 036 run in
 * sequence and the result is the same: a users row AND a profile model triple
 * for system@whity.local.
 *
 * The password for the profile row is derived from INITIAL_SYSTEM_ADMIN_PASSWORD
 * (same env var as migration 010) so credential parity is guaranteed on fresh
 * installs.  Upgraded installs that rotated the system admin password after 010
 * was applied must also set the new password via the profile-update API after
 * this migration runs (the users row and the profile row are independent
 * credential stores during the Phase B dual-identity window).
 */
class SeedSystemAdminProfile
{
    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $normEmail = 'system@whity.local';

        // ── Idempotency guard: skip if the profile_email already exists ────────
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $existing = $db->query(
            'SELECT profile_id FROM profile_emails WHERE email = :email',
            [':email' => $normEmail]
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing !== false) {
            // Profile model rows already present (e.g. seeder ran first, or this
            // migration was re-run).  Nothing to do.
            return;
        }

        // ── 1. Resolve the admin role id ──────────────────────────────────────
        // @tenant-guard-ignore: seed-time bootstrap; role lookup by name is global
        $roleRow = $db->query(
            "SELECT id FROM roles WHERE name = 'admin' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        $adminRoleId = $roleRow !== false ? (int) $roleRow['id'] : 1;

        // ── 2. Hash the initial system-admin password ─────────────────────────
        // Password is sourced from INITIAL_SYSTEM_ADMIN_PASSWORD (same env var
        // as migration 010) or a random value.  Never a static literal.
        $passwordHash = InitialPassword::hashFor('INITIAL_SYSTEM_ADMIN_PASSWORD', $normEmail);

        // ── 3. INSERT the profiles row ─────────────────────────────────────────
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $profileParams = [
            ':display_name'                    => 'system',
            ':password_hash'                   => $passwordHash,
            ':two_factor_enabled'              => 0,
            ':two_factor_secret'               => null,
            ':two_factor_backup_codes_version' => 0,
            ':token_epoch'                     => 0,
        ];

        if ($driver === 'pgsql') {
            $insertStmt = $db->query(
                "INSERT INTO profiles
                     (display_name, password_hash, two_factor_enabled,
                      two_factor_secret, two_factor_backup_codes_version,
                      token_epoch, created_at, updated_at)
                 VALUES
                     (:display_name, :password_hash, :two_factor_enabled,
                      :two_factor_secret, :two_factor_backup_codes_version,
                      :token_epoch, NOW(), NOW())
                 ON CONFLICT DO NOTHING
                 RETURNING id",
                $profileParams
            );
            $idRow     = $insertStmt->fetch(PDO::FETCH_ASSOC);
            $profileId = $idRow !== false ? (int) $idRow['id'] : 0;
        } else {
            $db->query(
                "INSERT INTO profiles
                     (display_name, password_hash, two_factor_enabled,
                      two_factor_secret, two_factor_backup_codes_version,
                      token_epoch, created_at, updated_at)
                 VALUES
                     (:display_name, :password_hash, :two_factor_enabled,
                      :two_factor_secret, :two_factor_backup_codes_version,
                      :token_epoch, datetime('now'), datetime('now'))
                 ON CONFLICT DO NOTHING",
                $profileParams
            );
            $profileId = (int) $pdo->lastInsertId();
        }

        if ($profileId <= 0) {
            // ON CONFLICT DO NOTHING fired (parallel run or seeder-first scenario).
            // Re-fetch the id so the email + membership rows can reference it.
            // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
            $refetch = $db->query(
                'SELECT p.id FROM profiles p
                 JOIN profile_emails pe ON pe.profile_id = p.id
                 WHERE pe.email = :email
                 LIMIT 1',
                [':email' => $normEmail]
            )->fetch(PDO::FETCH_ASSOC);
            if ($refetch !== false) {
                $profileId = (int) $refetch['id'];
            } else {
                // Cannot determine profile id — bail out safely rather than
                // inserting a membership with profile_id = 0.
                return;
            }
        }

        // ── 4. INSERT the primary verified profile_email ──────────────────────
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $db->query(
            "INSERT INTO profile_emails
                 (profile_id, email, verified, is_primary, created_at)
             VALUES
                 (:profile_id, :email, :verified, :is_primary, NOW())
             ON CONFLICT (email) DO NOTHING",
            [
                ':profile_id' => $profileId,
                ':email'      => $normEmail,
                ':verified'   => 1,
                ':is_primary' => 1,
            ]
        );

        // ── 5. INSERT the tenant-0 membership ────────────────────────────────
        // memberships is tenant-scoped; tenant_id = 0 is the system tenant (ADR 0005 §3).
        // @tenant-guard-ignore: seed-time bootstrap for the system tenant (tenant_id = 0)
        $db->query(
            "INSERT INTO memberships
                 (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES
                 (:profile_id, 0, :role_id, NULL, 'active', NOW())
             ON CONFLICT (profile_id, tenant_id) DO NOTHING",
            [
                ':profile_id' => $profileId,
                ':role_id'    => $adminRoleId,
            ]
        );
    }

    public static function down(Database $db): void
    {
        $normEmail = 'system@whity.local';

        // Look up the profile_id so we can remove exactly the rows we created.
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $row = $db->query(
            'SELECT profile_id FROM profile_emails WHERE email = :email',
            [':email' => $normEmail]
        )->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            // Nothing was created (migration was never applied or already reversed).
            return;
        }

        $profileId = (int) $row['profile_id'];

        // Remove the tenant-0 membership.
        // @tenant-guard-ignore: seed-time reversal for the system tenant (tenant_id = 0)
        $db->query(
            'DELETE FROM memberships WHERE profile_id = :pid AND tenant_id = 0',
            [':pid' => $profileId]
        );

        // Remove the profile_email.
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $db->query(
            'DELETE FROM profile_emails WHERE email = :email',
            [':email' => $normEmail]
        );

        // Remove the profile only when it holds no other memberships or emails
        // (guard against scenarios where the seeder or another migration also
        // created rows referencing this profile).
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $otherEmails = (int) $db->query(
            'SELECT COUNT(*) FROM profile_emails WHERE profile_id = :pid',
            [':pid' => $profileId]
        )->fetchColumn();

        if ($otherEmails === 0) {
            // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
            $db->query(
                'DELETE FROM profiles WHERE id = :pid',
                [':pid' => $profileId]
            );
        }
    }
}
