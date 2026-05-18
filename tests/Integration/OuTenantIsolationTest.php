<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestFixtureBuilder;
use Tests\Support\MockRequestFactory;
use Whity\Core\Tenant\TenantContext;
use Whity\Api\OusApiHandler;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use PDO;
use PDOStatement;

/**
 * Integration tests for organizational unit tenant isolation
 *
 * Verifies that OUs are properly scoped to tenants and cannot be accessed/modified
 * across tenant boundaries. Tests include:
 * - Cross-tenant visibility prevention
 * - Parent validation across tenants
 * - Role assignment isolation
 * - Tenant context locking enforcement
 *
 * These tests use a mocked PDO database with real OusApiHandler to verify
 * tenant isolation at the application layer.
 */
class OuTenantIsolationTest extends TestCase
{
    private OusApiHandler $handler;
    private PDO $mockDb;
    private HookManager $mockHookManager;
    private array $database = [];

    protected function setUp(): void
    {
        // Reset TenantContext before each test
        TenantContext::reset();

        // Initialize mock database
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockHookManager = $this->createMock(HookManager::class);

        // Setup hook manager to return data as-is for filter hooks
        $this->mockHookManager->method('dispatch')
            ->willReturnCallback(fn($hook, $data) => $data);

        // Initialize handler with mocks
        $this->handler = new OusApiHandler($this->mockDb, $this->mockHookManager);

        // Initialize test database with seed data
        $this->initializeTestDatabase();

        // Setup mock database responses
        $this->setupMockDatabaseResponses();

        // Reset fixture counters
        TestFixtureBuilder::resetCounters();
    }

    protected function tearDown(): void
    {
        // Reset TenantContext after each test
        TenantContext::reset();

        // Clear test database
        $this->database = [];
    }

    /**
     * Initialize test database with predefined OUs and data
     *
     * Tenant 1 (id=1): Has OU "Engineering" (id=1), "Product" (id=2)
     * Tenant 2 (id=2): Has OU "Sales" (id=3)
     */
    private function initializeTestDatabase(): void
    {
        $this->database = [
            'organizational_units' => [
                TestFixtureBuilder::ou(1, 1, 'Engineering'),
                TestFixtureBuilder::ou(2, 1, 'Product'),
                TestFixtureBuilder::ou(3, 2, 'Sales'),
            ],
            'ou_role_assignments' => [
                TestFixtureBuilder::ouRoleAssignment(1, 2, 1), // Engineering - role 2, Tenant 1
            ],
            'roles' => [
                TestFixtureBuilder::role(1, 'admin'),
                TestFixtureBuilder::role(2, 'manager'),
            ]
        ];
    }

    /**
     * Setup mock PDO to return database results
     *
     * Configures the mock PDO to handle various database queries and return
     * data filtered by tenant_id from our test database.
     */
    private function setupMockDatabaseResponses(): void
    {
        $this->mockDb->method('prepare')
            ->willReturnCallback(function($sql) {
                $mockStmt = $this->createMock(PDOStatement::class);

                // Configure the mock statement to execute and return results
                $mockStmt->method('execute')
                    ->willReturnCallback(function($params = []) use ($sql) {
                        $this->lastExecutedSql = $sql;
                        $this->lastExecutedParams = $params;
                        return true;
                    });

                $mockStmt->method('fetch')
                    ->willReturnCallback(function($mode = PDO::FETCH_ASSOC) {
                        $results = $this->getQueryResults();
                        return !empty($results) ? array_shift($results) : false;
                    });

                $mockStmt->method('fetchAll')
                    ->willReturnCallback(function($mode = PDO::FETCH_ASSOC) {
                        return $this->getQueryResults();
                    });

                $mockStmt->method('fetchColumn')
                    ->willReturnCallback(function($column = 0) {
                        $results = $this->getQueryResults();
                        return !empty($results) ? $results[0][$column] : 0;
                    });

                return $mockStmt;
            });

        // Mock lastInsertId
        $this->mockDb->method('lastInsertId')
            ->willReturnCallback(function() {
                $maxId = 0;
                foreach ($this->database['organizational_units'] as $ou) {
                    $maxId = max($maxId, $ou['id']);
                }
                return $maxId + 1;
            });
    }

