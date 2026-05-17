<?php

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Core\Request;
use PDO;
use PDOStatement;

/**
 * Tests for AuthHandler class
 */
class AuthHandlerTest extends TestCase
{
    private AuthHandler $authHandler;
    private PDO $mockDb;
    private JwtParser $jwtParser;

    protected function setUp(): void
    {
        // Create mock PDO and prepare test data
        $this->mockDb = $this->createMock(PDO::class);
        $this->jwtParser = new JwtParser('test-secret-key');
        $this->authHandler = new AuthHandler($this->mockDb, $this->jwtParser);
    }

    /**
     * Test login with valid credentials returns 200 with token and user
     */
    public function testLoginWithValidCredentials(): void
    {
        $hashedPassword = password_hash('password', PASSWORD_BCRYPT);

        // Mock the user query statement
        $mockUserStatement = $this->createMock(PDOStatement::class);
        $mockUserStatement->method('fetch')->willReturn([
            'id' => 1,
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
        $this->assertArrayHasKey('token', $responseData);
        $this->assertArrayHasKey('user', $responseData);

        // Verify token structure and claims
        $token = $responseData['token'];
        $this->assertIsString($token);

        $parsed = $this->jwtParser->parse($token);
        $this->assertIsArray($parsed);
        $this->assertSame('admin@whity.local', $parsed['email']);
        $this->assertSame('admin', $parsed['role']);
        $this->assertSame(1, $parsed['user_id']);

        // Verify user data in response
        $user = $responseData['user'];
        $this->assertSame(1, $user['id']);
        $this->assertSame('admin@whity.local', $user['email']);
        $this->assertSame('admin', $user['role']);
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
}
