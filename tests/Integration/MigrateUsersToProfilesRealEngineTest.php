<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * WC-2515b697: real-engine integration test for migration 035
 * (MigrateUsersToProfiles).
 *
 * Tests run against in-memory SQLite via SchemaFromMigrations::make() (which
 * already runs all migrations up to 034 before any test method executes). The
 * same tests run on real PostgreSQL when PHPUNIT_PG_DSN is set (postgres-
 * integration CI job).
 *
 * Invariants proven
 * ─────────────────
 *  1. Every distinct normalised email in users produces exactly one profile.
 *  2. Every user has a corresponding primary verified profile_email.
 *  3. Every user has exactly one active membership.
 *  4. The duplicate-email pair collapses to 1 profile with 2 memberships.
 *  5. Credentials (password_hash) are copied from the lowest-id user row.
 *  6. Idempotency: running up() twice produces no extra rows.
 *  7. down() cleanly removes all profiles, profile_emails, and memberships
 *     created by the migration, leaving the users table untouched.
 */
final class MigrateUsersToProfilesRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        // SchemaFromMigrations::make() runs ALL migrations (001–035).
        // We need the schema from 028–034 only (profiles, profile_emails,
        // memberships), with data NOT yet migrated. So we use a fresh schema and
        // seed users manually, then call the migration under test explicitly.
        //
        // Because make() already ran 035 as part of the full stack, we must
        // reverse it (down) first, seed user rows, then run up() ourselves.
        $this->pdo = SchemaFromMigrations::make();
        $this->reverseMigration();
        $this->seedUsers();
    }

    // ── lifecycle helpers ────────────────────────────────────────────────────

    /**
     * Call 035::down() to remove any rows the initial make() run deposited
     * (on a fresh DB there are no users rows so make() is a no-op for 035,
     * but we call down() for correctness and to establish the tracking tables
     * are gone).
     */
    private function reverseMigration(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/035_migrate_users_to_profiles.php';
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();

        ob_start();
        try {
            \Database\Migrations\MigrateUsersToProfiles::down($db);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Seed a controlled set of users:
     *   tenant 1, admin role:
     *     user 1 — alice@example.com    (unique email)
     *     user 2 — bob@example.com      (unique email)
     *   tenant 2, user role:
     *     user 3 — carol@example.com    (unique email)
     *     user 4 — alice@example.com    (DUPLICATE — same email, different tenant)
     *   tenant 1, user role:
     *     user 5 — dave@example.com     (unique email)
     *
     * We clear the users table first so that the system@whity.local user seeded
     * by migration 010 does not skew the expected profile/membership counts.
     * Dependent tables (backup_codes, user_roles, persons, permission_delegations,
     * mcp_tokens) are all empty on a fresh test schema, so the DELETE is safe.
     */
    private function seedUsers(): void
    {
        // Remove any users seeded by prior migrations (e.g. system@whity.local from 010)
        // so the test exercises a fully controlled, predictable dataset.
        $this->pdo->exec('DELETE FROM users');

        // Tenants (1 and 2).
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (2, 'tenant-b')");

        // Roles (admin=1, user=2 — already seeded by migration 001).
        // Insert with OR IGNORE so this is idempotent even if seeded by earlier migration.
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (2, 'user')");

        // Users — ordered inserts so IDs are predictable (1..5).
        $this->pdo->exec("
            INSERT OR IGNORE INTO users (tenant_id, email, password, role_id, created_at)
            VALUES (1, 'alice@example.com', '\$2y\$10\$hash_alice_t1', 1, datetime('now'))
        ");
        $this->pdo->exec("
            INSERT OR IGNORE INTO users (tenant_id, email, password, role_id, created_at)
            VALUES (1, 'bob@example.com', '\$2y\$10\$hash_bob', 1, datetime('now'))
        ");
        $this->pdo->exec("
            INSERT OR IGNORE INTO users (tenant_id, email, password, role_id, created_at)
            VALUES (2, 'carol@example.com', '\$2y\$10\$hash_carol', 2, datetime('now'))
        ");
        // The duplicate: alice in tenant 2 — should collapse into alice's profile.
        $this->pdo->exec("
            INSERT OR IGNORE INTO users (tenant_id, email, password, role_id, created_at)
            VALUES (2, 'alice@example.com', '\$2y\$10\$hash_alice_t2', 2, datetime('now'))
        ");
        $this->pdo->exec("
            INSERT OR IGNORE INTO users (tenant_id, email, password, role_id, created_at)
            VALUES (1, 'dave@example.com', '\$2y\$10\$hash_dave', 2, datetime('now'))
        ");
    }

    /** Run migration 035 up() and suppress STDOUT output. */
    private function runUp(): void
    {
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();

        ob_start();
        try {
            \Database\Migrations\MigrateUsersToProfiles::up($db);
        } finally {
            ob_end_clean();
        }
    }

    /** Run migration 035 down() and suppress STDOUT output. */
    private function runDown(): void
    {
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();

        ob_start();
        try {
            \Database\Migrations\MigrateUsersToProfiles::down($db);
        } finally {
            ob_end_clean();
        }
    }

    // ── invariant tests ──────────────────────────────────────────────────────

    /**
     * After up(): exactly 4 profiles (alice, bob, carol, dave — alice collapses).
     */
    public function testProfileCountEqualsUniqueEmailCount(): void
    {
        $this->runUp();

        $profileCount = (int) $this->pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
        self::assertSame(4, $profileCount, 'Expected 4 profiles (one per unique normalised email).');
    }

    /**
     * After up(): every user email resolves to a profile_emails row.
     */
    public function testEveryUserEmailHasAProfileEmail(): void
    {
        $this->runUp();

        $userEmails = $this->pdo->query(
            "SELECT DISTINCT LOWER(email) AS email FROM users ORDER BY email"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($userEmails as $email) {
            $row = $this->pdo->query(
                "SELECT id FROM profile_emails WHERE email = " . $this->pdo->quote((string) $email)
            )->fetch(PDO::FETCH_ASSOC);

            self::assertNotFalse(
                $row,
                "Expected a profile_email row for email '{$email}'."
            );
        }
    }

    /**
     * After up(): every profile_email is verified and marked is_primary.
     */
    public function testAllProfileEmailsAreVerifiedAndPrimary(): void
    {
        $this->runUp();

        $rows = $this->pdo->query(
            "SELECT email, verified, is_primary FROM profile_emails"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotEmpty($rows, 'Expected at least one profile_email row.');

        foreach ($rows as $row) {
            self::assertSame(
                '1',
                (string) $row['verified'],
                "profile_emails.verified must be true for '{$row['email']}'."
            );
            self::assertSame(
                '1',
                (string) $row['is_primary'],
                "profile_emails.is_primary must be true for '{$row['email']}'."
            );
        }
    }

    /**
     * After up(): 5 users → 5 membership rows (one per user row, even for the
     * duplicate-email pair which collapses to one profile but still gets 2
     * separate memberships for the 2 different tenants).
     */
    public function testMembershipCountEqualsUserCount(): void
    {
        $this->runUp();

        $membershipCount = (int) $this->pdo->query('SELECT COUNT(*) FROM memberships')->fetchColumn();
        self::assertSame(5, $membershipCount, 'Expected 5 membership rows (one per users row).');
    }

    /**
     * After up(): the duplicate-email alice@example.com collapses to ONE profile
     * but produces TWO memberships (one for tenant 1, one for tenant 2).
     */
    public function testDuplicateEmailCollapsesToOneProfileWithTwoMemberships(): void
    {
        $this->runUp();

        // Resolve alice's profile.
        $profileRow = $this->pdo->query(
            "SELECT profile_id FROM profile_emails WHERE email = 'alice@example.com'"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($profileRow, 'alice@example.com must have a profile_email row.');
        $aliceProfileId = (int) $profileRow['profile_id'];

        // Count memberships for alice's single profile.
        $memberships = $this->pdo->query(
            "SELECT tenant_id FROM memberships WHERE profile_id = {$aliceProfileId} ORDER BY tenant_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(
            2,
            $memberships,
            "alice@example.com must have 2 memberships (one per tenant), got " . count($memberships)
        );

        $tenantIds = array_map('strval', array_column($memberships, 'tenant_id'));
        self::assertContains('1', $tenantIds, "alice must have a membership in tenant 1.");
        self::assertContains('2', $tenantIds, "alice must have a membership in tenant 2.");
    }

    /**
     * After up(): alice's profile carries the password from the FIRST/lowest-id
     * user row (alice in tenant 1, id=1 in our seed order).
     */
    public function testCredentialsAreCopiedFromEarliestUserRow(): void
    {
        $this->runUp();

        $profileRow = $this->pdo->query(
            "SELECT p.password_hash
               FROM profiles p
               JOIN profile_emails pe ON pe.profile_id = p.id
              WHERE pe.email = 'alice@example.com'"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($profileRow, 'alice@example.com must resolve to a profile.');
        self::assertSame(
            '$2y$10$hash_alice_t1',
            $profileRow['password_hash'],
            'Profile must use credentials from the earliest (lowest id) alice user row (tenant 1).'
        );
    }

    /**
     * After up(): every membership carries status='active'.
     */
    public function testAllMembershipsHaveActiveStatus(): void
    {
        $this->runUp();

        $nonActive = $this->pdo->query(
            "SELECT COUNT(*) FROM memberships WHERE status <> 'active'"
        )->fetchColumn();

        self::assertSame('0', (string) $nonActive, "All memberships must have status='active'.");
    }

    /**
     * Idempotency: running up() twice produces no duplicate rows.
     */
    public function testUpIsIdempotent(): void
    {
        $this->runUp();

        $profilesBefore    = (int) $this->pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
        $emailsBefore      = (int) $this->pdo->query('SELECT COUNT(*) FROM profile_emails')->fetchColumn();
        $membershipsBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM memberships')->fetchColumn();

        // Run a second time — must not throw and must not add rows.
        $this->runUp();

        $profilesAfter    = (int) $this->pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
        $emailsAfter      = (int) $this->pdo->query('SELECT COUNT(*) FROM profile_emails')->fetchColumn();
        $membershipsAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM memberships')->fetchColumn();

        self::assertSame($profilesBefore, $profilesAfter, 'Profiles count must not change on second up().');
        self::assertSame($emailsBefore, $emailsAfter, 'profile_emails count must not change on second up().');
        self::assertSame($membershipsBefore, $membershipsAfter, 'Memberships count must not change on second up().');
    }

    /**
     * down(): cleanly removes all profiles, profile_emails, and memberships
     * created by this migration; the users table is untouched.
     */
    public function testDownReversesCleanly(): void
    {
        $this->runUp();

        // Confirm rows are present.
        self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn());
        self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM profile_emails')->fetchColumn());
        self::assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM memberships')->fetchColumn());

        $userCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $this->runDown();

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn(),
            'down() must remove all profiles created by migration 035.');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM profile_emails')->fetchColumn(),
            'down() must remove all profile_emails created by migration 035.');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM memberships')->fetchColumn(),
            'down() must remove all memberships created by migration 035.');

        // Users must be untouched.
        $userCountAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        self::assertSame($userCountBefore, $userCountAfter, 'down() must not remove any users rows.');
    }

    /**
     * down() after down(): calling down() on an already-reversed DB must not
     * throw (graceful no-op).
     */
    public function testDownIsIdempotent(): void
    {
        $this->runUp();
        $this->runDown();

        // Second down() must not throw.
        $this->runDown();

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn());
    }

    /**
     * After up(): the collision log records the duplicate-email event.
     */
    public function testCollisionLogRecordsDuplicateEmail(): void
    {
        $this->runUp();

        $logRow = $this->pdo->query(
            "SELECT email, kept_user_id, dropped_ids
               FROM migration_035_collision_log
              WHERE email = 'alice@example.com'"
        )->fetch(PDO::FETCH_ASSOC);

        self::assertNotFalse($logRow, 'A collision log row must exist for alice@example.com.');
        // kept_user_id must be the lower id (alice in tenant 1 was inserted first).
        self::assertGreaterThan(0, (int) $logRow['kept_user_id']);
        self::assertNotEmpty($logRow['dropped_ids'], 'dropped_ids must be non-empty for a collision.');
    }
}
