<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see RolesApiHandler} (WC-110).
 *
 * The original WC-16 roles tests use mocked PDO, which does not enforce real SQL
 * semantics. That masked two production defects against PostgreSQL:
 *
 *  1. {@see RolesApiHandler::resolvePermissionIds()} resolved the `permissions`
 *     payload ONLY by `permissions.name`, so the numeric permission ids the web
 *     UI actually sends linked zero permissions.
 *  2. Create inserted a `user_roles` provisioning row for the acting user, which
 *     the deletion guard then counted — making every API-created role
 *     undeletable.
 *
 * These tests drive the handler against a genuine SQL engine so the real
 * INSERT/SELECT/DELETE semantics are exercised. SQLite is used because CI has no
 * live PostgreSQL; the shared `:name` placeholder grammar and a registered
 * `NOW()` UDF make the handler's statements run unmodified.
 */
final class RolesApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        MockRequestFactory::setTestTenant(1);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== Defect 1: id | name resolution ====================

    public function testCreateWithNumericPermissionIdsLinksThePermissions(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Editor',
            // The web UI sends numeric permission ids from GET /api/permissions.
            'permissions' => [1, 3],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(2, $data['permissionCount'], 'Numeric ids must link the matching permissions.');
        $this->assertSame([1, 3], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testCreateWithPermissionNamesLinksThePermissions(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Viewer',
            'permissions' => ['users:read', 'roles:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(2, $data['permissionCount']);
        $this->assertSame([1, 2], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testCreateWithMixedIdsAndNamesLinksAllAndDeduplicates(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Mixed',
            // id 1 == users:read (duplicate), name roles:read == id 2, id 3 == tenants:read.
            'permissions' => [1, 'users:read', 'roles:read', 3],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(3, $data['permissionCount'], 'Mixed array must de-duplicate id/name overlap.');
        $this->assertSame([1, 2, 3], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testCreateDropsUnknownIdsAndNames(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Partial',
            // 999 / nope:perm do not exist and must be dropped, not fabricated.
            'permissions' => [1, 999, 'nope:perm', 'users:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(1, $data['permissionCount']);
        $this->assertSame([1], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testUpdateWithNumericPermissionIdsReplacesPermissions(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'ToEdit',
                'permissions' => ['users:read'],
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/' . $roleId, ['permissions' => [2, 3]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([2, 3], $this->linkedPermissionIds($roleId));
    }

    // ==================== Defect 2: created roles are deletable ====================

    public function testFreshlyCreatedRoleIsDeletable(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'Disposable',
                'permissions' => [1],
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        $response = $handler->delete(
            $this->authedRequest('DELETE', '/api/roles/' . $roleId),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode(), 'A freshly created role must be deletable.');
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = ' . $roleId)->fetchColumn()
        );
    }

    public function testRoleWithGenuineUserAssignmentStillReturns409(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'InUse',
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        // A genuine (other) user is assigned the role within the tenant.
        $this->pdo->exec(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at)
             VALUES (50, 1, 'real@example.com', 'x', {$roleId}, datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO user_roles (tenant_id, user_id, role_id, created_at)
             VALUES (1, 50, {$roleId}, datetime('now'))"
        );

        $response = $handler->delete(
            $this->authedRequest('DELETE', '/api/roles/' . $roleId),
            ['id' => (string) $roleId]
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    // ==================== AC3: tenant isolation ====================

    public function testRoleCreatedUnderTenantAIsInvisibleToTenantB(): void
    {
        // Tenant A creates a role.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAOnly']))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        // Tenant A sees it.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(1);
        $listA = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $namesA = array_column($listA, 'name');
        $this->assertContains('TenantAOnly', $namesA);

        // Tenant B does NOT.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $listB = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $namesB = array_column($listB, 'name');
        $this->assertNotContains('TenantAOnly', $namesB, "Tenant B must not see tenant A's role.");

        // And cannot fetch/delete it.
        $this->assertSame(
            404,
            $handler->get(new Request('GET', '/api/roles/' . $roleId), ['id' => (string) $roleId])->getStatusCode()
        );
    }

    public function testSystemTenantSeesRolesAcrossTenants(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAScoped']));

        TenantContext::reset();
        MockRequestFactory::setTestTenant(0); // SYSTEM tenant
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');

        $this->assertContains('TenantAScoped', $names);
    }

    // ==================== Helpers ====================

    private function handler(): RolesApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new RolesApiHandler($this->pdo, $hooks);
    }

    /**
     * Request carrying an authenticated acting user (user id 99, tenant 1).
     *
     * @param array<string, mixed>|null $body
     */
    private function authedRequest(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => 99, 'tenant_id' => 1];
        return $request;
    }

    /**
     * @return array<int, int> Linked permission ids for a role, ascending.
     */
    private function linkedPermissionIds(int $roleId): array
    {
        $stmt = $this->pdo->query(
            'SELECT permission_id FROM role_permissions WHERE role_id = ' . $roleId . ' ORDER BY permission_id'
        );
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Build an in-memory SQLite connection seeded with a roles/permissions schema
     * that mirrors the production migrations closely enough to exercise the
     * handler's real SQL.
     *
     * A `NOW()` UDF is registered because the handler's INSERTs use PostgreSQL's
     * NOW(); SQLite has no such function natively.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (0, 'system'), (1, 'tenant-a'), (2, 'tenant-b')");

        // roles now carries a nullable tenant_id (WC-110 defect 2 fix).
        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT DEFAULT \'\',
                parent_id INTEGER,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO permissions (id, name, description) VALUES
                (1, 'users:read', null),
                (2, 'roles:read', null),
                (3, 'tenants:read', null)
        ");

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
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER,
                created_at TEXT
            )
        ');

        $pdo->exec('
            CREATE TABLE user_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT,
                UNIQUE(user_id, role_id)
            )
        ');

        return $pdo;
    }
}
