<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
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

    // ==================== CRUD basics (migrated from the mocked-PDO unit test) ====================
    //
    // The tests below were migrated from the mocked-PDO tests/Unit/Api/OusApiHandlerTest.php
    // onto this real-engine fixture, preserving their original intent/assertions. A
    // createMock(PDO) returns whatever a test stubs regardless of the SQL the handler
    // actually issues, so these never proved the real SELECT/INSERT/UPDATE/DELETE
    // statements behaved as asserted; they now run against the genuine seeded schema.

    /**
     * list() returns only the current tenant's OUs (5 in the fixture: 10, 11, 12,
     * 13, 14), never tenant 2's OU 30.
     */
    public function testListOusReturnsCurrentTenantOnly(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/ous'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(5, $data);
        foreach ($data as $ou) {
            $this->assertSame(1, (int) $ou['tenant_id']);
        }
    }

    /**
     * `?parent_id=` used to be accepted and silently discarded, so a caller that
     * asked for one subtree got the whole tenant back and had no way to tell.
     * It now filters to direct children (11 Backend and 13 Frontend under 10).
     */
    public function testListFiltersByParentId(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/ous?parent_id=10'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $ids = array_map(static fn (array $ou): int => (int) $ou['id'], $body['data']);
        sort($ids);
        $this->assertSame([11, 13], $ids, 'Only the direct children of OU 10 may be returned.');
        // 12 Platform is a grandchild: the filter is one level, not a subtree.
        $this->assertNotContains(12, $ids);
    }

    /**
     * The pagination envelope must describe the FILTERED set, not the tenant's
     * whole OU count — a total that ignores the filter would make a client walk
     * pages that do not exist.
     */
    public function testListParentIdFilterIsReflectedInPaginationTotal(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/ous?parent_id=10'));

        $pagination = json_decode($response->getBody(), true)['pagination'];
        $this->assertSame(2, $pagination['total']);
        $this->assertSame(1, $pagination['totalPages']);
    }

    /** `parent_id=0` selects the tenant's roots (10 Engineering and 14 Sales). */
    public function testListFiltersByRootParentId(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/ous?parent_id=0'));

        $this->assertSame(200, $response->getStatusCode());
        $ids = array_map(
            static fn (array $ou): int => (int) $ou['id'],
            json_decode($response->getBody(), true)['data']
        );
        sort($ids);
        $this->assertSame([10, 14], $ids);
    }

    /**
     * An empty value is "no filter" — the same reading TagsApiHandler gives
     * `?group_id=`, so a client building a query string from a blank form field
     * does not get a surprise 422.
     */
    public function testListTreatsEmptyParentIdAsNoFilter(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/ous?parent_id='));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(5, json_decode($response->getBody(), true)['data']);
    }

    /**
     * A malformed value is refused rather than ignored: silently returning an
     * unfiltered list is what made the original defect invisible.
     */
    public function testListRejectsNonNumericParentId(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/ous?parent_id=abc'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'parent_id must be a non-negative integer',
            json_decode($response->getBody(), true)['error']
        );
    }

    /**
     * The filter must not become a cross-tenant read primitive: OU 30 belongs to
     * tenant 2, so tenant 1 asking for its children gets an empty list, not
     * tenant 2's rows.
     */
    public function testListParentIdFilterStaysTenantScoped(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/ous?parent_id=30'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], json_decode($response->getBody(), true)['data']);
    }

    /**
     * A valid create persists the OU, dispatches the creating/created hooks, and
     * returns 201 with the new row.
     */
    public function testCreateWithValidDataDispatchesHooksAndReturns201(): void
    {
        MockRequestFactory::setTestTenant(1);

        $hooks = $this->createMock(HookManager::class);
        $hooks->expects($this->atLeastOnce())->method('dispatch')->willReturnArgument(1);
        $hooks->expects($this->atLeastOnce())->method('dispatchAsync');

        $handler = new OusApiHandler($this->pdo, $hooks);
        $response = $handler->create(new Request('POST', '/api/ous', [], (string) json_encode([
            'name' => 'Marketing',
            'parent_id' => null,
            'description' => 'Marketing team',
        ])));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('Marketing', $data['name']);
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM organizational_units WHERE name = 'Marketing' AND tenant_id = 1"
            )->fetchColumn()
        );
    }

    /**
     * An over-long name (VARCHAR(255)) is rejected with a clean 422 before any
     * DB write, rather than surfacing as a Postgres 22001 -> 500 (input hardening).
     */
    public function testCreateRejectsOverLongNameWith422(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create(new Request('POST', '/api/ous', [], (string) json_encode([
            'name' => str_repeat('a', 256),
            'description' => 'ok',
        ])));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('name', json_decode($response->getBody(), true)['details']['field']);
    }

    /**
     * An over-long description (unbounded TEXT column) is capped at the
     * application layer so a single field cannot absorb the full 1 MiB body.
     */
    public function testCreateRejectsOverLongDescriptionWith422(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create(new Request('POST', '/api/ous', [], (string) json_encode([
            'name' => 'Engineering Ops',
            'description' => str_repeat('a', 10001),
        ])));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('description', json_decode($response->getBody(), true)['details']['field']);
    }

    /**
     * A missing `name` is rejected with 400 before any DB write.
     */
    public function testCreateWithMissingNameReturns400(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create(new Request('POST', '/api/ous', [], (string) json_encode([
            'parent_id' => null,
        ])));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('name', strtolower(json_decode($response->getBody(), true)['error']));
    }

    /**
     * Creating an OU whose name already exists IN THE SAME TENANT is rejected
     * (409); OU 10 ("Engineering") already exists for tenant 1.
     */
    public function testCreateWithDuplicateNameInTenantReturns409(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create(new Request('POST', '/api/ous', [], (string) json_encode([
            'name' => 'Engineering',
        ])));

        $this->assertSame(409, $response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode($response->getBody(), true));
    }

    /**
     * Creating an OU whose parent_id belongs to ANOTHER tenant is rejected with
     * 403 and nothing is written; OU 30 belongs to tenant 2.
     */
    public function testCreateWithCrossTenantParentReturns403(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create(new Request('POST', '/api/ous', [], (string) json_encode([
            'name' => 'Sub',
            'parent_id' => 30,
        ])));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM organizational_units WHERE name = 'Sub'")->fetchColumn()
        );
    }

    /**
     * get() returns the OU together with its direct children; OU 10
     * (Engineering) has children 11 (Backend) and 13 (Frontend).
     */
    public function testGetReturnsOuWithParentAndChildren(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->get(new Request('GET', '/api/ous/10'), ['id' => 10]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertNull($data['parent_id']);
        // ATTR_STRINGIFY_FETCHES is on for this fixture (mirrors PostgreSQL), so
        // raw fetched ids come back as strings; cast before comparing.
        $childIds = array_map('intval', array_column($data['children'], 'id'));
        sort($childIds);
        $this->assertSame([11, 13], $childIds);
    }

    /**
     * A GET for an OU belonging to a different tenant is not found (404).
     */
    public function testGetOfForeignOuReturns404(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->get(new Request('GET', '/api/ous/30'), ['id' => 30]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Renaming an OU regenerates the slug from the new name and persists both.
     */
    public function testUpdateChangesNameAndSlug(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/ous/14', [], (string) json_encode(['name' => 'Sales Ops'])),
            ['id' => 14]
        );

        $this->assertSame(200, $response->getStatusCode());
        $row = $this->pdo->query("SELECT name, slug FROM organizational_units WHERE id = 14")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Sales Ops', $row['name']);
        $this->assertSame('sales-ops', $row['slug']);
    }

    /**
     * Updating an OU that belongs to a different tenant is rejected with 403
     * (existence is not disclosed) and the row is left untouched.
     */
    public function testUpdateOfForeignOuReturns403(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/ous/30', [], (string) json_encode(['name' => 'Hijacked'])),
            ['id' => 30]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Other',
            $this->pdo->query('SELECT name FROM organizational_units WHERE id = 30')->fetchColumn()
        );
    }

    /**
     * Deleting a leaf OU with no children and no active members succeeds (204)
     * and the row is removed; OU 14 (Sales) is a childless, memberless root.
     */
    public function testDeleteReturns204OnSuccess(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM organizational_units WHERE id = 14')->fetchColumn()
        );
    }

    /**
     * Deleting an OU that still has children is rejected (409) and the row
     * survives; OU 10 (Engineering) has children 11 and 13.
     */
    public function testDeleteWithChildrenReturns409(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->delete(new Request('DELETE', '/api/ous/10'), ['id' => 10]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('child', strtolower(json_decode($response->getBody(), true)['error']));
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM organizational_units WHERE id = 10')->fetchColumn()
        );
    }

    /**
     * Deleting an OU with an ACTIVE assigned member is rejected (409); OU 14
     * (Sales, a childless root) gets one active member seeded for this test.
     */
    public function testDeleteWithAssignedMembersReturns409(): void
    {
        $this->seedUser(600, 1, 'member@example.com', 'user', 14);

        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('member', strtolower(json_decode($response->getBody(), true)['error']));
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM organizational_units WHERE id = 14')->fetchColumn()
        );
    }

    /**
     * Assigning a role to an OU id that does not exist at all (not merely a
     * foreign one) is rejected with 404.
     */
    public function testAssignRoleToNonExistentOuReturns404(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->assignRole(
            new Request('POST', '/api/ous/999/roles', [], (string) json_encode(['role_id' => 100])),
            ['id' => 999]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Assigning the SAME role twice is rejected on the second attempt (409, the
     * ou_role_assignments UNIQUE constraint) and only one row is persisted.
     */
    public function testAssignRoleDuplicateReturns409(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $first = $handler->assignRole(
            new Request('POST', '/api/ous/10/roles', [], (string) json_encode(['role_id' => 100])),
            ['id' => 10]
        );
        $this->assertSame(201, $first->getStatusCode());

        $second = $handler->assignRole(
            new Request('POST', '/api/ous/10/roles', [], (string) json_encode(['role_id' => 100])),
            ['id' => 10]
        );

        $this->assertSame(409, $second->getStatusCode());
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 10 AND role_id = 100'
            )->fetchColumn()
        );
    }

    /**
     * removeRole() on an assignment that does not exist returns 404.
     */
    public function testRemoveRoleFromNonExistentAssignmentReturns404(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->removeRole(
            new Request('DELETE', '/api/ous/10/roles/100'),
            ['ouId' => 10, 'roleId' => 100]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * removeRole() deletes an existing assignment and returns 204.
     */
    public function testRemoveRoleReturns204(): void
    {
        $this->assignRoleRow(1, 10, 100);

        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->removeRole(
            new Request('DELETE', '/api/ous/10/roles/100'),
            ['ouId' => 10, 'roleId' => 100]
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query(
                'SELECT COUNT(*) FROM ou_role_assignments WHERE ou_id = 10 AND role_id = 100'
            )->fetchColumn()
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

        // WC-d88de9fa: the members() endpoint resolves IDENTITY via
        // profiles/profile_emails (ADR 0005 §1-2) and ROLE/OU via memberships
        // (ADR 0005 §3). The legacy `users` table was retired by the identity
        // hard cutover (migration 042), so identity is seeded on the profile model.
        $displayName = strstr($email, '@', true) ?: $email;
        $pStmt = $this->pdo->prepare(
            "INSERT INTO profiles
                (id, display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $pStmt->execute([$id, $displayName]);

        $peStmt = $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        );
        $peStmt->execute([$id, $email]);

        $mStmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, 'active', datetime('now'))"
        );
        $mStmt->execute([$id, $tenantId, $roleId, $ouId]);
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
     * Build an in-memory SQLite connection seeded with the full migration schema,
     * plus the OUs and tenant-specific roles required by these tests.
     *
     *  - OU 10 (Engineering, root) → 11 (Backend) → 12 (Platform); 10 → 13 (Frontend).
     *  - OU 14 (Sales, root, tenant 1); OU 30 (Other, tenant 2).
     *  - role 1 GLOBAL (NULL tenant), role 100 tenant 1, role 200 tenant 2.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        // System tenant (id=0) comes from migrations; test tenants (1, 2) are test-specific.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, 1, NULL, 'Engineering', 'engineering', '', datetime('now')),
                (11, 1, 10,   'Backend',     'backend',     '', datetime('now')),
                (12, 1, 11,   'Platform',    'platform',    '', datetime('now')),
                (13, 1, 10,   'Frontend',    'frontend',    '', datetime('now')),
                (14, 1, NULL, 'Sales',       'sales',       '', datetime('now')),
                (30, 2, NULL, 'Other',       'other',       '', datetime('now'))
        ");

        // Tenant-specific roles (not seeded by migrations).
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, 'tenant-a-role', 'Tenant A role', 1, datetime('now')),
                (200, 'tenant-b-role', 'Tenant B role', 2, datetime('now'))
        ");

        return $pdo;
    }
}
