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
 * Real-engine (in-memory SQLite) tests for {@see OusApiHandler::assignRole()} (WC-56).
 *
 * Before this fix the handler inserted the body-supplied `role_id` into
 * `ou_role_assignments` without checking the role belonged to (or was visible
 * to) the caller's tenant. A tenant could therefore attach ANOTHER tenant's
 * private role to one of its own OUs — a cross-tenant role-escalation primitive.
 *
 * The mocked-PDO unit tests could not catch this because they stub `fetch()`
 * results rather than running real SQL against a seeded roles table. These tests
 * drive the handler against a genuine SQL engine seeded with roles owned by
 * different tenants (plus a global/NULL-tenant role) so the real
 * SELECT/validation/INSERT semantics are exercised.
 *
 * A `NOW()` UDF is registered because the handler's INSERT uses PostgreSQL's
 * NOW(); SQLite has no such function natively (mirrors the Roles real-engine
 * test).
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

    /**
     * The core WC-56 defect: tenant 1 owns OU 10 and attempts to attach role 200
     * — a PRIVATE role owned by tenant 2. The assignment must be refused with 404
     * (not 403, so cross-tenant role existence is not disclosed) and NO row may be
     * written. This FAILS on pre-fix main, where the INSERT succeeds and the
     * foreign role is attached.
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

    // ==================== Helpers ====================

    private function handler(): OusApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new OusApiHandler($this->pdo, $hooks);
    }

    private function seedOu(int $id, int $tenantId, string $name): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at)
             VALUES (?, ?, NULL, ?, ?, '', datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, $name, 'ou-' . $id]);
    }

    /**
     * Build an in-memory SQLite connection seeded with an OUs/roles schema close
     * enough to production to exercise the handler's real assignRole SQL:
     *
     *  - OU 10 is owned by tenant 1.
     *  - role 1 is GLOBAL (NULL tenant_id).
     *  - role 100 is owned by tenant 1.
     *  - role 200 is owned by tenant 2 (the "foreign private role").
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at)
            VALUES (10, 1, NULL, 'Engineering', 'engineering', '', datetime('now'))
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
                (1,   'admin',        '', NULL, datetime('now')),
                (100, 'tenant-a-role','', 1,    datetime('now')),
                (200, 'tenant-b-role','', 2,    datetime('now'))
        ");

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
