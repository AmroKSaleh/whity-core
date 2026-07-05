<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * End-to-end RBAC lifecycle integration tests (WC-17, issue #13).
 *
 * These tests fill the gaps left by the earlier RBAC suites by driving the FULL
 * request lifecycle — real {@see JwtParser} → real {@see RbacMiddleware} → real
 * {@see RoleChecker} → real {@see PermissionRegistry} → a mocked {@see Database}
 * seam — exactly the way the HTTP kernel wires a matched {@see Router} entry.
 *
 * Existing coverage NOT duplicated here:
 *  - {@see \Tests\Http\RbacMiddlewareTest} unit-tests the middleware against a
 *    mocked RoleChecker (allow/deny, header parsing, 403 body shape).
 *  - {@see RbacRouteEnforcementTest} proves single-permission allow/deny through
 *    the Router with a real RoleChecker.
 *  - {@see \Tests\Auth\RoleCheckerTest} proves hierarchy inheritance, cycle
 *    safety and caching at the RoleChecker level only.
 *
 * What THIS file adds:
 *  1. Role-hierarchy permission inheritance resolved end-to-end THROUGH the
 *     middleware (not just the checker): a viewer-level grant reaches a handler
 *     for an admin whose role inherits it up the parent chain.
 *  2. Permission granularity across multiple endpoints with one role: a reader
 *     passes the read route but is denied write/delete routes, each returning the
 *     correct `required` permission.
 *  3. The worker-level effective-permission cache behaves correctly across
 *     consecutive requests in a long-lived (FrankenPHP) worker.
 */
class RbacLifecycleTest extends TestCase
{
    private const SECRET = 'wc17-lifecycle-secret-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    protected function setUp(): void
    {
        // The effective-permission cache is process-static; reset between cases
        // so a resolution in one test never leaks into another.
        RoleChecker::clearCache();
        // Mirror production: the tenant is resolved/locked before RBAC runs.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Build a PDOStatement mock returning canned fetch / fetchAll values.
     *
     * @param mixed             $fetch    Value returned by fetch().
     * @param array<int, mixed> $fetchAll Value returned by fetchAll().
     */
    private function statement(mixed $fetch = false, array $fetchAll = []): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn($fetch);
        $statement->method('fetchAll')->willReturn($fetchAll);
        return $statement;
    }

    /**
     * Wire a mocked Database that resolves the role hierarchy the same way the
     * RoleChecker queries it, with NO direct role_permissions grant (forcing the
     * inheritance path).
     *
     * Convention: a user id maps to its own role id (userId === roleId).
     *
     * @param array<int, int|null>           $parents     role_id => parent role_id (null = root)
     * @param array<int, array<int, string>> $permsByRole role_id => [permission strings]
     */
    private function hierarchyDatabase(array $parents, array $permsByRole): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($parents, $permsByRole): PDOStatement {
                // memberships row lookup (getMembershipRow).
                if (str_contains($sql, 'FROM memberships')) {
                    $profileId = $params[':profileId'];
                    return $this->statement(['role_id' => $profileId, 'ou_id' => null, 'status' => 'active']);
                }

                // roles.parent_id lookup (getParentRoleId).
                if (str_contains($sql, 'SELECT parent_id FROM roles')) {
                    $roleId = $params[':roleId'];
                    return $this->statement(['parent_id' => $parents[$roleId] ?? null]);
                }

                // per-role direct permissions (getDirectPermissionsForRole).
                if (str_contains($sql, 'FROM permissions p')) {
                    $roleId = $params[':roleId'];
                    $rows = array_map(
                        static fn(string $name): array => ['name' => $name],
                        $permsByRole[$roleId] ?? []
                    );
                    return $this->statement(false, $rows);
                }

                // Legacy direct-grant probe in hasPermission: never a direct hit,
                // so resolution always descends into the hierarchy walk.
                return $this->statement(false);
            }
        );
        return $db;
    }

    /**
     * Resolve and enforce a request the way the HTTP kernel does.
     */
    private function dispatch(Router $router, RbacMiddleware $middleware, Request $request, callable $handler): Response
    {
        $match = $router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $next = static fn(Request $req): Response => $handler($req, $match['params']);

        return $middleware->handle($request, $next, $match['requiredRole'], $match['requiredPermission']);
    }

    /**
     * Headline gap: a hierarchy-inherited permission satisfies a protected route
     * end-to-end through the middleware.
     *
     * Hierarchy (parent chain expresses "higher inherits lower"):
     *   admin(2) -> editor(3) -> viewer(4 root)
     * viewer grants users:read; admin should reach the /api/users route because
     * it inherits viewer's grant up the chain — even though admin has NO direct
     * users:read grant.
     */
    public function testInheritedPermissionReachesProtectedRouteThroughMiddleware(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $registry = new PermissionRegistry(); // users:read registers lazily (core)
        $db = $this->hierarchyDatabase(
            parents: [2 => 3, 3 => 4, 4 => null],
            permsByRole: [
                2 => [CorePermissions::ROLES_READ],     // admin's own grant
                3 => [CorePermissions::OUS_READ],        // editor's own grant
                4 => [CorePermissions::USERS_READ],      // viewer's grant (inherited)
            ]
        );
        $middleware = new RbacMiddleware($jwtParser, new RoleChecker($db, $registry));

        $router = new Router('');
        $router->register('GET', '/api/users', static fn(): Response => new Response(200, '[]'), null, null, CorePermissions::USERS_READ);

        // user id 2 -> role id 2 (admin) by the fixture convention.
        $token = $jwtParser->create(['profile_id' => 2, 'active_tenant_id' => self::TENANT, 'email' => 'admin@example.com', 'token_epoch' => 0]);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = false;
        $response = $this->dispatch($router, $middleware, $request, function (Request $req) use (&$handlerReached): Response {
            $handlerReached = true;
            return new Response(200, json_encode(['data' => []]));
        });

        $this->assertTrue($handlerReached, 'Admin must reach the route via inherited viewer:users:read');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($request->user);
        $this->assertSame(2, $request->user->profile_id);
    }

    /**
     * A leaf role at the BOTTOM of the hierarchy does NOT inherit permissions
     * granted only to roles ABOVE it: inheritance flows down the parent chain,
     * never up. A viewer must be denied an admin-only permission.
     */
    public function testLeafRoleDoesNotInheritUpwardThroughMiddleware(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $registry = new PermissionRegistry();
        // viewer(4) is a root (no parent). admin's users:delete lives ABOVE it on
        // a separate branch and must be unreachable from viewer.
        $db = $this->hierarchyDatabase(
            parents: [4 => null],
            permsByRole: [
                4 => [CorePermissions::USERS_READ], // viewer can only read
            ]
        );
        $middleware = new RbacMiddleware($jwtParser, new RoleChecker($db, $registry));

        $router = new Router('');
        $router->register('DELETE', '/api/users/{id}', static fn(): Response => new Response(204, ''), null, null, CorePermissions::USERS_DELETE);

        $token = $jwtParser->create(['profile_id' => 4, 'active_tenant_id' => self::TENANT, 'email' => 'viewer@example.com', 'token_epoch' => 0]);
        $request = new Request('DELETE', '/api/users/9', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = false;
        $response = $this->dispatch($router, $middleware, $request, function (Request $req) use (&$handlerReached): Response {
            $handlerReached = true;
            return new Response(204, '');
        });

        $this->assertFalse($handlerReached, 'Viewer must not inherit an admin-only permission');
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(CorePermissions::USERS_DELETE, $body['required']);
    }

    /**
     * Permission granularity: a single role with users:read passes the read route
     * but is denied the write and delete routes for the SAME resource, each with
     * the precise missing permission echoed back.
     */
    public function testPermissionGranularityAcrossMultipleEndpointsWithOneRole(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $registry = new PermissionRegistry();
        // Reader role (id 4) is a root granting only users:read.
        $db = $this->hierarchyDatabase(
            parents: [4 => null],
            permsByRole: [4 => [CorePermissions::USERS_READ]]
        );
        $middleware = new RbacMiddleware($jwtParser, new RoleChecker($db, $registry));

        $router = new Router('');
        $router->register('GET', '/api/users', static fn(): Response => new Response(200, '[]'), null, null, CorePermissions::USERS_READ);
        $router->register('POST', '/api/users', static fn(): Response => new Response(201, '{}'), null, null, CorePermissions::USERS_WRITE);
        $router->register('DELETE', '/api/users/{id}', static fn(): Response => new Response(204, ''), null, null, CorePermissions::USERS_DELETE);

        $token = $jwtParser->create(['profile_id' => 4, 'active_tenant_id' => self::TENANT, 'email' => 'reader@example.com', 'token_epoch' => 0]);

        // GET is allowed.
        $getReached = false;
        $getResponse = $this->dispatch(
            $router,
            $middleware,
            new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]),
            function (Request $req) use (&$getReached): Response {
                $getReached = true;
                return new Response(200, '[]');
            }
        );
        $this->assertTrue($getReached, 'Reader should pass the read endpoint');
        $this->assertSame(200, $getResponse->getStatusCode());

        // POST (write) is denied with users:write as the missing permission.
        $postResponse = $this->dispatch(
            $router,
            $middleware,
            new Request('POST', '/api/users', ['Authorization' => "Bearer {$token}"], '{"name":"x"}'),
            static fn(Request $req): Response => new Response(201, '{}')
        );
        $this->assertSame(403, $postResponse->getStatusCode());
        $this->assertSame(
            CorePermissions::USERS_WRITE,
            json_decode($postResponse->getBody(), true)['required']
        );

        // DELETE is denied with users:delete as the missing permission.
        $deleteResponse = $this->dispatch(
            $router,
            $middleware,
            new Request('DELETE', '/api/users/3', ['Authorization' => "Bearer {$token}"]),
            static fn(Request $req): Response => new Response(204, '')
        );
        $this->assertSame(403, $deleteResponse->getStatusCode());
        $this->assertSame(
            CorePermissions::USERS_DELETE,
            json_decode($deleteResponse->getBody(), true)['required']
        );
    }

    /**
     * The worker-level effective-permission cache is honoured across consecutive
     * requests: a long-lived worker resolves the hierarchy once, then serves the
     * second request without re-walking it. Both requests still authorize.
     */
    public function testEffectivePermissionCacheServesConsecutiveRequests(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $registry = new PermissionRegistry();

        $hierarchyQueryCount = 0;
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$hierarchyQueryCount): PDOStatement {
                if (str_contains($sql, 'FROM memberships')) {
                    return $this->statement(['role_id' => 4, 'ou_id' => null, 'status' => 'active']);
                }
                if (str_contains($sql, 'SELECT parent_id FROM roles')) {
                    $hierarchyQueryCount++;
                    return $this->statement(['parent_id' => null]);
                }
                if (str_contains($sql, 'FROM permissions p')) {
                    $hierarchyQueryCount++;
                    return $this->statement(false, [['name' => CorePermissions::USERS_READ]]);
                }
                return $this->statement(false);
            }
        );
        $middleware = new RbacMiddleware($jwtParser, new RoleChecker($db, $registry));

        $router = new Router('');
        $router->register('GET', '/api/users', static fn(): Response => new Response(200, '[]'), null, null, CorePermissions::USERS_READ);

        $token = $jwtParser->create(['profile_id' => 4, 'active_tenant_id' => self::TENANT, 'email' => 'reader@example.com', 'token_epoch' => 0]);

        $first = $this->dispatch($router, $middleware, new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]), static fn(Request $req): Response => new Response(200, '[]'));
        $this->assertSame(200, $first->getStatusCode());
        $queriesAfterFirst = $hierarchyQueryCount;
        $this->assertGreaterThan(0, $queriesAfterFirst, 'First request must resolve the hierarchy from the DB');

        $second = $this->dispatch($router, $middleware, new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]), static fn(Request $req): Response => new Response(200, '[]'));
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(
            $queriesAfterFirst,
            $hierarchyQueryCount,
            'Second request must be served from the worker-level cache with no new hierarchy queries'
        );
    }
}
