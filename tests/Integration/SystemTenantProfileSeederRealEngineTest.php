<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Database\Database;
use Whity\Database\Seeder;

/**
 * Spy JwtParser: captures minted payloads so claim content can be asserted
 * without intercepting Set-Cookie headers (unavailable under the CLI SAPI).
 */
final class SystemProfileClaimCapturingJwtParser extends JwtParser
{
    /** @var list<array{payload: array<string, mixed>, type: string}> */
    public array $captured = [];

    public function create(array $payload, int $expiresIn = 3600, string $type = 'access'): string
    {
        $this->captured[] = ['payload' => $payload, 'type' => $type];

        return parent::create($payload, $expiresIn, $type);
    }

    /**
     * @return array<string, mixed>|null The last captured payload of a given type.
     */
    public function lastPayloadOfType(string $type): ?array
    {
        foreach (array_reverse($this->captured) as $entry) {
            if ($entry['type'] === $type) {
                return $entry['payload'];
            }
        }

        return null;
    }
}

/**
 * WC-10522424: real-engine proof that the system tenant + dev fixtures are
 * seeded into the profile model on a fresh install.
 *
 * Runs on both SQLite (local) and real PostgreSQL (CI: PHPUNIT_PG_DSN).
 *
 * Invariants proven
 * ─────────────────
 *  (a) After migrate + seed, system@whity.local has a profile, a primary
 *      verified profile_email, and an ACTIVE membership in tenant 0.
 *  (b) The system admin can authenticate through the profile login path
 *      (AuthHandler::identityClaims — the dual-claim seam from WC-d4340daf):
 *      the issued JWT carries {profile_id, active_tenant_id = 0}.
 *  (c) The dev fixtures (admin@example.com, user@example.com,
 *      superuser@example.com) also get profile model rows.
 *  (d) Idempotency: running the seeder twice produces no duplicate rows.
 *  (e) Migration 036 alone (without the seeder) also provisions the system
 *      admin profile model rows; re-running the seeder afterward is a no-op.
 */
final class SystemTenantProfileSeederRealEngineTest extends TestCase
{
    /**
     * Deterministic, >= 32-char fixture passwords (project secret policy).
     * These are test-only values that never reach production.
     */
    private const SYSTEM_ADMIN_PASSWORD = 'wc10522424-system-fixture-password-abcdef0123';
    private const DEV_FIXTURE_PASSWORD  = 'wc10522424-dev-fixture-password-abcdef012345';

    private const JWT_SECRET = 'wc10522424-jwt-test-secret-padded-to-32-bytes!';

    /** Env vars that seed() reads — set to known values so output is deterministic. */
    private const PASSWORD_ENV_VARS = [
        'INITIAL_SYSTEM_ADMIN_PASSWORD',
        'INITIAL_ADMIN_PASSWORD',
        'INITIAL_USER_PASSWORD',
        'INITIAL_SUPERUSER_PASSWORD',
    ];

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        // Silence seeder password announcements during tests.
        $_ENV['INITIAL_SYSTEM_ADMIN_PASSWORD'] = self::SYSTEM_ADMIN_PASSWORD;
        $_ENV['INITIAL_ADMIN_PASSWORD']         = self::DEV_FIXTURE_PASSWORD;
        $_ENV['INITIAL_USER_PASSWORD']          = self::DEV_FIXTURE_PASSWORD;
        $_ENV['INITIAL_SUPERUSER_PASSWORD']     = self::DEV_FIXTURE_PASSWORD;
        foreach (self::PASSWORD_ENV_VARS as $var) {
            putenv($var . '=' . $_ENV[$var]);
        }

