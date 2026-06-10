<?php

declare(strict_types=1);

namespace Tests\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Cross-tenant RBAC denial & zero-leakage tests (WC-17, issue #13; updated for
 * WC-54 tenant-scoped, OU-aware authorization).
 *
 * Scope note: WC-22 owns the data-leakage / query-scoping angle of tenant
 * isolation. THIS file approaches cross-tenant access from the RBAC angle: it
 * proves that the authoritative {@see RoleChecker} authorizes against the
 * caller's OWN grants — so a user in tenant B whose role lacks `users:read` is
 * denied even though a tenant-A user's role grants it — and that every denial
 * response leaks ZERO internal data (no user id, role, tenant id, SQL, or query
 * result), satisfying AC2.
 *
 * Tenant-scoping nuance (WC-54): a user's DIRECT role grants are not themselves
 * tenant-partitioned in the schema, so cross-tenant denial for direct grants is
 * realised by the two tenants' users holding DIFFERENT roles. The OU-inherited
 * grant path IS tenant-partitioned and is covered exhaustively by
 * {@see \Tests\Auth\OuRoleInheritanceRealEngineTest}. The full real pipeline is
 * exercised here: real {@see JwtParser} → real {@see RbacMiddleware} → real
 * {@see RoleChecker} → in-memory SQLite seeded with the production schema, with
 * the resolved tenant locked into {@see TenantContext} ahead of RBAC.
 */
class RbacCrossTenantDenialTest extends TestCase
{
    private const SECRET = 'wc17-xtenant-secret-padded-for-hs256-min-32-byte-key';

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Seed a user in a tenant whose dedicated role grants exactly $granted.
     *
     * @param array<int, string> $granted permissions granted to the user's role
     */
    private function seedUser(int $userId, int $tenantId, array $granted): void
    {
        $roleName = 'role_' . $userId;
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute([$roleName]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($granted as $permission) {
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
            'INSERT INTO users (id, tenant_id, email, password, role_id, ou_id, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, NOW())'
        )->execute([$userId, $tenantId, "u{$userId}@example.com", 'x', $roleId]);
    }

    private function roleChecker(): RoleChecker
    {
        return new RoleChecker($this->db, new PermissionRegistry());
    }

    /**
     * Dispatch a permission-protected GET /api/users through the real pipeline,
     * with the given resolved tenant locked into the context first.
     *
     * @param-out bool $handlerReached
     */
    private function dispatch(RbacMiddleware $middleware, int $tenantId, Request $request, ?bool &$handlerReached = null): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $router = new Router();
        $router->register('GET', '/api/users', static fn(): Response => new Response(200, '[]'), null, null, CorePermissions::USERS_READ);

        $match = $router->match($request);
        $this->assertNotNull($match);

        $reached = false;
        $response = $middleware->handle(
            $request,
            function (Request $req) use (&$reached): Response {
                $reached = true;
                return new Response(200, json_encode(['data' => ['sensitive' => 'tenant-A-record']]));
            },
            $match['requiredRole'],
            $match['requiredPermission']
        );
        $handlerReached = $reached;
        return $response;
    }

    /**
     * AC2: a caller from tenant B, whose own role does NOT grant users:read, is
     * denied 403 even though a tenant-A user's role grants the same permission.
     */
    public function testForeignTenantCallerIsDeniedDespiteSameNamedGrantInOtherTenant(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        // Tenant A user (100) has the grant; tenant B user (200) does not.
        $this->seedUser(100, 1, [CorePermissions::USERS_READ]);
        $this->seedUser(200, 2, []);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $token = $jwtParser->create(['user_id' => 200, 'tenant_id' => 2, 'email' => 'b@tenantb.example']);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatch($middleware, 2, $request, $handlerReached);

        $this->assertFalse($handlerReached, 'Foreign-tenant caller must never reach the handler');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Positive control: the tenant-A owner with the grant IS authorized, proving
     * the denial above is scoping and not a blanket deny.
     */
    public function testOwningTenantCallerIsAuthorized(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $this->seedUser(100, 1, [CorePermissions::USERS_READ]);
        $this->seedUser(200, 2, []);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $token = $jwtParser->create(['user_id' => 100, 'tenant_id' => 1, 'email' => 'a@tenanta.example']);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatch($middleware, 1, $request, $handlerReached);

        $this->assertTrue($handlerReached, 'Owning-tenant caller with the grant must be authorized');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * AC2 (zero leakage): the 403 denial body for a foreign-tenant caller exposes
     * only the documented contract — `error` plus the missing `required`
     * permission — and NOTHING about the caller, their tenant, the resource, or
     * the database.
     */
    public function testCrossTenantDenialLeaksNoInternalData(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $this->seedUser(200, 2, []);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $token = $jwtParser->create(['user_id' => 200, 'tenant_id' => 2, 'email' => 'b@tenantb.example']);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $response = $this->dispatch($middleware, 2, $request);

        $this->assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => CorePermissions::USERS_READ],
            $body,
            'Denial body must match the documented contract with no extra keys'
        );

        $raw = $response->getBody();
        foreach (['200', 'tenant', 'tenant_id', 'b@tenantb.example', 'sensitive', 'tenant-A-record', 'SELECT', 'role_id', 'user_id'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $raw,
                "Denial body must not leak '{$forbidden}'"
            );
        }
    }

    /**
     * AC2 (zero leakage): a 401 authentication failure likewise exposes only a
     * generic error message and no token or caller details.
     */
    public function testAuthenticationFailureLeaksNoTokenDetails(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        // Token signed by a different secret -> signature failure -> 401.
        $foreignToken = (new JwtParser('some-other-secret-padded-for-hs256-min-32-byte-key'))->create(['user_id' => 200, 'tenant_id' => 2]);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker());

        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$foreignToken}"]);
        $response = $this->dispatch($middleware, 2, $request);

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(['error' => 'Invalid or expired token'], $body);

        $raw = $response->getBody();
        foreach ([$foreignToken, '200', 'tenant', 'signature', 'secret'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $raw,
                "401 body must not leak '{$forbidden}'"
            );
        }
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
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                parent_id INTEGER,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL REFERENCES roles(id),
                permission_id INTEGER NOT NULL REFERENCES permissions(id),
                created_at TEXT,
                UNIQUE(role_id, permission_id)
            )
        ');
        $pdo->exec('
            CREATE TABLE organizational_units (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                parent_id INTEGER,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE ou_role_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                ou_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT,
                UNIQUE(ou_id, role_id)
            )
        ');
        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER,
                ou_id INTEGER,
                created_at TEXT
            )
        ');

        return $pdo;
    }
}
