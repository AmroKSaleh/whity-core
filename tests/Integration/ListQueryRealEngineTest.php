<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Http\ListQuery;
use Whity\Http\ListSpec;

/**
 * The list contract, executed against a real database (#1102).
 *
 * WHY A REAL ENGINE AND NOT STRING ASSERTIONS. Two of the three things this
 * class exists to get right cannot be checked by comparing SQL text:
 *
 *  - whether paging over TIED rows returns every row exactly once. That is a
 *    property of what the database does with `LIMIT/OFFSET`, not of the string
 *    we built.
 *  - whether case-insensitive search works. `ILIKE` is PostgreSQL-only and
 *    SQLite's `LIKE` is already case-insensitive, so the predicate differs by
 *    engine and only running it proves either branch.
 *
 * This suite therefore runs under whichever engine the harness supplies — real
 * PostgreSQL when `PHPUNIT_PG_DSN` is set, SQLite otherwise — and both are
 * exercised in CI's dialect shards.
 */
final class ListQueryRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);

        // A table of this suite's own, so the assertions do not depend on what
        // any product table happens to hold.
        $this->pdo->exec('DROP TABLE IF EXISTS list_query_probe');
        $this->pdo->exec(
            'CREATE TABLE list_query_probe (
                id INTEGER PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                grp VARCHAR(100) NOT NULL
            )'
        );

        // `grp` is deliberately TIED across many rows — that is the state in
        // which unstable paging misbehaves, and it is the ordinary state of a
        // column like "role" or "status".
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [$i, sprintf('Person %02d', $i), $i <= 20 ? 'staff' : 'guest'];
        }

        $stmt = $this->pdo->prepare('INSERT INTO list_query_probe (id, name, grp) VALUES (?, ?, ?)');
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS list_query_probe');
    }

    private function spec(): ListSpec
    {
        return new ListSpec(
            sortable: ['name' => 'name', 'group' => 'grp'],
            tiebreaker: 'id',
            searchable: ['name'],
            defaultSort: 'name',
            defaultDirection: 'asc',
        );
    }

    /** Run one page and return the ids it contains. */
    private function idsFor(string $path): array
    {
        $q = ListQuery::fromPath($path, $this->spec());

        $sql = 'SELECT id FROM list_query_probe';
        $predicate = $q->searchPredicate($this->pdo);
        if ($predicate !== '') {
            $sql .= ' WHERE ' . $predicate;
        }
        $sql .= ' ' . $q->orderBy() . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $q->bindAll($stmt);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * THE PROPERTY THAT MATTERS MOST: paging over a tied sort column returns
     * every row exactly once.
     *
     * Sorting by `grp` puts twenty rows in one value and ten in another. Without
     * the tiebreaker the database is free to order within a tie differently for
     * each query, so the same row can appear on two pages while another never
     * appears at all — and the symptom on screen is a row that vanished, which
     * reads as lost data rather than as an unstable query.
     */
    public function testPagingOverTiedRowsSeesEveryRowExactlyOnce(): void
    {
        $seen = [];
        for ($page = 1; $page <= 6; $page++) {
            foreach ($this->idsFor("/api/probe?sort=group&per_page=5&page={$page}") as $id) {
                $seen[] = $id;
            }
        }

        sort($seen);
        self::assertSame(range(1, 30), $seen, 'every row exactly once across the walk');
    }

    /** And the pages do not overlap. */
    public function testConsecutivePagesDoNotRepeatRows(): void
    {
        $first = $this->idsFor('/api/probe?sort=group&per_page=5&page=1');
        $second = $this->idsFor('/api/probe?sort=group&per_page=5&page=2');

        self::assertSame([], array_intersect($first, $second));
        self::assertCount(5, $first);
        self::assertCount(5, $second);
    }

    /** A sort key the endpoint does not offer falls back rather than refusing. */
    public function testAnUnknownSortKeyFallsBackToTheDefault(): void
    {
        $bogus = $this->idsFor('/api/probe?sort=not_a_column&per_page=5&page=1');
        $default = $this->idsFor('/api/probe?per_page=5&page=1');

        self::assertSame($default, $bogus);
    }

    /**
     * A sort key cannot carry SQL.
     *
     * The value never becomes SQL — it is a key looked up in the handler's own
     * map — so the injection attempt simply is not a sort. Asserting the ROWS
     * rather than the string is the point: if the value ever did reach the
     * query, this would error or return something else.
     */
    public function testASortKeyCannotInjectSql(): void
    {
        $attack = $this->idsFor('/api/probe?sort=' . rawurlencode('name; DROP TABLE list_query_probe--') . '&per_page=5');

        self::assertSame($this->idsFor('/api/probe?per_page=5'), $attack);
        self::assertSame(
            30,
            (int) $this->pdo->query('SELECT COUNT(*) FROM list_query_probe')->fetchColumn(),
            'the table is still there'
        );
    }

    /** Search matches case-insensitively on whichever engine is under the suite. */
    public function testSearchIsCaseInsensitive(): void
    {
        $lower = $this->idsFor('/api/probe?q=person%2001&per_page=50');
        $upper = $this->idsFor('/api/probe?q=PERSON%2001&per_page=50');

        self::assertSame([1], $lower);
        self::assertSame($lower, $upper, 'ILIKE on PostgreSQL, LIKE on SQLite — same answer');
    }

    /**
     * A caller's own `%` is matched literally.
     *
     * Unescaped, a search for `%` matches every row — so a user typing a percent
     * sign silently gets the whole table back instead of nothing.
     */
    public function testWildcardsInTheSearchTermAreEscaped(): void
    {
        self::assertSame([], $this->idsFor('/api/probe?q=%25&per_page=50'));
    }

    /** No search term means no predicate, so a caller can concatenate blindly. */
    public function testAnAbsentSearchProducesNoPredicate(): void
    {
        $q = ListQuery::fromPath('/api/probe', $this->spec());

        self::assertSame('', $q->searchPredicate($this->pdo));
        self::assertSame('', $q->andSearch($this->pdo));
    }

    /** An endpoint that offers no search ignores `q` rather than half-applying it. */
    public function testAnEndpointWithoutSearchIgnoresTheTerm(): void
    {
        $spec = new ListSpec(sortable: ['name' => 'name'], tiebreaker: 'id');
        $q = ListQuery::fromPath('/api/probe?q=person', $spec);

        self::assertNull($q->search);
        self::assertSame('', $q->searchPredicate($this->pdo));
    }

    /** The ORDER BY always ends in the tiebreaker, even with no sort chosen. */
    public function testTheTiebreakerIsAlwaysPresent(): void
    {
        $spec = new ListSpec(sortable: ['name' => 'name'], tiebreaker: 'id');

        self::assertSame('ORDER BY id ASC', ListQuery::fromPath('/api/probe', $spec)->orderBy());
        self::assertSame(
            'ORDER BY name DESC, id ASC',
            ListQuery::fromPath('/api/probe?sort=name&dir=desc', $spec)->orderBy()
        );
    }

    /** The envelope matches PaginationParams', so a moved endpoint looks unchanged. */
    public function testTheMetaShapeMatchesTheExistingEnvelope(): void
    {
        $q = ListQuery::fromPath('/api/probe?page=2&per_page=10', $this->spec());

        self::assertSame(
            ['page' => 2, 'perPage' => 10, 'total' => 30, 'totalPages' => 3],
            $q->meta(30)
        );
    }
}