        // SchemaFromMigrations::make() runs all migrations (001–036) which
        // includes 036_seed_system_admin_profile.  The system admin profile
        // model rows are therefore already present after make().
        $this->pdo = SchemaFromMigrations::make();
        $this->db  = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $this->db->forceConnect();
    }

    protected function tearDown(): void
    {
        foreach (self::PASSWORD_ENV_VARS as $var) {
            unset($_ENV[$var]);
            putenv($var);
        }
        $_COOKIE = [];
    }

    // ── (a) Structural proof: profile + email + membership ────────────────────

    public function testMigration036CreatesSystemAdminProfile(): void
    {
        // After all migrations run (via SchemaFromMigrations::make()), the
        // system admin must have a profile row reachable via profile_emails.
        $row = $this->fetchProfileByEmail('system@whity.local');

        self::assertNotNull($row, 'Migration 036 must create a profile for system@whity.local.');
    }

    public function testSystemAdminProfileEmailIsVerifiedAndPrimary(): void
    {
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $stmt = $this->pdo->prepare(
            'SELECT verified, is_primary FROM profile_emails WHERE email = :email'
        );
        $stmt->execute([':email' => 'system@whity.local']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'A profile_emails row must exist for system@whity.local.');
        self::assertTrue(
            (bool) $row['verified'],
            'The system admin profile_email must be verified = TRUE.'
        );
        self::assertTrue(
            (bool) $row['is_primary'],
            'The system admin profile_email must be is_primary = TRUE.'
        );
    }

    public function testSystemAdminHasActiveMembershipInSystemTenant(): void
    {
        $profile = $this->fetchProfileByEmail('system@whity.local');
        self::assertNotNull($profile);

        $profileId = (int) $profile['profile_id'];

        // @tenant-guard-ignore: seed-time system-tenant membership check (tenant_id = 0)
        $stmt = $this->pdo->prepare(
            "SELECT status, tenant_id FROM memberships WHERE profile_id = :pid AND tenant_id = 0"
        );
        $stmt->execute([':pid' => $profileId]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray(
            $membership,
            'system@whity.local must have a membership in tenant 0 (system tenant).'
        );
        self::assertSame(
            'active',
            (string) $membership['status'],
            'The system admin membership status must be "active".'
        );
        self::assertSame(
            0,
            (int) $membership['tenant_id'],
            'The system admin membership must be in tenant 0.'
        );
    }

    public function testSystemAdminMembershipHoldsAdminRole(): void
    {
        $profile   = $this->fetchProfileByEmail('system@whity.local');
        self::assertNotNull($profile);
        $profileId = (int) $profile['profile_id'];

        // @tenant-guard-ignore: seed-time system-tenant membership check (tenant_id = 0)
        $stmt = $this->pdo->prepare(
            'SELECT m.role_id, r.name AS role_name
             FROM memberships m
             JOIN roles r ON r.id = m.role_id
             WHERE m.profile_id = :pid AND m.tenant_id = 0'
        );
        $stmt->execute([':pid' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame(
            'admin',
            (string) $row['role_name'],
            'The system admin tenant-0 membership must hold the admin role.'
        );
    }

    // ── (b) Auth proof: dual-claim JWT carries profile_id + active_tenant_id ──

    /**
     * Prove that the system admin can authenticate via the profile login path.
     *
     * The seeder creates a users row AND profile model rows for
     * system@whity.local.  After running the seeder, login must issue a JWT
     * that carries {profile_id, active_tenant_id = 0} (the dual-claim seam from
     * WC-d4340daf / AuthHandler::identityClaims).
     */
    public function testSystemAdminAuthenticationIssuesDualClaimToken(): void
    {
        // The seeder runs AFTER migration 036 (which already created the profile
        // model rows).  Running the seeder is idempotent — it will not duplicate
        // anything, but it ensures the users row (required by the current login
        // handler) is also present.
        ob_start();
        Seeder::seed($this->db);
        ob_end_clean();

        $jwtParser = new SystemProfileClaimCapturingJwtParser(self::JWT_SECRET);
        $handler   = new AuthHandler(
            $this->pdo,
            $jwtParser,
            new TokenValidator($jwtParser, $this->pdo),
        );

        $request = new Request('POST', '/api/login', [], (string) json_encode([
            'email'    => 'system@whity.local',
            'password' => self::SYSTEM_ADMIN_PASSWORD,
        ]));

        $_COOKIE = [];
        $response = $handler->handle($request);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'Login for system@whity.local must succeed (200).'
        );

        $accessPayload = $jwtParser->lastPayloadOfType('access');
        self::assertIsArray($accessPayload, 'An access token must have been minted.');

        // New identity claims (ADR 0005 §5) — the system admin is migrated into
        // the profile model by migration 036, so the dual-claim window applies.
        self::assertArrayHasKey(
            'profile_id',
            $accessPayload,
            'Access token must carry profile_id for the system admin.'
        );
        self::assertSame(
            0,
            $accessPayload['active_tenant_id'] ?? null,
            'Access token active_tenant_id must be 0 (system tenant) for system@whity.local.'
        );

        // Legacy claims are still issued during the dual window.
        self::assertArrayHasKey(
            'user_id',
            $accessPayload,
            'Legacy user_id claim must still be present during the dual-claim window.'
        );
    }

    // ── (c) Dev fixtures also receive profile model rows ─────────────────────

    public function testSeederCreatesProfileForAdminFixture(): void
    {
        ob_start();
        Seeder::seed($this->db);
        ob_end_clean();

        $row = $this->fetchProfileByEmail('admin@example.com');
        self::assertNotNull($row, 'Seeder must create a profile for admin@example.com.');
    }

    public function testSeederCreatesProfileForUserFixture(): void
    {
        ob_start();
        Seeder::seed($this->db);
        ob_end_clean();

        $row = $this->fetchProfileByEmail('user@example.com');
        self::assertNotNull($row, 'Seeder must create a profile for user@example.com.');
    }

    public function testSeederCreatesProfileForSuperuserFixture(): void
    {
        ob_start();
        Seeder::seed($this->db);
        ob_end_clean();

        $row = $this->fetchProfileByEmail('superuser@example.com');
        self::assertNotNull($row, 'Seeder must create a profile for superuser@example.com.');
    }

    public function testAdminFixtureHasActiveMembershipInDefaultTenant(): void
    {
        ob_start();
        Seeder::seed($this->db);
        ob_end_clean();

        $row       = $this->fetchProfileByEmail('admin@example.com');
        self::assertNotNull($row);
        $profileId = (int) $row['profile_id'];

        $stmt = $this->pdo->prepare(
            "SELECT status FROM memberships WHERE profile_id = :pid AND tenant_id != 0"
        );
        $stmt->execute([':pid' => $profileId]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($membership, 'admin@example.com must have a non-system-tenant membership.');
        self::assertSame('active', (string) $membership['status']);
    }

    // ── (d) Idempotency ───────────────────────────────────────────────────────

    public function testReSeedingDoesNotDuplicateSystemAdminProfile(): void
    {
        ob_start();
        Seeder::seed($this->db);
        Seeder::seed($this->db);
        ob_end_clean();

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $stmt  = $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'system@whity.local'");
        $count = ($stmt !== false) ? (int) $stmt->fetchColumn() : 0;

        self::assertSame(
            1,
            $count,
            'Re-running the seeder must not duplicate the system admin profile_email row.'
        );
    }

    public function testReSeedingDoesNotDuplicateSystemAdminMembership(): void
    {
        ob_start();
        Seeder::seed($this->db);
        Seeder::seed($this->db);
        ob_end_clean();

        $profile   = $this->fetchProfileByEmail('system@whity.local');
        self::assertNotNull($profile);
        $profileId = (int) $profile['profile_id'];

        // @tenant-guard-ignore: seed-time system-tenant membership check (tenant_id = 0)
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM memberships WHERE profile_id = :pid AND tenant_id = 0'
        );
        $stmt->execute([':pid' => $profileId]);
        $count = (int) $stmt->fetchColumn();

        self::assertSame(
            1,
            $count,
            'Re-running the seeder must not duplicate the system admin membership.'
        );
    }

    // ── (e) Migration 036 alone is sufficient; seeder is additive ────────────

    public function testMigration036AloneSeesSystemAdminProfileBeforeSeeder(): void
    {
        // SchemaFromMigrations::make() already ran all migrations including 036.
        // No explicit Seeder::seed() call here — migration 036 alone must have
        // provisioned the profile model rows.
        $profile = $this->fetchProfileByEmail('system@whity.local');

        self::assertNotNull(
            $profile,
            'Migration 036 alone (without Seeder::seed()) must create the system admin profile.'
        );
    }

    public function testSeederAfterMigration036IsIdempotent(): void
    {
        // migrations were run by make(); now run the seeder and check no duplication.
        ob_start();
        Seeder::seed($this->db);
        ob_end_clean();

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $stmt  = $this->pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email = 'system@whity.local'");
        $count = ($stmt !== false) ? (int) $stmt->fetchColumn() : 0;

        self::assertSame(
            1,
            $count,
            'Running Seeder::seed() after migration 036 must not duplicate the system admin profile_email.'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function fetchProfileByEmail(string $email): ?array
    {
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $stmt = $this->pdo->prepare(
            'SELECT profile_id FROM profile_emails WHERE email = :email'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
