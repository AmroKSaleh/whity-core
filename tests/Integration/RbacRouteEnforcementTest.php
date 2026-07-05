<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
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
 * Integration tests for route-level RBAC enforcement (WC-14, issue #9; updated
 * for WC-54 tenant-scoped, OU-aware authorization).
 *
 * Drives the real {@see RbacMiddleware}, {@see RoleChecker} and
 * {@see PermissionRegistry} together, resolving the required permission from a
 * real {@see Router} entry exactly as the HTTP kernel does, against an in-memory
 * SQLite engine seeded with the production roles/permissions/users schema. The
 * resolved tenant is locked into {@see TenantContext} before dispatch, mirroring
 * the EnforceTenantIsolation middleware that runs ahead of RBAC in production.
 *
 * Acceptance criteria:
 *  1. /api/users requires {@see CorePermissions::USERS_READ}; a user whose role
 *     grants it reaches the handler.
 *  2. The same route denies a user without the permission with a structured 403
 *     `{error: 'Insufficient permissions', required: 'users:read'}`.
 *  3. A route with no permission requirement lets any authenticated user pass.
 */
class RbacRouteEnforcementTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;
    private Router $router;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->jwtParser = new JwtParser(self::SECRET);
        // Real registry; core permissions (incl. users:read) register lazily.
        $this->registry = new PermissionRegistry();
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
        $this->roleChecker = new RoleChecker($this->db, $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);
        $this->router = new Router('');

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    /**
     * Resolve and enforce a request the way the HTTP kernel does:
     * match the route, then run the matched permission through the middleware.
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
     * Seed a user (in tenant 1, no OU) whose direct role grants exactly the given
     * permissions; returns the user id.
     *
     * @param array<int, string> $grantedPermissions Permissions the user's role grants.
     */
    private function seedUserWithPermissions(int $userId, array $grantedPermissions): void
    {
        // A dedicated role per user keeps grants isolated between tests/cases.
        $roleName = 'role_' . $userId;
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute([$roleName]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($grantedPermissions as $permission) {
            $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
                ->execute([$permission]);
            $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
            )->execute([$roleId, $permissionId]);
        }

        $this->pdo->prepare(
            "INSERT OR IGNORE INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([$userId, "u{$userId}"]);

        $this->pdo->prepare(
            "INSERT OR IGNORE INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([$userId, self::TENANT, $roleId]);
    }

    /**
     * Build a signed access token carrying the given user id and tenant.
     */
    private function tokenFor(int $userId, string $email): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $userId,
            'email'            => $email,
            'active_tenant_id' => self::TENANT,
            'token_epoch'      => 0,
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
        $this->seedUserWithPermissions($userId, [CorePermissions::USERS_READ]);

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
        $this->assertSame($userId, $request->user->profile_id);
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
        $this->seedUserWithPermissions($userId, [CorePermissions::OUS_READ]);

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
        $this->seedUserWithPermissions($userId, ['widgets:create']);

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

    // ==================== Helpers ====================

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        // Tenant 1 hosts every fixture; seed it so users.tenant_id FK is
        // satisfied on PostgreSQL (SQLite does not enforce FKs by default).
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");

        return $pdo;
    }
}
