<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use PDO;

/**
 * Security-critical tests for the body/bearer token mode (WC-ddcd16ad).
 *
 * Runs against a real PostgreSQL database when PHPUNIT_PG_DSN is set.
 * These tests focus on the security invariants:
 *
 *  1. A suspended membership is rejected even via the Bearer path.
 *  2. An epoch-bumped access Bearer is rejected (password-change invalidation).
 *  3. A revoked Bearer is rejected (jti check).
 *  4. A refresh token cannot be used as an access Bearer (type mismatch).
 *  5. CSRF: cookie + Bearer → CSRF check still required (no bypass via Bearer).
 *  6. CSRF: Bearer-only → CSRF exempt (non-browser client unblocked).
 *  7. Refresh token epoch-bumped in token mode → rejected.
 *  8. Switch-tenant in token mode returns tokens in body.
 */
class BearerTokenModeSecurityTest extends TestCase
{
    private JwtParser $jwtParser;
    private PDO $pdo;

    private const SECRET   = 'security-test-secret-for-bearer-token-mode-padding32b';
    private const PASSWORD = 'securepassword123!';
    private const EMAIL    = 'security-bearer@example.com';
    private const USER_ID  = 99;
    private const TENANT_ID = 7;
    private const ROLE_ID   = 1;
    private const ROLE_NAME = 'admin';

    // Second user for multi-membership tests.
    private const SWITCH_EMAIL   = 'switch-tenant-bearer@example.com';
    private const SWITCH_USER_ID = 100;
    private const TENANT_B_ID    = 8;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->pdo       = $this->makeSchema();
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Membership + revocation security gates on the Bearer path
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * A Bearer access token whose profile's membership is suspended is rejected.
     *
     * This validates the ActiveTenantMembershipGuard runs on the Bearer path
     * exactly as it does on the cookie path.
     */
    public function testSuspendedMembershipBearerIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        // Mint a new-claims token (profile_id + active_tenant_id) so the
        // membership guard actually runs.
        $token = $this->jwtParser->create([
            'profile_id'       => self::USER_ID,
            'active_tenant_id' => self::TENANT_ID,
            'user_id'          => self::USER_ID,
            'tenant_id'        => self::TENANT_ID,
            'email'            => self::EMAIL,
            'role'             => self::ROLE_NAME,
            'token_epoch'      => 0,
        ], 900, 'access');

        // Suspend the membership in the DB.
        $this->pdo->prepare(
            "UPDATE memberships SET status = 'suspended'
             WHERE profile_id = ? AND tenant_id = ?"
        )->execute([self::USER_ID, self::TENANT_ID]);

