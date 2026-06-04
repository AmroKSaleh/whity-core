<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Api\TenantsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;
use PDO;
use PDOStatement;

/**
 * Integration tests for tenant management RBAC and tenant isolation (WC-81).
 *
 * These tests drive the real {@see TenantsApiHandler} through the
 * {@see EnforceTenantIsolation} middleware pipeline, mirroring how the
 * framework wires requests at runtime. A mocked PDO stands in for the
 * database so the suite runs without a live PostgreSQL instance (as in CI).
 *
 * They verify:
 * - Route-level tenant context is derived from the JWT, not from the caller.
 * - System users (tenant_id=0) can manage other tenants end-to-end.
 * - The system tenant (id=0) can never be deleted.
 * - Tenant isolation: a non-system user cannot reach across tenants.
 */
class TenantManagementRbacTest extends TestCase
{
    private JwtParser $jwtParser;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser('test_secret');
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Build a mocked PDOStatement returning the given fetch value(s).
     *
     * @param mixed $fetchReturn fetch() return value, or list for consecutive calls
     */
    private function statement(mixed $fetchReturn = false): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        if (is_array($fetchReturn) && array_is_list($fetchReturn)) {
            $stmt->method('fetch')->willReturnOnConsecutiveCalls(...$fetchReturn);
        } else {
            $stmt->method('fetch')->willReturn($fetchReturn);
        }
        return $stmt;
    }

    /**
     * Build the EnforceTenantIsolation middleware with a JWT parser stubbed to
     * return the supplied payload for any token.
     *
     * @param array<string,mixed> $payload
     */
    private function isolationMiddlewareFor(array $payload): EnforceTenantIsolation
    {
        $jwtMock = $this->createMock(JwtParser::class);
        $jwtMock->method('parse')->willReturn($payload);
        return new EnforceTenantIsolation($jwtMock);
    }

    /**
     * System user deleting another tenant succeeds end-to-end (AC1 + isolation).
     */
    public function testSystemUserDeletesAnotherTenantThroughPipeline(): void
    {
        $payload = ['user_id' => 1, 'tenant_id' => 0, 'email' => 'root@system'];
        $middleware = $this->isolationMiddlewareFor($payload);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->statement(['id' => 7]),       // SELECT target tenant
            $this->statement(['count' => 0]),    // user count
            $this->statement()                   // DELETE
        );
        $handler = new TenantsApiHandler($db, $this->createMock(HookManager::class));

        $request = new Request('DELETE', '/api/tenants/7', ['Authorization' => 'Bearer sys.token']);

        $capturedTenantId = null;
        $next = function (Request $req) use ($handler, &$capturedTenantId): Response {
            $capturedTenantId = TenantContext::getTenantId();
            return $handler->delete($req, ['id' => 7]);
        };

        $response = $middleware->handle($request, $next);

        $this->assertSame(0, $capturedTenantId, 'System user context must be tenant 0');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * System user updating another tenant succeeds end-to-end (AC1).
     */
    public function testSystemUserUpdatesAnotherTenantThroughPipeline(): void
    {
        $payload = ['user_id' => 1, 'tenant_id' => 0, 'email' => 'root@system'];
        $middleware = $this->isolationMiddlewareFor($payload);

        $db = $this->createMock(PDO::class);
        $db->method('prepare')->willReturnOnConsecutiveCalls(
            $this->statement(['id' => 7, 'name' => 'Old', 'slug' => 'old']), // SELECT
            $this->statement(false),                                          // name unique
            $this->statement()                                                // UPDATE
        );
        $handler = new TenantsApiHandler($db, $this->createMock(HookManager::class));

        $request = new Request(
            'PATCH',
            '/api/tenants/7',
            ['Authorization' => 'Bearer sys.token'],
            json_encode(['name' => 'New Name'])
        );

        $next = fn(Request $req): Response => $handler->update($req, ['id' => 7]);
        $response = $middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Deleting the system tenant (id=0) is blocked with 400 (AC2).
     */
    public function testDeleteSystemTenantBlockedThroughPipeline(): void
    {
        $payload = ['user_id' => 1, 'tenant_id' => 0, 'email' => 'root@system'];
        $middleware = $this->isolationMiddlewareFor($payload);

        $db = $this->createMock(PDO::class);
        // Guard must trip before any query is prepared.
        $db->expects($this->never())->method('prepare');
        $handler = new TenantsApiHandler($db, $this->createMock(HookManager::class));

        $request = new Request('DELETE', '/api/tenants/0', ['Authorization' => 'Bearer sys.token']);
        $next = fn(Request $req): Response => $handler->delete($req, ['id' => 0]);

        $response = $middleware->handle($request, $next);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Cannot delete system tenant', $data['error']);
    }

    /**
     * Tenant isolation: a non-system user cannot delete another tenant (AC3).
     *
     * The EnforceTenantIsolation middleware resolves the caller's tenant from the
     * JWT (tenant 3) and refuses the cross-tenant delete of tenant 7 with 403 at
     * the HTTP layer, before the handler runs and without touching the database.
     * (The handler retains its own equivalent guard as defense-in-depth.)
     */
    public function testNonSystemUserCannotDeleteForeignTenantThroughPipeline(): void
    {
        $payload = ['user_id' => 50, 'tenant_id' => 3, 'email' => 'user@tenant3'];
        $middleware = $this->isolationMiddlewareFor($payload);

        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');
        $handler = new TenantsApiHandler($db, $this->createMock(HookManager::class));

        $request = new Request('DELETE', '/api/tenants/7', ['Authorization' => 'Bearer t3.token']);
        $next = fn(Request $req): Response => $handler->delete($req, ['id' => 7]);

        $response = $middleware->handle($request, $next);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $data);
    }

    /**
     * Tenant isolation: a non-system user cannot update another tenant (AC3).
     */
    public function testNonSystemUserCannotUpdateForeignTenantThroughPipeline(): void
    {
        $payload = ['user_id' => 50, 'tenant_id' => 3, 'email' => 'user@tenant3'];
        $middleware = $this->isolationMiddlewareFor($payload);

        $db = $this->createMock(PDO::class);
        $db->expects($this->never())->method('prepare');
        $handler = new TenantsApiHandler($db, $this->createMock(HookManager::class));

        $request = new Request(
            'PATCH',
            '/api/tenants/7',
            ['Authorization' => 'Bearer t3.token'],
            json_encode(['name' => 'Hijack'])
        );
        $next = fn(Request $req): Response => $handler->update($req, ['id' => 7]);

        $response = $middleware->handle($request, $next);

        $this->assertSame(403, $response->getStatusCode());
    }
}
