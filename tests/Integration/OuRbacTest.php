<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * RBAC tests for organizational units (WC-54).
 *
 * Drives the REAL {@see RbacMiddleware} → {@see RoleChecker} pipeline against an
 * in-memory SQLite engine seeded with the production OU schema, with the resolved
 * tenant locked into {@see TenantContext} ahead of RBAC. Two concerns:
 *
 *  1. RBAC gating: an under-privileged user is denied the admin-gated OU
 *     endpoints; an admin reaches them.
 *  2. OU role inheritance (the WC-54 fix, exercising the REAL
 *     {@see RoleChecker::getEffectiveRolesForUser()} / {@see RoleChecker::hasRole()}
 *     rather than a hand-rolled simulation): a user's effective roles union their
 *     direct role with roles assigned to their OU, additively, and tenant-scoped
 *     so cross-tenant OU assignments never leak.
 */
class OuRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    private JwtParser $jwtParser;
    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;
    private RbacMiddleware $rbacMiddleware;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
        $this->roleChecker = new RoleChecker($this->db, new PermissionRegistry());
        $this->rbacMiddleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ==================== RBAC gating of OU endpoints ====================

    public function testUserRoleCannotListOus(): void
    {
        $userId = $this->seedUser('user@example.com', 'user', ouId: null);
        $response = $this->dispatchAdminGated('GET', '/api/ous', $userId, $handlerCalled);

        $this->assertFalse($handlerCalled, 'Handler should not be called for a non-admin user');
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $body['error']);
    }

    public function testUserRoleCannotCreateOu(): void
    {
        $userId = $this->seedUser('user@example.com', 'user', ouId: null);
        $response = $this->dispatchAdminGated('POST', '/api/ous', $userId, $handlerCalled);

        $this->assertFalse($handlerCalled);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserRoleCannotUpdateOu(): void
    {
        $userId = $this->seedUser('user@example.com', 'user', ouId: null);
        $response = $this->dispatchAdminGated('PATCH', '/api/ous/1', $userId, $handlerCalled);

        $this->assertFalse($handlerCalled);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserRoleCannotDeleteOu(): void
    {
        $userId = $this->seedUser('user@example.com', 'user', ouId: null);
        $response = $this->dispatchAdminGated('DELETE', '/api/ous/1', $userId, $handlerCalled);

        $this->assertFalse($handlerCalled);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUserRoleCannotAssignRoleToOu(): void
    {
        $userId = $this->seedUser('user@example.com', 'user', ouId: null);
        $response = $this->dispatchAdminGated('POST', '/api/ous/1/roles', $userId, $handlerCalled);

        $this->assertFalse($handlerCalled);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAdminRoleCanPerformAllOuOperations(): void
    {
        $userId = $this->seedUser('admin@example.com', 'admin', ouId: null);

        foreach ([
            ['GET', '/api/ous'],
            ['POST', '/api/ous'],
            ['PATCH', '/api/ous/1'],
            ['DELETE', '/api/ous/1'],
        ] as [$method, $path]) {
            $response = $this->dispatchAdminGated($method, $path, $userId, $handlerCalled);
            $this->assertTrue($handlerCalled, "Admin should reach the {$method} {$path} handler");
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    /**
     * A user whose DIRECT role is plain `user` but whose OU has `admin` assigned
     * gains admin access via OU inheritance — proving OU role assignments are
     * wired into the gating decision (WC-54), not merely advisory.
     */
    public function testUserInheritsAdminAccessViaOuRoleAssignment(): void
    {
        $ouId = $this->seedOu('leadership', parentId: null);
        $this->assignRoleToOu($ouId, 'admin');
        $userId = $this->seedUser('lead@example.com', 'user', ouId: $ouId);

        $response = $this->dispatchAdminGated('GET', '/api/ous', $userId, $handlerCalled);

        $this->assertTrue($handlerCalled, 'OU-inherited admin role must satisfy the admin gate');
        $this->assertSame(200, $response->getStatusCode());
    }

    // ==================== Real effective-roles resolution ====================

    /**
     * The REAL {@see RoleChecker::getEffectiveRolesForUser()} unions a user's
     * direct role with the roles assigned to their OU (additive).
     */
    public function testUserWithOuRoleGainsEffectiveRoles(): void
    {
        $ouId = $this->seedOu('engineering', parentId: null);
        $this->assignRoleToOu($ouId, 'editor');
        $userId = $this->seedUser('member@example.com', 'user', ouId: $ouId);

        $effective = $this->roleChecker->getEffectiveRolesForUser($userId, self::TENANT);

        sort($effective);
        $this->assertSame(['editor', 'user'], $effective, 'Effective roles must be the union of direct + OU roles');
    }

    /**
     * Cross-tenant OU assignments do not leak: an `admin` assignment made under
     * tenant 2 for the same OU id must not appear in tenant 1's resolution.
     */
    public function testEffectiveRolesDoNotLeakCrossTenantOuAssignments(): void
    {
        $ouId = $this->seedOu('shared', parentId: null);
        $this->assignRoleToOu($ouId, 'viewer');           // tenant 1 assignment
        $this->assignRoleToOuRaw($ouId, 'admin', tenantId: 2); // tenant 2 leak attempt
        $userId = $this->seedUser('member@example.com', 'user', ouId: $ouId);

        $effective = $this->roleChecker->getEffectiveRolesForUser($userId, self::TENANT);

        $this->assertContains('user', $effective);
        $this->assertContains('viewer', $effective, 'Tenant-1 OU role must be present');
        $this->assertNotContains('admin', $effective, 'Tenant-2 OU role must NOT leak into tenant 1');
    }

    // ==================== Helpers ====================

    /**
     * Dispatch a request through the real RBAC middleware against an admin-gated
     * route (requiredRole = 'admin'), capturing whether the downstream handler ran.
     *
     * @param-out bool $handlerCalled
     */
    private function dispatchAdminGated(string $method, string $path, int $userId, ?bool &$handlerCalled = null): Response
    {
        $token = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => self::TENANT,
            'email' => "u{$userId}@example.com",
            'exp' => time() + 3600,
        ]);
        $request = new Request($method, $path, ['Authorization' => "Bearer {$token}"]);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, json_encode(['data' => []]));
        };

        $response = $this->rbacMiddleware->handle($request, $next, 'admin');
        $handlerCalled = $reached;

        return $response;
    }

    private function seedOu(string $name, ?int $parentId): int
    {
        $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([self::TENANT, $parentId, $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignRoleToOu(int $ouId, string $roleName): void
    {
        $this->assignRoleToOuRaw($ouId, $roleName, self::TENANT);
    }

    private function assignRoleToOuRaw(int $ouId, string $roleName, int $tenantId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$tenantId, $ouId, $this->roleId($roleName)]);
    }

    private function seedUser(string $email, string $roleName, ?int $ouId): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, ou_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([self::TENANT, $email, 'x', $this->roleId($roleName), $ouId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function roleId(string $roleName): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$roleName]);

        return (int) $stmt->fetchColumn();
    }

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
        $pdo->exec("INSERT INTO roles (id, name, created_at) VALUES
            (1, 'admin', NOW()), (2, 'user', NOW()), (3, 'editor', NOW()), (4, 'viewer', NOW())");

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
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
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
                id INTEGER PRIMARY KEY AUTOINCREMENT,
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
