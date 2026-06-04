<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Tests\Support\MockRequestFactory;

/**
 * Real-engine (in-memory SQLite) tests for {@see UsersApiHandler::update()} (WC-113).
 *
 * The mocked-PDO unit tests pin only the request/response contract; they cannot
 * prove a write actually lands in the database, and a mocked seam masked exactly
 * this defect: before WC-113 `update()` rejected role changes outright (403) and
 * a role/name/tenant change persisted NOTHING while the UI still showed success.
 *
 * These tests drive the handler against a genuine SQL engine so the real
 * SELECT/UPDATE semantics are exercised and the persisted row is read back. The
 * central `testRoleUpdatePersists` FAILS on current main (the old handler returns
 * 403 for `role_id` and ignores `role`, so the role never changes) and passes
 * after the fix.
 *
 * SQLite is used because CI has no live PostgreSQL; a registered `NOW()` UDF lets
 * the handler's PostgreSQL-flavoured statements run unmodified, matching the
 * approach in {@see RolesApiHandlerRealEngineTest}.
 */
final class UsersApiHandlerRealEngineTest extends TestCase
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

    // ==================== AC1: role change persists ====================

    /**
     * Changing a user's role by NAME (as the Edit form sends it) persists the new
     * role: the row's role_id changes and the response reflects the new role.
     *
     * This is the regression test for the WC-113 no-op stub.
     */
    public function testRoleUpdatePersists(): void
    {
        // User 10 (tenant 1) starts as 'user' (role id 2).
        $this->seedUser(10, 1, 'persist@example.com', 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/10', ['role' => 'admin']),
            ['id' => '10']
        );

        $this->assertSame(200, $response->getStatusCode());

        // The persisted row now carries the admin role id (1).
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT role_id FROM users WHERE id = 10')->fetchColumn(),
            'The new role must be written to users.role_id.'
        );

        // The response is the updated user in the public shape, with the new role.
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('admin', $data['role']);
        $this->assertSame(10, $data['id']);
        $this->assertArrayNotHasKey('password', $data, 'The password hash must never leak.');
    }

    /**
     * The Edit form submits `name` and `tenantId` alongside `role`. `name` is
     * derived/read-only (no users.name column) and `tenantId` is out of scope, so
     * neither is persisted; only the role changes.
     */
    public function testNameAndTenantInBodyAreIgnoredButRolePersists(): void
    {
        $this->seedUser(11, 1, 'ignore@example.com', 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/11', [
                'name' => 'Brand New Name',
                'tenantId' => 99,            // must NOT re-home the user
                'role' => 'admin',
            ]),
            ['id' => '11']
        );

        $this->assertSame(200, $response->getStatusCode());

        $row = $this->pdo->query('SELECT tenant_id, role_id FROM users WHERE id = 11')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int) $row['tenant_id'], 'tenantId in the body must NOT move the user.');
        $this->assertSame(1, (int) $row['role_id'], 'role must still persist.');

        $data = json_decode($response->getBody(), true)['data'];
        // name remains derived from the email local-part, not the submitted value.
        $this->assertSame('ignore', $data['name']);
        $this->assertSame(1, $data['tenantId']);
    }

    // ==================== AC3: no-op still returns the record ====================

    /**
     * Re-assigning the SAME role (or sending only read-only fields) is a genuine
     * no-op: it still returns 200 with the unchanged record rather than an error.
     */
    public function testNoopReturnsCurrentRecord(): void
    {
        $this->seedUser(12, 1, 'noop@example.com', 2);

        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/12', [
                'name' => 'Whatever',   // read-only, ignored
                'role' => 'user',       // already the user's role -> no change
            ]),
            ['id' => '12']
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('user', $data['role']);
        $this->assertSame(2, (int) $this->pdo->query('SELECT role_id FROM users WHERE id = 12')->fetchColumn());
    }

    // ==================== AC2: tenant isolation ====================

    /**
     * A non-system tenant cannot edit a user belonging to another tenant: the
     * lookup is tenant-scoped, so the target is reported as 404 and untouched.
     */
    public function testCannotEditUserOutsideTenantReturns404(): void
    {
        // User 20 belongs to tenant 2.
        $this->seedUser(20, 2, 'foreign@example.com', 2);

        // Acting as tenant 1.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/20', ['role' => 'admin']),
            ['id' => '20']
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            2,
            (int) $this->pdo->query('SELECT role_id FROM users WHERE id = 20')->fetchColumn(),
            "Tenant 1 must not be able to change tenant 2's user role."
        );
    }

    /**
     * A tenant cannot assign a role OWNED by another tenant (not visible to it):
     * the role resolution is scoped to owned + global roles, so an unrelated
     * tenant-private role resolves to nothing and the request is rejected (404).
     */
    public function testCannotAssignAnotherTenantsPrivateRole(): void
    {
        $this->seedUser(30, 1, 'scoped@example.com', 2);
        // A private role owned by tenant 2.
        $this->seedRole(50, 'tenant2-only', 2);

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/30', ['role' => 'tenant2-only']),
            ['id' => '30']
        );

        $this->assertSame(404, $response->getStatusCode(), "Tenant 1 must not assign tenant 2's private role.");
        $this->assertSame(
            2,
            (int) $this->pdo->query('SELECT role_id FROM users WHERE id = 30')->fetchColumn()
        );
    }

    /**
     * The SYSTEM tenant (id 0) may edit a user that belongs to any tenant and
     * assign any role; the change persists.
     */
    public function testSystemTenantCanEditAcrossTenants(): void
    {
        $this->seedUser(40, 2, 'crosstenant@example.com', 2); // tenant 2 user

        MockRequestFactory::setTestTenant(0); // SYSTEM tenant
        $handler = $this->handler();
        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/users/40', ['role' => 'admin'], 0),
            ['id' => '40']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT role_id FROM users WHERE id = 40')->fetchColumn(),
            'SYSTEM tenant must be able to change a cross-tenant user role.'
        );
    }

    // ==================== cache invalidation ====================

    /**
     * A role change invalidates the worker-level effective-permission cache so a
     * stale resolved permission set cannot survive the re-assignment (WC-15).
     */
    public function testRoleChangeInvalidatesPermissionCache(): void
    {
        $this->seedUser(60, 1, 'cache@example.com', 2);

        // Warm the cache for role id 2 so we can detect that it is cleared. The
        // RoleChecker reads through the shared SQLite PDO via the Database seam.
        $pdo = $this->pdo;
        $database = \Whity\Database\Database::withFactory(static fn (): PDO => $pdo);
        $checker = new RoleChecker(
            $database,
            $this->createMock(\Whity\Core\RBAC\PermissionRegistry::class)
        );
        $checker->getEffectivePermissionsForRole(2);
        $this->assertTrue($this->cacheIsWarm(), 'Pre-condition: the cache should be warm.');

        $handler = $this->handler();
        $handler->update(
            $this->authedRequest('PATCH', '/api/users/60', ['role' => 'admin']),
            ['id' => '60']
        );

        $this->assertFalse($this->cacheIsWarm(), 'A role change must clear the effective-permission cache.');
    }

    // ==================== Helpers ====================

    private function handler(): UsersApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new UsersApiHandler($this->pdo, $hooks);
    }

    /**
     * Request carrying an authenticated acting user.
     *
     * @param array<string, mixed>|null $body
     */
    private function authedRequest(string $method, string $path, ?array $body = null, int $tenantId = 1): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => 99, 'tenant_id' => $tenantId];
        return $request;
    }

    private function seedUser(int $id, int $tenantId, string $email, int $roleId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at)
             VALUES (?, ?, ?, 'x', ?, datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, $email, $roleId]);
    }

    private function seedRole(int $id, string $name, ?int $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (id, name, description, tenant_id, created_at)
             VALUES (?, ?, '', ?, datetime('now'))"
        );
        $stmt->execute([$id, $name, $tenantId]);
    }

    /**
     * Read whether the RoleChecker worker cache currently holds any entry, using
     * reflection on its private static cache (it has no public getter).
     */
    private function cacheIsWarm(): bool
    {
        $ref = new \ReflectionClass(RoleChecker::class);
        $prop = $ref->getProperty('effectivePermissionCache');
        $prop->setAccessible(true);
        /** @var array<int, array<int, string>> $cache */
        $cache = $prop->getValue();
        return $cache !== [];
    }

    /**
     * Build an in-memory SQLite connection seeded with a users/roles/permissions
     * schema close enough to the production migrations to exercise the handler's
     * real SQL. The seeded base roles `admin` (id 1) and `user` (id 2) are GLOBAL
     * (NULL tenant_id), matching the post-migration-018 production state.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (0, 'system'), (1, 'tenant-a'), (2, 'tenant-b')");

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
        // Global base roles (NULL tenant_id), mirroring the seeded admin/user roles.
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1, 'admin', '', NULL, datetime('now')),
                (2, 'user', '', NULL, datetime('now')),
                (3, 'moderator', '', NULL, datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT
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

        $pdo->exec('
            CREATE TABLE organizational_units (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                name TEXT
            )
        ');

        return $pdo;
    }
}
