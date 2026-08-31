<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\TagGroupsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * `GET /api/tag-groups` on the shared list contract (#1102), against a real
 * engine.
 *
 * WHY A REAL ENGINE. Whether paging over a TIED sort column returns every row
 * exactly once is a fact about what the database does with LIMIT/OFFSET, not
 * about the SQL string; and case-insensitive search takes a different operator
 * per engine (ILIKE on PostgreSQL, LIKE on SQLite). Only running it proves
 * either. This suite runs under whichever engine the harness supplies.
 *
 * THE DISPLAY NAME IS SEARCHABLE BUT NOT SORTABLE, and that asymmetry is
 * deliberate rather than an oversight — see {@see TagGroupRepository::listSpec()}.
 * `CAST(display_name AS TEXT)` is standard on both engines so a search over the
 * label works; ordering by the label would need JSON member extraction, whose
 * syntax the two engines do not share.
 */
final class TagGroupsListContractRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const MANAGER_A = 10;
    private const MANAGER_B = 20;

    /**
     * Seeded in a scrambled order, so a `sort=key` assertion cannot pass on the
     * id tiebreaker alone.
     */
    private const KEYS = [
        'delta', 'alpha', 'foxtrot', 'charlie', 'india', 'bravo',
        'lima', 'echo', 'kilo', 'golf', 'juliet', 'hotel',
    ];

    /** The first seven share one `created_at`, the rest another — a real tie. */
    private const EARLY_COUNT = 7;

    private PDO $pdo;
    private TagGroupsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $_GET = [];

        $this->pdo = TaxonomyTestSeed::make();
        $db = TaxonomyTestSeed::wrap($this->pdo);

        $groups = new TagGroupRepository($this->pdo);
        foreach (self::KEYS as $key) {
            $groups->create(self::TENANT_A, $key, ['en' => 'Group ' . ucfirst($key)]);
        }

        // Tenant B owns a group with the SAME key as one of tenant A's, so a
        // search that leaked across tenants would return two rows, not one.
        $groups->create(self::TENANT_B, 'alpha', ['en' => 'Group Alpha']);

        $this->stampTimestamps();

        $this->handler = new TagGroupsApiHandler(
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
     * The tags screen is this test's reason to exist. It fetches this list whole
     * to build an id→label map for the tags table's Group column; paginated by
     * default it would render `#37` for every group past the cut, with nothing
     * raising an error. The Taxonomy plugin's `referenceSelect` dropdown reads it
     * the same way.
     */
    public function testAnUnpagedCallerStillGetsEveryRowAndNoPaginationBlock(): void
    {
        $body = $this->body('/api/tag-groups');

        self::assertCount(count(self::KEYS), $body['data']);
        self::assertArrayNotHasKey('pagination', $body, 'pagination is opt-in on this endpoint');
    }

    /** Sending `page` alone opts in — no `per_page` required. */
    public function testSendingPageAloneOptsIntoPagination(): void
    {
        $body = $this->body('/api/tag-groups?page=1');

        self::assertCount(12, $body['data'], 'the default page size still holds all twelve');
        self::assertSame(
            ['page' => 1, 'perPage' => 25, 'total' => 12, 'totalPages' => 1],
            $body['pagination']
        );
    }

    /** And the unpaged order is still the historical one: oldest id first. */
    public function testTheUnsortedOrderIsUnchanged(): void
    {
        self::assertSame(self::KEYS, $this->keys('/api/tag-groups'));
    }

    // ── sort ─────────────────────────────────────────────────────────────────

    public function testSortingByKeyReorders(): void
    {
        $ascending = self::KEYS;
        sort($ascending);

        self::assertSame($ascending, $this->keys('/api/tag-groups?sort=key'));
        self::assertSame(array_reverse($ascending), $this->keys('/api/tag-groups?sort=key&dir=desc'));
    }

    public function testSortingByUpdatedReorders(): void
    {
        // updated_at was stamped in reverse id order, so ascending by updated is
        // the reverse of the seed order — something the id tiebreaker alone
        // could never produce.
        self::assertSame(array_reverse(self::KEYS), $this->keys('/api/tag-groups?sort=updated'));
    }

    public function testSortingByCreatedReorders(): void
    {
        // The later block of rows comes first on a descending created sort.
        $descending = $this->keys('/api/tag-groups?sort=created&dir=desc');
        $late = array_slice(self::KEYS, self::EARLY_COUNT);

        self::assertSame($late, array_slice($descending, 0, count($late)));
    }

    /** A sort key this endpoint does not offer falls back rather than refusing. */
    public function testAnUnknownSortKeyFallsBackToTheDefault(): void
    {
        self::assertSame($this->keys('/api/tag-groups'), $this->keys('/api/tag-groups?sort=not_a_column'));
    }

    /**
     * `displayName` is NOT an offered sort key, so it falls back like any other
     * unknown one. Asserted rather than assumed: the screen shows that column,
     * so the next reader will reasonably expect it to sort, and this records
     * that the omission was a decision.
     */
    public function testDisplayNameIsNotAnOfferedSortKey(): void
    {
        self::assertSame($this->keys('/api/tag-groups'), $this->keys('/api/tag-groups?sort=displayName'));
    }

    /** And a sort key carrying SQL is simply not a sort. */
    public function testASortKeyCannotInjectSql(): void
    {
        $attack = '/api/tag-groups?sort=' . rawurlencode('group_key; DROP TABLE tag_groups--');

        self::assertSame($this->keys('/api/tag-groups'), $this->keys($attack));
        self::assertSame(13, $this->scalar('SELECT COUNT(*) FROM tag_groups'));
    }

    // ── paging over ties ─────────────────────────────────────────────────────

    /**
     * THE PROPERTY THAT MATTERS MOST: a walk over a TIED sort column returns
     * every row exactly once.
     *
     * `created` puts seven rows on one value and five on another. Without the
     * tiebreaker the database may order within a tie differently per query, so a
     * row can appear on two pages while another never appears at all — which
     * reads on screen as a group that vanished and is a query that is not total.
     */
    public function testPagingOverATiedSortColumnSeesEveryRowExactlyOnce(): void
    {
        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($this->keys("/api/tag-groups?sort=created&per_page=5&page={$page}") as $key) {
                $seen[] = $key;
            }
        }

        sort($seen);
        $expected = self::KEYS;
        sort($expected);

        self::assertSame($expected, $seen, 'every row exactly once across the walk');
    }

    public function testConsecutivePagesDoNotRepeatRows(): void
    {
        $first = $this->keys('/api/tag-groups?sort=created&per_page=5&page=1');
        $second = $this->keys('/api/tag-groups?sort=created&per_page=5&page=2');

        self::assertSame([], array_intersect($first, $second));
        self::assertCount(5, $first);
        self::assertCount(5, $second);
    }

    // ── search ───────────────────────────────────────────────────────────────

    /** Search narrows the rows AND the total the client pages against. */
    public function testSearchNarrowsTheRowsAndTheReportedTotal(): void
    {
        $body = $this->body('/api/tag-groups?q=juliet&per_page=5');

        self::assertSame(['juliet'], array_column($body['data'], 'key'));
        self::assertSame(1, $body['pagination']['total'], 'the count carries the search predicate');
        self::assertSame(1, $body['pagination']['totalPages']);
    }

    /**
     * A count that ignored the filter would report the unfiltered total and the
     * client would render page controls for pages that come back empty.
     */
    public function testAnUnmatchedSearchReportsZeroRatherThanTheWholeTable(): void
    {
        $body = $this->body('/api/tag-groups?q=nothing-matches-this&per_page=5');

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['pagination']['total']);
        self::assertSame(0, $body['pagination']['totalPages']);
    }

    /** Search is case-insensitive on whichever engine is under the suite. */
    public function testSearchIsCaseInsensitive(): void
    {
        self::assertSame(['juliet'], $this->keys('/api/tag-groups?q=JULIET'));
    }

    /**
     * The bilingual display name is searchable, which is what the screen's
     * search box means to the person typing in it.
     */
    public function testSearchCoversTheDisplayName(): void
    {
        self::assertSame(['echo'], $this->keys('/api/tag-groups?q=' . rawurlencode('Group Echo')));
    }

    /** A caller's own `%` is matched literally, not as "everything". */
    public function testWildcardsInTheSearchTermAreEscaped(): void
    {
        self::assertSame([], $this->keys('/api/tag-groups?q=%25'));
    }

    /** Search runs INSIDE the tenant scope; it cannot widen it. */
    public function testSearchCannotSurfaceAnotherTenantsGroup(): void
    {
        // Both tenants own a group keyed `alpha`.
        $rows = $this->body('/api/tag-groups?q=alpha')['data'];

        self::assertCount(1, $rows);
        self::assertSame('alpha', $rows[0]['key']);
        self::assertSame(self::TENANT_A, $rows[0]['tenant_id']);

        // And from the other side, tenant B sees only its own.
        $response = $this->handler->list($this->request(self::MANAGER_B, self::TENANT_B, '/api/tag-groups?q=alpha'));
        $fromB = json_decode($response->getBody(), true);
        self::assertCount(1, $fromB['data']);
        self::assertSame(self::TENANT_B, $fromB['data'][0]['tenant_id']);
    }

    /** Sorting cannot widen the tenant scope either. */
    public function testSortingCannotSurfaceAnotherTenantsGroup(): void
    {
        $rows = $this->body('/api/tag-groups?sort=key&per_page=100')['data'];

        self::assertCount(12, $rows);
        self::assertSame([self::TENANT_A], array_unique(array_column($rows, 'tenant_id')));
    }

    /** The query string is also read from $_GET, which is the runtime source. */
    public function testTheContractIsReadFromTheGetSuperglobal(): void
    {
        $_GET = ['q' => 'juliet'];

        self::assertSame(['juliet'], $this->keys('/api/tag-groups'));
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
    private function keys(string $path): array
    {
        return array_column($this->body($path)['data'], 'key');
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
     * Stamp `created_at` into two tied blocks and `updated_at` into distinct
     * values descending as id ascends.
     *
     * Rows inserted in the same second share both timestamps, which would let a
     * sort assertion pass on the id tiebreaker alone and prove nothing. The
     * tie in `created_at` is the point of the paging test; the spread in
     * `updated_at` is what makes an ordering observable.
     */
    private function stampTimestamps(): void
    {
        $ids = $this->column(
            'SELECT id FROM tag_groups WHERE tenant_id = ' . self::TENANT_A . ' ORDER BY id ASC'
        );

        $stmt = $this->pdo->prepare(
            'UPDATE tag_groups SET created_at = :created, updated_at = :updated WHERE id = :id'
        );

        $remaining = count($ids);
        foreach (array_values($ids) as $index => $id) {
            $stmt->execute([
                ':created' => $index < self::EARLY_COUNT ? '2026-01-01 00:00:00' : '2026-02-01 00:00:00',
                ':updated' => sprintf('2026-03-%02d 00:00:00', $remaining--),
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
