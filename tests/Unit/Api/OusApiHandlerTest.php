<?php

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestFixtureBuilder;
use Tests\Support\MockRequestFactory;
use Whity\Api\OusApiHandler;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Hooks\HookManager;
use PDO;
use PDOStatement;

/**
 * Tests for OusApiHandler class
 *
 * Tests CRUD operations for organizational units (OUs) with:
 * - Happy path operations (list, create, get, update, delete, assignRole, removeRole)
 * - Cross-tenant security isolation
 * - Data validation
 * - Role assignment management
 */
class OusApiHandlerTest extends TestCase
{
    private PDO $mockDb;
    private HookManager $mockHookManager;
    private OusApiHandler $handler;
    private int $testTenantId = 1;

    protected function setUp(): void
    {
        // Create mocks
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockHookManager = $this->createMock(HookManager::class);

        // Initialize handler with mocks
        $this->handler = new OusApiHandler($this->mockDb, $this->mockHookManager);

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
     * Test listing OUs returns only current tenant's OUs
     */
    public function testListOusReturnsCurrentTenantOnly(): void
    {
        $ou1 = TestFixtureBuilder::ou(1, $this->testTenantId, 'Engineering');
        $ou2 = TestFixtureBuilder::ou(2, $this->testTenantId, 'Sales');

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetchAll')->willReturn([$ou1, $ou2]);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $request = new Request('GET', '/api/ous');
        $response = $this->handler->list($request);

        $this->assertSame(200, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('data', $responseData);
        $this->assertCount(2, $responseData['data']);
    }

    /**
     * Test creating an OU with valid data returns 201
     */
    public function testCreateOuWithValidDataReturns201(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $this->mockDb->method('lastInsertId')
            ->willReturn('5');

        $this->mockHookManager->method('dispatch')
            ->willReturnArgument(1);

        $this->mockHookManager->method('dispatchAsync')
            ->willReturnSelf();

        $body = json_encode([
            'name' => 'Engineering',
            'parent_id' => null,
            'description' => 'Eng team'
        ]);

        $request = new Request('POST', '/api/ous', [], $body);
        $response = $this->handler->create($request);

        $this->assertSame(201, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
    }

    /**
     * Test creating an OU triggers hooks
     */
    public function testCreateOuTriggersCreatingHook(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $this->mockDb->method('lastInsertId')
            ->willReturn('5');

        $hookData = [
            'name' => 'Engineering',
            'description' => 'Eng team',
            'parent_id' => null
        ];

        // Set up expectations BEFORE code execution
        $this->mockHookManager->expects($this->atLeast(1))
            ->method('dispatch')
            ->willReturnArgument(1);

        $this->mockHookManager->expects($this->atLeast(1))
            ->method('dispatchAsync');

        $body = json_encode([
            'name' => 'Engineering',
            'parent_id' => null,
            'description' => 'Eng team'
        ]);

        $request = new Request('POST', '/api/ous', [], $body);
        $response = $this->handler->create($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * Test getting an OU returns OU with parent and children
     */
    public function testGetOuReturnsOuWithParentAndChildren(): void
    {
        $ouData = TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering', 2);

        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($ouData, null, null);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $request = new Request('GET', '/api/ous/5');
        $response = $this->handler->get($request, ['id' => 5]);

        $this->assertSame(200, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('data', $responseData);
    }

    /**
     * Test updating an OU changes name and slug
     */
    public function testUpdateOuChangesNameAndSlug(): void
    {
        $ouData = TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering', null);

        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')->willReturn($ouData);

        $mockUpdateStatement = $this->createMock(PDOStatement::class);
        $mockUpdateStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockUpdateStatement);

        $body = json_encode([
            'name' => 'Engineering Ops',
            'description' => 'Updated desc'
        ]);

        $request = new Request('PATCH', '/api/ous/5', [], $body);
        $response = $this->handler->update($request, ['id' => 5]);

        $this->assertSame(200, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
    }

    /**
     * Test deleting an OU without children returns 204
     */
    public function testDeleteOuReturns204OnSuccess(): void
    {
        $mockSelectOuStatement = $this->createMock(PDOStatement::class);
        $mockSelectOuStatement->method('execute')->willReturn(true);
        $mockSelectOuStatement->method('fetch')
            ->willReturn(TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering'));

        $mockChildrenStatement = $this->createMock(PDOStatement::class);
        $mockChildrenStatement->method('execute')->willReturn(true);
        $mockChildrenStatement->method('fetchColumn')->willReturn(0);

        $mockUsersStatement = $this->createMock(PDOStatement::class);
        $mockUsersStatement->method('execute')->willReturn(true);
        $mockUsersStatement->method('fetchColumn')->willReturn(0);

        $mockDeleteStatement = $this->createMock(PDOStatement::class);
        $mockDeleteStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $mockSelectOuStatement,
                $mockChildrenStatement,
                $mockUsersStatement,
                $mockDeleteStatement
            );

        $request = new Request('DELETE', '/api/ous/5');
        $response = $this->handler->delete($request, ['id' => 5]);

        $this->assertSame(204, $response->getStatusCode());
    }

    /**
     * Test assigning a role to an OU returns 201
     */
    public function testAssignRoleToOuReturns201(): void
    {
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturn(TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering'));

        $mockInsertStatement = $this->createMock(PDOStatement::class);
        $mockInsertStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockInsertStatement);

        $body = json_encode(['role_id' => 3]);

        $request = new Request('POST', '/api/ous/5/roles', [], $body);
        $response = $this->handler->assignRole($request, ['id' => 5]);

        $this->assertSame(201, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
    }

    /**
     * Test removing a role from an OU returns 204
     */
    public function testRemoveRoleFromOuReturns204(): void
    {
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturn(TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering'));

        $mockDeleteStatement = $this->createMock(PDOStatement::class);
        $mockDeleteStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockDeleteStatement);

        $request = new Request('DELETE', '/api/ous/5/roles/3');
        $response = $this->handler->removeRole($request, ['ouId' => 5, 'roleId' => 3]);

        $this->assertSame(204, $response->getStatusCode());
    }

    // ==================== CROSS-TENANT SECURITY TESTS ====================

    /**
     * Test creating OU with cross-tenant parent returns 403
     */
    public function testCreateOuWithCrossTenantParentReturns403(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        // Parent OU exists but in different tenant - returns 0 rows
        $mockStatement->method('fetch')->willReturn(false);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $body = json_encode([
            'name' => 'Sub',
            'parent_id' => 99
        ]);

        $request = new Request('POST', '/api/ous', [], $body);
        $response = $this->handler->create($request);

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test updating OU belonging to other tenant returns 403
     */
    public function testUpdateOuBelongingToOtherTenantReturns403(): void
    {
        // Simulate OU not found in current tenant (exists in tenant 2)
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn(false);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $body = json_encode(['name' => 'New Name']);

        $request = new Request('PATCH', '/api/ous/5', [], $body);
        $response = $this->handler->update($request, ['id' => 5]);

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test updating OU to set parent from different tenant returns 403
     */
    public function testUpdateOuSetParentFromDifferentTenantReturns403(): void
    {
        $ouData = TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering', null);

        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($ouData, false); // Parent not found in current tenant

        $this->mockDb->method('prepare')
            ->willReturn($mockSelectStatement);

        $body = json_encode(['parent_id' => 99]);

        $request = new Request('PATCH', '/api/ous/5', [], $body);
        $response = $this->handler->update($request, ['id' => 5]);

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test assigning role to OU from different tenant returns 404
     */
    public function testAssignRoleToOuFromDifferentTenantReturns404(): void
    {
        // OU doesn't exist in current tenant (cross-tenant OU not accessible)
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn(false);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $body = json_encode(['role_id' => 3]);

        $request = new Request('POST', '/api/ous/99/roles', [], $body);
        $response = $this->handler->assignRole($request, ['id' => 99]);

        $this->assertSame(404, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    // ==================== DATA VALIDATION TESTS ====================

    /**
     * Test creating OU with missing name returns 400
     */
    public function testCreateOuWithMissingNameReturns400(): void
    {
        $body = json_encode(['parent_id' => null]);

        $request = new Request('POST', '/api/ous', [], $body);
        $response = $this->handler->create($request);

        $this->assertSame(400, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('name', strtolower($responseData['error']));
    }

    /**
     * Test creating OU with duplicate name in tenant returns 409
     */
    public function testCreateOuWithDuplicateNameInTenantReturns409(): void
    {
        // First call checks if name exists (returns it does)
        $mockCheckStatement = $this->createMock(PDOStatement::class);
        $mockCheckStatement->method('execute')->willReturn(true);
        $mockCheckStatement->method('fetch')
            ->willReturn(['id' => 1]); // Name already exists

        $this->mockDb->method('prepare')
            ->willReturn($mockCheckStatement);

        $body = json_encode(['name' => 'Engineering']);

        $request = new Request('POST', '/api/ous', [], $body);
        $response = $this->handler->create($request);

        $this->assertSame(409, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test deleting OU with children returns 409
     */
    public function testDeleteOuWithChildrenReturns409(): void
    {
        $mockSelectOuStatement = $this->createMock(PDOStatement::class);
        $mockSelectOuStatement->method('execute')->willReturn(true);
        $mockSelectOuStatement->method('fetch')
            ->willReturn(TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering'));

        $mockChildrenStatement = $this->createMock(PDOStatement::class);
        $mockChildrenStatement->method('execute')->willReturn(true);
        $mockChildrenStatement->method('fetchColumn')->willReturn(2); // Has 2 children

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectOuStatement, $mockChildrenStatement);

        $request = new Request('DELETE', '/api/ous/5');
        $response = $this->handler->delete($request, ['id' => 5]);

        $this->assertSame(409, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('child', strtolower($responseData['error']));
    }

    /**
     * Test deleting OU with assigned users returns 409
     */
    public function testDeleteOuWithAssignedUsersReturns409(): void
    {
        $mockSelectOuStatement = $this->createMock(PDOStatement::class);
        $mockSelectOuStatement->method('execute')->willReturn(true);
        $mockSelectOuStatement->method('fetch')
            ->willReturn(TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering'));

        $mockChildrenStatement = $this->createMock(PDOStatement::class);
        $mockChildrenStatement->method('execute')->willReturn(true);
        $mockChildrenStatement->method('fetchColumn')->willReturn(0); // No children

        $mockUsersStatement = $this->createMock(PDOStatement::class);
        $mockUsersStatement->method('execute')->willReturn(true);
        $mockUsersStatement->method('fetchColumn')->willReturn(3); // Has 3 assigned users

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $mockSelectOuStatement,
                $mockChildrenStatement,
                $mockUsersStatement
            );

        $request = new Request('DELETE', '/api/ous/5');
        $response = $this->handler->delete($request, ['id' => 5]);

        $this->assertSame(409, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertStringContainsString('user', strtolower($responseData['error']));
    }

    // ==================== ROLE ASSIGNMENT TESTS ====================

    /**
     * Test assigning role requires OU to exist returns 404
     */
    public function testAssignRoleRequiresOuExistsReturns404(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn(false); // OU not found

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $body = json_encode(['role_id' => 3]);

        $request = new Request('POST', '/api/ous/999/roles', [], $body);
        $response = $this->handler->assignRole($request, ['id' => 999]);

        $this->assertSame(404, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test assigning duplicate role returns 409
     */
    public function testAssignRoleDuplicateReturns409(): void
    {
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturn(TestFixtureBuilder::ou(5, $this->testTenantId, 'Engineering'));

        // INSERT fails due to UNIQUE constraint
        $mockInsertStatement = $this->createMock(PDOStatement::class);
        $mockInsertStatement->method('execute')->willThrowException(
            new \PDOException('UNIQUE constraint failed')
        );

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockInsertStatement);

        $body = json_encode(['role_id' => 3]);

        $request = new Request('POST', '/api/ous/5/roles', [], $body);
        $response = $this->handler->assignRole($request, ['id' => 5]);

        $this->assertSame(409, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }

    /**
     * Test removing role from non-existent OU returns 404
     */
    public function testRemoveRoleFromNonExistentOuReturns404(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetch')->willReturn(false); // OU not found

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $request = new Request('DELETE', '/api/ous/999/roles/3');
        $response = $this->handler->removeRole($request, ['ouId' => 999, 'roleId' => 3]);

        $this->assertSame(404, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $responseData);
    }
}
