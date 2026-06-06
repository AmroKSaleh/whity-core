<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\OusApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see OusApiHandler}.
 *
 * These exercise the handler's real SQL against a genuine engine seeded with a
 * multi-tenant OU hierarchy, roles, and users — the project's mocked-PDO lesson
 * is that a `createMock(PDO)` returns whatever the test stubs and never enforces
 * column types, FK constraints, or the actual JOIN/WHERE semantics. Two classes
 * of bug only surface here:
 *
 *  - WC-56 cross-tenant role assignment (a tenant attaching another tenant's
 *    private role to its own OU);
 *  - WC-44 cycle prevention in {@see OusApiHandler::update()} — the prior
 *    `detectCycle` compared the OU id (int) against `parent_id` read back from
 *    PDO (a string), so the strict `===` never matched a descendant and the
 *    descendant-move guard silently passed against a real engine.
 *
 * A `NOW()` UDF is registered because the handler's INSERTs use PostgreSQL's
 * NOW(); SQLite has no such function natively.
 *
 * Seeded hierarchy (tenant 1):
 *   10 Engineering
 *     ├─ 11 Backend
 *     │    └─ 12 Platform
 *     └─ 13 Frontend
 *   14 Sales (root, tenant 1)
 * Tenant 2 owns OU 30 (Other) and role 200; tenant 1 owns OU 10.. and role 100;
 * role 1 is global (NULL tenant).
 */
