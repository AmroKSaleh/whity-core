<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantsApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see TenantsApiHandler::list()} (WC-122).
 *
 * The delete-tenant dialog reads `tenant.userCount`, but the list endpoint's
 * `LEFT JOIN ... COUNT(u.id) as userCount` aggregate comes back from MySQL with
 * the alias folded to lowercase (`usercount`), so the camelCase key the frontend
 * binds was never present and the "N associated users" warning never rendered.
 *
 * The fix shapes each row through `toPublicTenant()`, pinning the public contract
 * to camelCase regardless of how the engine folds the alias. These tests drive the
 * handler against a genuine SQL engine and assert the response payload carries
 * `userCount` (and `createdAt`) with the real user-count value.
 *
 * SQLite is used because CI has no live MySQL/PostgreSQL; the handler's SELECTs run
 * unmodified, matching {@see UsersApiHandlerRealEngineTest}.
 */
final class TenantsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
        // The list endpoint reads `page`/`per_page`/`sort`/`dir`/`q` from $_GET
        // as well as the path (FrankenPHP strips the query string from the
        // path at runtime), so a superglobal left behind by another test would
        // silently re-sort or filter this one's fixtures.
        $_GET = [];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $_GET = [];
    }

    /**
     * A regular tenant's own listing exposes the associated-user count under the
     * camelCase `userCount` key the frontend reads — never the lowercase
     * `usercount` MySQL would otherwise produce.
     */
    public function testListExposesUserCountForOwnTenant(): void
    {
        // Tenant 1 has two users.
        $this->seedUser(101, 1, 'a@example.com');
        $this->seedUser(102, 1, 'b@example.com');

        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(1, $data);

        $tenant = $data[0];
        $this->assertArrayHasKey('userCount', $tenant, 'The payload must carry the camelCase userCount key.');
        $this->assertArrayNotHasKey('usercount', $tenant, 'The lowercase alias must not leak into the contract.');
        $this->assertSame(2, $tenant['userCount']);
        $this->assertSame(1, $tenant['id']);
        $this->assertSame('Tenant A', $tenant['name']);
    }

    /**
     * A tenant with no users reports `userCount` as 0 (the warning branch must
     * not fire), exercising the LEFT JOIN's zero-count path.
     */
    public function testListReportsZeroUserCountForEmptyTenant(): void
    {
        // Tenant 2 exists with no users.
        MockRequestFactory::setTestTenant(2);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $this->assertSame(200, $response->getStatusCode());
        $tenant = json_decode($response->getBody(), true)['data'][0];
        $this->assertSame(0, $tenant['userCount']);
        $this->assertIsInt($tenant['userCount'], 'userCount must be a real integer, not a string.');
    }

    /**
     * The system tenant (id 0) sees every non-system tenant, each carrying its own
     * `userCount`. This is the path the admin actually hits before opening the
     * delete-tenant dialog.
     */
    public function testSystemUserListingExposesUserCountPerTenant(): void
    {
        $this->seedUser(201, 1, 'one@example.com');
        $this->seedUser(202, 2, 'two@example.com');
        $this->seedUser(203, 2, 'three@example.com');

        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];

        $byId = [];
        foreach ($data as $row) {
            $this->assertArrayHasKey('userCount', $row);
            $byId[$row['id']] = $row['userCount'];
        }

        // The system tenant (id 0) is excluded; tenants 1 and 2 are listed.
        $this->assertArrayNotHasKey(0, $byId, 'The system tenant must be excluded from the listing.');
        $this->assertSame(1, $byId[1]);
        $this->assertSame(2, $byId[2]);
    }

    /**
     * The public contract normalises `created_at` to `createdAt` so the list
     * payload matches the frontend `Tenant` type (WC-100/WC-113 casing alignment).
     */
    public function testListAliasesCreatedAtToCamelCase(): void
    {
        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->list(new Request('GET', '/api/tenants', []));

        $tenant = json_decode($response->getBody(), true)['data'][0];
        $this->assertArrayHasKey('createdAt', $tenant);
        $this->assertArrayNotHasKey('created_at', $tenant);
        $this->assertNotNull($tenant['createdAt']);
    }

    // ============ #1102: the shared list contract (sort + search) ============
    //
    // The tenants screen fetched `per_page=100` and sorted and filtered the
    // result IN THE BROWSER, with its own comment admitting that anything past
    // the hundredth workspace was simply unreachable. Moving the sort to the
    // server is what makes the rest of the list reachable, so these tests assert
    // the ORDER the endpoint returns — string-checking the SQL would prove
    // nothing about what the engine does with it, least of all across the two
    // engines this suite runs under.

    /**
     * Every key the endpoint offers actually reorders the list, in both
     * directions, and the default is the `created_at DESC` it always used.
     *
     * @dataProvider tenantSortCases
     * @param list<int> $expected Tenant ids in the order the endpoint must return them.
     */
    public function testEachSortKeyReordersTheList(string $query, array $expected): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $this->assertSame($expected, $this->listedIds('/api/tenants?' . $query));
    }

    /**
     * @return array<string, array{0: string, 1: list<int>}>
     */
    public static function tenantSortCases(): array
    {
        return [
            // Alpha, Bravo, Charlie, Delta Works, Echo Works.
            'name ascending'        => ['sort=name&dir=asc', [2, 4, 5, 1, 3]],
            'name descending'       => ['sort=name&dir=desc', [3, 1, 5, 4, 2]],
            // kilo, lima, mike, yankee, zulu — deliberately NOT the name order,
            // so a spec that quietly sorted by the wrong column would show up.
            'slug ascending'        => ['sort=slug&dir=asc', [3, 5, 1, 4, 2]],
            // Member counts 0, 0, 1, 2, 3 — the two zeroes are a real tie, broken
            // by id so the pair keeps its order in both directions.
            'userCount ascending'   => ['sort=userCount&dir=asc', [2, 4, 3, 1, 5]],
            'userCount descending'  => ['sort=userCount&dir=desc', [5, 1, 3, 2, 4]],
            'createdAt ascending'   => ['sort=createdAt&dir=asc', [4, 2, 5, 1, 3]],
            // No sort at all: the endpoint's historical created_at DESC.
            'no sort is createdAt descending' => ['', [3, 1, 5, 2, 4]],
        ];
    }

    /**
     * An unrecognised sort key falls back to the default rather than erroring.
     *
     * A client that asks for a column this endpoint does not offer — an older
     * build, a renamed column — should get the list, not a 400 it cannot act on.
     */
    public function testAnUnknownSortKeyFallsBackToTheDefault(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $bogus = $this->listedIds('/api/tenants?sort=not_a_column');

        $this->assertSame($this->listedIds('/api/tenants'), $bogus);
        $this->assertSame([3, 1, 5, 2, 4], $bogus);
    }

    /**
     * A sort key cannot carry SQL: it is a KEY into the handler's own map, never
     * an expression. Asserting the rows rather than the query string is the
     * point — if the value ever did reach the SQL, this would error or answer
     * differently.
     */
    public function testASortKeyCannotInjectSql(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $attack = $this->listedIds(
            '/api/tenants?sort=' . rawurlencode('name; DROP TABLE tenants--')
        );

        $this->assertSame($this->listedIds('/api/tenants'), $attack);
        $this->assertSame(
            6,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
            'the table is still there (five fixtures plus the system tenant)'
        );
    }

    /**
     * THE ONE THAT IS EASY TO GET WRONG: the search narrows the reported TOTAL,
     * not only the rows.
     *
     * A COUNT that ignores the filter reports the unfiltered total, and the
     * client dutifully renders page controls for pages that come back empty.
     */
    public function testSearchNarrowsBothTheRowsAndTheTotal(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $unfiltered = $this->listBody('/api/tenants');
        $this->assertSame(5, $unfiltered['pagination']['total']);

        $filtered = $this->listBody('/api/tenants?q=works');

        $this->assertSame([3, 1], array_column($filtered['data'], 'id'));
        $this->assertSame(2, $filtered['pagination']['total'], 'the COUNT must carry the search predicate');
        $this->assertSame(1, $filtered['pagination']['totalPages']);
    }

    /** The narrowed total survives paging, so page two is not advertised. */
    public function testSearchTotalIsFilteredEvenWhenPaged(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $body = $this->listBody('/api/tenants?q=works&per_page=1');

        $this->assertCount(1, $body['data']);
        $this->assertSame(2, $body['pagination']['total']);
        $this->assertSame(2, $body['pagination']['totalPages']);
    }

    /** Search is case-insensitive on whichever engine the suite is running. */
    public function testSearchIsCaseInsensitive(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $this->assertSame(
            $this->listedIds('/api/tenants?q=works'),
            $this->listedIds('/api/tenants?q=WORKS'),
            'ILIKE on PostgreSQL, LIKE on SQLite — the same answer either way'
        );
    }

    /** The slug is searchable too, not just the display name. */
    public function testSearchMatchesTheSlug(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $this->assertSame([4], $this->listedIds('/api/tenants?q=yankee'));
    }

    /**
     * Paging over a TIED sort column returns every row exactly once.
     *
     * Eight workspaces with no members at all share one `userCount`, and
     * `LIMIT/OFFSET` over an ORDER BY with ties has no defined order WITHIN the
     * tie — so without the id tiebreaker a workspace can appear on two pages
     * while another never appears at all. On screen that reads as a deleted
     * tenant, which is why this is asserted over a walk rather than one page.
     */
    public function testPagingOverATiedSortColumnSeesEveryTenantExactlyOnce(): void
    {
        $this->seedTiedTenants();
        MockRequestFactory::setTestTenant(0);

        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            foreach ($this->listedIds("/api/tenants?sort=userCount&per_page=3&page={$page}") as $id) {
                $seen[] = $id;
            }
        }

        sort($seen);
        $this->assertSame(range(1, 8), $seen, 'every workspace exactly once across the walk');
    }

    /** And two consecutive pages of a tied sort never overlap. */
    public function testConsecutivePagesOfATiedSortDoNotOverlap(): void
    {
        $this->seedTiedTenants();
        MockRequestFactory::setTestTenant(0);

        $first  = $this->listedIds('/api/tenants?sort=userCount&per_page=3&page=1');
        $second = $this->listedIds('/api/tenants?sort=userCount&per_page=3&page=2');

        $this->assertCount(3, $first);
        $this->assertCount(3, $second);
        $this->assertSame([], array_intersect($first, $second));
    }

    /**
     * A single-tenant caller's search still cannot reach another workspace.
     *
     * The search is ANDed onto `t.id = :tenant_id`, never substituted for it, so
     * a term that names a different workspace exactly matches nothing.
     */
    public function testSearchCannotReachAnotherTenantsWorkspace(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(1);

        $body = $this->listBody('/api/tenants?q=Alpha%20Systems');

        $this->assertSame([], array_column($body['data'], 'id'), 'tenant 2 must stay invisible to tenant 1');
        $this->assertSame(0, $body['pagination']['total']);
    }

    /**
     * A search that matches nothing is an empty list, NOT "tenant not found".
     *
     * The 404 means "the workspace you belong to is missing", which is a real
     * and alarming condition; reporting it because a reader mistyped a filter
     * would be a screen telling them their own workspace ceased to exist. `q`
     * is new to this endpoint, so no request a client can make today reaches
     * this branch.
     */
    public function testAnEmptySearchResultIsNotReportedAsAMissingTenant(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->list(new Request('GET', '/api/tenants?q=nothing-matches-this'));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame([], $body['data']);
        $this->assertSame(0, $body['pagination']['total']);
    }

    /** The genuine 404 — a caller whose own workspace is absent — is unchanged. */
    public function testAMissingOwnTenantStillReturns404(): void
    {
        MockRequestFactory::setTestTenant(99);

        $response = $this->handler()->list(new Request('GET', '/api/tenants'));

        $this->assertSame(404, $response->getStatusCode());
    }

    /** The envelope is unchanged, so a client cannot tell the endpoint moved. */
    public function testThePaginationEnvelopeKeepsItsShape(): void
    {
        $this->seedSortableTenants();
        MockRequestFactory::setTestTenant(0);

        $body = $this->listBody('/api/tenants?page=2&per_page=2&sort=name');

        $this->assertSame(
            ['page' => 2, 'perPage' => 2, 'total' => 5, 'totalPages' => 3],
            $body['pagination']
        );
    }

    // ---- list-contract fixtures & helpers ----

    /**
     * Run the list endpoint as the current tenant and return the response body.
     *
     * @return array{data: list<array<string, mixed>>, pagination: array<string, int>}
     */
    private function listBody(string $path): array
    {
        $response = $this->handler()->list(new Request('GET', $path));
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        /** @var array{data: list<array<string, mixed>>, pagination: array<string, int>} $body */
        $body = json_decode($response->getBody(), true);

        return $body;
    }

    /**
     * The tenant ids the endpoint returned, in the order it returned them.
     *
     * @return list<int>
     */
    private function listedIds(string $path): array
    {
        return array_map('intval', array_column($this->listBody($path)['data'], 'id'));
    }

    /**
     * Five workspaces whose name, slug, creation date and member count each
     * produce a DIFFERENT order, so a sort that silently used the wrong column
     * cannot pass by coincidence.
     */
    private function seedSortableTenants(): void
    {
        $rows = [
            [1, 'Delta Works',    'mike',   '2026-01-04 00:00:00'],
            [2, 'Alpha Systems',  'zulu',   '2026-01-02 00:00:00'],
            [3, 'Echo Works',     'kilo',   '2026-01-05 00:00:00'],
            [4, 'Bravo Holdings', 'yankee', '2026-01-01 00:00:00'],
            [5, 'Charlie Group',  'lima',   '2026-01-03 00:00:00'],
        ];

        // Tenants 1 and 2 already exist (see makeSqliteSchema); the rest do not.
        $update = $this->pdo->prepare('UPDATE tenants SET name = ?, slug = ?, created_at = ? WHERE id = ?');
        $insert = $this->pdo->prepare('INSERT INTO tenants (id, name, slug, created_at) VALUES (?, ?, ?, ?)');

        foreach ($rows as [$id, $name, $slug, $createdAt]) {
            if ($id <= 2) {
                $update->execute([$name, $slug, $createdAt, $id]);
                continue;
            }
            $insert->execute([$id, $name, $slug, $createdAt]);
        }

        // Member counts 2, 0, 1, 0, 3 — the two zeroes are the tie.
        $this->seedUser(301, 1, 'one@example.com');
        $this->seedUser(302, 1, 'two@example.com');
        $this->seedUser(303, 3, 'three@example.com');
        $this->seedUser(304, 5, 'four@example.com');
        $this->seedUser(305, 5, 'five@example.com');
        $this->seedUser(306, 5, 'six@example.com');
    }

    /** Eight workspaces with no members, so `userCount` is tied across all of them. */
    private function seedTiedTenants(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO tenants (id, name, slug, created_at) VALUES (?, ?, ?, ?)');
        for ($id = 3; $id <= 8; $id++) {
            $insert->execute([$id, sprintf('Tied %02d', $id), sprintf('tied-%02d', $id), '2026-02-01 00:00:00']);
        }
    }

    // ============ WC-49: tenant creation is gated to system administrators ============

    /**
     * A regular tenant's admin must not be able to create tenants. Creating a
     * tenant is a platform-level operation, so a non-system caller is refused
     * with 403 and NO row is written. This FAILS on pre-fix main, where the
     * handler creates the tenant for any caller behind the global `admin` role.
     */
    public function testNonSystemTenantAdminCannotCreateTenant(): void
    {
        $before = (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();

        MockRequestFactory::setTestTenant(1);
        $response = $this->handler()->create(
            new Request('POST', '/api/tenants', [], (string) json_encode(['name' => 'Rogue Tenant']))
        );

        $this->assertSame(403, $response->getStatusCode(), 'A non-system tenant admin must not create tenants.');
        $error = json_decode($response->getBody(), true)['error'];
        $this->assertSame('Only system administrators may create tenants', $error);

        // No tenant was provisioned by the unauthorized caller.
        $after = (int) $this->pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
        $this->assertSame($before, $after, 'A denied create must not write a tenant row.');
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'Rogue Tenant'")->fetchColumn()
        );
    }

    /**
     * A null/unresolved tenant context is not the system tenant and must also be
     * refused, so the gate can never be bypassed by an absent context.
     */
    public function testUnresolvedTenantContextCannotCreateTenant(): void
    {
        TenantContext::reset();
        $response = $this->handler()->create(
            new Request('POST', '/api/tenants', [], (string) json_encode(['name' => 'No Context Tenant']))
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'No Context Tenant'")->fetchColumn()
        );
    }

    /**
     * The system user (tenant 0) retains platform authority and may still create
     * tenants — the gate must not regress the legitimate flow.
     */
    public function testSystemUserCanStillCreateTenant(): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create(
            new Request('POST', '/api/tenants', [], (string) json_encode(['name' => 'New Tenant', 'slug' => 'new-tenant']))
        );

        $this->assertSame(201, $response->getStatusCode(), 'A system user must still be able to create tenants.');
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('New Tenant', $data['name']);
        $this->assertSame('new-tenant', $data['slug']);

        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE name = 'New Tenant'")->fetchColumn(),
            'The system-created tenant must be persisted.'
        );
    }

    // ============ WC-d88de9fa: delete guard counts only ACTIVE members ============

    /**
     * The delete guard counts only ACTIVE memberships. A system user deleting a
     * tenant whose ONLY membership is suspended must succeed — the 409
     * "has N member(s)" block must not fire for a non-active occupant.
     */
    public function testDeleteSucceedsWhenOnlyMemberIsSuspended(): void
    {
        // Tenant 2 has exactly one membership, and it is suspended.
        $this->seedMembershipWithStatus(701, 2, 'suspended');

        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/2', []), ['id' => 2]);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'A tenant whose only membership is suspended must be deletable (active count is 0).'
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 2')->fetchColumn(),
            'The tenant must be deleted.'
        );
    }

    /**
     * Conversely, an ACTIVE membership still blocks the delete with a 409 so the
     * guard is not simply disabled.
     */
    public function testDeleteBlockedWhenMemberIsActive(): void
    {
        $this->seedMembershipWithStatus(702, 2, 'active');

        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/2', []), ['id' => 2]);

        $this->assertSame(409, $response->getStatusCode(), 'An active member must block tenant deletion.');
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 2')->fetchColumn(),
            'The tenant must survive a blocked delete.'
        );
    }

    // ==================== access control (WC-81, migrated from the mocked-PDO unit test) ====================
    //
    // Migrated from the mocked-PDO tests/Unit/Api/TenantsApiHandlerTest.php onto
    // this real-engine fixture (tenants 1 and 2), preserving the original
    // intent/assertions: system users (tenant_id=0) may update/delete any other
    // tenant, the system tenant (id=0) can never be deleted, and non-system
    // users may not update/delete tenants other than their own.

    /**
     * AC1: a system user (tenant_id=0) updating another tenant succeeds (200)
     * and the write actually lands.
     */
    public function testSystemUserCanUpdateAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/tenants/1', [], (string) json_encode(['name' => 'Tenant A Renamed'])),
            ['id' => 1]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame(1, $data['data']['id']);
        $this->assertSame(
            'Tenant A Renamed',
            $this->pdo->query('SELECT name FROM tenants WHERE id = 1')->fetchColumn()
        );
    }

    /**
     * An over-long name (VARCHAR(255)) on update is rejected with a clean 422
     * before the write (input hardening).
     */
    public function testUpdateRejectsOverLongNameWith422(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/tenants/1', [], (string) json_encode(['name' => str_repeat('a', 256)])),
            ['id' => 1]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('name', json_decode($response->getBody(), true)['details']['field']);
    }

    /**
     * AC1: a system user (tenant_id=0) deleting another tenant (with zero
     * active members) succeeds (200) and the row is removed.
     */
    public function testSystemUserCanDeleteAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/2', []), ['id' => 2]);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame(2, $data['data']['id']);
        $this->assertSame('Tenant deleted', $data['data']['message']);
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 2')->fetchColumn()
        );
    }

    /**
     * AC2: deleting tenant 0 returns 400 "Cannot delete system tenant" and the
     * guard trips before any tenant row is touched.
     */
    public function testDeletingSystemTenantReturns400(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/0', []), ['id' => 0]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Cannot delete system tenant', json_decode($response->getBody(), true)['error']);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 0')->fetchColumn(),
            'The system tenant row must survive the rejected delete.'
        );
    }

    /**
     * AC2: the guard also applies when the id is provided as a string "0".
     */
    public function testDeletingSystemTenantStringIdReturns400(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/0', []), ['id' => '0']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Cannot delete system tenant', json_decode($response->getBody(), true)['error']);
    }

    /**
     * AC3: a non-system user updating a tenant other than their own returns 403
     * and the target row is untouched.
     */
    public function testNonSystemUserCannotUpdateAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/tenants/2', [], (string) json_encode(['name' => 'Hijack'])),
            ['id' => 2]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            'Cannot update other tenants',
            json_decode($response->getBody(), true)['error']
        );
        $this->assertSame('Tenant B', $this->pdo->query('SELECT name FROM tenants WHERE id = 2')->fetchColumn());
    }

    /**
     * AC3: a non-system user deleting a tenant other than their own returns 403
     * and the target row survives.
     */
    public function testNonSystemUserCannotDeleteAnotherTenant(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/2', []), ['id' => 2]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString(
            'Cannot delete other tenants',
            json_decode($response->getBody(), true)['error']
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 2')->fetchColumn()
        );
    }

    /**
     * Regression: a non-system user updating their OWN tenant still succeeds.
     */
    public function testNonSystemUserCanUpdateOwnTenant(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/tenants/1', [], (string) json_encode(['slug' => 'tenant-a-new'])),
            ['id' => 1]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('tenant-a-new', $this->pdo->query('SELECT slug FROM tenants WHERE id = 1')->fetchColumn());
    }

    /**
     * Regression: a non-system user deleting their OWN (empty) tenant still
     * succeeds.
     */
    public function testNonSystemUserCanDeleteOwnTenant(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->delete(new Request('DELETE', '/api/tenants/1', []), ['id' => 1]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenants WHERE id = 1')->fetchColumn()
        );
    }

    /**
     * A system user updating a tenant that does not exist returns 404.
     */
    public function testSystemUserUpdatingMissingTenantReturns404(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/tenants/99', [], (string) json_encode(['name' => 'X'])),
            ['id' => 99]
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A missing id on update returns 400.
     */
    public function testUpdateWithoutIdReturns400(): void
    {
        MockRequestFactory::setTestTenant(0);

        $response = $this->handler()->update(
            new Request('PATCH', '/api/tenants', [], (string) json_encode(['name' => 'X'])),
            []
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== Helpers ====================

    /**
     * Seed a single membership (with its own profile) at the given status so the
     * delete guard's active-only filter can be exercised.
     */
    private function seedMembershipWithStatus(int $id, int $tenantId, string $status): void
    {
        $pStmt = $this->pdo->prepare(
            "INSERT INTO profiles
                (id, display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $pStmt->execute([$id, "p{$id}"]);

        $mStmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, 2, ?, datetime('now'))"
        );
        $mStmt->execute([$id, $tenantId, $status]);
    }

    private function handler(): TenantsApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new TenantsApiHandler($this->pdo, $hooks);
    }

    private function seedUser(int $id, int $tenantId, string $email): void
    {
        // WC-d88de9fa: the tenants list counts memberships (ADR 0005 §3). Identity
        // is on the profile model; the legacy `users` table was retired by the
        // identity hard cutover (migration 042). Seed a profile and membership so
        // userCount reflects the seeded data.
        $pStmt = $this->pdo->prepare(
            "INSERT INTO profiles
                (id, display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $pStmt->execute([$id, $email]);

        $mStmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, 2, 'active', datetime('now'))"
        );
        $mStmt->execute([$id, $tenantId]);
    }

    /**
     * Build an in-memory SQLite connection seeded with the full migration schema.
     * Migration 010 seeds the system tenant (id 0). Tenants 1 and 2 are additional
     * test-data rows inserted here so the handler's LEFT JOIN sees them.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES
            (1, 'Tenant A', datetime('now')),
            (2, 'Tenant B', datetime('now'))");
        // On real PostgreSQL, explicit-id inserts do NOT advance the SERIAL
        // sequence, so the handler's next auto-id would collide with id=1/2.
        // Resync the sequence to max(id) so create() gets a fresh id.
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $pdo->exec("SELECT setval(pg_get_serial_sequence('tenants', 'id'), (SELECT MAX(id) FROM tenants))");
        }
        return $pdo;
    }

    // ── Bootstrap: a tenant and its first administrator (#779) ───────────────

    /**
     * Provisioning a tenant WITH an administrator is one atomic call.
     *
     * Before this, POST /api/tenants inserted only the tenants row, and the
     * three API paths that could have finished the job formed a cycle:
     * POST /api/users always targets the CALLER's tenant, and both
     * switch-tenant and select-tenant require an active membership in the target
     * before minting a token for it. So the membership needed a token and the
     * token needed the membership, and every install broke the cycle with a
     * direct SQL insert — outside the API's validation and outside its audit
     * trail.
     */
    public function testCreateProvisionsTheTenantAndItsFirstAdministrator(): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create($this->createRequest([
            'name' => 'Bootstrapped',
            'admin' => ['email' => 'owner@example.com', 'password' => 'Str0ng-Bootstrap-Pw!'],
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];
        $tenantId = (int) $data['id'];

        $this->assertSame('owner@example.com', $data['admin']['email']);
        $this->assertSame(1, $data['userCount'], 'the tenant has a member the moment it exists');

        $stmt = $this->pdo->prepare(
            'SELECT m.status, m.is_primary, r.name AS role
               FROM memberships m
               JOIN roles r ON r.id = m.role_id
               JOIN profile_emails pe ON pe.profile_id = m.profile_id
              WHERE m.tenant_id = ? AND pe.email = ?'
        );
        $stmt->execute([$tenantId, 'owner@example.com']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'the administrator is a real membership in the NEW tenant');
        $this->assertSame('active', $row['status']);
        $this->assertSame('admin', $row['role']);
        $this->assertTrue((bool) $row['is_primary'], 'the first membership is the primary one');
    }

    /**
     * An existing person becomes the new tenant's administrator without a second
     * identity being created.
     *
     * profile_emails.email is globally unique (ADR 0005 §2). A duplicate profile
     * would split that person's credential and token epoch across two rows, so a
     * password change or a forced logout would apply to only one of them.
     */
    public function testAnExistingProfileIsReusedAsTheAdministrator(): void
    {
        $this->seedProfileWithEmail(900, 'shared@example.com');

        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create($this->createRequest([
            'name' => 'Second Home',
            'admin' => ['email' => 'shared@example.com', 'password' => 'Str0ng-Bootstrap-Pw!'],
        ]));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $this->assertSame(900, json_decode($response->getBody(), true)['data']['admin']['id']);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM profile_emails WHERE email = ?');
        $count->execute(['shared@example.com']);
        $this->assertSame(1, (int) $count->fetchColumn(), 'one identity, two memberships');
    }

    /**
     * A rejected administrator leaves NO tenant behind.
     *
     * This is the failure semantic the whole change turns on. A tenant with no
     * members is invisible to every API path that requires a membership, so
     * anything left behind here could only be finished or removed by the direct
     * SQL this endpoint exists to eliminate.
     *
     * @dataProvider badAdminProvider
     * @param array<mixed> $admin
     */
    public function testARejectedAdministratorLeavesNoTenantBehind(array $admin, int $expectedStatus): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create($this->createRequest([
            'name' => 'Never Created',
            'admin' => $admin,
        ]));

        $this->assertSame($expectedStatus, $response->getStatusCode(), $response->getBody());

        $orphans = $this->pdo->prepare('SELECT COUNT(*) FROM tenants WHERE name = ?');
        $orphans->execute(['Never Created']);
        $this->assertSame(0, (int) $orphans->fetchColumn(), 'the tenant was not left behind');
    }

    /** @return array<string, array{0: array<mixed>, 1: int}> */
    public static function badAdminProvider(): array
    {
        return [
            'malformed email' => [['email' => 'not-an-email', 'password' => 'Str0ng-Bootstrap-Pw!'], 400],
            'password below policy' => [['email' => 'owner@example.com', 'password' => 'short'], 400],
            'missing password' => [['email' => 'owner@example.com'], 400],
            'unknown role' => [
                ['email' => 'owner@example.com', 'password' => 'Str0ng-Bootstrap-Pw!', 'role' => 'sorcerer'],
                404,
            ],
            'not an object' => [['nonsense'], 400],
        ];
    }

    /**
     * Creating a tenant WITHOUT an administrator behaves exactly as before.
     *
     * The block is optional, so every existing caller must be unaffected —
     * including the userCount the response reports.
     */
    public function testCreateWithoutAnAdministratorIsUnchanged(): void
    {
        MockRequestFactory::setTestTenant(0);
        $response = $this->handler()->create($this->createRequest(['name' => 'Bare Tenant']));

        $this->assertSame(201, $response->getStatusCode(), $response->getBody());
        $data = json_decode($response->getBody(), true)['data'];

        $this->assertArrayNotHasKey('admin', $data);
        $this->assertSame(0, $data['userCount']);

        $members = $this->pdo->prepare('SELECT COUNT(*) FROM memberships WHERE tenant_id = ?');
        $members->execute([(int) $data['id']]);
        $this->assertSame(0, (int) $members->fetchColumn());
    }

    /**
     * A create request carrying a JSON body.
     *
     * @param array<string, mixed> $body
     */
    private function createRequest(array $body): Request
    {
        return new Request('POST', '/api/tenants', [], (string) json_encode($body));
    }

    /** A profile that already owns an email, for the identity-reuse case. */
    private function seedProfileWithEmail(int $profileId, string $email): void
    {
        $this->pdo->prepare(
            "INSERT INTO profiles
                (id, display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, NOW(), NOW())"
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$profileId, $email]);
    }
}
