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
 * WC-779 (secondary): the bootstrap identity is configurable, the dev fixtures
 * are development-only, and a configured-but-inert password is reported.
 *
 * Runs on both engines: in-memory SQLite locally, real PostgreSQL when
 * PHPUNIT_PG_DSN is set (the CI dialect shard).
 *
 * What is proven here
 * ───────────────────
 *  (a) With INITIAL_SYSTEM_ADMIN_EMAIL set, `migrate run` alone lands the
 *      bootstrap administrator at THAT address — not at the hardcoded
 *      system@whity.local — and it is the SAME identity (one profile, still
 *      holding the tenant-0 admin membership), not a second account.
 *  (b) With the variable unset, nothing moves: an existing install upgrading
 *      to this migration keeps system@whity.local exactly as it was.
 *  (c) The renamed bootstrap administrator can actually authenticate with
 *      INITIAL_SYSTEM_ADMIN_PASSWORD — a rename that broke login would be
 *      worse than the unroutable address it replaced.
 *  (d) The seeder provisions the `*@example.com` fixtures only under
 *      APP_ENV=development; a production/staging seed must not materialise a
 *      live credential nobody asked for.
 *  (e) When an account already exists and its stored hash does NOT match the
 *      configured INITIAL_* value, the seeder SAYS so and still refuses to
 *      rewrite the credential (WHIT-587 item 5: a documented variable that
 *      goes inert with no warning is a silent dead end).
 */
final class BootstrapIdentityRealEngineTest extends TestCase
{
    /** Deterministic, >= 32-char fixture passwords (project secret policy). */
    private const SYSTEM_ADMIN_PASSWORD = 'wc779-system-admin-fixture-password-abc123';
    private const FIXTURE_PASSWORD      = 'wc779-dev-fixture-password-abcdef01234567';

    private const JWT_SECRET = 'wc779-jwt-test-secret-padded-to-32-bytes!!';

    /** The operator-chosen, routable bootstrap address under test. */
    private const CONFIGURED_EMAIL = 'ops@bootstrap.example';

    /** The historical, hardcoded default (migrations 010/036). */
    private const DEFAULT_EMAIL = 'system@whity.local';

    /** Environment the seeder and migrations read; restored in tearDown(). */
    private const MANAGED_ENV_VARS = [
        'INITIAL_SYSTEM_ADMIN_PASSWORD',
        'INITIAL_ADMIN_PASSWORD',
        'INITIAL_USER_PASSWORD',
        'INITIAL_SUPERUSER_PASSWORD',
        'INITIAL_SYSTEM_ADMIN_EMAIL',
        'APP_ENV',
    ];

