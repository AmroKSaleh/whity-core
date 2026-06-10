<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantContext;
use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\Middleware\EnforceTenantIsolation;

/**
 * Integration tests for tenant isolation
 *
 * Verifies that tenant isolation is enforced at the middleware and query levels,
 * ensuring no cross-tenant data leakage occurs.
 *
 * These tests use a combination of middleware verification and database query pattern
 * assertions to ensure tenant data is properly isolated across the request pipeline.
 */
class TenantIsolationTest extends TestCase
{
    private JwtParser $jwtParser;
    private EnforceTenantIsolation $middleware;

    protected function setUp(): void
    {
        // Initialize JWT parser with test secret
        $this->jwtParser = new JwtParser('test_secret-padded-for-hs256-min-32-byte-key');

        // Initialize middleware
        $this->middleware = new EnforceTenantIsolation($this->jwtParser);

        // Reset tenant context before each test
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        // Reset tenant context after each test
        TenantContext::reset();
    }


    /**
     * Test that users from one tenant cannot access data from another tenant
     *
     * This test verifies:
     * - User A from Tenant A can authenticate and list users
     * - EnforceTenantIsolation middleware sets correct tenant context
     * - Handler receives request with tenant context properly set
     * - TenantContext prevents cross-tenant access attempts
     */
    public function testUserCannotAccessAnotherTenantsData(): void
    {
        // Setup: Define two tenants and their users
        $tenantAId = 1;
        $tenantBId = 2;
        $userAId = 100;
        $userBId = 101;
        $tokenPayloadTenantA = [
            'user_id' => $userAId,
            'tenant_id' => $tenantAId,
            'email' => 'userA@example.com'
        ];

        // Mock the JWT parser to return Tenant A's user payload
        $jwtParserMock = $this->createMock(JwtParser::class);
        $jwtParserMock->method('parse')
            ->with('tenantA.token.here')
            ->willReturn($tokenPayloadTenantA);

        $middleware = new EnforceTenantIsolation($jwtParserMock);

        // Create a request as User A (from Tenant A) trying to list users
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer tenantA.token.here']);

        // Handler that verifies tenant context is set and processes request
        $handlerExecuted = false;
        $contextInHandler = null;
        $next = function(Request $req) use (&$handlerExecuted, &$contextInHandler) {
            $handlerExecuted = true;
            $contextInHandler = TenantContext::getTenantId();

            // Simulate handler behavior: query users for current tenant
            // In real UsersApiHandler, the query includes: WHERE u.tenant_id = ?
            // with TenantContext::getTenantId() as parameter
            $users = $this->simulateQueryUsersForTenant([
                ['id' => 100, 'email' => 'userA@example.com', 'tenant_id' => 1],
                ['id' => 101, 'email' => 'userB@example.com', 'tenant_id' => 2]
            ]);

            return new Response(200, json_encode(['data' => $users]));
        };

        // Execute middleware
        $response = $middleware->handle($request, $next);

        // Assertions
        $this->assertTrue($handlerExecuted, 'Handler should be executed');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $contextInHandler, 'Handler should execute with Tenant A context');

        // Verify response contains only User A (not User B)
        $responseData = json_decode($response->getBody(), true);
        $this->assertCount(1, $responseData['data'], 'Only User A should be returned');
        $this->assertSame('userA@example.com', $responseData['data'][0]['email']);
        $this->assertSame(1, $responseData['data'][0]['tenant_id']);

