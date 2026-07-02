<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\LoginThrottleService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * WC-0abcc29f: integration tests for AuthHandler brute-force protection.
 *
 * Verifies that the LoginThrottleService + DatabaseSharedStore integration
 * correctly returns 429 after the configured failure thresholds, and that
 * a successful login resets the per-account counter.
 */
final class AuthBruteForceTest extends TestCase
{
    private PDO $pdo;
    private AuthHandler $handler;

    private const JWT_SECRET       = 'test-secret-key-for-brute-force-integration-padded-32byte';
    private const VALID_PASSWORD    = 'correct-horse-battery-staple';
    private const WRONG_PASSWORD    = 'wrong-password';
    private const TEST_EMAIL        = 'throttle-test@example.com';
    private const TEST_USER_ID      = 10;
    private const TEST_TENANT_ID    = 1;
    private const TEST_ROLE_ID      = 1;
    private const TEST_IP           = '10.0.0.1';
    private const DIFFERENT_IP      = '10.0.0.2';

    protected function setUp(): void
    {
        $this->pdo = $this->buildSchema();

        $throttle      = new LoginThrottleService(new DatabaseSharedStore($this->pdo));
        $jwtParser     = new JwtParser(self::JWT_SECRET);
        $this->handler = new AuthHandler($this->pdo, $jwtParser, null, null, null, null, null, $throttle);
    }

    // ── per-user throttle on POST /api/login ─────────────────────────────────

    public function testLoginReturns401BeforeUserThreshold(): void
    {
        // 9 failures — not yet throttled
        for ($i = 0; $i < 9; $i++) {
            $r = $this->loginAttempt(self::WRONG_PASSWORD, self::TEST_IP);
            self::assertSame(401, $r->getStatusCode(), "Attempt {$i} should be 401");
        }
    }

    public function testLoginReturns429AfterUserThreshold(): void
    {
        // Drive 10 wrong-password failures to fill the per-user bucket
        for ($i = 0; $i < 10; $i++) {
            $this->loginAttempt(self::WRONG_PASSWORD, self::TEST_IP);
        }

        $r = $this->loginAttempt(self::VALID_PASSWORD, self::TEST_IP);
        self::assertSame(429, $r->getStatusCode());

        $body = json_decode($r->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
    }

    public function testSuccessfulLoginResetsUserCounter(): void
    {
        // Fill per-user bucket to 9 failures (one below threshold)
        for ($i = 0; $i < 9; $i++) {
            $this->loginAttempt(self::WRONG_PASSWORD, self::TEST_IP);
        }

        // Successful login — should reset the counter
        $r = $this->loginAttempt(self::VALID_PASSWORD, self::TEST_IP);
        self::assertSame(200, $r->getStatusCode());

        // Now 9 more failures should still be below the threshold (counter was reset)
        for ($i = 0; $i < 9; $i++) {
            $r = $this->loginAttempt(self::WRONG_PASSWORD, self::TEST_IP);
            self::assertSame(401, $r->getStatusCode(), "Post-reset attempt {$i} should be 401, not 429");
        }
    }

    // ── per-IP throttle on POST /api/login ───────────────────────────────────

    public function testLoginReturns429AfterIpThreshold(): void
    {
        // 20 failures from different non-existent emails — only IP counter accumulates
        for ($i = 0; $i < 20; $i++) {
            $r = $this->loginAttemptEmail("ghost{$i}@nowhere.test", self::WRONG_PASSWORD, self::TEST_IP);
            self::assertSame(401, $r->getStatusCode(), "Attempt {$i} should be 401");
        }

        // 21st attempt from same IP should be throttled even for valid credentials
        $r = $this->loginAttempt(self::VALID_PASSWORD, self::TEST_IP);
        self::assertSame(429, $r->getStatusCode());
    }

    public function testIpThrottleDoesNotAffectDifferentIp(): void
    {
        // Exhaust IP throttle for TEST_IP
        for ($i = 0; $i < 20; $i++) {
            $this->loginAttemptEmail("ghost{$i}@nowhere.test", self::WRONG_PASSWORD, self::TEST_IP);
        }

        // A request from a different IP should still succeed
        $r = $this->loginAttempt(self::VALID_PASSWORD, self::DIFFERENT_IP);
        self::assertSame(200, $r->getStatusCode());
    }

    // ── refresh endpoint throttle behaviour ──────────────────────────────────

    public function testRefreshFailuresDoNotFillIpCounter(): void
    {
        // 20 invalid-refresh attempts should NOT count toward the IP throttle.
        // Expired/missing cookies are normal UX noise, not attack signals.
        for ($i = 0; $i < 20; $i++) {
            $r = $this->refreshAttempt(self::TEST_IP);
            self::assertSame(401, $r->getStatusCode(), "Refresh attempt {$i} should be 401");
        }

        // Valid login from the same IP must still succeed (counter was not incremented).
        $r = $this->loginAttempt(self::VALID_PASSWORD, self::TEST_IP);
        self::assertSame(200, $r->getStatusCode(), 'Refresh failures must not fill the IP throttle counter');
    }

    public function testRefreshBlockedWhenIpAlreadyThrottledByLoginFailures(): void
    {
        // Fill the IP throttle via login failures (not refresh failures).
        for ($i = 0; $i < 20; $i++) {
            $this->loginAttemptEmail("ghost{$i}@nowhere.test", self::WRONG_PASSWORD, self::TEST_IP);
        }

        // Refresh from the same throttled IP should also be blocked.
        $r = $this->refreshAttempt(self::TEST_IP);
        self::assertSame(429, $r->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function loginAttempt(string $password, string $ip): Response
    {
        return $this->loginAttemptEmail(self::TEST_EMAIL, $password, $ip);
    }

    private function loginAttemptEmail(string $email, string $password, string $ip): Response
    {
        $request = new Request(
            'POST',
            '/api/login',
            [\Whity\Core\RateLimit\ClientIp::HEADER => $ip],
            (string) json_encode(['email' => $email, 'password' => $password])
        );

        return $this->handler->handle($request);
    }

    private function refreshAttempt(string $ip): Response
    {
        unset($_COOKIE['refresh_token']);

        $request = new Request(
            'POST',
            '/api/auth/refresh',
            [\Whity\Core\RateLimit\ClientIp::HEADER => $ip],
            ''
        );

        return $this->handler->handleRefresh($request);
    }

    private function buildSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Test', datetime('now'))");

        $stmt = $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, ?, datetime('now'), 0)"
        );
        $stmt->execute([
            self::TEST_USER_ID,
            self::TEST_TENANT_ID,
            self::TEST_EMAIL,
            password_hash(self::VALID_PASSWORD, PASSWORD_BCRYPT),
            self::TEST_ROLE_ID,
        ]);

        return $pdo;
    }
}
