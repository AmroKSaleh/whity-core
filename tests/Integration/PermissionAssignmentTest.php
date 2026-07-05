<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
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
        // Role 'admin' is granted users:create; the seeded user holds that role.
        $roleId = $this->seedRole('admin');
        $this->grant($roleId, 'users:create');
        $userId = $this->seedUser($roleId);

        $token = $this->jwtParser->create([
            'profile_id'       => $userId,
            'email'            => 'admin@example.com',
            'active_tenant_id' => self::TENANT,
            'token_epoch'      => 0,
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
        $this->assertSame($userId, $request->user->profile_id);
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
        $userId = $this->seedUser($roleId);

        $token = $this->jwtParser->create([
            'profile_id'       => $userId,
            'email'            => 'user@example.com',
            'active_tenant_id' => self::TENANT,
            'token_epoch'      => 0,
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
        $userId = $this->seedUser($roleId);

        $token = $this->jwtParser->create([
            'profile_id'       => $userId,
            'email'            => 'user@example.com',
            'active_tenant_id' => self::TENANT,
            'token_epoch'      => 0,
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
        $this->pdo->prepare('INSERT OR IGNORE INTO roles (name, created_at) VALUES (?, NOW())')->execute([$name]);
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
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

    private function seedUser(int $roleId): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('test-user', 'x', 0, 0, 0, datetime('now'), datetime('now'))"
        )->execute([]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, self::TENANT, $roleId]);

        return $profileId;
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
        $pdo = SchemaFromMigrations::make();
        // Migrations seed only the System tenant (id=0); tests insert users with
        // tenant_id=1 (TENANT constant), so add the test tenant to satisfy the FK.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'test-tenant')");

        return $pdo;
    }
}
