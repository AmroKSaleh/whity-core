<?php

declare(strict_types=1);

namespace Tests\Integration;

use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TwoFactorHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\CookieManager;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * WC-c35c4ce0 review BLOCKER: TwoFactorHandler's boolean reads must survive the
 * PostgreSQL "f"/"t" string representation.
 *
 * pdo_pgsql returns the STRING "f" for a false boolean, and (bool)"f" === true.
 * The setup()/status()/regenerateCodes() FALSE-branches (the "2FA is disabled"
 * cases) hinge on reading two_factor_enabled correctly; with a naive cast they
 * invert on Postgres (setup wrongly 400s, status wrongly reports enabled=true,
 * regenerate wrongly proceeds for a disabled user). These tests run against the
 * REAL engine (PG in CI via PHPUNIT_PG_DSN) with a 2FA-DISABLED user and assert
 * the correct false-branch behavior — the coverage gap that let CI stay green.
 *
 * Runs on real SQLite locally and real PostgreSQL in CI; the bug is only
 * OBSERVABLE on PG (SQLite returns 0/1), but exercising both keeps the contract
 * driver-agnostic.
 */
final class TwoFactorManagementDisabledStateRealEngineTest extends TestCase
{
    private const SECRET   = 'wc-c35c4ce0-2fa-mgmt-disabled-test-secret-32b';
    private const USER_ID  = 4242;
    private const TENANT   = 1;
    private const EMAIL    = 'twofa-disabled@example.com';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private TwoFactorHandler $handler;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = new JwtParser(self::SECRET);

        $totp = new TotpService(self::SECRET);
        $backupCodes = new BackupCodesService(new \Whity\Auth\DatabaseQueryWrapper($this->pdo));
        $this->handler = new TwoFactorHandler(
            $this->pdo,
            $totp,
            $backupCodes,
            new TokenValidator($this->jwtParser, $this->pdo),
        );

        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))"
        );
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");

        // A user with 2FA explicitly DISABLED — the false-branch under test.
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, two_factor_enabled,
                two_factor_secret, two_factor_backup_codes_version, created_at, token_epoch)
             VALUES (?, ?, ?, ?, 1, false, NULL, 0, datetime('now'), 0)"
        );
        $stmt->execute([self::USER_ID, self::TENANT, self::EMAIL, password_hash('pw', PASSWORD_BCRYPT)]);

        // Lock the tenant context the handler's self-scoped writes expect.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        // Authenticate the caller.
        $_COOKIE['access_token'] = $this->jwtParser->create([
            'user_id'   => self::USER_ID,
            'tenant_id' => self::TENANT,
            'email'     => self::EMAIL,
            'role'      => 'admin',
        ], 3600, 'access');
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        TenantContext::reset();
    }

    /**
     * setup() for a 2FA-DISABLED user must PROCEED (200 + secret), not 400
     * "already enabled". On PG the naive (bool)"f"===true would wrongly 400.
     */
    public function testSetupProceedsForDisabledUser(): void
    {
        $response = $this->handler->setup(new Request('POST', '/api/auth/2fa/setup', []));
        self::assertSame(
            200,
            $response->getStatusCode(),
            'setup() must proceed for a user with 2FA disabled (must not 400 "already enabled").'
        );
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('secret', $body, 'setup() must return a TOTP secret.');
    }

    /**
     * status() must report enabled=false for a 2FA-disabled user. On PG the naive
     * (bool)"f" would report enabled=true for everyone.
     */
    public function testStatusReportsDisabled(): void
    {
        $response = $this->handler->status(new Request('GET', '/api/auth/2fa/status', []));
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertFalse(
            $body['enabled'],
            'status() must report enabled=false for a user with 2FA disabled.'
        );
    }

    /**
     * regenerateCodes() for a 2FA-DISABLED user must be REFUSED (400). On PG the
     * naive (bool)"f"===true would bypass the guard and let a user without 2FA
     * insert backup codes.
     */
    public function testRegenerateCodesRefusedForDisabledUser(): void
    {
        $response = $this->handler->regenerateCodes(
            new Request('POST', '/api/auth/2fa/regenerate-codes', [])
        );
        self::assertSame(
            400,
            $response->getStatusCode(),
            'regenerateCodes() must refuse a user with 2FA disabled.'
        );

        // And no backup codes may have been inserted.
        // backup_codes is keyed on profile_id after migration 038 (user_id removed);
        // in this isolated test the table must be empty — the guard refuses before
        // any INSERT is attempted.
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM backup_codes');
        self::assertSame(
            0,
            (int) ($stmt !== false ? $stmt->fetchColumn() : 0),
            'No backup codes may be inserted for a 2FA-disabled user.'
        );
    }
}
