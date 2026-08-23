<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Document\Organizer\DocumentCriteria;
use Whity\Core\Document\Organizer\DocumentOrder;
use Whity\Core\Document\Organizer\DocumentSortField;

/**
 * Data-access for `documents` (#947 item 1) — the RECORD half of an issued
 * document: its identity, the template it came from, who raised it, from which
 * unit, and when. The bytes live one table over, in `document_artifacts`
 * ({@see DocumentArtifactRepository}), because a record survives a correction
 * and a set of bytes must not be rewritten by one — see migration 108.
 *
 * TENANT-OWNED. Every statement binds an explicit `tenant_id` predicate, spelled
 * out in literal SQL so scripts/ci-tenant-predicate-guard.php can verify it by
 * reading this file. A document issued under one tenant can never be read under
 * another, and the id in the path is never trusted on its own.
 *
 * VISIBILITY IS FILTERED IN SQL, NOT IN PHP
 * -----------------------------------------
 * {@see DocumentVisibilityPolicy} decides whether a caller sees only their own
 * documents or all of the tenant's, and the answer is pushed down into the
 * WHERE clause here (as {@see DocumentCriteria}'s `restrictToCreator`) rather
 * than applied to a fetched page. The template/block repositories filter in PHP
 * and can afford to — a tenant holds a few dozen templates. Documents
 * accumulate without bound, so filtering a page after LIMIT returns short pages
 * (25 rows fetched, 3 visible, "page 2" of a total the caller cannot see) and a
 * total that does not match what is listed. The count applies the same
 * predicate for the same reason.
 *
 * THE ORGANIZER'S FOLDERS LAND HERE AS CRITERIA (#978)
 * ----------------------------------------------------
 * #947 item 5's browser stores no folder tree; every folder is a query. Those
 * queries arrive as a {@see DocumentCriteria} — a closed vocabulary this class
 * translates into literal SQL fragments — rather than as SQL a view supplied.
 * {@see criteriaSql()} says why that boundary is where it is, and
 * {@see \Whity\Core\Document\Organizer\DocumentViewRegistry} says what it costs
 * to extend.
 */