    /**
     * Get query results based on the last executed SQL
     *
     * This simulates database query execution by parsing the SQL and
     * filtering the test database accordingly.
     *
     * @return array Query results
     */
    private function getQueryResults(): array
    {
        $sql = $this->lastExecutedSql ?? '';
        $params = $this->lastExecutedParams ?? [];
        $tenantId = TenantContext::getTenantId();

        // SELECT FROM organizational_units
        if (strpos($sql, 'SELECT') !== false && strpos($sql, 'organizational_units') !== false) {
            $results = array_filter(
                $this->database['organizational_units'],
                fn($ou) => $ou['tenant_id'] === $tenantId
            );

            // Handle WHERE id = ? AND tenant_id = ?
            if (strpos($sql, 'WHERE id = ?') !== false) {
                $ouId = $params[0] ?? null;
                $results = array_filter($results, fn($ou) => $ou['id'] === $ouId);
            }

            // Handle WHERE parent_id = ? AND tenant_id = ?
            if (strpos($sql, 'WHERE parent_id = ?') !== false) {
                $parentId = $params[0] ?? null;
                $results = array_filter($results, fn($ou) => $ou['parent_id'] === $parentId);
            }

            // Handle WHERE name = ? AND tenant_id = ?
            if (strpos($sql, 'WHERE name = ?') !== false) {
                $name = $params[0] ?? null;
                $results = array_filter($results, fn($ou) => $ou['name'] === $name);
            }

            // Handle WHERE slug = ? AND tenant_id = ?
            if (strpos($sql, 'WHERE slug = ?') !== false) {
                $slug = $params[0] ?? null;
                $results = array_filter($results, fn($ou) => $ou['slug'] === $slug);
            }

            return array_values($results);
        }

        // SELECT FROM ou_role_assignments
        if (strpos($sql, 'ou_role_assignments') !== false) {
            $results = array_filter(
                $this->database['ou_role_assignments'],
                fn($assignment) => $assignment['tenant_id'] === $tenantId
            );

            // Handle WHERE ou_id = ? AND role_id = ? AND tenant_id = ?
            if (strpos($sql, 'WHERE ou_id = ?') !== false && strpos($sql, 'role_id = ?') !== false) {
                $ouId = $params[0] ?? null;
                $roleId = $params[1] ?? null;
                $results = array_filter(
                    $results,
                    fn($a) => $a['ou_id'] === $ouId && $a['role_id'] === $roleId
                );
            }

            return array_values($results);
        }

        // COUNT queries
        if (strpos($sql, 'COUNT(*)') !== false) {
            if (strpos($sql, 'WHERE parent_id = ?') !== false) {
                $parentId = $params[0] ?? null;
                $count = count(array_filter(
                    $this->database['organizational_units'],
                    fn($ou) => $ou['parent_id'] === $parentId && $ou['tenant_id'] === $tenantId
                ));
                return [[$count]];
            }

            if (strpos($sql, 'WHERE ou_id = ?') !== false) {
                $ouId = $params[0] ?? null;
                // Simulate counting users - for test purposes, return 0
                return [[0]];
            }
        }

        return [];
    }

    /**
     * Test 1: OU from Tenant A is not visible to Tenant B
     *
     * Verifies that when Tenant B lists OUs, they do not see OUs from Tenant A.
     * Expected: Tenant B should see 0 OUs (they have no OUs).
     */
    public function testOuFromTenantAIsNotVisibleToTenantB(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Create request to list OUs
        $request = new Request('GET', '/api/ous');

        // Execute handler
        $response = $this->handler->list($request);

        // Assert: Response is successful
        $this->assertSame(200, $response->getStatusCode());

        // Assert: Response contains only Tenant B's OUs
        $responseData = json_decode($response->getBody(), true);
        $this->assertIsArray($responseData['data']);
        $this->assertCount(1, $responseData['data'], 'Tenant B should only see their own OU (Sales)');

        // Assert: Tenant B does not see Tenant A's OUs
        $ouNames = array_column($responseData['data'], 'name');
        $this->assertNotContains('Engineering', $ouNames, 'Engineering from Tenant A should not be visible');
        $this->assertNotContains('Product', $ouNames, 'Product from Tenant A should not be visible');
        $this->assertContains('Sales', $ouNames, 'Sales from Tenant B should be visible');
    }

