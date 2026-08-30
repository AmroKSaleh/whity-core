<?php

declare(strict_types=1);

namespace Whity\Http;

use PDO;
use PDOStatement;

/**
 * One list endpoint's page, sort and search, parsed and made safe to put in SQL.
 *
 * WHAT THIS IS FOR. {@see PaginationParams} already gave every list endpoint the
 * same `page` / `per_page` handling. It was not enough: the admin screens fetched
 * a large slice and then sorted and filtered it IN THE BROWSER, because no
 * endpoint accepted a sort or a search term. That works until a tenant outgrows
 * the slice — the users screen fetched `per_page=100` and its own comment
 * admitted that tenants with more than 100 users simply could not see the rest,
 * with nothing on screen saying so.
 *
 * Moving pagination to the server without moving sort and search WITH it would
 * trade that for a different wrong answer: a table that sorts only the twenty-five
 * rows it happens to be showing. So the three travel together, which is why this
 * is one object rather than three helpers.
 *
 * NOTHING FROM THE REQUEST REACHES SQL. A sort column cannot be a bound
 * parameter — it is an identifier — so the untrusted `sort` value is used only
 * as a KEY into {@see ListSpec::$sortable}, a map the handler wrote. An
 * unrecognised key is not an error: it falls back to the endpoint's default,
 * because a client that asks for a column it cannot see should get a list, not a
 * 400. `q` IS bound, like any other value.
 *
 * CASE-INSENSITIVE SEARCH IS ENGINE-AWARE. PostgreSQL has `ILIKE`; SQLite does
 * not, and its `LIKE` is already case-insensitive for ASCII. This repository runs
 * its suites against BOTH — {@see \Tests\Support\SchemaFromMigrations} uses real
 * PostgreSQL when `PHPUNIT_PG_DSN` is set and SQLite otherwise — so a predicate
 * written for one engine passes locally and fails the dialect shards, which is
 * the specific way this kind of change tends to break.
 *
 * USAGE
 *
 *     $q = ListQuery::fromPath($request->getPath(), new ListSpec(
 *         sortable:   ['email' => 'pe.email', 'created' => 'm.created_at'],
 *         tiebreaker: 'm.profile_id',
 *         searchable: ['pe.email', 'p.display_name'],
 *         defaultSort: 'created',
 *         defaultDirection: 'desc',
 *     ));
 *
 *     $where = 'm.tenant_id = :tenant_id' . $q->andSearch($db);
 *     $countStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM … WHERE {$where}");
 *     $q->bindSearch($countStmt);
 *     …
 *     $stmt = $db->prepare("SELECT … WHERE {$where} {$q->orderBy()} LIMIT :limit OFFSET :offset");
 *     $q->bindAll($stmt);
 *     return Response::json(['data' => $rows, 'pagination' => $q->meta($total)]);
 *
 * The COUNT must carry the same search predicate as the SELECT — otherwise the
 * total counts unfiltered rows and the client renders page controls for pages
 * that come back empty.
 */
final class ListQuery
{
    /** Bound parameter name for the search term. */
    private const SEARCH_PARAM = ':list_q';

    private function __construct(
        public readonly PaginationParams $page,
        private readonly ListSpec $spec,
        /** A validated key from the spec, or null for "tiebreaker only". */
        public readonly ?string $sort,
        /** `ASC` or `DESC`, already normalised. */
        public readonly string $direction,
        /** The caller's search term, or null when absent or unsupported. */
        public readonly ?string $search,
        /**
         * Whether this request should come back as ONE PAGE rather than the
         * whole list. See {@see fromPath()}'s `$alwaysPaginate` for why this is
         * a decision and not a constant.
         */
        public readonly bool $paginated,
    ) {
    }

