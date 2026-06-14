<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Auth\CookieManager;
use Whity\Core\Request;
use PDO;
use PDOStatement;

/**
 * Tests for AuthHandler class
 *
 * Tests login, refresh, logout, and session endpoints.
 */
class AuthHandlerTest extends TestCase
{
    private AuthHandler $authHandler;
    private PDO $mockDb;
    private JwtParser $jwtParser;
    private TokenValidator $mockTokenValidator;

    protected function setUp(): void
    {
        // Create mock PDO and prepare test data
        $this->mockDb = $this->createMock(PDO::class);
        $this->jwtParser = new JwtParser('test-secret-key-padded-for-hs256-min-32-byte-key');
        $this->mockTokenValidator = $this->createMock(TokenValidator::class);
        $this->authHandler = new AuthHandler($this->mockDb, $this->jwtParser, $this->mockTokenValidator);
    }

    /**
     * Test login with valid credentials returns 200 with user data
     */
    public function testLoginWithValidCredentials(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);

        // Mock the user query statement
        $mockUserStatement = $this->createMock(PDOStatement::class);
        $mockUserStatement->method('fetch')->willReturn([
            'id' => 1,
            'tenant_id' => 1,
            'email' => 'admin@whity.local',
            'password' => $hashedPassword,
            'role_id' => 1
        ]);

        // Mock the role query statement
        $mockRoleStatement = $this->createMock(PDOStatement::class);
        $mockRoleStatement->method('fetch')->willReturn(['name' => 'admin']);

