<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use PDO;

/**
 * Integration tests for the complete auth cycle.
 *
 * Tests the full authentication flow:
 * 1. Login with valid credentials
 * 2. Call /api/me with valid access token
 * 3. Refresh access token with refresh token
 * 4. Logout and revoke tokens
 * 5. Verify revoked token is rejected on refresh
 *
 * Runs against a real in-memory SQLite engine (WC-185): the previous mocked-PDO
 * version could not exercise the access-token revocation lookup, the per-user
 * token_epoch check, or the actual revoked_tokens writes — all of which this
 * flow now depends on. The schema mirrors production (users carries token_epoch
 * and UNIQUE(tenant_id, email); revoked_tokens is the global revocation table).
 */
class AuthFlowTest extends TestCase
{
    private JwtParser $jwtParser;
    private PDO $pdo;

    private const TEST_SECRET_KEY = 'test-secret-key-for-integration-tests-padded-min-32-byte-key';
    private const TEST_USER_PASSWORD = 'testpassword123';
    private const TEST_USER_EMAIL = 'testuser@example.com';
    private const TEST_USER_ID = 2; // id=1 is reserved for the system admin seeded by migration 010
    private const TEST_TENANT_ID = 1;
    private const TEST_ROLE_ID = 1;
    private const TEST_ROLE_NAME = 'admin';

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::TEST_SECRET_KEY);
        $this->pdo = $this->makeSchema();
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    // ==================== scenarios ====================

    /**
     * Scenario 1: Login with valid credentials returns user data, no token body.
     */
    public function testLoginWithValidCredentials(): void
    {
        $authHandler = new AuthHandler($this->pdo, $this->jwtParser);

        $request = new Request('POST', '/api/login', [], (string) json_encode([
            'email' => self::TEST_USER_EMAIL,
            'password' => self::TEST_USER_PASSWORD,
        ]));
        $response = $authHandler->handle($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertArrayNotHasKey('token', $responseData);

        $user = $responseData['user'];
        $this->assertSame(self::TEST_USER_ID, $user['id']);
        $this->assertSame(self::TEST_USER_EMAIL, $user['email']);
        $this->assertSame(self::TEST_ROLE_NAME, $user['role']);
    }

    /**
     * Scenario 2: /api/me with a valid access token returns user data.
     */
    public function testGetMeWithValidAccessToken(): void
    {
        $tokenValidator = new TokenValidator($this->jwtParser, $this->pdo);
        $authHandler = new AuthHandler($this->pdo, $this->jwtParser, $tokenValidator);

        $accessToken = $this->mintAccess(0);
        $_COOKIE['access_token'] = $accessToken;

        $request = new Request('GET', '/api/me', []);
        $response = $authHandler->handleMe($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('user', $responseData);

        $user = $responseData['user'];
        $this->assertSame(self::TEST_USER_ID, $user['id']);
        $this->assertSame(self::TEST_USER_EMAIL, $user['email']);
        $this->assertSame(self::TEST_ROLE_NAME, $user['role']);

        $claims = $this->jwtParser->parse($accessToken);
        $this->assertIsArray($claims);
        $this->assertSame(self::TEST_USER_ID, $claims['profile_id']);
        $this->assertSame(self::TEST_TENANT_ID, $claims['active_tenant_id']);
        $this->assertSame('access', $claims['type']);
        $this->assertArrayHasKey('jti', $claims);
    }

    /**
     * Scenario 3: refresh with a valid refresh token issues a new access token.
     */
    public function testRefreshWithValidRefreshToken(): void
    {
        $tokenValidator = new TokenValidator($this->jwtParser, $this->pdo);
        $authHandler = new AuthHandler($this->pdo, $this->jwtParser, $tokenValidator);

        $_COOKIE['refresh_token'] = $this->mintRefresh(0);

        $request = new Request('POST', '/api/auth/refresh', []);
        $response = $authHandler->handleRefresh($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertSame('success', $responseData['status']);
    }

    /**
     * Scenario 4: logout revokes BOTH the access and refresh jti (WC-185).
     */
    public function testLogoutRevokesToken(): void
    {
        $authHandler = new AuthHandler($this->pdo, $this->jwtParser);

        $accessToken = $this->mintAccess(0);
        $refreshToken = $this->mintRefresh(0);
        $accessJti = (string) $this->jwtParser->parse($accessToken)['jti'];
        $refreshJti = (string) $this->jwtParser->parse($refreshToken)['jti'];

        $_COOKIE['access_token'] = $accessToken;
        $_COOKIE['refresh_token'] = $refreshToken;

        $request = new Request('POST', '/api/auth/logout', []);
        $response = $authHandler->handleLogout($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertSame('logged out', $responseData['status']);

        $this->assertTrue($this->isRevoked($accessJti), 'Logout must revoke the access jti.');
        $this->assertTrue($this->isRevoked($refreshJti), 'Logout must revoke the refresh jti.');
    }

    /**
     * Scenario 5: a refresh token revoked by logout is rejected on refresh.
     */
    public function testRefreshWithRevokedTokenFails(): void
    {
        // Step 1: logout revokes the refresh token.
        $logoutHandler = new AuthHandler($this->pdo, $this->jwtParser);

        $refreshToken = $this->mintRefresh(0);
        $refreshJti = (string) $this->jwtParser->parse($refreshToken)['jti'];

        $_COOKIE['refresh_token'] = $refreshToken;
        $logoutHandler->handleLogout(new Request('POST', '/api/auth/logout', []));
        $this->assertTrue($this->isRevoked($refreshJti), 'Token should be revoked.');

        // Step 2: the revoked refresh token can no longer mint an access token.
        $tokenValidator = new TokenValidator($this->jwtParser, $this->pdo);
        $refreshHandler = new AuthHandler($this->pdo, $this->jwtParser, $tokenValidator);

        $_COOKIE['refresh_token'] = $refreshToken;
        $response = $refreshHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * The full cycle: login → /api/me → refresh → logout → revoked refresh fails.
     */
    public function testCompleteAuthCycle(): void
    {
        $tokenValidator = new TokenValidator($this->jwtParser, $this->pdo);
        $handler = new AuthHandler($this->pdo, $this->jwtParser, $tokenValidator);

        // Step 1: Login.
        $response = $handler->handle(new Request('POST', '/api/login', [], (string) json_encode([
            'email' => self::TEST_USER_EMAIL,
            'password' => self::TEST_USER_PASSWORD,
        ])));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::TEST_USER_EMAIL, json_decode($response->getBody(), true)['user']['email']);

        // Step 2: /api/me with an access token.
        $_COOKIE['access_token'] = $this->mintAccess(0);
        $response = $handler->handleMe(new Request('GET', '/api/me', []));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::TEST_USER_EMAIL, json_decode($response->getBody(), true)['user']['email']);
        unset($_COOKIE['access_token']);

        // Step 3: Refresh.
        $refreshToken = $this->mintRefresh(0);
        $refreshJti = (string) $this->jwtParser->parse($refreshToken)['jti'];
        $_COOKIE['refresh_token'] = $refreshToken;
        $response = $handler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('success', json_decode($response->getBody(), true)['status']);

        // Step 4: Logout (revokes the refresh jti).
        $response = $handler->handleLogout(new Request('POST', '/api/auth/logout', []));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('logged out', json_decode($response->getBody(), true)['status']);
        $this->assertTrue($this->isRevoked($refreshJti), 'Token should be revoked.');

        // Step 5: the revoked refresh token now fails.
        $_COOKIE['refresh_token'] = $refreshToken;
        $response = $handler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    // ==================== helpers ====================

    private function mintAccess(int $epoch): string
    {
        return $this->jwtParser->create([
            'profile_id' => self::TEST_USER_ID,
            'active_tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME,
            'token_epoch' => $epoch,
        ], 900, 'access');
    }

    private function mintRefresh(int $epoch): string
    {
        return $this->jwtParser->create([
            'profile_id' => self::TEST_USER_ID,
            'active_tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME,
            'token_epoch' => $epoch,
        ], 604800, 'refresh');
    }

    private function isRevoked(string $jti): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
        $stmt->execute([$jti]);

        return (bool) $stmt->fetchColumn();
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        // Migration 010 seeds system tenant (id=0). The test user lives in tenant 1.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Test Tenant', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles   (id, name) VALUES (1, 'admin')");

        // Legacy users row (dual-claim window backward compat).
        $stmt = $pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, ?, datetime('now'), 0)"
        );
        $stmt->execute([
            self::TEST_USER_ID,
            self::TEST_TENANT_ID,
            self::TEST_USER_EMAIL,
            password_hash(self::TEST_USER_PASSWORD, PASSWORD_BCRYPT),
            self::TEST_ROLE_ID,
        ]);

        // WC-c35c4ce0: the new login path resolves via profile_emails → profiles → memberships.
        // Seed the profile model rows so the test user can authenticate.
        $pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([
            self::TEST_USER_ID,
            'testuser',
            password_hash(self::TEST_USER_PASSWORD, PASSWORD_BCRYPT),
        ]);

        $pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([self::TEST_USER_ID, self::TEST_USER_EMAIL]);

        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::TEST_USER_ID, self::TEST_TENANT_ID, self::TEST_ROLE_ID]);

        return $pdo;
    }
}