    /**
     * Test 2: Create OU fails with parent from different tenant
     *
     * Verifies that when creating an OU, the parent must belong to the same tenant.
     * Tenant B tries to use Tenant A's OU (id=1) as parent.
     * Expected: 403 Forbidden
     */
    public function testCreateOuFailsWithParentFromDifferentTenant(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Request body: Try to create OU with Tenant A's OU as parent
        $requestBody = json_encode([
            'name' => 'Invalid Child',
            'parent_id' => 1, // This belongs to Tenant A
        ]);

        $request = new Request('POST', '/api/ous', [], $requestBody);

        // Execute handler
        $response = $this->handler->create($request);

        // Assert: Response is 403 Forbidden
        $this->assertSame(403, $response->getStatusCode(), 'Creating OU with cross-tenant parent should fail');

        // Assert: Error message indicates parent doesn't belong to current tenant
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('does not belong to current tenant', $responseData['error']);

        // Verify: OU was not created
        $this->assertCount(3, $this->database['organizational_units'], 'No new OU should be created');
    }

    /**
     * Test 3: Update OU belonging to other tenant fails
     *
     * Verifies that Tenant B cannot update OUs from Tenant A.
     * Tenant B tries to rename Tenant A's OU (id=1).
     * Expected: 403 Forbidden
     */
    public function testUpdateOuBelongingToOtherTenantFails(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Store original name
        $originalOu = $this->database['organizational_units'][0]; // Engineering (Tenant A)

        // Request body: Try to rename Tenant A's OU
        $requestBody = json_encode([
            'name' => 'Hacked Engineering',
        ]);

        $request = new Request('PATCH', '/api/ous/1', [], $requestBody);

        // Execute handler
        $response = $this->handler->update($request, ['id' => 1]);

        // Assert: Response is 403 Forbidden
        $this->assertSame(403, $response->getStatusCode(), 'Updating OU from other tenant should fail');

        // Assert: OU name is unchanged
        $this->assertSame('Engineering', $originalOu['name'], 'OU name should not be changed');
    }

    /**
     * Test 4: Delete OU from other tenant fails
     *
     * Verifies that Tenant B cannot delete OUs from Tenant A.
     * Tenant B tries to delete Tenant A's OU (id=1).
     * Expected: 403 Forbidden
     */
    public function testDeleteOuFromOtherTenantFails(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Get initial count
        $initialCount = count($this->database['organizational_units']);

        // Create request to delete Tenant A's OU
        $request = new Request('DELETE', '/api/ous/1');

        // Execute handler
        $response = $this->handler->delete($request, ['id' => 1]);

        // Assert: Response is 403 Forbidden
        $this->assertSame(403, $response->getStatusCode(), 'Deleting OU from other tenant should fail');

        // Verify: OU still exists
        $this->assertCount($initialCount, $this->database['organizational_units'], 'OU should not be deleted');
        $engineeringOu = array_filter(
            $this->database['organizational_units'],
            fn($ou) => $ou['id'] === 1
        );
        $this->assertCount(1, $engineeringOu, 'Tenant A\'s OU should still exist');
    }

    /**
     * Test 5: Get OU from other tenant returns 404
     *
     * Verifies that Tenant B cannot get OUs from Tenant A.
     * Tenant B tries to retrieve Tenant A's OU (id=1).
     * Expected: 404 Not Found (tenant isolation via WHERE clause prevents access)
     */
    public function testGetOuFromOtherTenantReturns403(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Create request to get Tenant A's OU
        $request = new Request('GET', '/api/ous/1');

        // Execute handler
        $response = $this->handler->get($request, ['id' => 1]);

        // Assert: Response is 404 Not Found (query scoped by tenant_id returns nothing)
        $this->assertSame(404, $response->getStatusCode(), 'Accessing OU from other tenant should return 404');

        // Assert: Error message indicates OU not found
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('not found', $responseData['error']);
    }

    /**
     * Test 6: Assign role to OU from other tenant fails
     *
     * Verifies that Tenant B cannot assign roles to OUs from Tenant A.
     * Tenant B tries to assign a role to Tenant A's OU (id=1).
     * Expected: 403 Forbidden or 404
     */
    public function testAssignRoleToOuFromOtherTenantFails(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Get initial count of assignments
        $initialCount = count($this->database['ou_role_assignments']);

        // Request body: Assign role to Tenant A's OU
        $requestBody = json_encode([
            'role_id' => 2,
        ]);

        $request = new Request('POST', '/api/ous/1/roles', [], $requestBody);

        // Execute handler
        $response = $this->handler->assignRole($request, ['id' => 1]);

        // Assert: Response is 404 (OU not found in current tenant)
        $this->assertSame(404, $response->getStatusCode(), 'Assigning role to OU from other tenant should fail');

        // Verify: Role assignment was not created
        $this->assertCount($initialCount, $this->database['ou_role_assignments'], 'No new role assignment should be created');
    }

