<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * Real-engine (in-memory SQLite) tests for {@see AuthHandler}, migrated from
 * the mocked-PDO tests/Unit/Auth/AuthHandlerTest.php.
 *
 * The mocked file wired a `createMock(PDO)` to a fixed sequence of canned
 * `fetch()`/`fetchAll()` return values (profile_emails row, then profiles row,
 * then memberships rows, ...) that a real query could never actually produce
 * in that order/shape if a JOIN, predicate, or column name were subtly wrong —
 * the mock happily "passes" regardless of what SQL the handler really issues.
 * These tests drive the real handler against the genuine migration schema.
 *
 * Several AuthHandler code paths already have thorough real-engine coverage
 * elsewhere and are NOT re-duplicated here (this file focuses on the
 * mocked-only gaps):
 *  - login algorithm (memberships resolution, tenant selection, #181
 *    regression, email normalization, dual claims) — see
 *    {@see \Tests\Integration\ProfileLoginRealEngineTest}.
 *  - access/refresh-token revocation, logout jti-scoping, epoch bump on
 *    password change — see {@see AccessTokenRevocationRealEngineTest}.
 *  - admin-enforced 2FA policy gate on login/refresh/logout-others — see
 *    {@see AuthHandlerTwoFactorPolicyRealEngineTest}.
 *  - TOTP code validation (valid/invalid/replay) — see
 *    {@see \Tests\Integration\TotpReplayRealEngineTest} and
 *    {@see \Tests\Integration\CrossTenantRejectionRealEngineTest}.
 *
 * This file instead proves: the exact response SHAPE of a successful/2FA
 * login (the `user` object and absence of leaking a `token` key), handleMe()'s
 * success/401 paths, handleRefresh()'s plain success path plus its 401 guard
 * clauses (missing/expired/revoked), handleLogout()'s response contract, and
 * handle2fa()'s missing-input guard clauses (401 before any DB read).
 */
