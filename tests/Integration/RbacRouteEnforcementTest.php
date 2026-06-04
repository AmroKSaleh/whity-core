<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDOStatement;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Integration tests for route-level RBAC enforcement (WC-14, issue #9).
 *
 * Drives the real {@see RbacMiddleware}, {@see RoleChecker} and
 * {@see PermissionRegistry} together, resolving the required permission from a
 * real {@see Router} entry exactly as the HTTP kernel does, to prove the three
 * acceptance criteria end-to-end:
 *
 *  1. /api/users requires {@see CorePermissions::USERS_READ}; a user whose role
 *     grants it reaches the handler.
 *  2. The same route denies a user without the permission with a structured 403
 *     `{error: 'Insufficient permissions', required: 'users:read'}`.
 *  3. A route with no permission requirement lets any authenticated user pass.
 *
 * Role-permission data is seeded in COLON notation via {@see CorePermissions}
 * and stubbed at the database layer, so these tests do not depend on the legacy
 * dot-notation seeds being reconciled (WC-55).
 */
class RbacRouteEnforcementTest extends TestCase
{
    private const SECRET = 'test-secret-key';

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private Database $db;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;
    private Router $router;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::SECRET);
        // Real registry; core permissions (incl. users:read) register lazily.
        $this->registry = new PermissionRegistry();
        $this->db = $this->createMock(Database::class);
        $this->roleChecker = new RoleChecker($this->db, $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);
        $this->router = new Router();
    }

    /**
     * Resolve and enforce a request the way the HTTP kernel does:
     * match the route, then run the matched permission through the middleware.
     *
     * @param array<string, mixed> $params Captured route params (unused here).
     */
    private function dispatch(Request $request, callable $handler): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $params = $match['params'];
        $next = static fn(Request $req): Response => $handler($req, $params);

        return $this->middleware->handle(
            $request,
            $next,
            $match['requiredRole'],
            $match['requiredPermission']
        );
    }

    /**
     * Stub the role_permissions lookup used by RoleChecker::hasPermission.
     *
     * @param array<int, string> $grantedPermissions Permissions the user's role grants.
     */
    private function seedRolePermissions(int $userId, array $grantedPermissions): void
    {
        $this->db->method('query')->willReturnCallback(
            function (string $sql, array $bindings) use ($userId, $grantedPermissions): PDOStatement {
                $statement = $this->createMock(PDOStatement::class);
                $matches = ($bindings[':userId'] ?? null) === $userId
                    && in_array($bindings[':permission'] ?? null, $grantedPermissions, true);
                $statement->method('fetch')->willReturn($matches ? ['1' => 1] : false);
                return $statement;
            }
        );
    }

    /**
     * Build a signed access token carrying the given user id.
     */
    private function tokenFor(int $userId, string $email): string
    {
        return $this->jwtParser->create([
            'user_id' => $userId,
            'email' => $email,
        ]);
    }

    /**
     * AC1: user whose role grants users:read reaches the handler.
     */
    public function testUserWithRequiredPermissionReachesHandler(): void
    {
        $userId = 10;
        $this->router->register(
            'GET',
            '/api/users',
            static fn() => new Response(200, '[]'),
            null,
            null,
            CorePermissions::USERS_READ
        );
        $this->seedRolePermissions($userId, [CorePermissions::USERS_READ]);

        $token = $this->tokenFor($userId, 'reader@example.com');
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = false;
        $response = $this->dispatch($request, function (Request $req) use (&$handlerReached): Response {
            $handlerReached = true;
            return new Response(200, json_encode(['data' => []]));
        });

        $this->assertTrue($handlerReached, 'Handler should run when the user has users:read');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($request->user);
        $this->assertSame($userId, $request->user->user_id);
    }

    /**
     * AC2: user without users:read gets a structured 403 with the required key.
     */
    public function testUserWithoutRequiredPermissionGetsStructured403(): void
    {
        $userId = 11;
        $this->router->register(
            'GET',
            '/api/users',
            static fn() => new Response(200, '[]'),
            null,
            null,
            CorePermissions::USERS_READ
        );
        // Role grants an unrelated permission only.
        $this->seedRolePermissions($userId, [CorePermissions::OUS_READ]);

        $token = $this->tokenFor($userId, 'noaccess@example.com');
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = false;
        $response = $this->dispatch($request, function (Request $req) use (&$handlerReached): Response {
            $handlerReached = true;
            return new Response(200, '[]');
        });

        $this->assertFalse($handlerReached, 'Handler must not run without users:read');
        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => 'users:read'],
            $body,
            'Denial body must match the documented contract exactly'
        );
    }

    /**
     * AC3: a route with no permission requirement lets any authenticated user pass.
     */
    public function testRouteWithoutPermissionRequirementPassesAuthenticatedUser(): void
    {
        $userId = 12;
        // No required role and no required permission -> unprotected route.
        $this->router->register(
            'GET',
            '/api/health',
            static fn() => new Response(200, 'ok')
        );

        $token = $this->tokenFor($userId, 'anyone@example.com');
        $request = new Request('GET', '/api/health', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = false;
        $response = $this->dispatch($request, function (Request $req) use (&$handlerReached): Response {
            $handlerReached = true;
            return new Response(200, 'ok');
        });

        $this->assertTrue($handlerReached, 'Unprotected routes should fail open for any caller');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Unregistered/unknown permissions are denied even if the DB has a stale grant.
     *
     * Guards the registry-first behaviour: a permission not present in the
     * registry can never be satisfied, regardless of the role_permissions table.
     */
    public function testUnknownPermissionIsDeniedEvenWithDatabaseGrant(): void
    {
        $userId = 13;
        $this->router->register(
            'POST',
            '/api/widgets',
            static fn() => new Response(201, '{}'),
            null,
            null,
            'widgets:create' // never registered in the registry
        );
        // DB would grant it, but the registry gate must reject first.
        $this->seedRolePermissions($userId, ['widgets:create']);

        $token = $this->tokenFor($userId, 'ghost@example.com');
        $request = new Request('POST', '/api/widgets', ['Authorization' => "Bearer {$token}"]);

        $response = $this->dispatch($request, static fn(Request $req): Response => new Response(201, '{}'));

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('widgets:create', $body['required']);
    }

    /**
     * Missing token on a protected route yields 401 before any handler runs.
     */
    public function testProtectedRouteWithoutTokenReturns401(): void
    {
        $this->router->register(
            'GET',
            '/api/users',
            static fn() => new Response(200, '[]'),
            null,
            null,
            CorePermissions::USERS_READ
        );

        $request = new Request('GET', '/api/users');
        $response = $this->dispatch($request, static fn(Request $req): Response => new Response(200, '[]'));

        $this->assertSame(401, $response->getStatusCode());
    }
}