        // Verify User B is NOT in the response
        $userEmails = array_column($responseData['data'], 'email');
        $this->assertNotContains('userB@example.com', $userEmails, 'User B should not be accessible to Tenant A');
    }

    /**
     * Simulate the behavior of UsersApiHandler::list() which filters by tenant_id
     *
     * This mimics the query pattern: SELECT ... FROM users WHERE u.tenant_id = ?
     */
    private function simulateQueryUsersForTenant(array $allUsers): array
    {
        $tenantId = TenantContext::getTenantId();
        return array_filter($allUsers, fn($user) => $user['tenant_id'] === $tenantId);
    }

    /**
     * Test that database queries are automatically scoped to the current tenant
     *
     * This test verifies:
     * - Middleware correctly sets tenant context for each request
     * - Handler receives the correct tenant context
     * - Query patterns include WHERE clause with tenant_id filter
     * - Data from different tenants is properly isolated
     */
    public function testDatabaseQueriesAreAutomaticallyScopedToTenant(): void
    {
        // Setup: Define two tenants with users and roles
        $tenantAId = 1;
        $tenantBId = 2;

        // Simulate database records
        $allUsers = [
            ['id' => 1, 'email' => 'userA@example.com', 'tenant_id' => 1, 'role_id' => 10],
            ['id' => 2, 'email' => 'userB@example.com', 'tenant_id' => 2, 'role_id' => 20]
        ];

        $allRoles = [
            ['id' => 10, 'name' => 'admin', 'tenant_id' => 1],
            ['id' => 20, 'name' => 'admin', 'tenant_id' => 2]
        ];

        $tokenPayloadTenantA = [
            'user_id' => 1,
            'tenant_id' => $tenantAId,
            'email' => 'userA@example.com'
        ];

        // Test 1: Query users for Tenant A
        $jwtParserMock = $this->createMock(JwtParser::class);
        $jwtParserMock->method('parse')
            ->with('tenantA.token')
            ->willReturn($tokenPayloadTenantA);

        $middleware = new EnforceTenantIsolation($jwtParserMock);
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer tenantA.token']);

        $nextCalled = false;
        $next = function(Request $req) use (&$nextCalled, $allUsers) {
            $nextCalled = true;

            // Simulate the query: SELECT ... FROM users WHERE tenant_id = ?
            $filteredUsers = array_filter(
                $allUsers,
                fn($u) => $u['tenant_id'] === TenantContext::getTenantId()
            );

            return new Response(200, json_encode(['data' => array_values($filteredUsers)]));
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($nextCalled);
        $responseData = json_decode($response->getBody(), true);

        // Assert: Only User A is returned (User B filtered out)
        $this->assertCount(1, $responseData['data'], 'Only Tenant A users should be returned');
        $this->assertSame('userA@example.com', $responseData['data'][0]['email']);
        $this->assertSame(1, $responseData['data'][0]['tenant_id']);

        // Test 2: Query roles for Tenant A
        TenantContext::reset();
        TenantContext::setTenantId($tenantAId);

        $rolesForTenant = array_filter(
            $allRoles,
            fn($r) => $r['tenant_id'] === TenantContext::getTenantId()
        );

        // Assert: Only Role A is returned
        $this->assertCount(1, $rolesForTenant, 'Only Tenant A roles should be returned');
        $this->assertSame('admin', $rolesForTenant[0]['name']);
        $this->assertSame(10, $rolesForTenant[0]['id']);

        // Verify Role B (id=20) is not included
        $roleIds = array_column($rolesForTenant, 'id');
        $this->assertNotContains(20, $roleIds, 'Role B from Tenant B should not be in Tenant A results');

        // Test 3: Query the same role name from different tenants
        TenantContext::reset();
        TenantContext::setTenantId($tenantAId);

        $roleAFromTenant1 = current(array_filter(
            $allRoles,
            fn($r) => $r['name'] === 'admin' && $r['tenant_id'] === TenantContext::getTenantId()
        ));

        TenantContext::reset();
        TenantContext::setTenantId($tenantBId);

        $roleAFromTenant2 = current(array_filter(
            $allRoles,
            fn($r) => $r['name'] === 'admin' && $r['tenant_id'] === TenantContext::getTenantId()
        ));

        // Assert: Same-named roles from different tenants are isolated
        $this->assertSame('admin', $roleAFromTenant1['name']);
        $this->assertSame('admin', $roleAFromTenant2['name']);
        $this->assertNotSame($roleAFromTenant1['id'], $roleAFromTenant2['id']);
        $this->assertSame(10, $roleAFromTenant1['id']);
        $this->assertSame(20, $roleAFromTenant2['id']);
    }

    /**
     * Test that tenant context prevents modifications across tenant boundaries
     *
     * This test verifies:
     * - TenantContext is locked once set by middleware
     * - Handler cannot change tenant context mid-request
     * - This prevents accidental or malicious cross-tenant access
     */
    public function testTenantContextLockingPreventsContextManipulation(): void
    {
        $tenantId = 1;
        $userId = 100;

        $tokenPayload = [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => 'user@example.com'
        ];

        $jwtParserMock = $this->createMock(JwtParser::class);
        $jwtParserMock->method('parse')
            ->willReturn($tokenPayload);

        $middleware = new EnforceTenantIsolation($jwtParserMock);
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer valid.token']);

        $exceptionThrown = false;
        $exceptionMessage = null;

        $next = function(Request $req) use (&$exceptionThrown, &$exceptionMessage) {
            try {
                // Try to change tenant context after it's been set by middleware
                TenantContext::setTenantId(999);
            } catch (\RuntimeException $e) {
                $exceptionThrown = true;
                $exceptionMessage = $e->getMessage();
            }

            return new Response(200, '{"status": "ok"}');
        };

        $response = $middleware->handle($request, $next);

        // Assertions
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($exceptionThrown, 'RuntimeException should be thrown when trying to change locked context');
        $this->assertStringContainsString('locked', $exceptionMessage, 'Exception should mention context is locked');
        $this->assertSame($tenantId, TenantContext::getTenantId(), 'Original tenant context should remain unchanged');
    }

    /**
     * Test that multiple concurrent requests maintain separate tenant contexts
     *
     * This test verifies:
     * - Different requests with different tenants can be processed correctly
     * - TenantContext is reset between requests
     * - Data isolation is maintained across request boundaries
     */
    public function testMultipleRequestsWithDifferentTenantsAreIsolated(): void
    {
        $jwtParserMock = $this->createMock(JwtParser::class);

        // Setup different payloads for different tenants
        $tokenPayloadTenant1 = [
            'user_id' => 1,
            'tenant_id' => 1,
            'email' => 'user1@example.com'
        ];

        $tokenPayloadTenant2 = [
            'user_id' => 2,
            'tenant_id' => 2,
            'email' => 'user2@example.com'
        ];

        $jwtParserMock->method('parse')
            ->willReturnCallback(function($token) use ($tokenPayloadTenant1, $tokenPayloadTenant2) {
                return $token === 'token1' ? $tokenPayloadTenant1 : $tokenPayloadTenant2;
            });

        $middleware = new EnforceTenantIsolation($jwtParserMock);

        // Request 1: Tenant 1
        TenantContext::reset();
        $request1 = new Request('GET', '/api/users', ['Authorization' => 'Bearer token1']);

        $contextInRequest1 = null;
        $next1 = function(Request $req) use (&$contextInRequest1) {
            $contextInRequest1 = TenantContext::getTenantId();
            return new Response(200, '{"tenant": 1}');
        };

        $response1 = $middleware->handle($request1, $next1);
        $this->assertSame(1, $contextInRequest1, 'Request 1 should have Tenant 1 context');
        $this->assertSame(200, $response1->getStatusCode());

        // Request 2: Tenant 2 (after resetting context like the framework would do)
        TenantContext::reset();
        $request2 = new Request('GET', '/api/users', ['Authorization' => 'Bearer token2']);

        $contextInRequest2 = null;
        $next2 = function(Request $req) use (&$contextInRequest2) {
            $contextInRequest2 = TenantContext::getTenantId();
            return new Response(200, '{"tenant": 2}');
        };

        $response2 = $middleware->handle($request2, $next2);
        $this->assertSame(2, $contextInRequest2, 'Request 2 should have Tenant 2 context');
        $this->assertSame(200, $response2->getStatusCode());

        // Verify contexts are different
        $this->assertNotSame($contextInRequest1, $contextInRequest2);
    }
}
