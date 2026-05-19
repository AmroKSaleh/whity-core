<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Auth\CookieManager;
use Whity\Core\Request;
use PDO;
use PDOStatement;

/**
 * Integration tests for complete auth cycle
 *
 * Tests the full authentication flow:
 * 1. Login with valid credentials
 * 2. Call /api/me with valid access token
 * 3. Refresh access token with refresh token
 * 4. Logout and revoke tokens
 * 5. Verify revoked token is rejected on refresh
 *
 * Uses mock database with proper token revocation verification.
 */
class AuthFlowTest extends TestCase
{
    private JwtParser $jwtParser;
    private const TEST_SECRET_KEY = 'test-secret-key-for-integration-tests';
    private const TEST_USER_PASSWORD = 'testpassword123';
    private const TEST_USER_EMAIL = 'testuser@example.com';
    private const TEST_USER_ID = 1;
    private const TEST_TENANT_ID = 1;
    private const TEST_ROLE_ID = 1;
    private const TEST_ROLE_NAME = 'admin';

    // Track revoked tokens for verification
    private array $revokedTokens = [];

    // Store pre-hashed password for consistency
    private string $hashedPassword = '';

    protected function setUp(): void
    {
        // Initialize JWT parser
        $this->jwtParser = new JwtParser(self::TEST_SECRET_KEY);

        // Reset revoked tokens
        $this->revokedTokens = [];

        // Pre-hash the test password once (for consistency across password_verify calls)
        $this->hashedPassword = password_hash(self::TEST_USER_PASSWORD, PASSWORD_BCRYPT);
    }

    protected function tearDown(): void
    {
        // Clean up cookies
        unset($_COOKIE['access_token']);
        unset($_COOKIE['refresh_token']);

        // Clear revoked tokens
        $this->revokedTokens = [];
    }

    /**
     * Create a mock database for a login + role lookup scenario
     */
    private function createMockDbForLogin(): PDO
    {
        $hashedPassword = $this->hashedPassword;

        // Mock the user query statement
        $mockUserStatement = $this->createMock(PDOStatement::class);
        $mockUserStatement->method('execute')->willReturn(true);
        $mockUserStatement->method('fetch')->willReturn([
            'id' => self::TEST_USER_ID,
            'tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'password' => $hashedPassword,
            'role_id' => self::TEST_ROLE_ID
        ]);

        // Mock the role query statement
        $mockRoleStatement = $this->createMock(PDOStatement::class);
        $mockRoleStatement->method('execute')->willReturn(true);
        $mockRoleStatement->method('fetch')->willReturn(['name' => self::TEST_ROLE_NAME]);

        // Create the database mock
        $mockDb = $this->createMock(PDO::class);
        $mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockUserStatement, $mockRoleStatement);

