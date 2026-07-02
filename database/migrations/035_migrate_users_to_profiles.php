<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * MigrateUsersToProfiles — data migration (WC-2515b697 — Phase B, migration 035).
 *
 * Implements ADR 0005 §8: collapses the existing `users` rows into the
 * `profiles` / `profile_emails` / `memberships` model introduced by migrations
 * 028–030 and creates the audit log for any credential-collision events.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Duplicate-email collapse rule
 * ─────────────────────────────────────────────────────────────────────────────
 * When the same email address appears in the `users` table under multiple
 * `tenant_id` values (the multi-tenant scenario), all rows collapse to ONE
 * profile record keyed on the normalised (lowercased, trimmed) email.  The
 * profile receives the credential columns (password_hash, two_factor_* columns,
 * token_epoch) from the EARLIEST-CREATED user row (lowest `id`).  Each user
 * row produces exactly one membership(profile_id, tenant_id, role_id, ou_id,
 * status='active').  The primary profile_email is created once per unique
 * normalised email (verified=true, is_primary=true).
 *
 * Note: users has UNIQUE(tenant_id, email), so within a single tenant duplicate
 * emails cannot exist.  The memberships ON CONFLICT DO NOTHING guard relies on
 * UNIQUE(profile_id, tenant_id) on the memberships table — that constraint
 * ensures a second up() run cannot silently create duplicate memberships.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Credential-collision audit log
 * ─────────────────────────────────────────────────────────────────────────────
 * An ephemeral `migration_035_collision_log` table is created and populated
 * within up().  Each row records:
 *   email          — the affected normalised email address (no hash, no PII
 *                    beyond what is already in the users table)
 *   kept_user_id   — users.id whose password / 2FA was retained
 *   dropped_ids    — TEXT (comma-separated users.ids) whose credentials were
 *                    discarded
 *
 * Operators MUST review the collision summary printed to STDOUT and notify
 * affected users before deploying.  Snapshot the `users` table before running
 * up(); down() cannot recover discarded credential hashes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Idempotency
 * ─────────────────────────────────────────────────────────────────────────────
 * up() checks for an existing profile via profile_emails before inserting, so
 * running up() twice is safe: no duplicate rows are created.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Atomicity
 * ─────────────────────────────────────────────────────────────────────────────
 * up() wraps the entire migration in a database transaction.  A crash mid-loop
 * rolls back all partial rows, leaving the database in a clean pre-migration
 * state so the operator can retry from scratch.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() removes only the rows this migration created, identified via the
 * migration_035_profile_ids tracking table:
 *   - memberships whose profile_id was created here
 *   - profile_emails for those profiles
 *   - profiles created here
 *   - the collision-log table
 *   - the profile-ids tracking table
 *
 * The `users` table is intentionally left untouched by both up() and down();
 * it remains the live identity anchor during the Phase B transition.  FKs from
 * backup_codes, user_roles, persons, permission_delegations and mcp_tokens to
 * users.id are therefore not re-pointed in this migration.
 */