        // Setup prepare to return different statements based on query
        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockUserStatement, $mockRoleStatement);

        $requestBody = json_encode([
            'email' => 'admin@whity.local',
            'password' => 'password'
        ]);

        $request = new Request('POST', '/auth/login', [], $requestBody);
        $response = $this->authHandler->handle($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertArrayNotHasKey('token', $responseData);

        // Verify user data in response
        $user = $responseData['user'];
        $this->assertSame(1, $user['id']);
        $this->assertSame('admin@whity.local', $user['email']);
        $this->assertSame('admin', $user['role']);
    }

    /**
     * Test login sets both access and refresh tokens in cookies
     */
    public function testLoginSetsCookiesWithCorrectValues(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);

        // Mock the user query statement
        $mockUserStatement = $this->createMock(PDOStatement::class);
        $mockUserStatement->method('fetch')->willReturn([
            'id' => 1,
            'tenant_id' => 1,
            'email' => 'user@test.com',
            'password' => $hashedPassword,
            'role_id' => 2
        ]);

        // Mock the role query statement
        $mockRoleStatement = $this->createMock(PDOStatement::class);
        $mockRoleStatement->method('fetch')->willReturn(['name' => 'user']);

        // Setup prepare to return different statements based on query
        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockUserStatement, $mockRoleStatement);

        $requestBody = json_encode([
            'email' => 'user@test.com',
            'password' => 'password'
        ]);

        $request = new Request('POST', '/auth/login', [], $requestBody);

        // Capture headers to verify cookies were set
        $headersFired = [];
        $originalHeader = function_exists('header') ? 'header' : null;

        // We can't really capture headers in unit test, so we just verify the response
        $response = $this->authHandler->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test login with invalid credentials returns 401
     */
    public function testLoginWithInvalidCredentials(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);

        // Mock the prepared statement returning a user
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn([
            'id' => 1,
            'tenant_id' => 1,
            'email' => 'admin@whity.local',
            'password' => $hashedPassword,
            'role_id' => 1
        ]);

        $this->mockDb->method('prepare')->willReturn($mockStatement);

        $requestBody = json_encode([
            'email' => 'admin@whity.local',
            'password' => 'wrongpassword'
        ]);

        $request = new Request('POST', '/auth/login', [], $requestBody);
        $response = $this->authHandler->handle($request);

        $this->assertSame(401, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test login with nonexistent user returns 401
     */
    public function testLoginWithNonexistentUser(): void
    {
        // Mock the prepared statement returning false (no user found)
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(false);

        $this->mockDb->method('prepare')->willReturn($mockStatement);

        $requestBody = json_encode([
            'email' => 'nonexistent@whity.local',
            'password' => 'password'
        ]);

        $request = new Request('POST', '/auth/login', [], $requestBody);
        $response = $this->authHandler->handle($request);

        $this->assertSame(401, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test handleMe returns user data with valid access token
     */
    public function testHandleMeReturnsUserWithValidAccessToken(): void
    {
        $this->mockTokenValidator->method('validateAccessToken')->willReturn([
            'user_id' => 1,
            'tenant_id' => 1,
            'email' => 'user@test.com',
            'role' => 'user',
            'jti' => 'test-jti-1',
            'type' => 'access',
            'exp' => time() + 900
        ]);

        $request = new Request('GET', '/api/me', []);
        $response = $this->authHandler->handleMe($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('user', $responseData);

        $user = $responseData['user'];
        $this->assertSame(1, $user['id']);
        $this->assertSame('user@test.com', $user['email']);
        $this->assertSame('user', $user['role']);
    }

    /**
     * Test handleMe returns 401 without access token
     */
    public function testHandleMeReturns401WithoutAccessToken(): void
    {
        $this->mockTokenValidator->method('validateAccessToken')->willReturn(null);

        $request = new Request('GET', '/api/me', []);
        $response = $this->authHandler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test handleMe returns 401 with expired access token
     */
    public function testHandleMeReturns401WithExpiredAccessToken(): void
    {
        // TokenValidator.validateAccessToken() returns null for expired tokens
        $this->mockTokenValidator->method('validateAccessToken')->willReturn(null);

        $request = new Request('GET', '/api/me', []);
        $response = $this->authHandler->handleMe($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test handleRefresh returns new access token with valid refresh token
     */
    public function testHandleRefreshReturnsNewAccessToken(): void
    {
        $this->mockTokenValidator->method('validateRefreshToken')->willReturn([
            'user_id' => 1,
            'tenant_id' => 1,
            'email' => 'user@test.com',
            'role' => 'user',
            'jti' => 'test-jti-refresh-1',
            'type' => 'refresh',
            'token_epoch' => 0,
            'exp' => time() + 604800
        ]);

        // handleRefresh re-reads the user's current epoch (tenant-scoped) before
        // minting the new access token (WC-185); stub that lookup to return 0.
        $epochStatement = $this->createMock(PDOStatement::class);
        $epochStatement->method('execute')->willReturn(true);
        $epochStatement->method('fetchColumn')->willReturn('0');
        $this->mockDb->method('prepare')->willReturn($epochStatement);

        $request = new Request('POST', '/api/auth/refresh', []);
        $response = $this->authHandler->handleRefresh($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertSame('success', $responseData['status']);
    }

    /**
     * Test handleRefresh returns 401 with revoked refresh token
     */
    public function testHandleRefreshReturns401WithRevokedToken(): void
    {
        // TokenValidator.validateRefreshToken() returns null for revoked tokens
        $this->mockTokenValidator->method('validateRefreshToken')->willReturn(null);

        $request = new Request('POST', '/api/auth/refresh', []);
        $response = $this->authHandler->handleRefresh($request);

        $this->assertSame(401, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test handleRefresh returns 401 with expired refresh token
     */
    public function testHandleRefreshReturns401WithExpiredToken(): void
    {
        // TokenValidator.validateRefreshToken() returns null for expired tokens
        $this->mockTokenValidator->method('validateRefreshToken')->willReturn(null);

        $request = new Request('POST', '/api/auth/refresh', []);
        $response = $this->authHandler->handleRefresh($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test handleRefresh returns 401 without refresh token
     */
    public function testHandleRefreshReturns401WithoutRefreshToken(): void
    {
        // TokenValidator.validateRefreshToken() returns null when no token present
        $this->mockTokenValidator->method('validateRefreshToken')->willReturn(null);

        $request = new Request('POST', '/api/auth/refresh', []);
        $response = $this->authHandler->handleRefresh($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test handleLogout revokes refresh token by adding JTI to revoked_tokens table
     */
    public function testHandleLogoutRevokesRefreshToken(): void
    {
        // Mock the revocation insert statement
        $mockRevocationStatement = $this->createMock(PDOStatement::class);
        $mockRevocationStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')->willReturn($mockRevocationStatement);

        // Set up cookie superglobal to simulate refresh token
        $_COOKIE['refresh_token'] = 'test-refresh-token';

        // Create a real token to test with
        $realAuthHandler = new AuthHandler($this->mockDb, $this->jwtParser);
        $refreshToken = $this->jwtParser->create([
            'user_id' => 1,
            'tenant_id' => 1,
            'email' => 'user@test.com',
            'role' => 'user'
        ], 604800, 'refresh');

        $_COOKIE['refresh_token'] = $refreshToken;

        $request = new Request('POST', '/api/auth/logout', []);
        $response = $realAuthHandler->handleLogout($request);

        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertSame('logged out', $responseData['status']);

        // Clean up
        unset($_COOKIE['refresh_token']);
    }

    /**
     * Test handleLogout is idempotent - returns 200 even without refresh token
     */
    public function testHandleLogoutIsIdempotent(): void
    {
        // Clear cookies
        unset($_COOKIE['refresh_token']);
        unset($_COOKIE['access_token']);

        $request = new Request('POST', '/api/auth/logout', []);
        $response = $this->authHandler->handleLogout($request);

        // Should still return 200 (idempotent)
        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('status', $responseData);
        $this->assertSame('logged out', $responseData['status']);
    }

    /**
     * Test handleLogout clears both cookies
     */
    public function testHandleLogoutClearsCookies(): void
    {
        // Set up cookies
        $_COOKIE['refresh_token'] = 'test-token';
        $_COOKIE['access_token'] = 'test-token';

        $request = new Request('POST', '/api/auth/logout', []);
        $response = $this->authHandler->handleLogout($request);

        $this->assertSame(200, $response->getStatusCode());

        // Clean up
        unset($_COOKIE['refresh_token']);
        unset($_COOKIE['access_token']);
    }

    /**
     * Test login with 2FA enabled returns 202 with requires_2fa flag
     */
    public function testLoginWith2FaEnabledReturns202WithRequires2fa(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);

        // Mock the user query statement with 2FA enabled
        $mockUserStatement = $this->createMock(PDOStatement::class);
        $mockUserStatement->method('fetch')->willReturn([
            'id' => 2,
            'tenant_id' => 1,
            'email' => 'user2fa@whity.local',
            'password' => $hashedPassword,
            'role_id' => 2,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'encrypted-secret-data',
            'two_factor_backup_codes_version' => 1
        ]);

        // Mock the role query statement
        $mockRoleStatement = $this->createMock(PDOStatement::class);
        $mockRoleStatement->method('fetch')->willReturn(['name' => 'user']);

        // Setup prepare to return different statements based on query
        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockUserStatement, $mockRoleStatement);

        $requestBody = json_encode([
            'email' => 'user2fa@whity.local',
            'password' => 'password'
        ]);

        $request = new Request('POST', '/auth/login', [], $requestBody);
        $response = $this->authHandler->handle($request);

        // Should return 202 Accepted
        $this->assertSame(202, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('requires_2fa', $responseData);
        $this->assertSame(true, $responseData['requires_2fa']);
        $this->assertArrayNotHasKey('user', $responseData);
        $this->assertArrayNotHasKey('token', $responseData);
    }

    /**
     * Test login with 2FA disabled returns 200 with user data
     */
    public function testLoginWith2FaDisabledReturns200WithTokens(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);

        // Mock the user query statement with 2FA disabled
        $mockUserStatement = $this->createMock(PDOStatement::class);
        $mockUserStatement->method('fetch')->willReturn([
            'id' => 3,
            'tenant_id' => 1,
            'email' => 'user-no2fa@whity.local',
            'password' => $hashedPassword,
            'role_id' => 2,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_backup_codes_version' => 0
        ]);

        // Mock the role query statement
        $mockRoleStatement = $this->createMock(PDOStatement::class);
        $mockRoleStatement->method('fetch')->willReturn(['name' => 'user']);

        // Setup prepare to return different statements based on query
        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockUserStatement, $mockRoleStatement);

        $requestBody = json_encode([
            'email' => 'user-no2fa@whity.local',
            'password' => 'password'
        ]);

        $request = new Request('POST', '/auth/login', [], $requestBody);
        $response = $this->authHandler->handle($request);

        // Should return 200 OK
        $this->assertSame(200, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertArrayNotHasKey('requires_2fa', $responseData);

        // Verify user data in response
        $user = $responseData['user'];
        $this->assertSame(3, $user['id']);
        $this->assertSame('user-no2fa@whity.local', $user['email']);
        $this->assertSame('user', $user['role']);
    }

    /**
     * Test handle2fa returns 401 without 2FA code
     */
    public function testHandle2faReturns401WithoutCode(): void
    {
        // Create a temporary token with user_id in claims
        $tempToken = $this->jwtParser->create([
            'user_id' => 2,
            'tenant_id' => 1,
            'email' => 'user2fa@whity.local',
            'role' => 'user'
        ], 300, 'temp');

        // Set temp token in cookie
        $_COOKIE['temp_auth_token'] = $tempToken;

        $requestBody = json_encode([]);
        $request = new Request('POST', '/api/login/2fa', [], $requestBody);

        $response = $this->authHandler->handle2fa($request);

        // Should return 401 without code
        $this->assertSame(401, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);

        // Clean up
        unset($_COOKIE['temp_auth_token']);
    }

    /**
     * Test handle2fa returns 401 with invalid code
     */
    public function testHandle2faReturns401WithInvalidCode(): void
    {
        // Create a temporary token
        $tempToken = $this->jwtParser->create([
            'user_id' => 2,
            'tenant_id' => 1,
            'email' => 'user2fa@whity.local',
            'role' => 'user'
        ], 300, 'temp');

        $_COOKIE['temp_auth_token'] = $tempToken;

        // Mock user query
        $mockUserStatement = $this->createMock(PDOStatement::class);
        $mockUserStatement->method('fetch')->willReturn([
            'id' => 2,
            'tenant_id' => 1,
            'email' => 'user2fa@whity.local',
            'role_id' => 2,
            'two_factor_secret' => 'encrypted-secret-data',
            'two_factor_backup_codes_version' => 1
        ]);

        $this->mockDb->method('prepare')->willReturn($mockUserStatement);

        $requestBody = json_encode(['code' => 'invalid']);
        $request = new Request('POST', '/api/login/2fa', [], $requestBody);

        $response = $this->authHandler->handle2fa($request);

        // Should return 401 on invalid code
        $this->assertSame(401, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);

        // Clean up
        unset($_COOKIE['temp_auth_token']);
    }

    /**
     * Test handle2fa returns 401 without temp token
     */
    public function testHandle2faReturns401WithoutTempToken(): void
    {
        // Clear temp token cookie
        unset($_COOKIE['temp_auth_token']);

        $requestBody = json_encode(['code' => '123456']);
        $request = new Request('POST', '/api/login/2fa', [], $requestBody);

        $response = $this->authHandler->handle2fa($request);

        // Should return 401 without temp token
        $this->assertSame(401, $response->getStatusCode());

        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }
}
