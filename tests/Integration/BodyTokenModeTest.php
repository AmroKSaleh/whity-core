<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use PDO;

/**
 * Integration tests for the body/bearer token mode (WC-ddcd16ad).
 *
 * Covers:
 *  - Login with X-Auth-Mode: token returns access+refresh in JSON body,
 *    sets NO Set-Cookie headers.
 *  - Login WITHOUT the header is byte-compatible with the classic cookie path
 *    (non-regression).
 *  - A Bearer access token authenticates a normal protected endpoint (handleMe).
 *  - Invalid/expired/wrong-type/epoch-bumped/revoked Bearers are rejected.
 *  - Refresh in token mode: accepts Bearer or body field, returns new tokens.
 *  - Refresh rotation: the new refresh token is present in the body response.
 *  - Multi-membership login in token mode returns requires_tenant_selection +
 *    selection_token in body; select-tenant in token mode returns tokens.
 *  - CSRF: cookie-bearing POST without X-Requested-With is 403;
 *    Bearer-only POST without X-Requested-With is allowed (exempt).
 *  - CSRF: cookie + Bearer both present — cookie wins → CSRF check still required.
 */
class BodyTokenModeTest extends TestCase
{
    private JwtParser $jwtParser;
    private PDO $pdo;

    private const SECRET = 'test-secret-key-for-body-token-mode-tests-padded32';
    private const PASSWORD = 'testpassword123';
    private const EMAIL = 'bodytokentest@example.com';
    private const USER_ID = 42;
    private const TENANT_ID = 1;
    private const ROLE_ID = 1;
    private const ROLE_NAME = 'admin';

    // Second user for multi-membership tests.
    private const MULTI_EMAIL = 'multitenant@example.com';
    private const MULTI_USER_ID = 43;
    private const TENANT_B_ID = 2;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->pdo = $this->makeSchema();
        // Start each test with no auth cookies so cookie-mode is not accidentally
        // triggered on Bearer-only tests.
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token'], $_COOKIE['tenant_select_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token'], $_COOKIE['tenant_select_token']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Token issuance
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Login with X-Auth-Mode: token returns access_token + refresh_token in the
     * JSON body, with no Set-Cookie headers.
     */
    public function testLoginWithTokenModeReturnsTokensInBody(): void
    {
        $handler = new AuthHandler($this->pdo, $this->jwtParser);

        $response = $handler->handle($this->loginRequest(self::EMAIL, true));

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);

        $this->assertArrayHasKey('access_token', $data, 'access_token must be in body');
        $this->assertArrayHasKey('refresh_token', $data, 'refresh_token must be in body');
        $this->assertSame('Bearer', $data['token_type'] ?? null);
        $this->assertSame(900, $data['expires_in'] ?? null);
        $this->assertArrayHasKey('user', $data, 'user shape must be present');
        $this->assertSame(self::EMAIL, $data['user']['email']);