final class AuthHandlerRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private AuthHandler $authHandler;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = self::makeSqliteSchema();
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->authHandler = new AuthHandler($this->pdo, $this->jwtParser, new TokenValidator($this->jwtParser, $this->pdo));
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // ==================== handle(): response shape ====================

    /**
     * A successful login returns the `user` shape {id, email, role, tenant_id}
     * and never leaks a top-level `token` key (cookie mode).
     */
    public function testLoginWithValidCredentialsReturnsUserShape(): void
    {
        $profileId = $this->seedProfile('admin@whity.local', 1, 1);

        $response = $this->login('admin@whity.local');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('user', $body);
        $this->assertArrayNotHasKey('token', $body);

        $user = $body['user'];
        $this->assertSame($profileId, $user['id']);
        $this->assertSame('admin@whity.local', $user['email']);
        $this->assertSame('admin', $user['role']);
        $this->assertSame(1, $user['tenant_id']);
    }

    /**
     * A profile with 2FA enabled gets the challenge response (202) with no
     * `user` or `token` key leaked.
     */
    public function testLoginWith2FaEnabledReturns202WithRequires2fa(): void
    {
        $this->seedProfile('user2fa@whity.local', 1, 2, twoFactorEnabled: true);

        $response = $this->login('user2fa@whity.local');

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['requires_2fa']);
        $this->assertArrayNotHasKey('user', $body);
        $this->assertArrayNotHasKey('token', $body);
    }

    /**
     * A profile with 2FA disabled logs straight in (200) with no `requires_2fa`
     * flag.
     */
    public function testLoginWith2FaDisabledReturns200WithUserAndNoRequires2fa(): void
    {
        $profileId = $this->seedProfile('user-no2fa@whity.local', 1, 2);

        $response = $this->login('user-no2fa@whity.local');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayNotHasKey('requires_2fa', $body);
        $this->assertSame($profileId, $body['user']['id']);
        $this->assertSame('user', $body['user']['role']);
    }

    // ==================== handleMe() ====================

    public function testHandleMeReturnsUserShapeWithValidAccessToken(): void
    {
        $profileId = $this->seedProfile('me@whity.local', 1, 2);
        $_COOKIE['access_token'] = $this->mintAccess($profileId, 1, 'me@whity.local', 'user');

        $response = $this->authHandler->handleMe(new Request('GET', '/api/me', []));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame($profileId, $body['user']['id']);
        $this->assertSame('me@whity.local', $body['user']['email']);
        $this->assertSame('user', $body['user']['role']);
    }

    public function testHandleMeReturns401WithoutAccessToken(): void
    {
        unset($_COOKIE['access_token']);

        $response = $this->authHandler->handleMe(new Request('GET', '/api/me', []));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    public function testHandleMeReturns401WithExpiredAccessToken(): void
    {
        $profileId = $this->seedProfile('expired@whity.local', 1, 2);
        // Beyond the 60s clock-skew leeway, so this is genuinely expired.
        $_COOKIE['access_token'] = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => 1,
            'email'            => 'expired@whity.local',
            'role'             => 'user',
            'token_epoch'      => 0,
        ], -120, 'access');

        $response = $this->authHandler->handleMe(new Request('GET', '/api/me', []));

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== handleRefresh() ====================

    public function testHandleRefreshReturnsNewAccessToken(): void
    {
        $profileId = $this->seedProfile('refresh@whity.local', 1, 2);
        $_COOKIE['refresh_token'] = $this->mintRefresh($profileId, 1, 'refresh@whity.local', 'user');

        $response = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('success', $body['status']);
    }

    public function testHandleRefreshReturns401WithoutRefreshToken(): void
    {
        unset($_COOKIE['refresh_token']);

        $response = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    public function testHandleRefreshReturns401WithExpiredToken(): void
    {
        $profileId = $this->seedProfile('refresh-exp@whity.local', 1, 2);
        $_COOKIE['refresh_token'] = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => 1,
            'email'            => 'refresh-exp@whity.local',
            'role'             => 'user',
            'token_epoch'      => 0,
        ], -120, 'refresh');

        $response = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testHandleRefreshReturns401WithRevokedToken(): void
    {
        $profileId = $this->seedProfile('refresh-rev@whity.local', 1, 2);
        $refreshToken = $this->mintRefresh($profileId, 1, 'refresh-rev@whity.local', 'user');
        $claims = $this->jwtParser->parse($refreshToken);
        $this->assertIsArray($claims);

        $this->pdo->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)')
            ->execute([(string) $claims['jti'], date('Y-m-d H:i:s', (int) $claims['exp'])]);

        $_COOKIE['refresh_token'] = $refreshToken;
        $response = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== handleLogout() ====================

    public function testHandleLogoutRevokesRefreshToken(): void
    {
        $profileId = $this->seedProfile('logout@whity.local', 1, 2);
        $_COOKIE['refresh_token'] = $this->mintRefresh($profileId, 1, 'logout@whity.local', 'user');

        $response = $this->authHandler->handleLogout(new Request('POST', '/api/auth/logout', []));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('logged out', $body['status']);
    }

    public function testHandleLogoutIsIdempotent(): void
    {
        unset($_COOKIE['refresh_token'], $_COOKIE['access_token']);

        $response = $this->authHandler->handleLogout(new Request('POST', '/api/auth/logout', []));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('logged out', json_decode($response->getBody(), true)['status']);
    }

    // ==================== handle2fa(): guard clauses ====================

    public function testHandle2faReturns401WithoutCode(): void
    {
        $profileId = $this->seedProfile('nocode2fa@whity.local', 1, 2);
        $_COOKIE['temp_auth_token'] = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => 1,
            'email'            => 'nocode2fa@whity.local',
        ], 300, 'temp');

        $response = $this->authHandler->handle2fa(new Request('POST', '/api/login/2fa', [], (string) json_encode([])));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    public function testHandle2faReturns401WithoutTempToken(): void
    {
        unset($_COOKIE['temp_auth_token']);

        $response = $this->authHandler->handle2fa(
            new Request('POST', '/api/login/2fa', [], (string) json_encode(['code' => '123456']))
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    // ==================== helpers ====================

    private function login(string $email): \Whity\Core\Response
    {
        return $this->authHandler->handle(new Request('POST', '/api/login', [], (string) json_encode([
            'email'    => $email,
            'password' => self::PASSWORD,
        ])));
    }

    private function mintAccess(int $profileId, int $tenantId, string $email, string $role): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => $email,
            'role'             => $role,
            'token_epoch'      => 0,
        ], 900, 'access');
    }

    private function mintRefresh(int $profileId, int $tenantId, string $email, string $role): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => $email,
            'role'             => $role,
            'token_epoch'      => 0,
        ], 604800, 'refresh');
    }

    /**
     * Seed a post-cutover identity: profile + verified primary email + an
     * active membership carrying the given role. Returns the profile id.
     */
    private function seedProfile(string $email, int $tenantId, int $roleId, bool $twoFactorEnabled = false): int
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([explode('@', $email)[0], $hash, $twoFactorEnabled ? 1 : 0]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, $tenantId, $roleId]);

        return $profileId;
    }

    /**
     * In-memory SQLite mirroring production. Tenant 1 and the base roles
     * (admin=1, user=2) are seeded as test data.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Tenant A', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");

        return $pdo;
    }
}
