<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\TagsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * `GET /api/tags` on the shared list contract (#1102), against a real engine.
 *
 * WHY A REAL ENGINE. Two of the properties here cannot be checked by comparing
 * SQL text. Whether paging over a TIED sort column returns every row exactly
 * once is a fact about what the database does with LIMIT/OFFSET, and whether
 * case-insensitive search works depends on which operator the engine got
 * (ILIKE on PostgreSQL, LIKE on SQLite) — so only running it proves either.
 * This suite runs under whichever engine the harness supplies.
 *
 * THE FIRST TEST IS THE REGRESSION ONE. This endpoint returned every tag before
 * it took the contract, and it feeds a picker and the tag-group record screen as
 * well as the admin table. If pagination ever became unconditional here those
 * callers would go short by twenty-five without an error anywhere, so
 * {@see testAnUnpagedCallerStillGetsEveryRowAndNoPaginationBlock()} pins the
 * decision rather than leaving it to be re-litigated by whoever reads the
 * handler next.
 */
final class TagsListContractRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const MANAGER_A = 10;
    private const MANAGER_B = 20;

    /** Seven tags here and five in `beta` — a tie wide enough to page across. */
    private const ALPHA_TAGS = ['delta', 'echo', 'foxtrot', 'golf', 'hotel', 'india', 'juliet'];
    private const BETA_TAGS  = ['kilo', 'lima', 'mike', 'november', 'oscar'];

    private PDO $pdo;
    private TagsApiHandler $handler;
    private int $alphaId;
    private int $betaId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $_GET = [];

        $this->pdo = TaxonomyTestSeed::make();
        $db = TaxonomyTestSeed::wrap($this->pdo);

        $groups = new TagGroupRepository($this->pdo);
        // `alpha` sorts before `beta` by key AND its display name sorts before
        // beta's, so a group sort cannot pass by accident on id order.
        $this->alphaId = (int) $groups->create(self::TENANT_A, 'alpha', ['en' => 'Alpha Group']);
        $this->betaId = (int) $groups->create(self::TENANT_A, 'beta', ['en' => 'Beta Group']);
        $foreignGroup = (int) $groups->create(self::TENANT_B, 'alpha', ['en' => 'Alpha Group']);

        $tags = new TagRepository($this->pdo);
        foreach (self::ALPHA_TAGS as $name) {
            $tags->create(self::TENANT_A, $this->alphaId, $name);
        }
        foreach (self::BETA_TAGS as $name) {
            $tags->create(self::TENANT_A, $this->betaId, $name);
        }

        // Tenant B holds a tag with the SAME name as one of tenant A's, so a
        // search that leaked across tenants would return two rows and not one.
        $tags->create(self::TENANT_B, $foreignGroup, 'delta');

        // Distinct, DESCENDING created_at against ascending id, so `sort=created`
        // cannot be satisfied by the id tiebreaker alone.
        $this->stampCreatedAtInReverseIdOrder();

        $this->handler = new TagsApiHandler(
            $tags,
            $groups,
            new RoleChecker($db, new PermissionRegistry())
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
        $_GET = [];
    }

    // ── the pagination-default decision ──────────────────────────────────────

    /**
     * A caller that sends neither `page` nor `per_page` gets what it got before
     * this endpoint had pagination: every row, and NO `pagination` key.
     *
     * The picker in `web/components/taxonomy/tag-picker.tsx` is this test's
     * reason to exist. It fetches the list whole; paginated by default it would
     * silently stop offering most of a tenant's tags, which reads on screen as
     * missing data rather than as a truncated response.
     */
    public function testAnUnpagedCallerStillGetsEveryRowAndNoPaginationBlock(): void
    {
        $body = $this->body('/api/tags');

        self::assertCount(count(self::ALPHA_TAGS) + count(self::BETA_TAGS), $body['data']);
        self::assertArrayNotHasKey('pagination', $body, 'pagination is opt-in on this endpoint');
    }

    /** Sending `per_page` alone opts in — no `page` required. */
    public function testSendingPerPageAloneOptsIntoPagination(): void
    {
        $body = $this->body('/api/tags?per_page=5');

        self::assertCount(5, $body['data']);
        self::assertSame(
            ['page' => 1, 'perPage' => 5, 'total' => 12, 'totalPages' => 3],
            $body['pagination']
        );
    }

    /**
     * A malformed `per_page` still opts in.
     *
     * The flag asks whether the caller ASKED to page, not whether its value
     * parsed: `per_page=abc` is a client that means to page and got the value
     * wrong, and {@see \Whity\Http\PaginationParams} already clamps it to the
     * default. Reading it as "no pagination" would hand that client the whole
     * table instead — the exact silent-truncation-in-reverse this endpoint's
     * default exists to avoid.
     */
    public function testAMalformedPageParameterStillOptsIn(): void
    {
        $body = $this->body('/api/tags?per_page=not-a-number');

        self::assertArrayHasKey('pagination', $body);
        self::assertSame(25, $body['pagination']['perPage']);
    }

    /** And the unpaged order is still the historical one: oldest id first. */
    public function testTheUnsortedOrderIsUnchanged(): void
    {
        self::assertSame(
            array_merge(self::ALPHA_TAGS, self::BETA_TAGS),
            $this->names('/api/tags')
        );
    }

    // ── sort ─────────────────────────────────────────────────────────────────

    /** Every offered sort key reorders the rows, in both directions. */
    public function testSortingByNameReorders(): void
    {
        $ascending = self::ALPHA_TAGS;
        $all = array_merge(self::ALPHA_TAGS, self::BETA_TAGS);
        sort($ascending);
        sort($all);

        self::assertSame($all, $this->names('/api/tags?sort=name'));
        self::assertSame(array_reverse($all), $this->names('/api/tags?sort=name&dir=desc'));
    }

    public function testSortingByGroupReorders(): void
    {
        // `beta` first on a descending group sort; the seeded order is alpha's.
        $descending = $this->names('/api/tags?sort=group&dir=desc');

        self::assertSame(self::BETA_TAGS, array_slice($descending, 0, count(self::BETA_TAGS)));
        self::assertSame(self::ALPHA_TAGS, array_slice($descending, count(self::BETA_TAGS)));
    }

    public function testSortingByCreatedReorders(): void
    {
        // created_at was stamped in reverse id order, so ascending by created is
        // descending by the seed order — a result the id tiebreaker alone could
        // never produce.
        $seeded = array_merge(self::ALPHA_TAGS, self::BETA_TAGS);

        self::assertSame(array_reverse($seeded), $this->names('/api/tags?sort=created'));
    }

    /**
     * A sort key this endpoint does not offer falls back to the default rather
     * than refusing — a client asking for a column it cannot see should still
     * get a list.
     */
    public function testAnUnknownSortKeyFallsBackToTheDefault(): void
    {
        self::assertSame($this->names('/api/tags'), $this->names('/api/tags?sort=not_a_column'));
    }

    /** And a sort key carrying SQL is simply not a sort. */
    public function testASortKeyCannotInjectSql(): void
    {
        $attack = '/api/tags?sort=' . rawurlencode('name; DROP TABLE tags--');

        self::assertSame($this->names('/api/tags'), $this->names($attack));
        self::assertSame(13, $this->scalar('SELECT COUNT(*) FROM tags'));
    }

    // ── paging over ties ─────────────────────────────────────────────────────

    /**
     * THE PROPERTY THAT MATTERS MOST: a walk over a TIED sort column returns
     * every row exactly once.
     *
     * Sorting by `group` puts seven rows on one value and five on another.
     * Without the tiebreaker the database may order within a tie differently per
     * query, so a row can appear on two pages while another never appears at
     * all — and on screen that is a tag that vanished, which reads as lost data
     * and is an unstable query.
     */
    public function testPagingOverATiedSortColumnSeesEveryRowExactlyOnce(): void
    {
        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($this->names("/api/tags?sort=group&per_page=5&page={$page}") as $name) {
                $seen[] = $name;
            }
        }

        sort($seen);
        $expected = array_merge(self::ALPHA_TAGS, self::BETA_TAGS);
        sort($expected);

        self::assertSame($expected, $seen, 'every row exactly once across the walk');
    }

    public function testConsecutivePagesDoNotRepeatRows(): void
    {
        $first = $this->names('/api/tags?sort=group&per_page=5&page=1');
        $second = $this->names('/api/tags?sort=group&per_page=5&page=2');

        self::assertSame([], array_intersect($first, $second));
    }

    // ── search ───────────────────────────────────────────────────────────────

    /** Search narrows the rows AND the total the client pages against. */
    public function testSearchNarrowsTheRowsAndTheReportedTotal(): void
    {
        $body = $this->body('/api/tags?q=lima&per_page=5');

        self::assertSame(['lima'], array_column($body['data'], 'name'));
        self::assertSame(1, $body['pagination']['total'], 'the count carries the search predicate');
        self::assertSame(1, $body['pagination']['totalPages']);
    }

    /**
     * A count that ignored the filter would report the unfiltered total and the
     * client would render page controls for pages that come back empty. Asserted
     * separately from the row check because that is the failure that survives a
     * correct-looking first page.
     */
    public function testAnUnmatchedSearchReportsZeroRatherThanTheWholeTable(): void
    {
        $body = $this->body('/api/tags?q=nothing-matches-this&per_page=5');

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['pagination']['total']);
        self::assertSame(0, $body['pagination']['totalPages']);
    }

    /** Search is case-insensitive on whichever engine is under the suite. */
    public function testSearchIsCaseInsensitive(): void
    {
        self::assertSame(['lima'], $this->names('/api/tags?q=LIMA'));
    }

    /** The group's key and its readable display name are both searchable. */
    public function testSearchCoversTheGroupKeyAndDisplayName(): void
    {
        self::assertSame(self::BETA_TAGS, $this->names('/api/tags?q=beta'));
        self::assertSame(self::BETA_TAGS, $this->names('/api/tags?q=' . rawurlencode('Beta Group')));
    }

    /** A caller's own `%` is matched literally, not as "everything". */
    public function testWildcardsInTheSearchTermAreEscaped(): void
    {
        self::assertSame([], $this->names('/api/tags?q=%25'));
    }

    /** Search runs INSIDE the tenant scope; it cannot widen it. */
    public function testSearchCannotSurfaceAnotherTenantsTag(): void
    {
        // Both tenants own a tag called `delta`.
        self::assertSame(['delta'], $this->names('/api/tags?q=delta'));

        $rows = $this->body('/api/tags?q=delta')['data'];
        self::assertSame([self::TENANT_A], array_unique(array_column($rows, 'tenant_id')));

        // And from the other side, tenant B sees only its own.
        $fromB = json_decode(
            $this->handler->list($this->request(self::MANAGER_B, self::TENANT_B, '/api/tags?q=delta'))->getBody(),
            true
        );
        self::assertCount(1, $fromB['data']);
        self::assertSame(self::TENANT_B, $fromB['data'][0]['tenant_id']);
    }

    /** Sorting cannot widen the tenant scope either. */
    public function testSortingCannotSurfaceAnotherTenantsTag(): void
    {
        $rows = $this->body('/api/tags?sort=group&dir=desc&per_page=100')['data'];

        self::assertCount(12, $rows);
        self::assertSame([self::TENANT_A], array_unique(array_column($rows, 'tenant_id')));
    }

    // ── composition with the pre-existing group filter ───────────────────────

    /**
     * `group_id` still narrows, and it narrows the COUNT too — a filtered page
     * that reported the tenant's whole total would page into nothing.
     */
    public function testTheGroupFilterComposesWithSearchAndTheCount(): void
    {
        $body = $this->body('/api/tags?group_id=' . $this->betaId . '&per_page=3');

        self::assertCount(3, $body['data']);
        self::assertSame(count(self::BETA_TAGS), $body['pagination']['total']);

        $narrowed = $this->body('/api/tags?group_id=' . $this->alphaId . '&q=echo&per_page=3');
        self::assertSame(['echo'], array_column($narrowed['data'], 'name'));
        self::assertSame(1, $narrowed['pagination']['total']);

        // A search that matches only in the OTHER group returns nothing here.
        $crossed = $this->body('/api/tags?group_id=' . $this->alphaId . '&q=lima&per_page=3');
        self::assertSame([], $crossed['data']);
        self::assertSame(0, $crossed['pagination']['total']);
    }

    /** The query string is also read from $_GET, which is the runtime source. */
    public function testTheContractIsReadFromTheGetSuperglobal(): void
    {
        $_GET = ['q' => 'lima'];

        self::assertSame(['lima'], $this->names('/api/tags'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function body(string $path): array
    {
        $response = $this->handler->list($this->request(self::MANAGER_A, self::TENANT_A, $path));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function names(string $path): array
    {
        return array_column($this->body($path)['data'], 'name');
    }

    private function request(int $profileId, int $tenantId, string $path): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $request = new Request('GET', $path, [], '');
        $request->user = (object) ['profile_id' => $profileId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    /**
     * Give every tag a distinct `created_at`, descending as id ascends.
     *
     * Rows inserted in the same second share a timestamp, which would let a
     * `sort=created` assertion pass on the id tiebreaker alone and prove
     * nothing. Reversing the two orders makes the sort observable.
     */
    private function stampCreatedAtInReverseIdOrder(): void
    {
        $ids = $this->column('SELECT id FROM tags ORDER BY id ASC');
        $stmt = $this->pdo->prepare('UPDATE tags SET created_at = :created WHERE id = :id');

        $offset = count($ids);
        foreach ($ids as $id) {
            $stmt->execute([
                ':created' => sprintf('2026-01-%02d 00:00:00', $offset--),
                ':id' => (int) $id,
            ]);
        }
    }

    /**
     * One integer out of a scalar query.
     *
     * `PDO::query()` can return false, and PHPStan says so. Asserting it here
     * keeps the fixture queries readable while giving a query that failed a
     * message of its own instead of a silent zero.
     */
    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, "query failed: {$sql}");

        return (int) $stmt->fetchColumn();
    }

    /**
     * The first column of every row a query returns. See {@see scalar()}.
     *
     * @return list<mixed>
     */
    private function column(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, "query failed: {$sql}");

        /** @var list<mixed> $values */
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $values;
    }
}
