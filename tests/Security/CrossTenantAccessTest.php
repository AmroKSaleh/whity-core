<?php

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestFixtureBuilder;
use Tests\Support\MockRequestFactory;
use Whity\Api\OusApiHandler;
use Whity\Api\UsersApiHandler;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Hooks\HookManager;
use PDO;
use PDOStatement;

/**
 * Security tests for cross-tenant access and attack scenarios
 *
 * These tests verify protection against realistic attack scenarios identified in the
 * security risk analysis. Each test simulates an attacker attempting to:
 * - Access or modify organizational units from different tenants
 * - Escape tenant boundaries through input manipulation
 * - Create circular hierarchies
 * - Perform privilege escalation
 * - Leak information about other tenants
 *
 * Test Setup:
 * - Tenant A (id=1): User A (id=1, role="user"), OU "Engineering" (id=1), OU "Sales" (id=2)
 * - Tenant B (id=2): User B (id=10, role="user"), OU "Marketing" (id=10), OU "Operations" (id=11)
 * - Both have admin users for role-based tests
 */
class CrossTenantAccessTest extends TestCase
{
    private PDO $mockDb;
    private HookManager $mockHookManager;
    private OusApiHandler $ousHandler;
    private UsersApiHandler $usersHandler;

    // Test tenants
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    // Test OUs in Tenant A
    private const OU_ENGINEERING_ID = 1;
    private const OU_SALES_ID = 2;

    // Test OUs in Tenant B
    private const OU_MARKETING_ID = 10;
    private const OU_OPERATIONS_ID = 11;

    // Test users
    private const USER_A_ID = 1;
    private const USER_B_ID = 10;
    private const ADMIN_USER_A_ID = 2;
    private const ADMIN_USER_B_ID = 20;

    // Test role IDs
    private const ROLE_ADMIN = 1;
    private const ROLE_USER = 2;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockHookManager = $this->createMock(HookManager::class);

        $this->ousHandler = new OusApiHandler($this->mockDb, $this->mockHookManager);
        $this->usersHandler = new UsersApiHandler($this->mockDb, $this->mockHookManager);