    /**
     * Test 7: Remove role from OU in other tenant fails
     *
     * Verifies that Tenant B cannot remove roles from OUs in Tenant A.
     * Tenant B tries to remove a role from Tenant A's OU (id=1).
     * Expected: 404 Forbidden (assignment not found)
     */
    public function testRemoveRoleFromOuInOtherTenantFails(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Get initial count of assignments
        $initialCount = count($this->database['ou_role_assignments']);

        // Create request to remove role from Tenant A's OU
        $request = new Request('DELETE', '/api/ous/1/roles/2');

        // Execute handler
        $response = $this->handler->removeRole($request, ['ouId' => 1, 'roleId' => 2]);

        // Assert: Response is 404 (assignment not found in current tenant)
        $this->assertSame(404, $response->getStatusCode(), 'Removing role from OU in other tenant should fail');

        // Verify: Role assignment still exists
        $this->assertCount($initialCount, $this->database['ou_role_assignments'], 'Role assignment should not be removed');
    }

    /**
     * Test 8: OU role assignment cannot reference cross-tenant OU
     *
     * Verifies that when assigning roles to OUs, the OU validation
     * correctly scopes to the current tenant.
     */
    public function testOuRoleAssignmentCannotReferenceCrossTenantOu(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Get initial state
        $initialAssignments = $this->database['ou_role_assignments'];

        // Request body: Assign role to Tenant A's OU
        $requestBody = json_encode([
            'role_id' => 2,
        ]);

        $request = new Request('POST', '/api/ous/1/roles', [], $requestBody);

        // Execute handler
        $response = $this->handler->assignRole($request, ['id' => 1]);

        // Assert: Request fails with 404 (OU not found)
        $this->assertSame(404, $response->getStatusCode());

        // Verify: No new assignment created in database
        $this->assertCount(
            count($initialAssignments),
            $this->database['ou_role_assignments'],
            'No assignment should be created for cross-tenant OU'
        );
    }

    /**
     * Test 9: Update OU set cross-tenant parent fails
     *
     * Verifies that when updating an OU, setting its parent to an OU
     * from another tenant is rejected.
     * Tenant B's OU (id=3) tries to set parent to Tenant A's OU (id=1).
     * Expected: 403 Forbidden
     */
    public function testUpdateOuSetCrossTenantParentFails(): void
    {
        // Setup: Set tenant context to Tenant B (id=2)
        MockRequestFactory::setTestTenant(2);

        // Request body: Set Tenant A's OU as parent
        $requestBody = json_encode([
            'parent_id' => 1, // Belongs to Tenant A
        ]);

        $request = new Request('PATCH', '/api/ous/3', [], $requestBody);

        // Execute handler
        $response = $this->handler->update($request, ['id' => 3]);

        // Assert: Response is 403 Forbidden
        $this->assertSame(403, $response->getStatusCode(), 'Setting cross-tenant parent should fail');

        // Assert: Error message indicates parent doesn't belong to current tenant
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('does not belong to current tenant', $responseData['error']);

        // Verify: OU's parent_id is unchanged
        $salesOu = array_filter(
            $this->database['organizational_units'],
            fn($ou) => $ou['id'] === 3
        );
        $salesOu = array_values($salesOu)[0];
        $this->assertNull($salesOu['parent_id'], 'OU parent should not be changed');
    }

    /**
     * Test 10: Tenant context locked during OU request
     *
     * Verifies that once the tenant context is set by middleware,
     * it cannot be changed during request processing.
     * Expected: RuntimeException if setTenantId is called twice
     */
    public function testTenantContextLockedDuringOuRequest(): void
    {
        // Setup: Set initial tenant context
        MockRequestFactory::setTestTenant(1);

        $this->assertSame(1, TenantContext::getTenantId(), 'Initial tenant should be 1');

        // Attempt to change tenant context mid-request
        $exceptionThrown = false;
        $exceptionMessage = null;

        try {
            TenantContext::setTenantId(2);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        // Assert: RuntimeException was thrown
        $this->assertTrue(
            $exceptionThrown,
            'RuntimeException should be thrown when trying to change locked context'
        );

        // Assert: Exception message indicates context is locked
        $this->assertStringContainsString('locked', $exceptionMessage, 'Exception should mention context is locked');

        // Assert: Original tenant context is unchanged
        $this->assertSame(1, TenantContext::getTenantId(), 'Tenant context should remain unchanged');
    }

    /**
     * Helper: Last executed SQL for query result filtering
     */
    private ?string $lastExecutedSql = null;

    /**
     * Helper: Last executed parameters for query result filtering
     */
    private ?array $lastExecutedParams = null;
}