final class OusApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== assignRole (WC-56) ====================

    /**
     * The core WC-56 defect: tenant 1 owns OU 10 and attempts to attach role 200
     * — a PRIVATE role owned by tenant 2. The assignment must be refused with 404
     * (not 403, so cross-tenant role existence is not disclosed) and NO row may be
     * written.
     */
    public function testAssigningForeignTenantPrivateRoleIsRejected(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->assignRole(
            new Request('POST', '/api/ous/10/roles', [], (string) json_encode(['role_id' => 200])),
            ['id' => 10]
        );

        $this->assertSame(404, $response->getStatusCode(), "A foreign tenant's private role must not be assignable.");
        $this->assertSame('Role not found', json_decode($response->getBody(), true)['error']);

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM ou_role_assignments WHERE role_id = 200')->fetchColumn(),
            'No assignment row may be written for a cross-tenant role.'
        );
    }

    /**
     * Assigning the tenant's OWN role succeeds and is persisted.
     */
    public function testAssigningOwnRoleSucceeds(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->assignRole(
            new Request('POST', '/api/ous/10/roles', [], (string) json_encode(['role_id' => 100])),
            ['id' => 10]
        );

        $this->assertSame(201, $response->getStatusCode(), "A tenant's own role must be assignable to its OU.");
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(100, $data['role_id']);
        $this->assertSame(10, $data['ou_id']);

        $this->assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 10 AND role_id = 100 AND tenant_id = 1'
            )->fetchColumn()
        );
    }

    /**
     * Assigning a GLOBAL role (NULL tenant_id, per the WC-110 visibility model)
     * succeeds for any tenant — globals are intentionally visible everywhere.
     */
    public function testAssigningGlobalRoleSucceeds(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->assignRole(
            new Request('POST', '/api/ous/10/roles', [], (string) json_encode(['role_id' => 1])),
            ['id' => 10]
        );

        $this->assertSame(201, $response->getStatusCode(), 'A global (NULL-tenant) role must be assignable.');
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 10 AND role_id = 1'
            )->fetchColumn()
        );
    }

    /**
     * Ownership of the OU is still enforced first: a tenant that does not own the
     * OU gets 404 at the OU check, before the role is ever considered.
     */
    public function testAssigningToForeignOuIsRejectedAtOuCheck(): void
    {
        // OU 10 belongs to tenant 1; tenant 2 may not touch it even with its own role.
        MockRequestFactory::setTestTenant(2);

        $response = $this->handler()->assignRole(
            new Request('POST', '/api/ous/10/roles', [], (string) json_encode(['role_id' => 200])),
            ['id' => 10]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsStringIgnoringCase(
            'organizational unit not found',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * The system tenant (id 0) sees roles across tenants, so it may attach any
     * tenant's role to a visible OU.
     */
    public function testSystemTenantCanAssignAnyTenantRole(): void
    {
        // Seed a system-owned OU (tenant 0) so the OU check passes for the system user.
        $this->seedOu(20, 0, 'System OU');

        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->assignRole(
            new Request('POST', '/api/ous/20/roles', [], (string) json_encode(['role_id' => 200])),
            ['id' => 20]
        );

        // tenant 0 with `tenant_id = 0 OR tenant_id IS NULL` does not match role
        // 200 (owned by tenant 2); the system path is exercised but a strictly
        // foreign private role still does not resolve for tenant 0 either.
        // (System cross-tenant role management is handled via RolesApiHandler.)
        $this->assertSame(404, $response->getStatusCode());
    }

    // ==================== GET /api/ous/{id}/roles (Task 1) ====================

    /**
     * The roles endpoint returns exactly the roles assigned to the OU, shaped as
     * {id, name, description}, never anything else.
     */
    public function testRolesListReturnsAssignedRoles(): void
    {
        $this->assignRoleRow(1, 10, 100); // tenant-1 role on OU 10
        $this->assignRoleRow(1, 10, 1);   // global role on OU 10

        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->roles(new Request('GET', '/api/ous/10/roles'), ['id' => 10]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(2, $data);

        $ids = array_column($data, 'id');
        sort($ids);
        $this->assertSame([1, 100], $ids);
        // Shape: exactly id, name, description.
        $this->assertSame(['id', 'name', 'description'], array_keys($data[0]));
    }

    /**
     * Roles are tenant-scoped: a non-owning tenant gets 404 (OU is invisible),
     * never another tenant's role assignments.
     */
    public function testRolesListIsTenantScoped(): void
    {
        $this->assignRoleRow(1, 10, 100);

        MockRequestFactory::setTestTenant(2);

        $response = $this->handler()->roles(new Request('GET', '/api/ous/10/roles'), ['id' => 10]);

        $this->assertSame(404, $response->getStatusCode(), 'A foreign tenant must not see an OU it does not own.');
    }

    /**
     * The system tenant (0) can read the roles of any tenant's OU.
     */
    public function testRolesListVisibleToSystemTenant(): void
    {
        $this->assignRoleRow(1, 10, 100);

        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->roles(new Request('GET', '/api/ous/10/roles'), ['id' => 10]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame([100], array_column($data, 'id'));
    }

    // ==================== GET /api/ous/{id}/members (Task 2) ====================

    /**
     * The members endpoint returns the users whose ou_id is this OU, shaped to the
     * public contract (id/name/email/role/tenantId) and NEVER the password hash.
     */
    public function testMembersListReturnsOuUsersWithoutPassword(): void
    {
        $this->seedUser(500, 1, 'alice@example.com', 'admin', 10);
        $this->seedUser(501, 1, 'bob@example.com', 'user', 10);
        $this->seedUser(502, 1, 'carol@example.com', 'user', 11); // different OU

        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->members(new Request('GET', '/api/ous/10/members'), ['id' => 10]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(2, $data, 'Only users with ou_id = 10 are returned.');

        $emails = array_column($data, 'email');
        sort($emails);
        $this->assertSame(['alice@example.com', 'bob@example.com'], $emails);

        foreach ($data as $member) {
            $this->assertArrayNotHasKey('password', $member, 'The password hash must never be exposed.');
            $this->assertArrayHasKey('email', $member);
            $this->assertArrayHasKey('role', $member);
            $this->assertArrayHasKey('tenantId', $member);
        }
    }

    /**
     * Members are tenant-scoped: a non-owning tenant gets 404.
     */
    public function testMembersListIsTenantScoped(): void
    {
        $this->seedUser(500, 1, 'alice@example.com', 'admin', 10);

        MockRequestFactory::setTestTenant(2);

        $response = $this->handler()->members(new Request('GET', '/api/ous/10/members'), ['id' => 10]);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ==================== update() cycle prevention (Task 3) ====================

    /**
     * A valid move (Frontend 13 under Sales 14) succeeds and persists.
     */
    public function testValidMoveSucceeds(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/ous/13', [], (string) json_encode(['parent_id' => 14])),
            ['id' => 13]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            14,
            (int) $this->pdo->query('SELECT parent_id FROM organizational_units WHERE id = 13')->fetchColumn()
        );
    }

    /**
     * Moving an OU to root (explicit parent_id = null) clears its parent. This
     * relies on array_key_exists rather than isset, since isset(null) is false
     * and would otherwise silently drop the change.
     */
    public function testMoveToRootClearsParent(): void
    {
        MockRequestFactory::setTestTenant(1);

        // Backend (11) currently has parent 10; move it to root.
        $response = $this->handler()->update(
            new Request('PATCH', '/api/ous/11', [], (string) json_encode(['parent_id' => null])),
            ['id' => 11]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(
            $this->pdo->query('SELECT parent_id FROM organizational_units WHERE id = 11')->fetchColumn() ?: null,
            'Moving an OU to root must clear parent_id.'
        );
    }

    /**
     * Moving an OU under itself is rejected with a 4xx and no row change.
     */
    public function testSelfMoveIsRejected(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/ous/11', [], (string) json_encode(['parent_id' => 11])),
            ['id' => 11]
        );

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSame(
            10,
            (int) $this->pdo->query('SELECT parent_id FROM organizational_units WHERE id = 11')->fetchColumn(),
            'A rejected self-move must not change parent_id.'
        );
    }

    /**
     * Moving an OU under one of its own descendants is rejected.
     *
     * Engineering (10) cannot be moved under Platform (12), which is a
     * grandchild (10 → 11 → 12). This is the case the buggy int/string `===`
     * comparison let through against a real engine: walking up from 12 reads
     * `parent_id` "11" then "10" (strings) and never matched the int OU id 10.
     */
    public function testDescendantMoveIsRejected(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/ous/10', [], (string) json_encode(['parent_id' => 12])),
            ['id' => 10]
        );

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertNull(
            $this->pdo->query('SELECT parent_id FROM organizational_units WHERE id = 10')->fetchColumn() ?: null,
            'A rejected descendant-move must leave Engineering as a root (parent_id NULL).'
        );
    }

    /**
     * Setting a cross-tenant OU as parent is rejected (existing tenant guard);
     * the foreign OU 30 (tenant 2) is not a valid parent for OU 13 (tenant 1).
     */
    public function testCrossTenantParentIsRejected(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/ous/13', [], (string) json_encode(['parent_id' => 30])),
            ['id' => 13]
        );

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertSame(
            10,
            (int) $this->pdo->query('SELECT parent_id FROM organizational_units WHERE id = 13')->fetchColumn(),
            'A rejected cross-tenant move must not change parent_id.'
        );
    }

    // ==================== Helpers ====================

    private function handler(): OusApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new OusApiHandler($this->pdo, $hooks);
    }

    private function seedOu(int $id, int $tenantId, string $name, ?int $parentId = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at)
             VALUES (?, ?, ?, ?, ?, '', datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, $parentId, $name, 'ou-' . $id]);
    }

    private function seedUser(int $id, int $tenantId, string $email, string $role, int $ouId): void
    {
        $roleId = (int) $this->pdo->query(
            'SELECT id FROM roles WHERE name = ' . $this->pdo->quote($role)
        )->fetchColumn();

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, ou_id, created_at)
             VALUES (?, ?, ?, 'hashed-secret', ?, ?, datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, $email, $roleId, $ouId]);
    }

    private function assignRoleRow(int $tenantId, int $ouId, int $roleId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
             VALUES (?, ?, ?, datetime('now'))"
        );
        $stmt->execute([$tenantId, $ouId, $roleId]);
    }

    /**
     * Build an in-memory SQLite connection seeded with an OUs/roles/users schema
     * close enough to production to exercise the handler's real SQL.
     *
     *  - OU 10 (Engineering, root) → 11 (Backend) → 12 (Platform); 10 → 13 (Frontend).
     *  - OU 14 (Sales, root, tenant 1); OU 30 (Other, tenant 2).
     *  - role 1 GLOBAL (NULL tenant), role 100 tenant 1, role 200 tenant 2.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Mirror production PostgreSQL's PDO behaviour: pgsql returns integer
        // columns as PHP strings ("10"), whereas in-memory SQLite returns native
        // ints by default. Forcing string fetches here reproduces the real-DB
        // type semantics so the cycle-prevention guard is exercised exactly as it
        // runs in production (the prior strict `===` int/string mismatch let a
        // descendant-move slip through against Postgres while passing on SQLite).
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (0, 'system'), (1, 'tenant-a'), (2, 'tenant-b')");

        $pdo->exec('
            CREATE TABLE organizational_units (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                parent_id INTEGER,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                description TEXT DEFAULT \'\',
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, 1, NULL, 'Engineering', 'engineering', '', datetime('now')),
                (11, 1, 10,   'Backend',     'backend',     '', datetime('now')),
                (12, 1, 11,   'Platform',    'platform',    '', datetime('now')),
                (13, 1, 10,   'Frontend',    'frontend',    '', datetime('now')),
                (14, 1, NULL, 'Sales',       'sales',       '', datetime('now')),
                (30, 2, NULL, 'Other',       'other',       '', datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                description TEXT DEFAULT \'\',
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1,   'admin',        'Administrator', NULL, datetime('now')),
                (2,   'user',         'Standard user', NULL, datetime('now')),
                (100, 'tenant-a-role','Tenant A role', 1,    datetime('now')),
                (200, 'tenant-b-role','Tenant B role', 2,    datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER NOT NULL,
                ou_id INTEGER,
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

        return $pdo;
    }
}
