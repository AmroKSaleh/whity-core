<?php

declare(strict_types=1);

namespace Tests\Integration;

use OTPHP\TOTP;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Request;

/**
 * WC-security-audit: TOTP codes must not be replayable.
 *
 * Before this fix, `AuthHandler::handle2fa()` validated a submitted TOTP code
 * via `TotpService::validateCode()`, a stateless check against the current
 * +/-1 step window. Because a TOTP code stays valid for its WHOLE ~30-second
 * period regardless of how many separate requests check it, presenting the
 * SAME valid code twice (e.g. a captured code replayed by an attacker while
 * it is still within its window) completed a SECOND, independent login —
 * the classic TOTP replay weakness RFC 6238 recommends guarding against.
 *
 * The fix threads a per-profile anti-replay floor
 * (`profiles.two_factor_last_used_step`, migration 080) through an atomic
 * `UPDATE ... WHERE two_factor_last_used_step IS NULL OR < :step` guard
 * (`AuthHandler::consumeTotpStep()`), mirroring the single-use burn
 * {@see \Whity\Auth\BackupCodesService::validateCode()} already uses for
 * backup codes.
 *
 * Runs on BOTH SQLite (default) and PostgreSQL (PHPUNIT_PG_DSN): the fix uses
 * only ANSI CURRENT_TIMESTAMP, so — unlike some 2FA real-engine tests — this
 * one is not gated to Postgres.
 */
final class TotpReplayRealEngineTest extends TestCase
{
    private const JWT_SECRET = 'wc-security-audit-totp-replay-test-secret-32b';
    private const TENANT     = 1;
    private const EMAIL      = 'totp-replay@corp.com';
    private const PROFILE_ID = 90001;

    private PDO $pdo;
    private JwtParser $jwtParser;
    private AuthHandler $handler;
    private string $plainSecret;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();

        $this->jwtParser = new JwtParser(self::JWT_SECRET);

        $totpService       = new TotpService('totp-replay-test-encryption-key-32-chars');
        $this->plainSecret = $totpService->generateSecret();
        $encryptedSecret   = $totpService->encryptSecret($this->plainSecret);

        $this->handler = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
            null,
            $totpService,
        );

        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->pdo->exec("INSERT INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', NOW()) ON CONFLICT (id) DO NOTHING");
            $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT (id) DO NOTHING");
        } else {
            $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))");
            $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");
        }

        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_secret, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (" . self::PROFILE_ID . ", 'test-profile', '" . password_hash('irrelevant', PASSWORD_BCRYPT) . "',
                true, '" . $encryptedSecret . "', 0, 0, {$now}, {$now})"
        );
        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, {$now})"
        )->execute([self::PROFILE_ID, self::EMAIL]);

        $memberships = new MembershipRepository($this->pdo);
        $memberships->insert(self::PROFILE_ID, self::TENANT, 1);
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    private function mintTempTokenAndRequest(string $code): Request
    {
        $_COOKIE['temp_auth_token'] = $this->jwtParser->create([
            'profile_id'       => self::PROFILE_ID,
            'active_tenant_id' => self::TENANT,
            'email'            => self::EMAIL,
        ], 300, 'temp');

        return new Request('POST', '/api/login/2fa', [], (string) json_encode(['code' => $code]));
    }

    /**
     * The failing case this fix closes: the SAME valid TOTP code must not
     * complete two independent logins.
     */
    public function testSameTotpCodeCannotBeUsedTwice(): void
    {
        $code = TOTP::create($this->plainSecret)->now();

        $first = $this->handler->handle2fa($this->mintTempTokenAndRequest($code));
        self::assertSame(200, $first->getStatusCode(), 'the first use of a valid code must complete login');

        // Same code, presented again (a captured/replayed code) — must be
        // rejected even though it is still within its validity window.
        $second = $this->handler->handle2fa($this->mintTempTokenAndRequest($code));
        self::assertSame(401, $second->getStatusCode(), 'a replayed TOTP code must be rejected');
    }

    /**
     * A FRESH code (the next time-step) must still authenticate after a
     * previous step was consumed — anti-replay must not lock the account out
     * of legitimate subsequent logins.
     */
    public function testNextTotpCodeStillWorksAfterPriorStepConsumed(): void
    {
        $totp  = TOTP::create($this->plainSecret);
        $step0 = intdiv(time(), $totp->getPeriod());
        $code0 = $totp->at($step0 * $totp->getPeriod());

        $first = $this->handler->handle2fa($this->mintTempTokenAndRequest($code0));
        self::assertSame(200, $first->getStatusCode());

        // A later time-step's code (simulates the next real login, some time
        // after the first) must still be accepted.
        $code1 = $totp->at(($step0 + 1) * $totp->getPeriod());
        $second = $this->handler->handle2fa($this->mintTempTokenAndRequest($code1));
        self::assertSame(200, $second->getStatusCode(), 'a later, unconsumed step must still authenticate');
    }

    /**
     * An invalid code must never advance or otherwise touch the anti-replay
     * floor (a wrong-code request should not affect subsequent valid ones).
     */
    public function testInvalidCodeDoesNotConsumeTheReplayFloor(): void
    {
        $bad = $this->handler->handle2fa($this->mintTempTokenAndRequest('000000'));
        self::assertSame(401, $bad->getStatusCode());

        $validCode = TOTP::create($this->plainSecret)->now();
        $good = $this->handler->handle2fa($this->mintTempTokenAndRequest($validCode));
        self::assertSame(200, $good->getStatusCode(), 'a genuinely valid code must still work after an invalid attempt');
    }
}