class MigrateUsersToProfiles
{
    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $pdo->beginTransaction();
        try {
            self::runUp($db, $driver);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function runUp(Database $db, string $driver): void
    {
        // ── 0. Tracking / audit tables ────────────────────────────────────────
        // migration_035_collision_log: audit trail for emails where multiple users
        // shared the same normalised email across different tenants.
        $db->exec("
            CREATE TABLE IF NOT EXISTS migration_035_collision_log (
                id           SERIAL PRIMARY KEY,
                email        VARCHAR(255) NOT NULL UNIQUE,
                kept_user_id INTEGER      NOT NULL,
                dropped_ids  TEXT         NOT NULL,
                created_at   TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // migration_035_profile_ids: maps users.id → profiles.id so down() can
        // remove exactly the rows this migration created.
        $db->exec("
            CREATE TABLE IF NOT EXISTS migration_035_profile_ids (
                user_id    INTEGER NOT NULL,
                profile_id INTEGER NOT NULL,
                PRIMARY KEY (user_id)
            )
        ");

        // ── 1. Fetch all users ordered by id ASC ─────────────────────────────
        // Ordering ASC guarantees the FIRST occurrence for a given normalised
        // email (lowest id) is the canonical/kept row for credential purposes.
        // tenant_id = 0 is the system tenant (a technical artifact seeded by
        // migration 010); system users are excluded — they are internal service
        // accounts and do not participate in the profile/membership model.
        // @tenant-guard-ignore: cross-tenant migration reads all users to collapse email identities
        $stmt = $db->query("
            SELECT
                u.id,
                u.tenant_id,
                LOWER(TRIM(u.email))                   AS norm_email,
                u.email                                AS raw_email,
                u.password,
                u.role_id,
                u.ou_id,
                u.two_factor_enabled,
                u.two_factor_secret,
                u.two_factor_backup_codes_version,
                u.token_epoch,
                u.created_at
            FROM users u
            WHERE u.tenant_id != 0
            ORDER BY u.id ASC
        ");

        /** @var array<string, array{profile_id:int, kept_user_id:int, dropped_ids:list<int>}> */
        $emailIndex = [];   // normEmail → [profile_id, kept_user_id, dropped_ids[]]

        /** @var list<array<string, mixed>> */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($rows as $user) {
            $normEmail = (string) $user['norm_email'];
            $userId    = (int)    $user['id'];

            if (!isset($emailIndex[$normEmail])) {
                // ── First (canonical) occurrence for this email ───────────────
                // Check whether a profile already exists for this email (idempotent
                // re-run: a previous up() may have inserted it).
                // @tenant-guard-ignore: profile_emails is a global (non-tenant-scoped) table
                $existingRow = $db->query(
                    'SELECT profile_id FROM profile_emails WHERE email = :email',
                    [':email' => $normEmail]
                )->fetch(\PDO::FETCH_ASSOC);

                if ($existingRow !== false) {
                    // Profile already exists — record it for membership creation.
                    $profileId = (int) $existingRow['profile_id'];
                } else {
                    // INSERT a new profile row and capture its id.
                    // On PostgreSQL we use RETURNING id (reliable, no sequence
                    // ambiguity); on SQLite we fall back to lastInsertId() which
                    // is the established codebase idiom on that driver.
                    $createdAt = (string) ($user['created_at'] ?? date('Y-m-d H:i:s'));

                    // Normalise the two_factor_enabled value for both drivers.
                    // PostgreSQL returns "t"/"f" strings for BOOLEAN columns;
                    // PHP's (bool) cast on "f" would incorrectly return true.
                    // We therefore canonicalise to a plain int (0/1) which both
                    // drivers accept, casting via the string value from PG.
                    $tfEnabled = (int) ($user['two_factor_enabled'] === 't'
                        || $user['two_factor_enabled'] === true
                        || $user['two_factor_enabled'] === 1
                        || $user['two_factor_enabled'] === '1');

                    $profileParams = [
                        ':display_name'                    => self::localPart((string) $user['raw_email']),
                        ':password_hash'                   => (string) $user['password'],
                        ':two_factor_enabled'              => $tfEnabled,
                        ':two_factor_secret'               => $user['two_factor_secret'] ?? null,
                        ':two_factor_backup_codes_version' => (int) ($user['two_factor_backup_codes_version'] ?? 0),
                        ':token_epoch'                     => (int) ($user['token_epoch'] ?? 0),
                        ':created_at'                      => $createdAt,
                        ':updated_at'                      => $createdAt,
                    ];

                    if ($driver === 'pgsql') {
                        // Use RETURNING id on PostgreSQL — reliable, no sequence ambiguity.
                        $insertStmt = $db->query(
                            "INSERT INTO profiles
                                 (display_name, password_hash, two_factor_enabled,
                                  two_factor_secret, two_factor_backup_codes_version,
                                  token_epoch, created_at, updated_at)
                             VALUES
                                 (:display_name, :password_hash, :two_factor_enabled,
                                  :two_factor_secret, :two_factor_backup_codes_version,
                                  :token_epoch, :created_at, :updated_at)
                             RETURNING id",
                            $profileParams
                        );
                        $idRow = $insertStmt->fetch(\PDO::FETCH_ASSOC);
                        $profileId = (int) ($idRow !== false ? $idRow['id'] : 0);
                    } else {
                        // SQLite: lastInsertId() is the established codebase idiom.
                        $db->query(
                            "INSERT INTO profiles
                                 (display_name, password_hash, two_factor_enabled,
                                  two_factor_secret, two_factor_backup_codes_version,
                                  token_epoch, created_at, updated_at)
                             VALUES
                                 (:display_name, :password_hash, :two_factor_enabled,
                                  :two_factor_secret, :two_factor_backup_codes_version,
                                  :token_epoch, :created_at, :updated_at)",
                            $profileParams
                        );
                        $profileId = (int) $db->getPdo()->lastInsertId();
                    }

                    // INSERT the primary verified profile_email (idempotent via
                    // the UNIQUE(email) constraint on profile_emails — first call
                    // wins, re-run is a no-op).
                    $db->query(
                        "INSERT INTO profile_emails
                             (profile_id, email, verified, is_primary, created_at)
                         VALUES
                             (:profile_id, :email, :verified, :is_primary, :created_at)
                         ON CONFLICT (email) DO NOTHING",
                        [
                            ':profile_id' => $profileId,
                            ':email'      => $normEmail,
                            ':verified'   => 1,
                            ':is_primary' => 1,
                            ':created_at' => $createdAt,
                        ]
                    );
                }

                $emailIndex[$normEmail] = [
                    'profile_id'   => $profileId,
                    'kept_user_id' => $userId,
                    'dropped_ids'  => [],
                ];

                // Track user_id → profile_id for down().
                $db->query(
                    "INSERT INTO migration_035_profile_ids (user_id, profile_id)
                     VALUES (:uid, :pid)
                     ON CONFLICT (user_id) DO NOTHING",
                    [':uid' => $userId, ':pid' => $profileId]
                );
            } else {
                // ── Duplicate-email collision ─────────────────────────────────
                // This user's credentials are dropped; the canonical profile's
                // credentials (from the lowest-id user) are kept.
                $emailIndex[$normEmail]['dropped_ids'][] = $userId;

                // Track the mapping so down() can remove the membership row.
                $db->query(
                    "INSERT INTO migration_035_profile_ids (user_id, profile_id)
                     VALUES (:uid, :pid)
                     ON CONFLICT (user_id) DO NOTHING",
                    [
                        ':uid' => $userId,
                        ':pid' => $emailIndex[$normEmail]['profile_id'],
                    ]
                );
            }

            // ── 2. Membership for every user row ─────────────────────────────
            // UNIQUE(profile_id, tenant_id) on memberships prevents duplicates on
            // re-run.
            $profileId = $emailIndex[$normEmail]['profile_id'];
            $db->query(
                "INSERT INTO memberships
                     (profile_id, tenant_id, role_id, ou_id, status, created_at)
                 VALUES
                     (:profile_id, :tenant_id, :role_id, :ou_id, 'active', :created_at)
                 ON CONFLICT (profile_id, tenant_id) DO NOTHING",
                [
                    ':profile_id' => $profileId,
                    ':tenant_id'  => (int) $user['tenant_id'],
                    ':role_id'    => (int) $user['role_id'],
                    ':ou_id'      => isset($user['ou_id']) && $user['ou_id'] !== null ? (int) $user['ou_id'] : null,
                    ':created_at' => (string) ($user['created_at'] ?? date('Y-m-d H:i:s')),
                ]
            );
        }

        // ── 3. Persist collision audit rows ───────────────────────────────────
        $collisionCount = 0;
        foreach ($emailIndex as $normEmail => $data) {
            if ($data['dropped_ids'] !== []) {
                ++$collisionCount;
                // Guard: skip if this email is already logged (idempotent re-run).
                $alreadyLogged = $db->query(
                    'SELECT id FROM migration_035_collision_log WHERE email = :email',
                    [':email' => $normEmail]
                )->fetch(\PDO::FETCH_ASSOC);
                if ($alreadyLogged === false) {
                    $db->query(
                        "INSERT INTO migration_035_collision_log
                             (email, kept_user_id, dropped_ids, created_at)
                         VALUES
                             (:email, :kept_user_id, :dropped_ids, NOW())",
                        [
                            ':email'        => $normEmail,
                            ':kept_user_id' => $data['kept_user_id'],
                            ':dropped_ids'  => implode(',', $data['dropped_ids']),
                        ]
                    );
                }
            }
        }

        // ── 4. Operator summary to STDOUT ─────────────────────────────────────
        $profileCount    = count($emailIndex);
        $membershipCount = count($rows);
        echo "\n";
        echo "=== migration_035 summary ===\n";
        echo "  users processed  : {$membershipCount}\n";
        echo "  profiles created : {$profileCount}\n";
        echo "  email collisions : {$collisionCount}\n";
        if ($collisionCount > 0) {
            echo "\n  Collision emails (credentials from the lowest users.id were kept):\n";
            foreach ($emailIndex as $normEmail => $data) {
                if ($data['dropped_ids'] !== []) {
                    $droppedStr = implode(',', $data['dropped_ids']);
                    echo "    {$normEmail}  kept_id={$data['kept_user_id']}  dropped_ids={$droppedStr}\n";
                }
            }
            echo "\n  ACTION REQUIRED: notify the affected users before deploying.\n";
            echo "  Snapshot the users table before running up() — down() cannot\n";
            echo "  recover discarded credential hashes.\n";
        }
        echo "=== end migration_035 summary ===\n\n";
    }

    public static function down(Database $db): void
    {
        // Guard: if the tracking table does not exist (migration never ran or was
        // partially reversed), drop any remnants and exit cleanly.
        //
        // Driver-aware existence check: sqlite_master is SQLite-only and is NOT
        // translated on the production `migrate run` path (only tests use
        // SchemaFromMigrations which translates it).  We therefore probe using
        // the appropriate catalogue view for each driver.
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            // to_regclass returns NULL when the relation does not exist; non-NULL
            // means the table is present in the current search_path.
            $stmt = $pdo->query(
                "SELECT to_regclass('migration_035_profile_ids') AS name"
            );
            $row  = ($stmt !== false) ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            if ($stmt !== false) { $stmt->closeCursor(); }
            $tableExists = $row !== false && $row['name'] !== null;
        } else {
            $stmt = $pdo->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = 'migration_035_profile_ids'"
            );
            $row  = ($stmt !== false) ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            if ($stmt !== false) { $stmt->closeCursor(); }
            $tableExists = $row !== false;
        }

        if (!$tableExists) {
            $db->exec('DROP TABLE IF EXISTS migration_035_collision_log');
            return;
        }

        // ── Remove memberships created by this migration ──────────────────────
        // @tenant-guard-ignore: cross-tenant reversal — removes all migration-created membership rows
        $db->exec("
            DELETE FROM memberships
            WHERE profile_id IN (
                SELECT DISTINCT profile_id FROM migration_035_profile_ids
            )
        ");

        // ── Remove profile_emails created by this migration ───────────────────
        // @tenant-guard-ignore: profile_emails is a global (non-tenant-scoped) table
        $db->exec("
            DELETE FROM profile_emails
            WHERE profile_id IN (
                SELECT DISTINCT profile_id FROM migration_035_profile_ids
            )
        ");

        // ── Remove profiles created by this migration ─────────────────────────
        // @tenant-guard-ignore: profiles is a global (non-tenant-scoped) table
        $db->exec("
            DELETE FROM profiles
            WHERE id IN (
                SELECT DISTINCT profile_id FROM migration_035_profile_ids
            )
        ");

        // ── Drop tracking / audit tables ──────────────────────────────────────
        $db->exec('DROP TABLE IF EXISTS migration_035_collision_log');
        $db->exec('DROP TABLE IF EXISTS migration_035_profile_ids');
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** Returns the local-part (before @) of an email address for display_name. */
    private static function localPart(string $email): string
    {
        $at = strrpos($email, '@');
        return $at !== false ? substr($email, 0, $at) : $email;
    }
}