        $request  = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $token]);
        $response = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode(), 'Suspended membership must be rejected via Bearer');

        // Restore for other tests.
        $this->pdo->prepare(
            "UPDATE memberships SET status = 'active'
             WHERE profile_id = ? AND tenant_id = ?"
        )->execute([self::USER_ID, self::TENANT_ID]);
    }

    /**
     * A revoked access token is rejected via the Bearer path
     * (same jti-revocation check as the cookie path).
     */
    public function testRevokedBearerIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $token  = $this->mintAccess(0);
        $claims = $this->jwtParser->parse($token);
        $this->assertNotNull($claims);

        $this->revokeJti((string) $claims['jti'], (int) $claims['exp']);

        $request  = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $token]);
        $response = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode(), 'Revoked jti must be rejected via Bearer');
    }

    /**
     * An epoch-bumped Bearer access token is rejected — password-change
     * invalidation applies equally on the Bearer and cookie paths.
     */
    public function testEpochBumpedBearerIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $staleToken = $this->mintAccess(0);
        // Bump epoch.
        $this->pdo->prepare(
            'UPDATE users SET token_epoch = 1 WHERE id = ? AND tenant_id = ?'
        )->execute([self::USER_ID, self::TENANT_ID]);

        $request  = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $staleToken]);
        $response = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode(), 'Epoch-bumped Bearer must be rejected');

        // Reset.
        $this->pdo->prepare(
            'UPDATE users SET token_epoch = 0 WHERE id = ? AND tenant_id = ?'
        )->execute([self::USER_ID, self::TENANT_ID]);
    }

    /**
     * A refresh token presented as a Bearer access token is rejected (type mismatch).
     * An attacker who captures the refresh token cannot use it to access protected endpoints.
     */
    public function testRefreshTokenUsedAsBearerAccessIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $refreshToken = $this->mintRefresh(0);
        $request      = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $refreshToken]);
        $response     = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode(), 'Refresh token must not be accepted as access Bearer');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CSRF security invariants
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * A request with BOTH a cookie AND a Bearer header requires X-Requested-With.
     *
     * This is the critical CSRF invariant: the presence of an auth cookie makes
     * the request ambient, regardless of whether a Bearer header is also present.
     * An attacker cannot bypass the CSRF guard by injecting a Bearer header
     * into a cookie-bearing cross-site request.
     */
    public function testCookiePlusBearerStillRequiresXRequestedWith(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('POST', '/api/v1/users', [
            'Cookie'        => 'access_token=sometoken',
            'Authorization' => 'Bearer eyJvalid.looking.token',
        ]);

        $passed   = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertFalse($passed, 'Cookie+Bearer must not bypass CsrfGuard');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * A Bearer-only POST (no cookie) is exempt from CSRF check.
     *
     * A cross-site attacker cannot set the Authorization header without a CORS
     * preflight (blocked by our strict origin allowlist), so there is no CSRF
     * risk for Bearer-only requests.
     */
    public function testBearerOnlyPostIsExemptFromCsrf(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('DELETE', '/api/v1/users/123', [
            'Authorization' => 'Bearer eyJvalid.looking.token',
        ]);

        $passed   = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertTrue($passed, 'Bearer-only request must pass CsrfGuard without X-Requested-With');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A cookie-bearing POST without X-Requested-With is still blocked (CSRF guard intact).
     */
    public function testCookieBearingPostWithoutXRequestedWithIsBlocked(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('POST', '/api/v1/users', [
            'Cookie' => 'access_token=sometoken',
        ]);

        $passed   = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertFalse($passed, 'Cookie-bearing POST without X-Requested-With must be blocked');
        $this->assertSame(403, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Refresh security in token mode
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * An epoch-bumped refresh token is rejected in token mode via the body field.
     */
    public function testEpochBumpedRefreshInTokenModeRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $staleRefresh = $this->mintRefresh(0);
        $this->pdo->prepare(
            'UPDATE users SET token_epoch = 1 WHERE id = ? AND tenant_id = ?'
        )->execute([self::USER_ID, self::TENANT_ID]);

        $body    = json_encode(['refresh_token' => $staleRefresh]);
        $request = new Request('POST', '/api/auth/refresh', ['X-Auth-Mode' => 'token'], (string) $body);

        $response = $handler->handleRefresh($request);
        $this->assertSame(401, $response->getStatusCode(), 'Epoch-bumped refresh must be rejected in token mode');

        $this->pdo->prepare(
            'UPDATE users SET token_epoch = 0 WHERE id = ? AND tenant_id = ?'
        )->execute([self::USER_ID, self::TENANT_ID]);
    }

    /**
     * A revoked refresh token is rejected in token mode (jti check applies).
     */
    public function testRevokedRefreshInTokenModeRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $refreshToken = $this->mintRefresh(0);
        $claims       = $this->jwtParser->parse($refreshToken);
        $this->assertNotNull($claims);
        $this->revokeJti((string) $claims['jti'], (int) $claims['exp']);

        $body    = json_encode(['refresh_token' => $refreshToken]);
        $request = new Request('POST', '/api/auth/refresh', ['X-Auth-Mode' => 'token'], (string) $body);

        $response = $handler->handleRefresh($request);
        $this->assertSame(401, $response->getStatusCode(), 'Revoked refresh must be rejected in token mode');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Switch-tenant in token mode
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Switch-tenant in token mode: Bearer access + X-Auth-Mode: token returns
     * tokens in body (not cookies).
     */
    public function testSwitchTenantInTokenModeReturnsTokensInBody(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        // Mint an access token with new claims for the switch-tenant user in Tenant B.
        $accessToken = $this->jwtParser->create([
            'profile_id'       => self::SWITCH_USER_ID,
            'active_tenant_id' => self::TENANT_B_ID,
            'user_id'          => self::SWITCH_USER_ID,
            'tenant_id'        => self::TENANT_B_ID,
            'email'            => self::SWITCH_EMAIL,
            'role'             => self::ROLE_NAME,
            'token_epoch'      => 0,
        ], 900, 'access');

        $body    = json_encode(['tenant_id' => self::TENANT_ID]);
        $request = new Request('POST', '/api/auth/switch-tenant', [
            'Authorization' => 'Bearer ' . $accessToken,
            'X-Auth-Mode'   => 'token',
        ], (string) $body);

        $response = $handler->handleSwitchTenant($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('access_token', $data, 'Switch-tenant token mode must return access_token in body');
        $this->assertArrayHasKey('refresh_token', $data, 'Switch-tenant token mode must return refresh_token in body');
        $this->assertSame('Bearer', $data['token_type'] ?? null);

        // Verify the new token is for the new tenant.
        $ac = $this->jwtParser->parse($data['access_token']);
        $this->assertSame(self::TENANT_ID, $ac['active_tenant_id'] ?? null);
    }

    /**
     * Switch-tenant to a tenant the profile is NOT a member of is rejected (403),
     * same as the cookie path.
     */
    public function testSwitchTenantToUnauthorizedTenantIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $accessToken = $this->jwtParser->create([
            'profile_id'       => self::SWITCH_USER_ID,
            'active_tenant_id' => self::TENANT_B_ID,
            'user_id'          => self::SWITCH_USER_ID,
            'tenant_id'        => self::TENANT_B_ID,
            'email'            => self::SWITCH_EMAIL,
            'role'             => self::ROLE_NAME,
            'token_epoch'      => 0,
        ], 900, 'access');

        // Try to switch to a tenant the profile does NOT belong to.
        $body    = json_encode(['tenant_id' => 999]);
        $request = new Request('POST', '/api/auth/switch-tenant', [
            'Authorization' => 'Bearer ' . $accessToken,
            'X-Auth-Mode'   => 'token',
        ], (string) $body);

        $response = $handler->handleSwitchTenant($request);
        $this->assertSame(403, $response->getStatusCode(), 'Switch to unauthorized tenant must be 403');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Logout revocation in token mode (WC-ddcd16ad BLOCKER 2)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Logout in token mode revokes the access token from Authorization: Bearer
     * AND the refresh token from the body — the revocation contract must hold
     * for token-mode clients that keep tokens in memory (no cookies). After
     * logout the access token must be rejected on a protected endpoint and the
     * refresh token must be rejected on refresh.
     */
    public function testTokenModeLogoutRevokesBearerAccessAndBodyRefresh(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $accessToken  = $this->mintAccess(0);
        $refreshToken = $this->mintRefresh(0);
        $accessJti    = $this->jtiOf($accessToken);
        $refreshJti   = $this->jtiOf($refreshToken);

        // Logout with access via Bearer, refresh in body — NO cookies at all.
        $logoutReq = new Request('POST', '/api/auth/logout', [
            'Authorization' => 'Bearer ' . $accessToken,
        ], (string) json_encode(['refresh_token' => $refreshToken]));

        $logoutResp = $handler->handleLogout($logoutReq);
        $this->assertSame(200, $logoutResp->getStatusCode());

        // Both jtis are revoked in the global table.
        $this->assertTrue($this->isRevoked($accessJti), 'access jti must be revoked');
        $this->assertTrue($this->isRevoked($refreshJti), 'refresh jti must be revoked');

        // Revoked access Bearer no longer authenticates a protected endpoint.
        $meResp = $handler->handleMe(
            new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $accessToken])
        );
        $this->assertSame(401, $meResp->getStatusCode(), 'Revoked access Bearer must be 401 after logout');

        // Revoked refresh no longer mints a new access token.
        $refreshResp = $handler->handleRefresh(
            new Request('POST', '/api/auth/refresh', ['X-Auth-Mode' => 'token'],
                (string) json_encode(['refresh_token' => $refreshToken]))
        );
        $this->assertSame(401, $refreshResp->getStatusCode(), 'Revoked refresh must be 401 after logout');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function isRevoked(string $jti): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
        $stmt->execute([$jti]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Parse a token and return its jti claim as a string (asserting validity).
     */
    private function jtiOf(string $token): string
    {
        $claims = $this->jwtParser->parse($token);
        $this->assertIsArray($claims, 'Token must be a valid JWT');
        $this->assertArrayHasKey('jti', $claims);
        return (string) $claims['jti'];
    }

    private function mintAccess(int $epoch): string
    {
        return $this->jwtParser->create([
            'user_id'     => self::USER_ID,
            'tenant_id'   => self::TENANT_ID,
            'email'       => self::EMAIL,
            'role'        => self::ROLE_NAME,
            'token_epoch' => $epoch,
        ], 900, 'access');
    }

    private function mintRefresh(int $epoch): string
    {
        return $this->jwtParser->create([
            'user_id'     => self::USER_ID,
            'tenant_id'   => self::TENANT_ID,
            'email'       => self::EMAIL,
            'role'        => self::ROLE_NAME,
            'token_epoch' => $epoch,
        ], 604800, 'refresh');
    }

    private function revokeJti(string $jti, int $exp): void
    {
        $this->pdo->prepare(
            'INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?) ON CONFLICT (jti) DO NOTHING'
        )->execute([$jti, date('Y-m-d H:i:s', $exp)]);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (7, 'Security Tenant', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (8, 'Security Tenant B', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles   (id, name) VALUES (1, 'admin')");

        // Primary test user.
        $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, ?, datetime('now'), 0)"
        )->execute([self::USER_ID, self::TENANT_ID, self::EMAIL, password_hash(self::PASSWORD, PASSWORD_BCRYPT), self::ROLE_ID]);

        $pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([self::USER_ID, 'secbearer', password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);

        $pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([self::USER_ID, self::EMAIL]);

        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::USER_ID, self::TENANT_ID, self::ROLE_ID]);

        // Switch-tenant user — member of BOTH Tenant B and Tenant 7.
        $pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([self::SWITCH_USER_ID, 'switchbearer', password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);

        $pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([self::SWITCH_USER_ID, self::SWITCH_EMAIL]);

        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::SWITCH_USER_ID, self::TENANT_B_ID, self::ROLE_ID]);

        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::SWITCH_USER_ID, self::TENANT_ID, self::ROLE_ID]);

        // Legacy users row for the switch-tenant user (dual window).
        $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, ?, datetime('now'), 0)"
        )->execute([self::SWITCH_USER_ID, self::TENANT_B_ID, self::SWITCH_EMAIL, password_hash(self::PASSWORD, PASSWORD_BCRYPT), self::ROLE_ID]);

        return $pdo;
    }
}
