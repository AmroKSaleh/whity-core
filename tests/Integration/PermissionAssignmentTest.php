<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Integration tests for permission assignment and enforcement.
 *
 * Verifies that permission assignment to roles and permission enforcement work
 * correctly end-to-end through the real {@see PermissionRegistry},
 * {@see RoleChecker} and {@see RbacMiddleware}, against an in-memory SQLite engine
 * seeded with the production schema. The resolved tenant is locked into
 * {@see TenantContext} ahead of RBAC, mirroring production.
 *
 * Key flows tested:
 * 1. Permission registered → assigned to role → user with role can access.
 * 2. Permission registered → NOT assigned to role → user denied access.
 * 3. Permission registered → assigned → unregistered → user instantly denied
 *    (the registry gate denies before any grant lookup).
 */
class PermissionAssignmentTest extends TestCase
{
    private const TENANT = 1;

    private JwtParser $jwtParser;
    private PermissionRegistry $permissionRegistry;
    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;
    private RbacMiddleware $rbacMiddleware;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->permissionRegistry = new PermissionRegistry();
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
        $this->roleChecker = new RoleChecker($this->db, $this->permissionRegistry);
        $this->jwtParser = new JwtParser('test-secret-key-padded-for-hs256-min-32-byte-key');
        $this->rbacMiddleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Test 1: a user whose role is granted the permission reaches the handler.
     */
    public function testUserWithAssignedPermissionCanAccess(): void
    {
        $this->permissionRegistry->register('core-users', ['users:create']);
        // Role 'admin' is granted users:create; user 1 holds that role.
        $roleId = $this->seedRole('admin');
        $this->grant($roleId, 'users:create');
        $this->seedUser(1, $roleId);

        $token = $this->jwtParser->create([
            'user_id' => 1,
            'email' => 'admin@example.com',
            'tenant_id' => self::TENANT,
            'exp' => time() + 3600,
        ]);

        $request = new Request('POST', '/api/users', ['Authorization' => "Bearer {$token}"]);
        $handlerCalled = false;
        $next = function (Request $req) use (&$handlerCalled): Response {
            $handlerCalled = true;
            return new Response(200, json_encode(['data' => 'User created successfully']));
        };

        $response = $this->rbacMiddleware->handle($request, $next, null, 'users:create');

        $this->assertTrue($handlerCalled, 'Handler should be called when permission is granted');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($request->user);
        $this->assertSame(1, $request->user->user_id);
        $this->assertSame('admin@example.com', $request->user->email);
    }

    /**
     * Test 2: a user whose role lacks the permission (and has no inheriting
     * hierarchy or OU grant) is denied 403.
     */
    public function testUserWithoutAssignedPermissionDenied(): void
    {
        $this->permissionRegistry->register('core-users', ['users:delete']);
        // Role grants nothing relevant; no parent, no OU.
        $roleId = $this->seedRole('user');
        $this->seedUser(2, $roleId);

        $token = $this->jwtParser->create([
            'user_id' => 2,
            'email' => 'user@example.com',
            'tenant_id' => self::TENANT,
            'exp' => time() + 3600,
        ]);

        $request = new Request('DELETE', '/api/users/1', ['Authorization' => "Bearer {$token}"]);
        $handlerCalled = false;
        $next = function (Request $req) use (&$handlerCalled): Response {
            $handlerCalled = true;
            return new Response(200, '{}');
        };

        $response = $this->rbacMiddleware->handle($request, $next, null, 'users:delete');

        $this->assertFalse($handlerCalled, 'Handler should not be called when permission is denied');
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $body['error']);
    }

    /**
     * Test 3: unregistering a permission instantly denies access even though the
     * role_permissions grant still exists — the registry gate denies first.
     */
    public function testDeletedPluginPermissionsInstantlyDenied(): void
    {
        $this->permissionRegistry->register('custom-plugin', ['custom_plugin:action']);
        $this->assertTrue($this->permissionRegistry->exists('custom_plugin:action'));

        $roleId = $this->seedRole('plugin-role');
        $this->grant($roleId, 'custom_plugin:action');
        $this->seedUser(3, $roleId);

        $token = $this->jwtParser->create([
            'user_id' => 3,
            'email' => 'user@example.com',
            'tenant_id' => self::TENANT,
            'exp' => time() + 3600,
        ]);

        // Before deletion: access works.
        $request1 = new Request('POST', '/api/custom', ['Authorization' => "Bearer {$token}"]);
        $firstReached = false;
        $next1 = function (Request $req) use (&$firstReached): Response {
            $firstReached = true;
            return new Response(200, json_encode(['status' => 'ok']));
        };
        $response1 = $this->rbacMiddleware->handle($request1, $next1, null, 'custom_plugin:action');
        $this->assertTrue($firstReached, 'Handler should be called before permission deletion');
        $this->assertSame(200, $response1->getStatusCode());

        // Simulate plugin unload: a fresh registry no longer knows the permission.
        $this->permissionRegistry = new PermissionRegistry();
        $this->assertFalse($this->permissionRegistry->exists('custom_plugin:action'));
        RoleChecker::clearCache();
        $this->roleChecker = new RoleChecker($this->db, $this->permissionRegistry);
        $this->rbacMiddleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);

        // After deletion: denied, even though the DB grant still exists.
        $request2 = new Request('POST', '/api/custom', ['Authorization' => "Bearer {$token}"]);
        $secondReached = false;
        $next2 = function (Request $req) use (&$secondReached): Response {
            $secondReached = true;
            return new Response(200, json_encode(['status' => 'ok']));
        };
        $response2 = $this->rbacMiddleware->handle($request2, $next2, null, 'custom_plugin:action');

        $this->assertFalse($secondReached, 'Handler should not be called after permission deletion');
        $this->assertSame(403, $response2->getStatusCode());
        $body = json_decode($response2->getBody(), true);
        $this->assertSame('Insufficient permissions', $body['error']);
    }

    // ==================== Helpers ====================

    private function seedRole(string $name): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute([$name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();
        $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $permissionId]);
    }

    private function seedUser(int $userId, int $roleId): void
    {
        $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, password, role_id, ou_id, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, NOW())'
        )->execute([$userId, self::TENANT, "u{$userId}@example.com", 'x', $roleId]);
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

        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, parent_id INTEGER, tenant_id INTEGER, created_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, created_at TEXT, UNIQUE(role_id, permission_id))');
        $pdo->exec('CREATE TABLE organizational_units (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, parent_id INTEGER, name TEXT NOT NULL, slug TEXT NOT NULL, created_at TEXT)');
        $pdo->exec('CREATE TABLE ou_role_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, ou_id INTEGER NOT NULL, role_id INTEGER NOT NULL, created_at TEXT, UNIQUE(ou_id, role_id))');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, email TEXT NOT NULL, password TEXT NOT NULL, role_id INTEGER, ou_id INTEGER, created_at TEXT)');

        return $pdo;
    }
}