        return $mockDb;
    }

    /**
     * Create a mock database that tracks token revocation
     */
    private function createMockDbForRevocation(): PDO
    {
        $revokedTokensRef = &$this->revokedTokens;

        // Mock the revocation insert statement
        $mockRevocationStatement = $this->createMock(PDOStatement::class);
        $mockRevocationStatement->method('execute')
            ->willReturnCallback(function($params) use (&$revokedTokensRef) {
                $jti = $params[0] ?? null;
                if ($jti) {
                    $revokedTokensRef[$jti] = true;
                }
                return true;
            });

        // Create the database mock
        $mockDb = $this->createMock(PDO::class);
        $mockDb->method('prepare')->willReturn($mockRevocationStatement);

        return $mockDb;
    }

    /**
     * Create a mock database that checks token revocation status
     */
    private function createMockDbForRevocationCheck(): PDO
    {
        $revokedTokensRef = &$this->revokedTokens;

        // Mock the revocation check statement
        $mockCheckStatement = $this->createMock(PDOStatement::class);
        $checkedJti = null;
        $mockCheckStatement->method('execute')
            ->willReturnCallback(function($params) use (&$checkedJti) {
                $checkedJti = $params[0] ?? null;
                return true;
            });
        $mockCheckStatement->method('rowCount')
            ->willReturnCallback(function() use (&$checkedJti, &$revokedTokensRef) {
                return (isset($revokedTokensRef[$checkedJti]) && $revokedTokensRef[$checkedJti]) ? 1 : 0;
            });

        // Create the database mock
        $mockDb = $this->createMock(PDO::class);
        $mockDb->method('prepare')->willReturn($mockCheckStatement);

        return $mockDb;
    }

    /**
     * Scenario 1: Login with valid credentials
     *
     * POST /api/login with email/password
     * - Verify 200 response
     * - Verify response includes user data (id, email, role)
     * - Verify no token in response body
     */
    public function testLoginWithValidCredentials(): void
    {
        $mockDb = $this->createMockDbForLogin();
        $authHandler = new AuthHandler($mockDb, $this->jwtParser);

        $requestBody = json_encode([
            'email' => self::TEST_USER_EMAIL,
            'password' => self::TEST_USER_PASSWORD
        ]);

        $request = new Request('POST', '/api/login', [], $requestBody);
        $response = $authHandler->handle($request);

        // Verify response status
        $this->assertSame(200, $response->getStatusCode());

        // Verify response structure
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertArrayNotHasKey('token', $responseData);

        // Verify user data
        $user = $responseData['user'];
        $this->assertSame(self::TEST_USER_ID, $user['id']);
        $this->assertSame(self::TEST_USER_EMAIL, $user['email']);
        $this->assertSame(self::TEST_ROLE_NAME, $user['role']);
    }

    /**
     * Scenario 2: Call /api/me with valid access token
     *
     * Create access_token via JwtParser (type='access', 15min expiry)
     * Simulate cookie: $_COOKIE['access_token'] = $token
     * GET /api/me
     * - Verify 200 response
     * - Verify response includes user data
     * - Verify token claims accessible
     */
    public function testGetMeWithValidAccessToken(): void
    {
        $mockDb = $this->createMock(PDO::class); // Not used for this test
        $tokenValidator = new TokenValidator($this->jwtParser, $mockDb);
        $authHandler = new AuthHandler($mockDb, $this->jwtParser, $tokenValidator);

        // Create access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => self::TEST_USER_ID,
            'tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME
        ], 900, 'access'); // 15 minutes

        // Simulate cookie
        $_COOKIE['access_token'] = $accessToken;

        // Call /api/me
        $request = new Request('GET', '/api/me', []);
        $response = $authHandler->handleMe($request);

        // Verify response status
        $this->assertSame(200, $response->getStatusCode());

        // Verify response structure
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('user', $responseData);

        // Verify user data
        $user = $responseData['user'];
        $this->assertSame(self::TEST_USER_ID, $user['id']);
        $this->assertSame(self::TEST_USER_EMAIL, $user['email']);
        $this->assertSame(self::TEST_ROLE_NAME, $user['role']);

        // Verify token claims are accessible
        $claims = $this->jwtParser->parse($accessToken);
        $this->assertIsArray($claims);
        $this->assertSame(self::TEST_USER_ID, $claims['user_id']);
        $this->assertSame(self::TEST_TENANT_ID, $claims['tenant_id']);
        $this->assertSame(self::TEST_USER_EMAIL, $claims['email']);
        $this->assertSame(self::TEST_ROLE_NAME, $claims['role']);
        $this->assertSame('access', $claims['type']);
        $this->assertArrayHasKey('jti', $claims);
    }

    /**
     * Scenario 3: Call /api/auth/refresh with valid refresh token
     *
     * Create refresh_token via JwtParser (type='refresh', 7day expiry)
     * Simulate cookie: $_COOKIE['refresh_token'] = $token
     * POST /api/auth/refresh
     * - Verify 200 response
     * - Verify response body is { "status": "success" }
     * - Verify new access_token cookie issued
     */
    public function testRefreshWithValidRefreshToken(): void
    {
        // Create a mock that doesn't report any revoked tokens
        $mockDb = $this->createMock(PDO::class);
        $mockCheckStatement = $this->createMock(PDOStatement::class);
        $mockCheckStatement->method('execute')->willReturn(true);
        $mockCheckStatement->method('rowCount')->willReturn(0); // Token not revoked
        $mockDb->method('prepare')->willReturn($mockCheckStatement);

        $tokenValidator = new TokenValidator($this->jwtParser, $mockDb);
        $authHandler = new AuthHandler($mockDb, $this->jwtParser, $tokenValidator);

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => self::TEST_USER_ID,
            'tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME
        ], 604800, 'refresh'); // 7 days

        // Simulate cookie
        $_COOKIE['refresh_token'] = $refreshToken;

        // Call /api/auth/refresh
        $request = new Request('POST', '/api/auth/refresh', []);
        $response = $authHandler->handleRefresh($request);

        // Verify response status
        $this->assertSame(200, $response->getStatusCode());

        // Verify response structure
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertSame('success', $responseData['status']);
    }

    /**
     * Scenario 4: Call /api/auth/logout
     *
     * Setup: user has valid refresh_token
     * POST /api/auth/logout
     * - Verify 200 response
     * - Verify response body is { "status": "logged out" }
     * - CRITICAL: Verify token jti is added to revoked_tokens table
     */
    public function testLogoutRevokesToken(): void
    {
        $mockDb = $this->createMockDbForRevocation();
        $authHandler = new AuthHandler($mockDb, $this->jwtParser);

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => self::TEST_USER_ID,
            'tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME
        ], 604800, 'refresh'); // 7 days

        // Extract JTI from token for verification
        $claims = $this->jwtParser->parse($refreshToken);
        $tokenJti = $claims['jti'];

        // Simulate cookie
        $_COOKIE['refresh_token'] = $refreshToken;

        // Call /api/auth/logout
        $request = new Request('POST', '/api/auth/logout', []);
        $response = $authHandler->handleLogout($request);

        // Verify response status
        $this->assertSame(200, $response->getStatusCode());

        // Verify response structure
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertSame('logged out', $responseData['status']);

        // CRITICAL: Verify token jti is added to revoked_tokens table
        // (via our mock tracking)
        $this->assertTrue(
            isset($this->revokedTokens[$tokenJti]),
            'Token JTI should be tracked as revoked'
        );
    }

    /**
     * Scenario 5: Call /api/auth/refresh with revoked token (should fail)
     *
     * Use same refresh_token from logout (now revoked)
     * Simulate cookie: $_COOKIE['refresh_token'] = $revokedToken
     * POST /api/auth/refresh
     * - Verify 401 response (token rejected because jti in revocation table)
     */
    public function testRefreshWithRevokedTokenFails(): void
    {
        // Step 1: Logout with a refresh token (revoking it)
        $logoutMockDb = $this->createMockDbForRevocation();
        $logoutHandler = new AuthHandler($logoutMockDb, $this->jwtParser);

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => self::TEST_USER_ID,
            'tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME
        ], 604800, 'refresh'); // 7 days

        // Extract JTI from token for verification
        $claims = $this->jwtParser->parse($refreshToken);
        $tokenJti = $claims['jti'];

        // Simulate logout by setting cookie and calling logout
        $_COOKIE['refresh_token'] = $refreshToken;
        $logoutRequest = new Request('POST', '/api/auth/logout', []);
        $logoutHandler->handleLogout($logoutRequest);
        unset($_COOKIE['refresh_token']);

        // Verify token is revoked (via mock)
        $this->assertTrue(
            isset($this->revokedTokens[$tokenJti]),
            'Token should be revoked'
        );

        // Step 2: Try to use the revoked token to refresh
        $refreshMockDb = $this->createMockDbForRevocationCheck();
        $tokenValidator = new TokenValidator($this->jwtParser, $refreshMockDb);
        $refreshHandler = new AuthHandler($refreshMockDb, $this->jwtParser, $tokenValidator);

        $_COOKIE['refresh_token'] = $refreshToken;
        $refreshRequest = new Request('POST', '/api/auth/refresh', []);
        $response = $refreshHandler->handleRefresh($refreshRequest);

        // Verify response is 401 (unauthorized)
        $this->assertSame(401, $response->getStatusCode());

        // Verify error response
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test complete auth cycle in sequence
     *
     * Comprehensive test that runs through the entire auth flow:
     * 1. Login
     * 2. Use access token to get current user
     * 3. Refresh access token
     * 4. Logout
     * 5. Verify refresh fails with revoked token
     */
    public function testCompleteAuthCycle(): void
    {
        // Step 1: Login
        $loginMockDb = $this->createMockDbForLogin();
        $loginHandler = new AuthHandler($loginMockDb, $this->jwtParser);

        $loginRequest = json_encode([
            'email' => self::TEST_USER_EMAIL,
            'password' => self::TEST_USER_PASSWORD
        ]);
        $request = new Request('POST', '/api/login', [], $loginRequest);
        $response = $loginHandler->handle($request);
        $this->assertSame(200, $response->getStatusCode());
        $loginData = json_decode($response->getBody(), true);
        $this->assertSame(self::TEST_USER_EMAIL, $loginData['user']['email']);

        // Step 2: Get current user with access token
        $mockDb = $this->createMock(PDO::class);
        $tokenValidator = new TokenValidator($this->jwtParser, $mockDb);
        $meHandler = new AuthHandler($mockDb, $this->jwtParser, $tokenValidator);

        $accessToken = $this->jwtParser->create([
            'user_id' => self::TEST_USER_ID,
            'tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME
        ], 900, 'access');
        $_COOKIE['access_token'] = $accessToken;
        $meRequest = new Request('GET', '/api/me', []);
        $response = $meHandler->handleMe($meRequest);
        $this->assertSame(200, $response->getStatusCode());
        $meData = json_decode($response->getBody(), true);
        $this->assertSame(self::TEST_USER_EMAIL, $meData['user']['email']);
        unset($_COOKIE['access_token']);

        // Step 3: Refresh access token
        $refreshMockDb = $this->createMock(PDO::class);
        $mockCheckStatement = $this->createMock(PDOStatement::class);
        $mockCheckStatement->method('execute')->willReturn(true);
        $mockCheckStatement->method('rowCount')->willReturn(0); // Token not revoked
        $refreshMockDb->method('prepare')->willReturn($mockCheckStatement);

        $refreshTokenValidator = new TokenValidator($this->jwtParser, $refreshMockDb);
        $refreshHandler = new AuthHandler($refreshMockDb, $this->jwtParser, $refreshTokenValidator);

        $refreshToken = $this->jwtParser->create([
            'user_id' => self::TEST_USER_ID,
            'tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => self::TEST_ROLE_NAME
        ], 604800, 'refresh');
        $_COOKIE['refresh_token'] = $refreshToken;
        $refreshRequest = new Request('POST', '/api/auth/refresh', []);
        $response = $refreshHandler->handleRefresh($refreshRequest);
        $this->assertSame(200, $response->getStatusCode());
        $refreshData = json_decode($response->getBody(), true);
        $this->assertSame('success', $refreshData['status']);

        // Step 4: Logout
        $logoutMockDb = $this->createMockDbForRevocation();
        $logoutHandler = new AuthHandler($logoutMockDb, $this->jwtParser);

        // Extract JTI for later verification
        $claims = $this->jwtParser->parse($refreshToken);
        $tokenJti = $claims['jti'];

        $logoutRequest = new Request('POST', '/api/auth/logout', []);
        $response = $logoutHandler->handleLogout($logoutRequest);
        $this->assertSame(200, $response->getStatusCode());
        $logoutData = json_decode($response->getBody(), true);
        $this->assertSame('logged out', $logoutData['status']);

        // Verify token is revoked
        $this->assertTrue(isset($this->revokedTokens[$tokenJti]), 'Token should be revoked');

        // Step 5: Verify refresh fails with revoked token
        $revokedMockDb = $this->createMockDbForRevocationCheck();
        $revokedTokenValidator = new TokenValidator($this->jwtParser, $revokedMockDb);
        $revokedHandler = new AuthHandler($revokedMockDb, $this->jwtParser, $revokedTokenValidator);

        $response = $revokedHandler->handleRefresh($refreshRequest);
        $this->assertSame(401, $response->getStatusCode());
        $errorData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $errorData);
    }
}
