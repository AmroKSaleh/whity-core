<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\RbacMiddleware;

/**
 * Integration tests proving the audit-log route is gated on `audit:read` (WC-34).
 *
 * Mirrors {@see RbacRouteEnforcementTest}: it wires the real {@see Router},
 * {@see RbacMiddleware}, {@see RoleChecker} and {@see PermissionRegistry}
 * together and resolves the route's required permission exactly as the HTTP
 * kernel does, then asserts:
 *
 *  1. A user whose role grants audit:read reaches the handler.
 *  2. A user without it gets a structured 403 with `required: audit:read`.
 *  3. The audit:read permission is part of the canonical CorePermissions set.
 */
final class AuditLogRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT_ID = 3;

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private Router $router;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry = new PermissionRegistry();
        $this->router = new Router('');

        // Register the audit-log route exactly as public/index.php does: gated on
        // the audit:read permission (requiredRole null, requiredPermission set).
        $this->router->register(
            'GET',
            '/api/audit-logs',
            static fn (): Response => new Response(200, json_encode(['data' => []])),
            null,
            null,
            CorePermissions::AUDIT_READ
        );

        // RBAC is tenant-scoped (WC-54): EnforceTenantIsolation locks the context
        // before RBAC runs. Emulate that here so the middleware does not fail
        // closed on an unresolved tenant.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAuditReadIsRegisteredInCorePermissions(): void
    {
        $this->assertContains(CorePermissions::AUDIT_READ, CorePermissions::all());
        $this->assertSame('audit:read', CorePermissions::AUDIT_READ);
        $this->assertTrue($this->registry->exists(CorePermissions::AUDIT_READ));
    }

    public function testUserWithAuditReadReachesHandler(): void
    {
        $token = $this->tokenFor(21);
        $request = new Request('GET', '/api/audit-logs', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = false;
        $response = $this->dispatch($request, true, function (Request $req) use (&$handlerReached): Response {
            $handlerReached = true;
            return new Response(200, json_encode(['data' => []]));
        });

        $this->assertTrue($handlerReached, 'Handler should run when the user has audit:read');
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUserWithoutAuditReadGetsStructured403(): void
    {
        $token = $this->tokenFor(22);
        $request = new Request('GET', '/api/audit-logs', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = false;
        $response = $this->dispatch($request, false, function (Request $req) use (&$handlerReached): Response {
            $handlerReached = true;
            return new Response(200, '[]');
        });

        $this->assertFalse($handlerReached, 'Handler must not run without audit:read');
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $body['error']);
        $this->assertSame('audit:read', $body['required']);
    }

    public function testMissingTokenReturns401(): void
    {
        $request = new Request('GET', '/api/audit-logs');
        $response = $this->dispatch($request, true, static fn (Request $req): Response => new Response(200, '[]'));

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== Helpers ====================

    /**
     * Resolve and enforce a request the way the HTTP kernel does, using a
     * {@see RoleChecker} stubbed to grant or deny the audit:read permission.
     *
     * @param bool     $granted Whether the (stubbed) RoleChecker grants audit:read.
     * @param callable $handler The downstream route handler.
     */
    private function dispatch(Request $request, bool $granted, callable $handler): Response
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')->willReturnCallback(
            static fn (int $userId, string $permission, int $tenantId): bool =>
                $granted && $permission === CorePermissions::AUDIT_READ
        );
        $middleware = new RbacMiddleware($this->jwtParser, $roleChecker);

        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $params = $match['params'];
        $next = static fn (Request $req): Response => $handler($req, $params);

        return $middleware->handle(
            $request,
            $next,
            $match['requiredRole'],
            $match['requiredPermission']
        );
    }

    private function tokenFor(int $userId): string
    {
        return $this->jwtParser->create([
            'profile_id' => $userId,
            'active_tenant_id' => self::TENANT_ID,
            'token_epoch' => 0,
            'email' => 'admin@example.com',
        ]);
    }
}