final class DocumentRepository
{
    /**
     * The row-visibility predicate for a caller WITHOUT the tenant-wide grant
     * (#947 item 3), as literal SQL.
     *
     *   I raised it, OR a route reached me, OR a role was granted to me on it.
     *
     * The three disjuncts of {@see DocumentVisibilityPolicy::canView()}, pushed
     * into the WHERE clause. The policy stays the single statement of the RULE;
     * this is the same rule expressed for a question whose shape a return value
     * cannot carry — these two are joins, not values.
     *
     * WHY A CONSTANT AND NOT TWO SPELLINGS. The list and the count MUST apply an
     * identical predicate or the pagination total is a number the caller cannot
     * reach — the exact defect this class's docblock already records having paid
     * for. Two hand-written copies is how they drift, so there is one, and both
     * statements interpolate it.
     *
     * That interpolation is the one concession to
     * scripts/ci-tenant-predicate-guard.php, which reads this source: the
     * `tenant_id = :tenant_id` predicate every statement needs is still written
     * LITERALLY at each call site, and only this caller-scoped clause is shared.
     * Every subquery re-binds `:tenant_id` itself, so a document, a recipient row
     * and a grant row all have to belong to the same tenant for the row to be
     * visible — a cross-tenant recipient row cannot make another tenant's
     * document appear.
     *
     * EVERYONE-GRANTS ARE EXCLUDED from the third clause (`profile_id = :created_by`
     * only, never `IS NULL`) for the reason
     * {@see \Whity\Core\RBAC\ResourceRoleAssignmentRepository::hasProfileGrantAt()}
     * records: migration 088 defines that row as modifying what already-reachable
     * people may do, not as granting reach. Reading it as access would make one
     * such row publish a document to the whole tenant.
     *
     * `EXISTS` rather than `IN (subquery)` or a `LEFT JOIN … DISTINCT`: both
     * alternatives can multiply the row before collapsing it again, and a
     * `COUNT(*)` over a multiplied row is wrong rather than slow.
     *
     * `resource_type = 'document'` is spelled as a LITERAL rather than
     * interpolating {@see \Whity\Core\RBAC\ResourceTypeRegistry::TYPE_DOCUMENT},
     * because a nowdoc cannot interpolate a class constant and concatenating
     * around it would break exactly the literal-SQL readability the predicate
     * guard depends on. The duplication is pinned instead: DocumentRepository's
     * real-engine test asserts this clause and the registry constant agree, so a
     * rename fails a build rather than silently emptying every restricted list.
     */
    private const VISIBLE_TO_CALLER = <<<'SQL'
        created_by = :created_by
        OR EXISTS (
            SELECT 1 FROM document_route_recipients r
             WHERE r.tenant_id = :tenant_id
               AND r.document_id = documents.id
               AND r.profile_id = :created_by
        )
        OR EXISTS (
            SELECT 1 FROM resource_role_assignments rra
             WHERE rra.tenant_id = :tenant_id
               AND rra.resource_type = 'document'
               AND rra.resource_id = documents.id
               AND rra.profile_id = :created_by
        )
        SQL;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a document record and return its id.
     *
     * `template_name` is a SNAPSHOT taken at issue time, not a join: the
     * template may be renamed or deleted (the foreign key is ON DELETE SET
     * NULL, deliberately — see migration 108), and a browser listing a document
     * whose origin was retired should still be able to say what it was.
     *
     * @param array{document_template_id?: ?int, template_name: string, title: string,
     *              origin_ou_id?: ?int, created_by?: ?int} $rec
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO documents
                 (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (:tenant_id, :document_template_id, :template_name, :title, :origin_ou_id, :created_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id'            => $tenantId,
            ':document_template_id' => $rec['document_template_id'] ?? null,
            ':template_name'        => $rec['template_name'],
            ':title'                => $rec['title'],
            ':origin_ou_id'         => $rec['origin_ou_id'] ?? null,
            ':created_by'           => $rec['created_by'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * One document, tenant-scoped. Null when it does not exist OR belongs to
     * another tenant — the caller cannot tell the two apart, which is the point.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at
             FROM documents WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * A page of the tenant's documents matching a view's criteria.
     *
     * Newest first unless an `$order` is named — see {@see orderSql()} for why
     * the default is not expressible as one.
     *
     * @return list<array<string, mixed>>
     */
    public function listForCriteria(
        int $tenantId,
        DocumentCriteria $criteria,
        int $limit,
        int $offset,
        ?DocumentOrder $order = null
    ): array {
        if ($criteria->matchesNothing) {
            return [];
        }

        // The table is NOT aliased, and that is a constraint rather than a
        // style: self::VISIBLE_TO_CALLER correlates its subqueries on
        // `documents.id`, and PostgreSQL makes the original table name
        // unusable once an alias is introduced. An alias here would work on
        // SQLite and fail on production.
        $sql = 'SELECT id, tenant_id, document_template_id, template_name, title,
                       origin_ou_id, created_by, created_at
                  FROM documents
                 WHERE tenant_id = :tenant_id';
        $bindings = [];
        $sql .= $this->criteriaSql($criteria, $bindings);
        $sql .= $this->orderSql($order) . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        foreach ($bindings as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        // LIMIT/OFFSET are bound as INT explicitly. PDO's default is PARAM_STR,
        // which emulated prepares quote — `LIMIT '25'` is a syntax error on
        // PostgreSQL and silently accepted on SQLite, so the SQLite unit run
        // would pass and the real engine would not.
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * How many documents the same criteria as {@see listForCriteria()} match, so
     * the pagination envelope reports a total the caller can actually reach.
     */
    public function countForCriteria(int $tenantId, DocumentCriteria $criteria): int
    {
        if ($criteria->matchesNothing) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) FROM documents WHERE tenant_id = :tenant_id';
        $bindings = [];
        $sql .= $this->criteriaSql($criteria, $bindings);

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        foreach ($bindings as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Translate a view's criteria into `AND …` clauses appended to a statement
     * that ALREADY binds `tenant_id`.
     *
     * WHY THIS IS ASSEMBLED AND THE OLD PAIR OF STATEMENTS WAS NOT
     * ------------------------------------------------------------
     * This method used to be two literal statements — "all of the tenant's" and
     * "only mine" — precisely so scripts/ci-tenant-predicate-guard.php could
     * read them. #978 adds a view registry, and the product of {creator} ×
     * {unit set} × {collection} × {search} is sixteen statements written out,
     * which is not a defensible way to keep a linter happy.
     *
     * The invariant survives intact because of WHERE the tenant predicate sits:
     * `WHERE tenant_id = :tenant_id` is in the LITERAL base of both callers,
     * and the scanner stitches a statement's literal fragments together within
     * the enclosing function, so what it evaluates already carries the
     * predicate. Every fragment below is likewise a literal in this file — none
     * is supplied by a view, a plugin or a request, which is the property
     * {@see DocumentCriteria} exists to guarantee and the reason its vocabulary
     * is closed.
     *
     * The collection filter is a correlated EXISTS rather than a JOIN: a join
     * against a membership table returns the document once per matching row and
     * has to be re-collapsed, and it silently multiplies the COUNT — the exact
     * bug that makes a pagination total disagree with its own page.
     *
     * @param array<string, int|string> $bindings Filled in with the values to bind.
     */
    private function criteriaSql(DocumentCriteria $criteria, array &$bindings): string
    {
        $sql = '';

        // The VISIBILITY restriction and the VIEW's own creator filter are
        // separate clauses that are both ANDed — see DocumentCriteria for why
        // collapsing them into one would let a view widen what a caller may see.
        //
        // Visibility is self::VISIBLE_TO_CALLER, the three-disjunct predicate
        // #947 item 3 widened this from "created_by = me": you raised it, you
        // are a routing recipient of it, or you hold a role granted on it. The
        // constant is interpolated rather than re-spelled so the list and the
        // count cannot drift — the defect that makes a pagination total a page
        // the caller can never reach. Its own `:created_by` binding is the
        // CALLER, which is why the view's creator filter below binds a
        // differently-named placeholder.
        if ($criteria->restrictToCreator !== null) {
            $sql .= ' AND (' . self::VISIBLE_TO_CALLER . ')';
            $bindings[':created_by'] = $criteria->restrictToCreator;
        }

        if ($criteria->createdBy !== null) {
            $sql .= ' AND created_by = :view_created_by';
            $bindings[':view_created_by'] = $criteria->createdBy;
        }

        if ($criteria->originOuIds !== null) {
            // An empty anchor set cannot be written as `IN ()` — that is a
            // syntax error on PostgreSQL — and must not silently become "no
            // filter", which would turn "my unit's documents" into the whole
            // tenant's. `matchesNothing` is the intended way to say it, so this
            // is the belt to that braces.
            if ($criteria->originOuIds === []) {
                return $sql . ' AND 1 = 0';
            }
            $placeholders = [];
            foreach (array_values($criteria->originOuIds) as $i => $ouId) {
                $name = ':origin_ou_' . $i;
                $placeholders[] = $name;
                $bindings[$name] = $ouId;
            }
            $sql .= ' AND origin_ou_id IN (' . implode(', ', $placeholders) . ')';
        }

        if ($criteria->inCollectionId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM document_collection_items i
                                   WHERE i.tenant_id = :tenant_id
                                     AND i.collection_id = :collection_id
                                     AND i.document_id = documents.id)';
            $bindings[':collection_id'] = $criteria->inCollectionId;
        }

        if ($criteria->search !== null && $criteria->search !== '') {
            // LOWER(…) LIKE rather than ILIKE: ILIKE is PostgreSQL-only, and a
            // predicate that works on production but not on the engine the unit
            // suite builds its schema on is a predicate nothing tests. The
            // wildcards are added here so a term containing `%` or `_` is
            // matched literally rather than as a pattern the caller did not write.
            // `ESCAPE` is spelled out because SQLite has NO default escape
            // character for LIKE while PostgreSQL's is a backslash — omitting it
            // would make the escaping above work on production and be visible as
            // literal backslashes in the test engine.
            $sql .= " AND LOWER(title) LIKE :search ESCAPE '\\'";
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($criteria->search));
            $bindings[':search'] = '%' . $escaped . '%';
        }

        return $sql;
    }

    /**
     * The `ORDER BY` clause, as a LITERAL fragment chosen by a `match` over a
     * closed enum.
     *
     * WHY THE REQUEST'S STRING NEVER REACHES THIS SQL
     * -----------------------------------------------
     * An identifier cannot be bound as a parameter, so `ORDER BY` is the one
     * clause where request text is habitually interpolated — and where doing so
     * is an injection. The request names a {@see DocumentSortField} case or is
     * rejected at the edge ({@see \Whity\Api\DocumentsApiHandler::order()}), and
     * what lands here is a `match` over that enum whose arms are string
     * literals. The direction is derived from a BOOLEAN for the same reason: not
     * from `'asc'`/`'desc'` text that happened to validate.
     *
     * WHY EVERY ARM CARRIES `, id DESC`
     * ---------------------------------
     * A non-unique sort key makes pagination lose and repeat rows. Two documents
     * issued in the same second, or from the same template, have no defined
     * relative position, so the engine is free to order them differently between
     * the query for page 1 and the query for page 2 — and a row that moves
     * across the boundary is either shown twice or never shown at all. There is
     * no error, no gap in the numbering, and the total still adds up: the
     * failure is a document the reader concludes was never issued. `id` is
     * unique per tenant and never reused, so appending it makes every order
     * total.
     *
     * WHY THE DEFAULT IS `id DESC` RATHER THAN `created_at DESC`
     * ---------------------------------------------------------
     * They are not the same order, and the difference is the reason this is not
     * expressible as a {@see DocumentOrder}. `created_at` is set by `NOW()`,
     * which in PostgreSQL is the TRANSACTION's start time — several documents
     * issued inside one transaction share it exactly, and their order among
     * themselves is then arbitrary. `id DESC` is the order they were actually
     * recorded in. Keeping it as the unnamed default also means a caller who
     * names no sort gets the list #947 item 1 shipped, byte for byte.
     *
     * WHY `LOWER()` ON THE TEXT COLUMNS, AND WHAT IT DOES NOT DO
     * ---------------------------------------------------------
     * Without it, PostgreSQL under the C locale orders every capital ahead of
     * every lowercase letter, so `Zebra` precedes `apple` and an alphabetical
     * list is not one. `LOWER()` is applied here rather than relying on a
     * case-insensitive collation because the unit suite builds its schema on
     * SQLite, whose default is already case-sensitive-but-different, and a
     * predicate that orders one way in CI and another in production is one
     * nothing tests.
     *
     * It is NOT linguistic collation. For Arabic — a standing requirement here —
     * `LOWER()` is a no-op and the result is codepoint order, which groups the
     * script correctly but does not implement Arabic alphabetisation (or
     * Latin-with-diacritics, for that matter). Doing that properly means an ICU
     * collation chosen per tenant, which is a decision about the tenant's
     * language rather than about this clause, and is deliberately not made here.
     */
    private function orderSql(?DocumentOrder $order): string
    {
        if ($order === null) {
            return ' ORDER BY id DESC';
        }

        $direction = $order->descending ? ' DESC' : ' ASC';

        return match ($order->field) {
            DocumentSortField::Title        => ' ORDER BY LOWER(title)' . $direction . ', id DESC',
            DocumentSortField::CreatedAt    => ' ORDER BY created_at' . $direction . ', id DESC',
            DocumentSortField::TemplateName => ' ORDER BY LOWER(template_name)' . $direction . ', id DESC',
        };
    }

    /**
     * Map a raw row to the typed shape the handler serialises.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'tenant_id'            => (int) $row['tenant_id'],
            'document_template_id' => $row['document_template_id'] !== null ? (int) $row['document_template_id'] : null,
            'template_name'        => (string) $row['template_name'],
            'title'                => (string) $row['title'],
            'origin_ou_id'         => $row['origin_ou_id'] !== null ? (int) $row['origin_ou_id'] : null,
            'created_by'           => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at'           => (string) $row['created_at'],
        ];
    }
}
