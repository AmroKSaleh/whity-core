<?php

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestFixtureBuilder;
use Tests\Support\MockRequestFactory;
use Whity\Api\UsersApiHandler;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Hooks\HookManager;
use PDO;
use PDOStatement;

/**
 * Tests for UsersApiHandler class
 *
 * Tests CRUD operations for users with:
 * - ou_id assignment and validation
 * - Cross-tenant security isolation
 * - Data validation
 * - Email uniqueness per tenant
 */
class UsersApiHandlerTest extends TestCase
{
    private PDO $mockDb;
    private HookManager $mockHookManager;
    private UsersApiHandler $handler;
    private int $testTenantId = 1;

    protected function setUp(): void
    {
        // Create mocks
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockHookManager = $this->createMock(HookManager::class);

        // Initialize handler with mocks
        $this->handler = new UsersApiHandler($this->mockDb, $this->mockHookManager);

        // Set test tenant
        MockRequestFactory::setTestTenant($this->testTenantId);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        TestFixtureBuilder::resetCounters();
    }

    // ==================== HAPPY PATH TESTS ====================

    /**
     * Test updating user with valid ou_id returns 200
     */
    public function testUpdateUserWithValidOuIdReturns200(): void
    {
        $userId = 5;
        $ouId = 10;
        $userData = TestFixtureBuilder::user($userId, $this->testTenantId, 2);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($userData, null, null); // First for user lookup, then for OU check

        // Mock OU validation query
        $mockOuCheckStatement = $this->createMock(PDOStatement::class);
        $mockOuCheckStatement->method('execute')->willReturn(true);
        $mockOuCheckStatement->method('fetch')->willReturn(['id' => $ouId]); // OU exists in current tenant

        // Mock UPDATE query
        $mockUpdateStatement = $this->createMock(PDOStatement::class);
        $mockUpdateStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockOuCheckStatement, $mockUpdateStatement);

        $body = json_encode(['ou_id' => $ouId]);

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        $this->assertSame(200, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('data', $responseData);
    }

    /**
     * Test updating user with ou_id = 0 (NULL) returns 200
     */
    public function testUpdateUserWithOuIdZeroReturns200(): void
    {
        $userId = 5;
        $userData = TestFixtureBuilder::user($userId, $this->testTenantId, 2, 10);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($userData);

        // Mock UPDATE query - ou_id should be set to NULL
        $mockUpdateStatement = $this->createMock(PDOStatement::class);
        $mockUpdateStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockUpdateStatement);

        $body = json_encode(['ou_id' => 0]);

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test updating user with ou_id = null (NULL) returns 200
     */
    public function testUpdateUserWithOuIdNullReturns200(): void
    {
        $userId = 5;
        $userData = TestFixtureBuilder::user($userId, $this->testTenantId, 2, 10);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($userData);

        // Mock UPDATE query - ou_id should be set to NULL
        $mockUpdateStatement = $this->createMock(PDOStatement::class);
        $mockUpdateStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockUpdateStatement);

        $body = json_encode(['ou_id' => null]);

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test updating user without ou_id in body returns 200
     */
    public function testUpdateUserWithoutOuIdReturns200(): void
    {
        $userId = 5;
        $userData = TestFixtureBuilder::user($userId, $this->testTenantId, 2, 10);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($userData, false); // First for user, then for email check (email doesn't exist)

        // Mock UPDATE query
        $mockUpdateStatement = $this->createMock(PDOStatement::class);
        $mockUpdateStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockSelectStatement, $mockUpdateStatement);

        $body = json_encode(['email' => 'new@example.com']);

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        $this->assertSame(200, $response->getStatusCode());
    }

    // ==================== CROSS-TENANT SECURITY TESTS ====================

    /**
     * Test updating user with cross-tenant ou_id returns 403
     */
    public function testUpdateUserWithCrossTenantOuIdReturns403(): void
    {
        $userId = 5;
        $ouId = 99; // OU from different tenant
        $userData = TestFixtureBuilder::user($userId, $this->testTenantId, 2);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturn($userData);

        // Mock OU validation query - OU doesn't exist in current tenant
        $mockOuCheckStatement = $this->createMock(PDOStatement::class);
        $mockOuCheckStatement->method('execute')->willReturn(true);
        $mockOuCheckStatement->method('fetch')->willReturn(false); // OU not in current tenant

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockOuCheckStatement);

        $body = json_encode(['ou_id' => $ouId]);

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('OU does not belong to current tenant', $responseData['error']);
    }

    /**
     * Test updating user from different tenant returns 404
     */
    public function testUpdateUserFromDifferentTenantReturns404(): void
    {
        $userId = 5;
        $ouId = 10;

        // Mock SELECT user query - user not found in current tenant
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')->willReturn(false);

        $this->mockDb->method('prepare')
            ->willReturn($mockSelectStatement);

        $body = json_encode(['ou_id' => $ouId]);

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        $this->assertSame(404, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    // ==================== VALIDATION TESTS ====================

    /**
     * Test updating user with role_id blocked returns 403
     */
    public function testUpdateUserWithRoleIdBlockReturns403(): void
    {
        $userId = 5;
        $userData = TestFixtureBuilder::user($userId, $this->testTenantId, 2);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturn($userData);

        $this->mockDb->method('prepare')
            ->willReturn($mockSelectStatement);

        $body = json_encode(['role_id' => 3]); // Attempt privilege escalation

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('Role changes are not allowed', $responseData['error']);
    }

    /**
     * Test updating user without ID returns 400
     */
    public function testUpdateUserWithoutIdReturns400(): void
    {
        $body = json_encode(['ou_id' => 10]);

        $request = new Request('PATCH', '/api/users', [], $body);
        $response = $this->handler->update($request, []);

        $this->assertSame(400, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test updating user with no changes returns 200
     */
    public function testUpdateUserWithNoChangesReturns200(): void
    {
        $userId = 5;
        $userData = TestFixtureBuilder::user($userId, $this->testTenantId, 2, 10);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturn($userData);

        $this->mockDb->method('prepare')
            ->willReturn($mockSelectStatement);

        $body = json_encode(['ou_id' => 10]); // Same OU ID (no change in database)

        $request = new Request('PATCH', '/api/users/' . $userId, [], $body);
        $response = $this->handler->update($request, ['id' => $userId]);

        // With current logic, empty ou_id body means nothing changes - should return 200
        $this->assertSame(200, $response->getStatusCode());
    }
}