    /**
     * Parse `page`, `per_page`, `sort`, `dir` and `q` out of a request path.
     *
     * Reads the same sources as {@see PaginationParams::fromPath()} so a handler
     * only has to learn one convention.
     *
     * ADDING PAGINATION TO AN ENDPOINT THAT HAD NONE CHANGES ITS ANSWER. An
     * endpoint that returns every row today, paginated unconditionally, starts
     * returning twenty-five — and the clients that fetch it to build a picker or
     * an id→label map (and that are not updated in this repository: desktop,
     * mobile) go on rendering, silently short. Nothing errors; the list is just
     * incomplete. So `$paginated` defaults to "only when the caller asked", and
     * an endpoint that ALREADY paged before adopting this contract opts back in
     * with `$alwaysPaginate: true` rather than quietly losing its own default.
     *
     * SORT AND SEARCH ARE NOT CONDITIONAL. Only the LIMIT/OFFSET window is: a
     * caller may sort or search the full list without asking for a page, which
     * is what a picker wants and what the admin screens' "fetch a big slice and
     * filter it in the browser" workaround was standing in for.
     *
     * @param bool $alwaysPaginate True for an endpoint whose CURRENT behaviour
     *        is already "one page by default", so adopting this contract does
     *        not silently un-paginate it.
     */
    public static function fromPath(string $rawPath, ListSpec $spec, bool $alwaysPaginate = false): self
    {
        $params = self::queryFrom($rawPath);

        $requested = isset($params['sort']) && is_string($params['sort']) ? $params['sort'] : null;
        // An unknown key is not an error — it is simply not a sort. See the
        // class docblock: refusing would make a client that asks for a column it
        // cannot see unable to read the list at all.
        $sort = $requested !== null && $spec->columnFor($requested) !== null
            ? $requested
            : $spec->defaultSort;

        $dir = isset($params['dir']) && is_string($params['dir'])
            ? strtolower($params['dir'])
            : strtolower($spec->defaultDirection);
        $direction = $dir === 'desc' ? 'DESC' : 'ASC';

        $term = isset($params['q']) && is_string($params['q']) ? trim($params['q']) : '';
        $search = $term !== '' && $spec->searchable !== [] ? $term : null;

        return new self(
            PaginationParams::fromPath($rawPath),
            $spec,
            $sort,
            $direction,
            $search,
            $alwaysPaginate || isset($params['page']) || isset($params['per_page'])
        );
    }

    /**
     * The full `ORDER BY`, tiebreaker included.
     *
     * Always returns a clause: even with no sort selected the tiebreaker orders
     * the rows, because an unordered `LIMIT/OFFSET` is not stable across pages.
     */
    public function orderBy(): string
    {
        $tiebreak = $this->spec->tiebreaker . ' ASC';

        if ($this->sort === null) {
            return 'ORDER BY ' . $tiebreak;
        }

        $column = (string) $this->spec->columnFor($this->sort);

        return sprintf('ORDER BY %s %s, %s', $column, $this->direction, $tiebreak);
    }

    /**
     * The search predicate, ready to append to an existing `WHERE`.
     *
     * Returns `''` when there is nothing to search for, so a caller can
     * concatenate it unconditionally.
     */
    public function andSearch(PDO $db): string
    {
        $predicate = $this->searchPredicate($db);

        return $predicate === '' ? '' : ' AND ' . $predicate;
    }

    /** The bare predicate, for a query with no other conditions. */
    public function searchPredicate(PDO $db): string
    {
        if ($this->search === null) {
            return '';
        }

        // PostgreSQL needs ILIKE for case-insensitivity; SQLite has no ILIKE and
        // its LIKE is already case-insensitive for ASCII. See the class docblock.
        $operator = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'ILIKE' : 'LIKE';

        $clauses = array_map(
            static fn (string $column): string => sprintf('%s %s %s', $column, $operator, self::SEARCH_PARAM),
            $this->spec->searchable
        );

        return '(' . implode(' OR ', $clauses) . ')';
    }

    /** Bind the search term. Safe to call when there is none. */
    public function bindSearch(PDOStatement $stmt): void
    {
        if ($this->search === null) {
            return;
        }

        // Wildcards around the term, escaped so a caller's own `%` or `_` is
        // matched literally rather than acting as a wildcard of their choosing.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search);
        $stmt->bindValue(self::SEARCH_PARAM, '%' . $escaped . '%', PDO::PARAM_STR);
    }

    /**
     * Bind the search term, the row limit and the offset.
     *
     * Only for a statement that actually carries `LIMIT :limit OFFSET :offset` —
     * binding a placeholder a query does not contain is an error on a real
     * prepare. When {@see $paginated} is false the statement has no window, so
     * bind with {@see bindSearch()} instead.
     */
    public function bindAll(PDOStatement $stmt): void
    {
        $this->bindSearch($stmt);
        $stmt->bindValue(':limit', $this->page->perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $this->page->offset, PDO::PARAM_INT);
    }

    /**
     * The response envelope's `pagination` object.
     *
     * Identical in shape to {@see PaginationParams::meta()}, so a client cannot
     * tell whether an endpoint moved from one to the other.
     *
     * @return array{page: int, perPage: int, total: int, totalPages: int}
     */
    public function meta(int $total): array
    {
        return $this->page->meta($total);
    }

    /**
     * @return array<string, mixed>
     */
    private static function queryFrom(string $rawPath): array
    {
        $params = $_GET;

        $queryString = parse_url($rawPath, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            $parsed = [];
            parse_str($queryString, $parsed);
            $params = array_merge($params, $parsed);
        }

        return $params;
    }
}