    /** @var array<string, string|false> Pre-test environment, restored afterwards. */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::MANAGED_ENV_VARS as $var) {
            $this->savedEnv[$var] = $_ENV[$var] ?? getenv($var);
        }

        // Pinned so no "generated initial password" notice reaches stdout
        // (PHPUnit's beStrictAboutOutputDuringTests would flag it as risky).
        $this->putEnv('INITIAL_SYSTEM_ADMIN_PASSWORD', self::SYSTEM_ADMIN_PASSWORD);
        $this->putEnv('INITIAL_ADMIN_PASSWORD', self::FIXTURE_PASSWORD);
        $this->putEnv('INITIAL_USER_PASSWORD', self::FIXTURE_PASSWORD);
        $this->putEnv('INITIAL_SUPERUSER_PASSWORD', self::FIXTURE_PASSWORD);
    }

    protected function tearDown(): void
    {
        foreach (self::MANAGED_ENV_VARS as $var) {
            $saved = $this->savedEnv[$var] ?? false;
            if (is_string($saved)) {
                $this->putEnv($var, $saved);
            } else {
                unset($_ENV[$var]);
                putenv($var);
            }
        }
        $this->savedEnv = [];
        $_COOKIE        = [];
    }

    // ── (a) migrate run alone honours the configured address ─────────────────

    public function testMigrationsPlaceTheBootstrapAdminAtTheConfiguredAddress(): void
    {
        $this->putEnv('INITIAL_SYSTEM_ADMIN_EMAIL', self::CONFIGURED_EMAIL);

        $pdo = SchemaFromMigrations::make();

        self::assertTrue(
            $this->emailExists($pdo, self::CONFIGURED_EMAIL),
            'With INITIAL_SYSTEM_ADMIN_EMAIL set, migrations alone must land the bootstrap administrator at that address.'
        );
        self::assertFalse(
            $this->emailExists($pdo, self::DEFAULT_EMAIL),
            'The hardcoded system@whity.local address must not survive a configured rename.'
        );
    }

    public function testConfiguredBootstrapAdminIsOneIdentityNotTwo(): void
    {
        $this->putEnv('INITIAL_SYSTEM_ADMIN_EMAIL', self::CONFIGURED_EMAIL);

        $pdo = SchemaFromMigrations::make();

        // @tenant-guard-ignore: profiles/profile_emails are sanctioned GLOBAL tables (ADR 0005 §§1-2)
        $stmt = $pdo->query('SELECT COUNT(*) FROM profiles');
        self::assertNotFalse($stmt);
        self::assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'The rename must MOVE the bootstrap identity, not add a second profile beside it.'
        );
    }

    public function testConfiguredBootstrapAdminKeepsTheTenantZeroAdminMembership(): void
    {
        $this->putEnv('INITIAL_SYSTEM_ADMIN_EMAIL', self::CONFIGURED_EMAIL);

        $pdo = SchemaFromMigrations::make();

        // @tenant-guard-ignore: seed-time system-tenant membership check (tenant_id = 0)
        $stmt = $pdo->prepare(
            'SELECT m.status, r.name AS role_name
               FROM profile_emails pe
               JOIN memberships m ON m.profile_id = pe.profile_id
               JOIN roles r       ON r.id = m.role_id
              WHERE pe.email = :email AND m.tenant_id = 0'
        );
        $stmt->execute([':email' => self::CONFIGURED_EMAIL]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'The renamed bootstrap administrator must keep its tenant-0 membership.');
        self::assertSame('active', (string) $row['status']);
        self::assertSame('admin', (string) $row['role_name']);
    }

    // ── (b) unset variable => existing installs are untouched ────────────────

    public function testUnsetVariableLeavesTheHistoricalAddressInPlace(): void
    {
        unset($_ENV['INITIAL_SYSTEM_ADMIN_EMAIL']);
        putenv('INITIAL_SYSTEM_ADMIN_EMAIL');

        $pdo = SchemaFromMigrations::make();

        self::assertTrue(
            $this->emailExists($pdo, self::DEFAULT_EMAIL),
            'With the variable unset the bootstrap administrator must stay at system@whity.local — existing installs must not move.'
        );
    }

    // ── (c) the routable identity actually authenticates ─────────────────────

    public function testConfiguredBootstrapAdminCanAuthenticate(): void
    {
        $this->putEnv('INITIAL_SYSTEM_ADMIN_EMAIL', self::CONFIGURED_EMAIL);

        $pdo       = SchemaFromMigrations::make();
        $jwtParser = new JwtParser(self::JWT_SECRET);
        $handler   = new AuthHandler($pdo, $jwtParser, new TokenValidator($jwtParser, $pdo));

        $_COOKIE  = [];
        $response = $handler->handle(new Request('POST', '/api/login', [], (string) json_encode([
            'email'    => self::CONFIGURED_EMAIL,
            'password' => self::SYSTEM_ADMIN_PASSWORD,
        ])));

        self::assertSame(
            200,
            $response->getStatusCode(),
            'The renamed bootstrap administrator must still log in with INITIAL_SYSTEM_ADMIN_PASSWORD.'
        );
    }

    // ── seeder agrees with the migration (no duplicate bootstrap account) ────

    public function testSeedingAfterAConfiguredRenameDoesNotResurrectTheDefaultAddress(): void
    {
        $this->putEnv('INITIAL_SYSTEM_ADMIN_EMAIL', self::CONFIGURED_EMAIL);

        $db = $this->seededDatabase($pdo);

        self::assertFalse(
            $this->emailExists($pdo, self::DEFAULT_EMAIL),
            'The seeder must resolve the bootstrap address the same way the migration does, not re-create system@whity.local.'
        );
        self::assertSame(1, $this->emailCount($pdo, self::CONFIGURED_EMAIL));
        unset($db);
    }

    public function testRenameOntoAnAddressAnotherAccountHoldsIsRefusedAndReported(): void
    {
        // Seed the fixtures first so admin@example.com is genuinely taken.
        $this->putEnv('APP_ENV', 'development');
        $pdo = SchemaFromMigrations::make();
        $db  = Database::withFactory(fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        ob_start();
        Seeder::seed($db);
        ob_end_clean();

        $this->putEnv('INITIAL_SYSTEM_ADMIN_EMAIL', 'admin@example.com');

        ob_start();
        $landedOn = Seeder::seed($db);
        $output   = (string) ob_get_clean();

        self::assertSame(
            self::DEFAULT_EMAIL,
            $landedOn,
            'Colliding two accounts to honour an env var is worse than leaving the rename undone.'
        );
        self::assertTrue($this->emailExists($pdo, self::DEFAULT_EMAIL));
        self::assertStringContainsString(
            'already belongs to another account',
            $output,
            'A refused rename must say why, not fail silently.'
        );
    }

    // ── (d) fixtures are development-only ────────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function nonDevelopmentEnvironments(): iterable
    {
        yield 'production' => ['production'];
        yield 'staging'    => ['staging'];
    }

    /** @dataProvider nonDevelopmentEnvironments */
    public function testSeederOmitsDevFixturesOutsideDevelopment(string $appEnv): void
    {
        $this->putEnv('APP_ENV', $appEnv);

        $this->seededDatabase($pdo);

        foreach (['admin@example.com', 'user@example.com', 'superuser@example.com'] as $fixture) {
            self::assertFalse(
                $this->emailExists($pdo, $fixture),
                sprintf('APP_ENV=%s must not materialise the %s fixture account.', $appEnv, $fixture)
            );
        }
    }

    public function testSeederStillProvisionsTheBootstrapAdminOutsideDevelopment(): void
    {
        $this->putEnv('APP_ENV', 'production');

        $this->seededDatabase($pdo);

        self::assertTrue(
            $this->emailExists($pdo, self::DEFAULT_EMAIL),
            'Gating the fixtures must not take the bootstrap administrator with them.'
        );
    }

    public function testSeederProvisionsDevFixturesInDevelopment(): void
    {
        $this->putEnv('APP_ENV', 'development');

        $this->seededDatabase($pdo);

        foreach (['admin@example.com', 'user@example.com', 'superuser@example.com'] as $fixture) {
            self::assertTrue(
                $this->emailExists($pdo, $fixture),
                sprintf('APP_ENV=development must still provide the %s fixture account.', $fixture)
            );
        }
    }

    // ── (e) an inert credential variable is reported, never silently applied ─

    public function testSeederWarnsWhenAnExistingAccountsPasswordDoesNotMatchTheConfiguredValue(): void
    {
        $this->putEnv('APP_ENV', 'production');

        $pdo = SchemaFromMigrations::make();
        $db  = Database::withFactory(fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        $this->overwriteStoredPassword($pdo, self::DEFAULT_EMAIL, 'a-password-nobody-configured-000');

        ob_start();
        Seeder::seed($db);
        $output = (string) ob_get_clean();

        self::assertStringContainsString(
            'INITIAL_SYSTEM_ADMIN_PASSWORD',
            $output,
            'A configured password that cannot take effect must name the variable that is inert.'
        );
        self::assertStringContainsString(
            self::DEFAULT_EMAIL,
            $output,
            'The warning must name the account whose stored credential does not match.'
        );
    }

    public function testSeederNeverRewritesAnExistingPassword(): void
    {
        $this->putEnv('APP_ENV', 'production');

        $pdo = SchemaFromMigrations::make();
        $db  = Database::withFactory(fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        $this->overwriteStoredPassword($pdo, self::DEFAULT_EMAIL, 'a-password-nobody-configured-000');
        $before = $this->storedPassword($pdo, self::DEFAULT_EMAIL);

        ob_start();
        Seeder::seed($db);
        ob_end_clean();

        self::assertSame(
            $before,
            $this->storedPassword($pdo, self::DEFAULT_EMAIL),
            'Seeding must warn about a credential mismatch, never reset the credential.'
        );
    }

    public function testMatchingPasswordProducesNoWarning(): void
    {
        $this->putEnv('APP_ENV', 'production');

        $pdo = SchemaFromMigrations::make();
        $db  = Database::withFactory(fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        ob_start();
        Seeder::seed($db);
        $output = (string) ob_get_clean();

        self::assertStringNotContainsString(
            'does not match',
            $output,
            'An account whose stored credential DOES match the configured value must produce no noise.'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Build a migrated schema and run the seeder over it.
     *
     * @param PDO|null $pdo Receives the underlying PDO for direct assertions.
     */
    private function seededDatabase(?PDO &$pdo): Database
    {
        $pdo = SchemaFromMigrations::make();
        $db  = Database::withFactory(fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        ob_start();
        Seeder::seed($db);
        ob_end_clean();

        return $db;
    }

    private function emailExists(PDO $pdo, string $email): bool
    {
        return $this->emailCount($pdo, $email) > 0;
    }

    private function emailCount(PDO $pdo, string $email): int
    {
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM profile_emails WHERE email = :email');
        $stmt->execute([':email' => $email]);

        return (int) $stmt->fetchColumn();
    }

    private function storedPassword(PDO $pdo, string $email): string
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $pdo->prepare(
            'SELECT p.password_hash FROM profiles p
               JOIN profile_emails pe ON pe.profile_id = p.id
              WHERE pe.email = :email'
        );
        $stmt->execute([':email' => $email]);

        return (string) $stmt->fetchColumn();
    }

    private function overwriteStoredPassword(PDO $pdo, string $email, string $plaintext): void
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL table (ADR 0005 §1)
        $stmt = $pdo->prepare(
            'UPDATE profiles SET password_hash = :hash
              WHERE id = (SELECT profile_id FROM profile_emails WHERE email = :email)'
        );
        $stmt->execute([
            ':hash'  => password_hash($plaintext, PASSWORD_BCRYPT),
            ':email' => $email,
        ]);
    }

    private function putEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        putenv($name . '=' . $value);
    }
}