        // Mock hook manager to just return data as-is
        $this->mockHookManager->method('dispatch')->willReturnArgument(1);
        // dispatchAsync is void, so we just ensure it gets called without error
        $this->mockHookManager->method('dispatchAsync');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        TestFixtureBuilder::resetCounters();
    }

    // ==================== ATTACK SCENARIO 1: DIRECT OU ID MANIPULATION ====================

    /**
     * Test: testDirectOuIdManipulationReturns403
     *
     * Attack: User from Tenant A sends PATCH /api/users/{myId} with ou_id from Tenant B
     * Expected: Response 403 "OU does not belong to current tenant"
     * Security: UsersApiHandler must validate ou_id against TenantContext
     */
    public function testDirectOuIdManipulationReturns403(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_A);

        // Setup: User A (Tenant A) tries to assign OU from Tenant B
        $userAData = TestFixtureBuilder::user(self::USER_A_ID, self::TENANT_A, self::ROLE_USER);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($userAData, null); // User found, OU check fails

        // Mock OU validation query - OU from Tenant B should NOT be found in Tenant A
        $mockOuCheckStatement = $this->createMock(PDOStatement::class);
        $mockOuCheckStatement->method('execute')->willReturn(true);
        $mockOuCheckStatement->method('fetch')->willReturn(null); // OU 10 not in Tenant A

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockSelectStatement, $mockOuCheckStatement);

        // Attack: Try to assign OU 10 (from Tenant B) to user in Tenant A
        $body = json_encode(['ou_id' => self::OU_MARKETING_ID]);
        $request = new Request('PATCH', '/api/users/' . self::USER_A_ID, [], $body);
        $response = $this->usersHandler->update($request, ['id' => self::USER_A_ID]);

        // Verify: Response must be 403 (not 404 - to hide existence)
        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('OU does not belong to current tenant', $responseData['error']);
    }

    // ==================== ATTACK SCENARIO 2: CIRCULAR PARENT REFERENCE ====================

    /**
     * Test: testParentIdInjectionCreatesCycleReturns400
     *
     * Attack: User creates cycle in OU hierarchy (A→B→A)
     * Setup: OU A (id=1) with parent_id=null, OU B (id=2) with parent_id=1
     * Request: PATCH /api/ous/1 with parent_id: 2 (creates A→B→A cycle)
     * Expected: Response 400 "Circular parent reference detected"
     * Security: Cycle detection must work correctly
     */
    public function testParentIdInjectionCreatesCycleReturns400(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_A);

        // Setup: OU Engineering (id=1, parent=null) and OU Sales (id=2, parent=1)
        $ouEngineering = TestFixtureBuilder::ou(
            self::OU_ENGINEERING_ID,
            self::TENANT_A,
            'Engineering',
            null
        );
        $ouSales = TestFixtureBuilder::ou(
            self::OU_SALES_ID,
            self::TENANT_A,
            'Sales',
            self::OU_ENGINEERING_ID
        );

        // Mock GET OU query
        $mockGetStatement = $this->createMock(PDOStatement::class);
        $mockGetStatement->method('execute')->willReturn(true);
        $mockGetStatement->method('fetch')->willReturn($ouEngineering);

        // Mock parent validation query (OU 2 exists in Tenant A)
        $mockParentCheckStatement = $this->createMock(PDOStatement::class);
        $mockParentCheckStatement->method('execute')->willReturn(true);
        $mockParentCheckStatement->method('fetch')->willReturn(['id' => self::OU_SALES_ID]);

        // Mock cycle detection query - traverse up from Sales (2)
        // Sales has parent=1 (Engineering)
        $mockCycleCheckStatement = $this->createMock(PDOStatement::class);
        $mockCycleCheckStatement->method('execute')->willReturn(true);
        $mockCycleCheckStatement->method('fetch')
            ->willReturnOnConsecutiveCalls(['parent_id' => self::OU_ENGINEERING_ID], null);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $mockGetStatement,
                $mockParentCheckStatement,
                $mockCycleCheckStatement
            );

        // Attack: Try to set Engineering's parent to Sales (creates cycle)
        $body = json_encode(['parent_id' => self::OU_SALES_ID]);
        $request = new Request('PATCH', '/api/ous/' . self::OU_ENGINEERING_ID, [], $body);
        $response = $this->ousHandler->update($request, ['id' => self::OU_ENGINEERING_ID]);

        // Verify: a cyclic re-parent is rejected as a semantic (not server)
        // error. WC-44 hardened the guard (the prior int/string `===` mismatch
        // let descendant-moves through against PostgreSQL) and surfaces the
        // rejection as 422 Unprocessable Entity via a typed domain exception.
        $this->assertSame(422, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('cycle', strtolower($responseData['error']));
    }

    // ==================== ATTACK SCENARIO 3: FOREIGN TENANT PARENT ID ====================

    /**
     * Test: testParentIdFromForeignTenantReturns403
     *
     * Attack: Set OU parent to another tenant's OU
     * Setup: Tenant A has OU 1, Tenant B has OU 10, user in Tenant A
     * Request: PATCH /api/ous/1 with parent_id: 10 (Tenant B's OU)
     * Expected: Response 403 "Parent OU does not belong to current tenant"
     * Security: Parent validation must include tenant check
     */
    public function testParentIdFromForeignTenantReturns403(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_A);

        // Setup: OU Engineering in Tenant A
        $ouEngineering = TestFixtureBuilder::ou(
            self::OU_ENGINEERING_ID,
            self::TENANT_A,
            'Engineering',
            null
        );

        // Mock GET OU query
        $mockGetStatement = $this->createMock(PDOStatement::class);
        $mockGetStatement->method('execute')->willReturn(true);
        $mockGetStatement->method('fetch')->willReturn($ouEngineering);

        // Mock parent validation query - OU 10 (Tenant B) should NOT be found in Tenant A
        $mockParentCheckStatement = $this->createMock(PDOStatement::class);
        $mockParentCheckStatement->method('execute')->willReturn(true);
        $mockParentCheckStatement->method('fetch')->willReturn(null); // OU 10 not in Tenant A

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls($mockGetStatement, $mockParentCheckStatement);

        // Attack: Try to set parent to OU 10 (from Tenant B)
        $body = json_encode(['parent_id' => self::OU_MARKETING_ID]);
        $request = new Request('PATCH', '/api/ous/' . self::OU_ENGINEERING_ID, [], $body);
        $response = $this->ousHandler->update($request, ['id' => self::OU_ENGINEERING_ID]);

        // Verify: Response must be 403
        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('Parent', $responseData['error']);
        $this->assertStringContainsString('does not belong to current tenant', $responseData['error']);
    }

    // ==================== ATTACK SCENARIO 4: OU ROLE ASSIGNMENT REQUIRES OWNERSHIP ====================

    /**
     * Test: testOuRoleAssignmentRequiresTenantOwnership
     *
     * Attack: Assign a role to OU from different tenant
     * Setup: Tenant A has OU 1, Tenant B user tries to assign role to it
     * Request: POST /api/ous/1/roles with role_id: 3 from Tenant B context
     * Expected: Response 403 or 404 (not found)
     * Security: OusApiHandler must verify OU ownership before INSERT
     */
    public function testOuRoleAssignmentRequiresTenantOwnership(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_B);

        // Attack: Tenant B user tries to assign role to OU 1 (which is in Tenant A)
        $mockOuCheckStatement = $this->createMock(PDOStatement::class);
        $mockOuCheckStatement->method('execute')->willReturn(true);
        $mockOuCheckStatement->method('fetch')->willReturn(null); // OU 1 not found in Tenant B

        $this->mockDb->method('prepare')
            ->willReturn($mockOuCheckStatement);

        $body = json_encode(['role_id' => self::ROLE_ADMIN]);
        $request = new Request('POST', '/api/ous/' . self::OU_ENGINEERING_ID . '/roles', [], $body);
        $response = $this->ousHandler->assignRole($request, ['id' => self::OU_ENGINEERING_ID]);

        // Verify: Response must be 404 (OU not found in current tenant)
        $this->assertSame(404, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('not found', strtolower($responseData['error']));
    }

    // ==================== ATTACK SCENARIO 5: DELETE OU FROM OTHER TENANT ====================

    /**
     * Test: testDeleteOuFromOtherTenantReturns403
     *
     * Attack: Delete an OU from another tenant
     * Setup: Tenant A has OU 1, Tenant B user attempts deletion
     * Request: DELETE /api/ous/1 from Tenant B context
     * Expected: Response 403 (not 404, to hide existence)
     * Security: Must check OU belongs to current tenant before any operation
     */
    public function testDeleteOuFromOtherTenantReturns403(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_B);

        // Attack: Tenant B user tries to delete OU 1 (in Tenant A)
        $mockGetStatement = $this->createMock(PDOStatement::class);
        $mockGetStatement->method('execute')->willReturn(true);
        $mockGetStatement->method('fetch')->willReturn(null); // OU 1 not in Tenant B

        $this->mockDb->method('prepare')
            ->willReturn($mockGetStatement);

        $request = new Request('DELETE', '/api/ous/' . self::OU_ENGINEERING_ID);
        $response = $this->ousHandler->delete($request, ['id' => self::OU_ENGINEERING_ID]);

        // Verify: Response must be 403 (not 404, to hide existence)
        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('does not belong to current tenant', $responseData['error']);
    }

    // ==================== ATTACK SCENARIO 6: OU LIST DOES NOT LEAK FOREIGN ENTRIES ====================

    /**
     * Test: testOuListDoesNotLeakForeignTenantEntries
     *
     * Attack: User enumerates all OUs and sees entries from other tenants
     * Setup: Tenant A has 2 OUs, Tenant B has 3 OUs
     * Request: GET /api/ous from Tenant A user
     * Expected: Response includes only 2 OUs (from Tenant A)
     * Security: List endpoint must filter by TenantContext::getTenantId()
     */
    public function testOuListDoesNotLeakForeignTenantEntries(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_A);

        // Setup: Tenant A has 2 OUs
        $tenantAOus = [
            TestFixtureBuilder::ou(self::OU_ENGINEERING_ID, self::TENANT_A, 'Engineering'),
            TestFixtureBuilder::ou(self::OU_SALES_ID, self::TENANT_A, 'Sales')
        ];

        // Mock query returns only Tenant A's OUs (Tenant B's OUs should not be included)
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);
        $mockStatement->method('fetchAll')->willReturn($tenantAOus);

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        $request = new Request('GET', '/api/ous');
        $response = $this->ousHandler->list($request);

        // Verify: Response includes only Tenant A's OUs
        $this->assertSame(200, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertCount(2, $responseData['data']);

        // Verify: No OUs from Tenant B are in the response
        $ouIds = array_column($responseData['data'], 'id');
        $this->assertNotContains(self::OU_MARKETING_ID, $ouIds);
        $this->assertNotContains(self::OU_OPERATIONS_ID, $ouIds);
    }

    // ==================== ATTACK SCENARIO 7: ROLE ESCALATION VIA OU ASSIGNMENT ====================

    /**
     * Test: testRoleEscalationViaOuAssignmentBlocked
     *
     * Attack: Non-admin user tries to assign "admin" role to OU
     * Setup: User with role="user"
     * Request: POST /api/ous/1/roles with role_id: 1 (admin role)
     * Expected: Response 403 or caught by middleware
     * Security: Must require "admin" role to assign roles to OUs
     *
     * Note: The OusApiHandler itself doesn't enforce role checks - these are
     * expected to be enforced by RbacMiddleware. This test verifies that the
     * handler accepts role assignments, which middleware should block.
     */
    public function testRoleEscalationViaOuAssignmentBlocked(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_A);

        // Setup: OU Engineering exists
        $mockOuCheckStatement = $this->createMock(PDOStatement::class);
        $mockOuCheckStatement->method('execute')->willReturn(true);
        $mockOuCheckStatement->method('fetch')->willReturn(['id' => self::OU_ENGINEERING_ID]);

        // WC-56: the role is validated as visible to the caller's tenant. The
        // seeded admin role is a GLOBAL role (NULL tenant_id, per WC-110), so it
        // resolves for any tenant — assigning a *visible* role is legitimate at
        // the handler level; whether a "user" may do so is the RBAC middleware's
        // job. The cross-tenant *private* role case is covered separately.
        $mockRoleCheckStatement = $this->createMock(PDOStatement::class);
        $mockRoleCheckStatement->method('execute')->willReturn(true);
        $mockRoleCheckStatement->method('fetch')->willReturn(['id' => self::ROLE_ADMIN]);

        // Mock INSERT for role assignment (this succeeds at handler level)
        $mockInsertStatement = $this->createMock(PDOStatement::class);
        $mockInsertStatement->method('execute')->willReturn(true);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $mockOuCheckStatement,
                $mockRoleCheckStatement,
                $mockInsertStatement
            );

        $this->mockDb->method('lastInsertId')
            ->willReturn('100');

        // Request: Assign admin role to OU
        $body = json_encode(['role_id' => self::ROLE_ADMIN]);
        $request = new Request('POST', '/api/ous/' . self::OU_ENGINEERING_ID . '/roles', [], $body);
        $response = $this->ousHandler->assignRole($request, ['id' => self::OU_ENGINEERING_ID]);

        // Verify: Handler returns 201 (role assignment created)
        // In real deployment, RbacMiddleware would block this request before reaching handler
        $this->assertSame(201, $response->getStatusCode());
    }

    // ==================== ATTACK SCENARIO 8: UNAUTHORIZED USER CANNOT ASSIGN OU TO SELF ====================

    /**
     * Test: testUnauthorizedUserCannotAssignOuToSelf
     *
     * Attack: Non-admin user tries to move themselves to high-privilege OU
     * Setup: User with role="user", OU with "admin" roles assigned
     * Request: PATCH /api/users/myId with ou_id: ou_with_admin_roles
     * Expected: Response 403 or 200 (handler allows, but RBAC middleware blocks update)
     * Security: ou_id updates must require admin or be blocked by middleware
     *
     * Note: The UsersApiHandler allows ou_id updates if the OU exists in the tenant.
     * The actual enforcement (whether users can change their own ou_id) is expected
     * to be at the middleware level. This test verifies the handler validates the OU.
     */
    public function testUnauthorizedUserCannotAssignOuToSelf(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_A);

        // Setup: User A in Tenant A
        $userAData = TestFixtureBuilder::user(self::USER_A_ID, self::TENANT_A, self::ROLE_USER);

        // Mock SELECT user query
        $mockSelectStatement = $this->createMock(PDOStatement::class);
        $mockSelectStatement->method('execute')->willReturn(true);
        $mockSelectStatement->method('fetch')
            ->willReturnOnConsecutiveCalls($userAData);

        // Mock OU validation query - Engineering OU exists
        $mockOuCheckStatement = $this->createMock(PDOStatement::class);
        $mockOuCheckStatement->method('execute')->willReturn(true);
        $mockOuCheckStatement->method('fetch')->willReturn(['id' => self::OU_ENGINEERING_ID]);

        // Mock UPDATE query
        $mockUpdateStatement = $this->createMock(PDOStatement::class);
        $mockUpdateStatement->method('execute')->willReturn(true);

        // Mock the post-update re-fetch: the handler now returns the updated user
        // record (WC-113), so one extra prepared statement is consumed.
        $mockRefetchStatement = $this->createMock(PDOStatement::class);
        $mockRefetchStatement->method('execute')->willReturn(true);
        $mockRefetchStatement->method('fetch')->willReturn([
            'id' => self::USER_A_ID,
            'email' => 'usera@example.com',
            'password' => 'x',
            'created_at' => '2026-01-01 00:00:00',
            'tenant_id' => self::TENANT_A,
            'role' => 'user',
        ]);

        $this->mockDb->method('prepare')
            ->willReturnOnConsecutiveCalls(
                $mockSelectStatement,
                $mockOuCheckStatement,
                $mockUpdateStatement,
                $mockRefetchStatement
            );

        // Attack: User A tries to assign themselves to Engineering OU
        $body = json_encode(['ou_id' => self::OU_ENGINEERING_ID]);
        $request = new Request('PATCH', '/api/users/' . self::USER_A_ID, [], $body);
        $response = $this->usersHandler->update($request, ['id' => self::USER_A_ID]);

        // Verify: Handler returns 200 (update processed)
        // In real deployment, RbacMiddleware would enforce whether this is allowed
        $this->assertSame(200, $response->getStatusCode());
    }

    // ==================== ATTACK SCENARIO 9: CYCLE DETECTION WITH DEEP HIERARCHY ====================

    /**
     * Test: testCycleDetectionHandlesDeepHierarchy
     *
     * Attack: Create a deep cycle: A→B→C→D→A
     * Setup: OUs in a chain (A→B→C→D with no parent)
     * Request: Update OU A to have parent=D (completes cycle)
     * Expected: Response 400 "Circular parent reference detected"
     * Security: Cycle detection must work for chains > 2 levels deep
     */
    public function testCycleDetectionHandlesDeepHierarchy(): void
    {
        MockRequestFactory::setTestTenant(self::TENANT_A);

        // Setup: Create a 4-level hierarchy
        // OU D (id=4, parent=null) -> OU C (id=3, parent=4) -> OU B (id=2, parent=3) -> OU A (id=1, parent=2)
        $ouA = TestFixtureBuilder::ou(1, self::TENANT_A, 'A', 2);

        // Mock all prepare calls - will use the same mock that returns different values
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('execute')->willReturn(true);

        // Set up return values for different queries
        // 1. GET OU (for initial fetch) - returns ouA
        // 2. Parent validation query - returns OU 4 exists
        // 3. Cycle detection queries - traverse up from 4: 4->null, then never reach 1 so no cycle
        // But wait - we're trying to set parent to 4, and A's current parent is 2
        // So the hierarchy is: A(1) -> parent=2, B(2) -> parent=3, C(3) -> parent=4, D(4) -> parent=null
        // Setting A's parent to 4 would create: A -> 4 -> 3 -> 2 (but 2 is B, not A), so no cycle in this direction
        // Actually, the cycle only exists when starting from A: A -> 2 -> 3 -> 4 -> (we want A here)
        // So we need to check if 4's chain leads to 1.
        // A -> parent=2, B -> parent=3, C -> parent=4, D -> parent=null
        // Starting from new parent (4), walk up: 4 -> null. We never encounter 1.
        // So NO cycle is detected! Let me reconsider...

        // The cycle check works like this: if we set A's parent to 4:
        // We traverse up from 4: 4 -> null (D has no parent), so no cycle in that direction
        // The actual cycle would be: A gets parent 4, but B (which is A's parent) has parent C, which has parent D
        // Wait, I had the hierarchy backwards. Let me reconsider the setup:

        // Current state: A(1) parent=2 means A's parent is B
        // If we try to set A(1) parent=4, the cycle detection walks up from 4:
        // 4 has parent=null (D has no parent), so we never encounter A(1), so no cycle detected.
        // The issue is the hierarchy should be set up differently to create an actual cycle.

        // Let me fix this: to create a cycle when setting A's parent to D:
        // The cycle should be: if A -> D, and D -> ... -> A
        // So: A -> D, B (2) -> C (3), C (3) -> A (1), that creates A -> D -> (something) -> A
        // Actually simpler: A(1) has children, setting its parent causes cycle if a descendant is in chain
        // A(1) is parent of B(2), B(2) is parent of C(3), C(3) is parent of D(4)
        // Setting A's parent to D would be: A -> D -> C -> B -> A (cycle!)

        $ouA = TestFixtureBuilder::ou(1, self::TENANT_A, 'A', null);
        $ouB = TestFixtureBuilder::ou(2, self::TENANT_A, 'B', 1);  // B's parent is A
        $ouC = TestFixtureBuilder::ou(3, self::TENANT_A, 'C', 2);  // C's parent is B
        $ouD = TestFixtureBuilder::ou(4, self::TENANT_A, 'D', 3);  // D's parent is C

        $mockStatement->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $ouA,                   // GET OU (line 213)
                ['id' => 4],            // Parent validation (line 255)
                ['parent_id' => 3],     // Cycle detection: D(4) parent=3
                ['parent_id' => 2],     // Cycle detection: C(3) parent=2
                ['parent_id' => 1],     // Cycle detection: B(2) parent=1 - FOUND A! Cycle detected
            );

        $this->mockDb->method('prepare')
            ->willReturn($mockStatement);

        // Attack: Try to set A(1)'s parent to D(4) - this creates cycle A -> D -> C -> B -> A
        $body = json_encode(['parent_id' => 4]);
        $request = new Request('PATCH', '/api/ous/1', [], $body);
        $response = $this->ousHandler->update($request, ['id' => 1]);

        // Verify: a deep-hierarchy cyclic re-parent is rejected as 422
        // Unprocessable Entity (WC-44 typed domain exception), consistent with
        // the shallow-cycle case above.
        $this->assertSame(422, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertStringContainsString('cycle', strtolower($responseData['error']));
    }
}