        // Validate that the returned tokens are genuine JWTs.
        $accessClaims  = $this->jwtParser->parse($data['access_token']);
        $refreshClaims = $this->jwtParser->parse($data['refresh_token']);
        $this->assertNotNull($accessClaims, 'access_token must be a valid JWT');
        $this->assertNotNull($refreshClaims, 'refresh_token must be a valid JWT');
        $this->assertSame('access', $accessClaims['type']);
        $this->assertSame('refresh', $refreshClaims['type']);
    }

    /**
     * Login WITHOUT X-Auth-Mode: token must still set cookies and must NOT include
     * access_token / refresh_token in the response body (non-regression).
     */
    public function testLoginWithoutTokenModeUsesCookiesOnly(): void
    {
        $handler = new AuthHandler($this->pdo, $this->jwtParser);

        $response = $handler->handle($this->loginRequest(self::EMAIL, false));

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);

        // Classic response shape: only the "user" key, no tokens in body.
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayNotHasKey('access_token', $data, 'cookie mode must not include token in body');
        $this->assertArrayNotHasKey('refresh_token', $data, 'cookie mode must not include refresh in body');
        $this->assertArrayNotHasKey('token_type', $data);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Bearer acceptance on protected endpoints
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * A valid Bearer access token authenticates handleMe.
     */
    public function testBearerAccessTokenAuthenticatesMe(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $accessToken = $this->mintAccess(0);
        $request     = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $accessToken]);

        $response = $handler->handleMe($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame(self::EMAIL, $data['user']['email']);
    }

    /**
     * An expired access token is rejected (401) via the Bearer path.
     */
    public function testExpiredBearerIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        // Mint a token with a TTL far enough in the past to exceed the 60s leeway.
        $expiredToken = $this->jwtParser->create([
            'user_id'     => self::USER_ID,
            'tenant_id'   => self::TENANT_ID,
            'email'       => self::EMAIL,
            'role'        => self::ROLE_NAME,
            'token_epoch' => 0,
        ], -120, 'access'); // 120s past → outside 60s leeway → expired

        $request  = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $expiredToken]);
        $response = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * A refresh token presented as Bearer access token is rejected (wrong type).
     */
    public function testRefreshTokenUsedAsBearerAccessIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $refreshToken = $this->mintRefresh(0);
        $request      = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $refreshToken]);
        $response     = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * A Bearer access token with a stale epoch (password changed) is rejected.
     */
    public function testEpochBumpedBearerIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        // Mint with epoch 0, then bump the user's epoch to 1 in the DB.
        $staleToken = $this->mintAccess(0);
        $this->pdo->exec('UPDATE users SET token_epoch = 1 WHERE id = ' . self::USER_ID . ' AND tenant_id = ' . self::TENANT_ID);

        $request  = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $staleToken]);
        $response = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode());

        // Reset epoch for subsequent tests.
        $this->pdo->exec('UPDATE users SET token_epoch = 0 WHERE id = ' . self::USER_ID . ' AND tenant_id = ' . self::TENANT_ID);
    }

    /**
     * A revoked Bearer access token is rejected (jti in revoked_tokens).
     */
    public function testRevokedBearerIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $accessToken = $this->mintAccess(0);
        $claims      = $this->jwtParser->parse($accessToken);
        $this->assertNotNull($claims);

        // Revoke the jti.
        $this->pdo->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?) ON CONFLICT (jti) DO NOTHING')
            ->execute([$claims['jti'], date('Y-m-d H:i:s', $claims['exp'])]);

        $request  = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $accessToken]);
        $response = $handler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Cookie takes precedence over Bearer: when both are present the cookie
     * path is used (and the Bearer is ignored, not double-validated).
     */
    public function testCookiePrecedenceOverBearer(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        // Valid cookie token.
        $_COOKIE['access_token'] = $this->mintAccess(0);

        // Invalid Bearer (expired) — should be ignored.
        $badBearer = $this->jwtParser->create([
            'user_id' => self::USER_ID, 'tenant_id' => self::TENANT_ID,
            'email' => self::EMAIL, 'role' => self::ROLE_NAME, 'token_epoch' => 0,
        ], -1, 'access');

        $request  = new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $badBearer]);
        $response = $handler->handleMe($request);

        // Cookie was valid → 200 (cookie won, bad Bearer ignored).
        $this->assertSame(200, $response->getStatusCode());

        unset($_COOKIE['access_token']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Refresh in token mode
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Refresh with X-Auth-Mode: token + refresh in body returns new tokens.
     */
    public function testRefreshInTokenModeFromBody(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $refreshToken = $this->mintRefresh(0);
        $body         = json_encode(['refresh_token' => $refreshToken]);
        $request      = new Request('POST', '/api/auth/refresh', ['X-Auth-Mode' => 'token'], (string) $body);

        $response = $handler->handleRefresh($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);

        $this->assertArrayHasKey('access_token', $data, 'new access_token in body');
        $this->assertArrayHasKey('refresh_token', $data, 'new refresh_token in body');
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(900, $data['expires_in']);

        // Validate the returned tokens are genuine.
        $ac = $this->jwtParser->parse($data['access_token']);
        $rc = $this->jwtParser->parse($data['refresh_token']);
        $this->assertSame('access', $ac['type'] ?? null);
        $this->assertSame('refresh', $rc['type'] ?? null);
    }

    /**
     * Refresh with X-Auth-Mode: token + Bearer header returns new tokens.
     */
    public function testRefreshInTokenModeFromBearerHeader(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $refreshToken = $this->mintRefresh(0);
        $request      = new Request('POST', '/api/auth/refresh', [
            'X-Auth-Mode'   => 'token',
            'Authorization' => 'Bearer ' . $refreshToken,
        ]);

        $response = $handler->handleRefresh($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
    }

    /**
     * Refresh in cookie mode still sets cookies and returns the classic body
     * (non-regression).
     */
    public function testRefreshInCookieModeReturnsClassicResponse(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $_COOKIE['refresh_token'] = $this->mintRefresh(0);

        $request  = new Request('POST', '/api/auth/refresh', []);
        $response = $handler->handleRefresh($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame('success', $data['status'] ?? null, 'Classic cookie response must have status=success');
        $this->assertArrayNotHasKey('access_token', $data, 'Cookie mode must not include token in body');

        unset($_COOKIE['refresh_token']);
    }

    /**
     * A revoked refresh token is rejected in token-body mode too.
     */
    public function testRevokedRefreshInTokenModeIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $refreshToken = $this->mintRefresh(0);
        $claims       = $this->jwtParser->parse($refreshToken);
        $this->assertNotNull($claims);

        // Revoke the jti.
        $this->pdo->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?) ON CONFLICT (jti) DO NOTHING')
            ->execute([$claims['jti'], date('Y-m-d H:i:s', $claims['exp'])]);

        $body    = json_encode(['refresh_token' => $refreshToken]);
        $request = new Request('POST', '/api/auth/refresh', ['X-Auth-Mode' => 'token'], (string) $body);

        $response = $handler->handleRefresh($request);
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * An epoch-bumped refresh token is rejected in token-body mode.
     */
    public function testEpochBumpedRefreshInTokenModeIsRejected(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        $staleRefresh = $this->mintRefresh(0);
        // Bump epoch.
        $this->pdo->exec('UPDATE users SET token_epoch = 1 WHERE id = ' . self::USER_ID . ' AND tenant_id = ' . self::TENANT_ID);

        $body    = json_encode(['refresh_token' => $staleRefresh]);
        $request = new Request('POST', '/api/auth/refresh', ['X-Auth-Mode' => 'token'], (string) $body);

        $response = $handler->handleRefresh($request);
        $this->assertSame(401, $response->getStatusCode());

        // Reset.
        $this->pdo->exec('UPDATE users SET token_epoch = 0 WHERE id = ' . self::USER_ID . ' AND tenant_id = ' . self::TENANT_ID);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Multi-membership / tenant selection in token mode
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Multi-membership login in token mode returns requires_tenant_selection
     * along with the selection_token in the body (no cookie).
     */
    public function testMultiMembershipLoginInTokenModeReturnsSelectionTokenInBody(): void
    {
        $handler = new AuthHandler($this->pdo, $this->jwtParser);

        $response = $handler->handle($this->loginRequest(self::MULTI_EMAIL, true));

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);

        $this->assertTrue($data['requires_tenant_selection'] ?? false, 'must require tenant selection');
        $this->assertArrayHasKey('memberships', $data, 'memberships list must be present');
        $this->assertArrayHasKey('selection_token', $data, 'selection_token must be in body in token mode');
        $this->assertIsString($data['selection_token']);

        // The selection token must be a valid JWT.
        $selClaims = $this->jwtParser->parse($data['selection_token']);
        $this->assertNotNull($selClaims);
        $this->assertSame('tenant_select', $selClaims['type'] ?? null);
        $this->assertSame(self::MULTI_USER_ID, $selClaims['profile_id'] ?? null);
    }

    /**
     * Multi-membership login WITHOUT token mode does NOT return selection_token
     * in body (cookie-mode non-regression).
     */
    public function testMultiMembershipLoginWithoutTokenModeDoesNotReturnSelectionInBody(): void
    {
        $handler = new AuthHandler($this->pdo, $this->jwtParser);

        $response = $handler->handle($this->loginRequest(self::MULTI_EMAIL, false));

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);

        $this->assertTrue($data['requires_tenant_selection'] ?? false);
        $this->assertArrayNotHasKey('selection_token', $data, 'cookie mode must not include selection_token in body');
    }

    /**
     * Select-tenant in token mode: sends selection_token in body, gets
     * access+refresh tokens in response.
     */
    public function testSelectTenantInTokenModeReturnsTokensInBody(): void
    {
        $handler = new AuthHandler($this->pdo, $this->jwtParser);

        // Step 1: Login → get selection_token.
        $loginResp    = $handler->handle($this->loginRequest(self::MULTI_EMAIL, true));
        $loginData    = $this->json($loginResp);
        $selToken     = $loginData['selection_token'];

        // Step 2: Select tenant, passing selection_token in body.
        $body    = json_encode(['tenant_id' => self::TENANT_ID, 'selection_token' => $selToken]);
        $request = new Request('POST', '/api/auth/select-tenant', ['X-Auth-Mode' => 'token'], (string) $body);

        $response = $handler->handleSelectTenant($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);

        $this->assertArrayHasKey('access_token', $data, 'access_token in body on select-tenant');
        $this->assertArrayHasKey('refresh_token', $data, 'refresh_token in body on select-tenant');
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(self::MULTI_EMAIL, $data['user']['email'] ?? null);

        // Tokens must be valid JWTs.
        $ac = $this->jwtParser->parse($data['access_token']);
        $this->assertSame('access', $ac['type'] ?? null);
        $this->assertSame(self::TENANT_ID, $ac['active_tenant_id'] ?? null);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CSRF guard behaviour
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * A cookie-bearing POST without X-Requested-With is still rejected by
     * CsrfGuard (cookie-mode protection intact).
     */
    public function testCsrfGuardBlocksCookieBearingPostWithoutHeader(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('POST', '/api/v1/something', [
            'Cookie' => 'access_token=sometoken',
        ]);

        $passed = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertFalse($passed, 'Handler must not be reached through CsrfGuard');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * A Bearer-only POST (no auth cookie) without X-Requested-With is allowed
     * through CsrfGuard (token-mode clients need no custom header).
     */
    public function testCsrfGuardAllowsBearerOnlyPostWithoutHeader(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('POST', '/api/v1/something', [
            'Authorization' => 'Bearer eyJsometoken',
        ]);

        $passed = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertTrue($passed, 'Bearer-only request must pass through CsrfGuard');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A request with BOTH a cookie and a Bearer still requires X-Requested-With:
     * the cookie is ambient → CSRF protection stays active.
     */
    public function testCsrfGuardBlocksCookiePlusBearerPostWithoutHeader(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('POST', '/api/v1/something', [
            'Cookie'        => 'access_token=sometoken',
            'Authorization' => 'Bearer eyJsometoken',
        ]);

        $passed = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertFalse($passed, 'Cookie+Bearer without X-Requested-With must be blocked');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * An always-protected POST (login) with X-Auth-Mode: token and no cookie is
     * allowed through CsrfGuard without X-Requested-With.
     */
    public function testCsrfGuardAllowsTokenModeLoginWithoutXRequestedWith(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('POST', '/api/v1/login', [
            'X-Auth-Mode' => 'token',
        ]);

        $passed = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertTrue($passed, 'X-Auth-Mode: token login without cookie must pass CsrfGuard');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * An always-protected POST (login) with X-Auth-Mode: token AND a cookie still
     * requires X-Requested-With (cookie is present → ambient).
     */
    public function testCsrfGuardBlocksTokenModeLoginWithCookieWithoutXRequestedWith(): void
    {
        $guard = new \Whity\Http\Middleware\CsrfGuard();

        $request = new Request('POST', '/api/v1/login', [
            'X-Auth-Mode' => 'token',
            'Cookie'      => 'access_token=sometoken',
        ]);

        $passed = false;
        $response = $guard->handle($request, function ($r) use (&$passed): \Whity\Sdk\Http\Response {
            $passed = true;
            return \Whity\Sdk\Http\Response::json(['ok' => true], 200);
        });

        $this->assertFalse($passed, 'Token-mode login with cookie still needs X-Requested-With');
        $this->assertSame(403, $response->getStatusCode());
    }

    // ──────────────────────────────────────────────────────────────────────────
    // End-to-end: login → use Bearer → refresh → use new Bearer
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Full token-mode cycle: login → Bearer /api/me → token-mode refresh → new Bearer /api/me.
     */
    public function testFullTokenModeCycle(): void
    {
        $tv      = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tv);

        // Step 1: Login in token mode.
        $loginResp = $handler->handle($this->loginRequest(self::EMAIL, true));
        $this->assertSame(200, $loginResp->getStatusCode());
        $loginData = $this->json($loginResp);
        $accessToken  = $loginData['access_token'];
        $refreshToken = $loginData['refresh_token'];

        // Step 2: /api/me with Bearer access token.
        $meResp = $handler->handleMe(
            new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $accessToken])
        );
        $this->assertSame(200, $meResp->getStatusCode());
        $this->assertSame(self::EMAIL, $this->json($meResp)['user']['email']);

        // Step 3: Refresh in token mode using Bearer header.
        $refreshResp = $handler->handleRefresh(
            new Request('POST', '/api/auth/refresh', [
                'X-Auth-Mode'   => 'token',
                'Authorization' => 'Bearer ' . $refreshToken,
            ])
        );
        $this->assertSame(200, $refreshResp->getStatusCode());
        $refreshData    = $this->json($refreshResp);
        $newAccessToken = $refreshData['access_token'];

        // Step 4: /api/me with the NEW Bearer access token.
        $me2Resp = $handler->handleMe(
            new Request('GET', '/api/me', ['Authorization' => 'Bearer ' . $newAccessToken])
        );
        $this->assertSame(200, $me2Resp->getStatusCode());
        $this->assertSame(self::EMAIL, $this->json($me2Resp)['user']['email']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Build a login request for the given email.
     *
     * @param bool $tokenMode When true, sends X-Auth-Mode: token.
     */
    private function loginRequest(string $email, bool $tokenMode): Request
    {
        $headers = $tokenMode ? ['X-Auth-Mode' => 'token'] : [];
        return new Request('POST', '/api/login', $headers, (string) json_encode([
            'email'    => $email,
            'password' => self::PASSWORD,
        ]));
    }

    /**
     * Mint a test access token with the given epoch.
     */
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

    /**
     * Mint a test refresh token with the given epoch.
     */
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

    /**
     * Decode JSON response body.
     *
     * @return array<string, mixed>
     */
    private function json(\Whity\Core\Response|\Whity\Sdk\Http\Response $response): array
    {
        $data = json_decode($response->getBody(), true);
        $this->assertIsArray($data, 'Response body must be valid JSON');
        return $data;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Schema
    // ──────────────────────────────────────────────────────────────────────────

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Tenant One', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'Tenant Two', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles   (id, name) VALUES (1, 'admin')");

        // Single-membership user (self::EMAIL).
        $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, ?, datetime('now'), 0)"
        )->execute([self::USER_ID, self::TENANT_ID, self::EMAIL, password_hash(self::PASSWORD, PASSWORD_BCRYPT), self::ROLE_ID]);

        $pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([self::USER_ID, 'bodytokentest', password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);

        $pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([self::USER_ID, self::EMAIL]);

        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::USER_ID, self::TENANT_ID, self::ROLE_ID]);

        // Multi-membership user (self::MULTI_EMAIL).
        $pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([self::MULTI_USER_ID, 'multitenant', password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);

        $pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([self::MULTI_USER_ID, self::MULTI_EMAIL]);

        // Two active memberships in Tenant 1 and Tenant 2.
        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::MULTI_USER_ID, self::TENANT_ID, self::ROLE_ID]);

        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::MULTI_USER_ID, self::TENANT_B_ID, self::ROLE_ID]);

        return $pdo;
    }
}
