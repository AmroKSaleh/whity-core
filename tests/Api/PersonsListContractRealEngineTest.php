<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RelationsSchema;
use Whity\Api\PersonsApiHandler;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * `GET /api/persons` on the shared list contract (#1102), against a real engine.
 *
 * WHY A REAL ENGINE. Whether paging over a TIED sort column returns every row
 * exactly once is a fact about what the database does with LIMIT/OFFSET, not
 * about the SQL string; and case-insensitive search takes a different operator
 * per engine (ILIKE on PostgreSQL, LIKE on SQLite). Only running it proves
 * either. This suite runs under whichever engine the harness supplies.
 *
 * THIS ENDPOINT'S DEFAULT IS THE OPPOSITE OF THE TAXONOMY ONES', on purpose. It
 * already paginated before it took the contract, so it keeps paginating with no
 * parameters — see {@see PersonsApiHandler::list()}. What it GAINS is sort and
 * search: the relations screen fetched `per_page=100` and sorted and filtered in
 * the browser precisely because the server did neither, and its own comment
 * recorded that a tenant with more than a hundred people simply could not see
 * the rest.
 *
 * NULL ORDERING IS NOT ASSERTED. PostgreSQL sorts NULLs last on ASC and SQLite
 * sorts them first, so the `account` tests assert that the two groups come out
 * CONTIGUOUS and that the directions are mirror images — the properties that
 * hold on both — rather than which end the account-less people land on.
 */
final class PersonsListContractRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    /**
     * Seeded in a scrambled order, so a `sort=name` assertion cannot pass on the
     * id tiebreaker alone.
     */
    private const NAMES = [
        'Delta Person', 'Alpha Person', 'Foxtrot Person', 'Charlie Person',
        'India Person', 'Bravo Person', 'Lima Person', 'Echo Person',
        'Kilo Person', 'Golf Person', 'Juliet Person', 'Hotel Person',
    ];

    /** These four get a linked profile; the other eight tie on NULL. */
    private const WITH_ACCOUNTS = ['Delta Person', 'India Person', 'Kilo Person', 'Golf Person'];

    private PDO $pdo;

    protected function setUp(): void
    {
        $_GET = [];
        $this->pdo = RelationsSchema::make();

        foreach (self::NAMES as $name) {
            RelationsSchema::seedPerson($this->pdo, self::TENANT_A, $name);
        }

        // Tenant B holds a person with the SAME name as one of tenant A's, so a
        // search that leaked across tenants would return two rows, not one.
        RelationsSchema::seedPerson($this->pdo, self::TENANT_B, 'Delta Person');

        $this->linkProfiles();
        $this->stampCreatedAtInReverseIdOrder();

        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $_GET = [];
    }

    // ── the pagination-default decision ──────────────────────────────────────

    /**
     * A caller that sends nothing still gets ONE PAGE and a `pagination`
     * envelope — this endpoint's default is unchanged by the move.
     *
     * The mirror image of the taxonomy endpoints' test: there the risk was
     * silently starting to paginate, here it is silently STOPPING. A client that
     * sends no parameters used to get twenty-five people and must keep getting
     * twenty-five, not the tenant's whole person graph.
     */
    public function testTheDefaultIsStillOnePageWithAPaginationEnvelope(): void
    {
        $body = $this->body('/api/persons');

        self::assertArrayHasKey('pagination', $body, 'this endpoint paginates unconditionally');
        self::assertSame(25, $body['pagination']['perPage']);
        self::assertSame(count(self::NAMES), $body['pagination']['total']);
    }

    /** And the default order is the historical one: display name ascending. */
    public function testTheDefaultOrderIsUnchanged(): void
    {
        $expected = self::NAMES;
        sort($expected);

        self::assertSame($expected, $this->names('/api/persons?per_page=100'));
    }

    // ── sort ─────────────────────────────────────────────────────────────────

    public function testSortingByNameReorders(): void
    {
        $ascending = self::NAMES;
        sort($ascending);

        self::assertSame($ascending, $this->names('/api/persons?sort=name&per_page=100'));
        self::assertSame(
            array_reverse($ascending),
            $this->names('/api/persons?sort=name&dir=desc&per_page=100')
        );
    }

    /**
     * Sorting by `account` groups the people who have one, and the two
     * directions are mirror images.
     *
     * Asserting contiguity rather than position is what makes this pass on both
     * engines: PostgreSQL puts NULLs last on an ascending sort and SQLite puts
     * them first, and neither is wrong.
     */
    public function testSortingByAccountGroupsTheAccountHolders(): void
    {
        $ascending = $this->accountFlags('/api/persons?sort=account&per_page=100');
        $descending = $this->accountFlags('/api/persons?sort=account&dir=desc&per_page=100');

        self::assertContiguous($ascending, 'ascending account sort');
        self::assertContiguous($descending, 'descending account sort');
        self::assertSame(
            $ascending,
            array_reverse($descending),
            'the two directions are mirror images of each other'
        );
        self::assertSame(count(self::WITH_ACCOUNTS), count(array_filter($ascending)));
    }

    public function testSortingByCreatedReorders(): void
    {
        // created_at was stamped in reverse id order, so ascending by created is
        // the reverse of the seed order — a result the id tiebreaker alone could
        // never produce.
        self::assertSame(
            array_reverse(self::NAMES),
            $this->names('/api/persons?sort=created&per_page=100')
        );
    }

    /** A sort key this endpoint does not offer falls back rather than refusing. */
    public function testAnUnknownSortKeyFallsBackToTheDefault(): void
    {
        self::assertSame(
            $this->names('/api/persons?per_page=100'),
            $this->names('/api/persons?sort=not_a_column&per_page=100')
        );
    }

    /**
     * `relations` is NOT an offered sort key, so it falls back like any other
     * unknown one. Asserted rather than assumed: the screen shows that column,
     * so the next reader will expect it to sort, and this records that the
     * omission was a decision — the count is assembled in PHP from
     * reciprocal-derived edges, not read from a column of `persons`.
     */
    public function testRelationCountIsNotAnOfferedSortKey(): void
    {
        self::assertSame(
            $this->names('/api/persons?per_page=100'),
            $this->names('/api/persons?sort=relations&per_page=100')
        );
    }

    /** And a sort key carrying SQL is simply not a sort. */
    public function testASortKeyCannotInjectSql(): void
    {
        $attack = '/api/persons?per_page=100&sort=' . rawurlencode('display_name; DROP TABLE persons--');

        self::assertSame($this->names('/api/persons?per_page=100'), $this->names($attack));
        self::assertSame(13, $this->scalar('SELECT COUNT(*) FROM persons'));
    }

    // ── paging over ties ─────────────────────────────────────────────────────

    /**
     * THE PROPERTY THAT MATTERS MOST: a walk over a TIED sort column returns
     * every row exactly once.
     *
     * `account` puts eight rows on NULL and four on a profile id. Without the
     * tiebreaker the database may order within a tie differently per query, so a
     * person can appear on two pages while another never appears at all — which
     * reads on screen as a relative who vanished and is a query that is not
     * total.
     */
    public function testPagingOverATiedSortColumnSeesEveryRowExactlyOnce(): void
    {
        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($this->names("/api/persons?sort=account&per_page=5&page={$page}") as $name) {
                $seen[] = $name;
            }
        }

        sort($seen);
        $expected = self::NAMES;
        sort($expected);

        self::assertSame($expected, $seen, 'every row exactly once across the walk');
    }

    public function testConsecutivePagesDoNotRepeatRows(): void
    {
        $first = $this->names('/api/persons?sort=account&per_page=5&page=1');
        $second = $this->names('/api/persons?sort=account&per_page=5&page=2');

        self::assertSame([], array_intersect($first, $second));
        self::assertCount(5, $first);
        self::assertCount(5, $second);
    }

    // ── search ───────────────────────────────────────────────────────────────

    /** Search narrows the rows AND the total the client pages against. */
    public function testSearchNarrowsTheRowsAndTheReportedTotal(): void
    {
        $body = $this->body('/api/persons?q=juliet&per_page=5');

        self::assertSame(['Juliet Person'], array_column($body['data'], 'displayName'));
        self::assertSame(1, $body['pagination']['total'], 'the count carries the search predicate');
        self::assertSame(1, $body['pagination']['totalPages']);
    }

    /**
     * A count that ignored the filter would report the unfiltered total and the
     * client would render page controls for pages that come back empty.
     */
    public function testAnUnmatchedSearchReportsZeroRatherThanTheWholeTable(): void
    {
        $body = $this->body('/api/persons?q=nobody-by-this-name&per_page=5');

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['pagination']['total']);
        self::assertSame(0, $body['pagination']['totalPages']);
    }

    /** Search is case-insensitive on whichever engine is under the suite. */
    public function testSearchIsCaseInsensitive(): void
    {
        self::assertSame(['Juliet Person'], $this->names('/api/persons?q=JULIET'));
    }

    /**
     * A caller's own `%` is matched literally.
     *
     * This is a behaviour CHANGE, and an intended one: the pre-contract `search`
     * interpolated the term into a LIKE unescaped, so a user typing a percent
     * sign got the whole tenant back instead of nothing.
     */
    public function testWildcardsInTheSearchTermAreEscaped(): void
    {
        self::assertSame([], $this->names('/api/persons?q=%25'));
    }

    /** Search runs INSIDE the tenant scope; it cannot widen it. */
    public function testSearchCannotSurfaceAnotherTenantsPerson(): void
    {
        // Both tenants own a person called `Delta Person`.
        $body = $this->body('/api/persons?q=delta&per_page=100');

        self::assertCount(1, $body['data']);
        self::assertSame(self::TENANT_A, $body['data'][0]['tenantId']);
        self::assertSame(1, $body['pagination']['total'], 'and the count is scoped too');

        // And from the other side, tenant B sees only its own.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $fromB = $this->body('/api/persons?q=delta&per_page=100');
        self::assertCount(1, $fromB['data']);
        self::assertSame(self::TENANT_B, $fromB['data'][0]['tenantId']);
    }

    /** Sorting cannot widen the tenant scope either. */
    public function testSortingCannotSurfaceAnotherTenantsPerson(): void
    {
        $body = $this->body('/api/persons?sort=name&per_page=100');

        self::assertCount(count(self::NAMES), $body['data']);
        self::assertSame([self::TENANT_A], array_unique(array_column($body['data'], 'tenantId')));
    }

    // ── the legacy `search` spelling ─────────────────────────────────────────

    /**
     * `search` still filters, because the clients that send it are not updated
     * in this repository.
     *
     * It is read from $_GET, which is the runtime source: FrankenPHP strips the
     * query string from the path, so a filter parsed only out of the path is
     * dead in production (WC-537). That is why this asserts the superglobal.
     */
    public function testTheLegacySearchParameterStillFilters(): void
    {
        $_GET = ['search' => 'Juliet'];

        self::assertSame(['Juliet Person'], $this->names('/api/persons'));
    }

    /** An explicit `q` wins over a stale `search` a client still appends. */
    public function testAnExplicitQueryWinsOverTheLegacySpelling(): void
    {
        $_GET = ['search' => 'Juliet'];

        self::assertSame(['Kilo Person'], $this->names('/api/persons?q=kilo'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function handler(): PersonsApiHandler
    {
        return new PersonsApiHandler(
            new PersonRepository($this->pdo),
            new RelationRepository($this->pdo)
        );
    }

    /** @return array<string, mixed> */
    private function body(string $path): array
    {
        $response = $this->handler()->list(new Request('GET', $path));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function names(string $path): array
    {
        return array_column($this->body($path)['data'], 'displayName');
    }

    /** @return list<bool> */
    private function accountFlags(string $path): array
    {
        return array_map(
            static fn (array $row): bool => (bool) $row['hasAccount'],
            $this->body($path)['data']
        );
    }

    /**
     * Assert a boolean sequence changes value at most once — i.e. the true rows
     * form one block and the false rows another.
     *
     * @param list<bool> $flags
     */
    private static function assertContiguous(array $flags, string $what): void
    {
        $transitions = 0;
        for ($i = 1, $n = count($flags); $i < $n; $i++) {
            if ($flags[$i] !== $flags[$i - 1]) {
                $transitions++;
            }
        }

        self::assertLessThanOrEqual(1, $transitions, "{$what}: the two groups are not contiguous");
    }

    /** Give four of tenant A's people a real linked profile. */
    private function linkProfiles(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE persons SET profile_id = :profile_id WHERE tenant_id = :tenant_id AND display_name = :name'
        );

        foreach (self::WITH_ACCOUNTS as $index => $name) {
            $profileId = RelationsSchema::seedProfile(
                $this->pdo,
                self::TENANT_A,
                sprintf('person%d@example.test', $index)
            );
            $stmt->execute([
                ':profile_id' => $profileId,
                ':tenant_id' => self::TENANT_A,
                ':name' => $name,
            ]);
        }
    }

    /**
     * Give every person a distinct `created_at`, descending as id ascends.
     *
     * Rows inserted in the same second share a timestamp, which would let a
     * `sort=created` assertion pass on the id tiebreaker alone and prove
     * nothing. Reversing the two orders makes the sort observable.
     */
    private function stampCreatedAtInReverseIdOrder(): void
    {
        $ids = $this->column(
            'SELECT id FROM persons WHERE tenant_id = ' . self::TENANT_A . ' ORDER BY id ASC'
        );

        $stmt = $this->pdo->prepare('UPDATE persons SET created_at = :created WHERE id = :id');

        $remaining = count($ids);
        foreach ($ids as $id) {
            $stmt->execute([
                ':created' => sprintf('2026-01-%02d 00:00:00', $remaining--),
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
