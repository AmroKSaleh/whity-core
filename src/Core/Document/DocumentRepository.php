<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Document\Organizer\DocumentCriteria;

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
     * A page of the tenant's documents matching a view's criteria, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForCriteria(int $tenantId, DocumentCriteria $criteria, int $limit, int $offset): array
    {
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
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

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
     * THE THREE ROUTING FRAGMENTS ARE EXISTS FOR THE SAME REASON, DOUBLED
     * -------------------------------------------------------------------
     * A document reaches many recipients and accumulates many trail rows, so a
     * join to either table multiplies the document by its own routing volume —
     * the busiest documents wrongest. `EXISTS` asks the only question these
     * folders pose ("is there such a row?"), stops at the first hit, and leaves
     * the COUNT describing documents rather than events.
     *
     * Each of them re-binds `:tenant_id` inside the subquery, exactly as
     * {@see VISIBLE_TO_CALLER} does. The document, the recipient row and the
     * trail row all have to belong to the same tenant for the document to match,
     * so a mis-tenanted row cannot bridge the two — and the predicate is written
     * out rather than inferred from the join, because a subquery whose only
     * scoping is `document_id` is one the guard cannot verify and one that stops
     * being scoped the moment somebody widens the join.
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

        // "Awaiting me" — #947 item 5's inbox folder.
        //
        // `closed_by_event_id IS NULL` is the predicate, not an optimisation.
        // Migration 112 deliberately gives the recipient row no status column;
        // open-ness IS the absence of a closing trail pointer, and this is the
        // same clause RouteRecipientRepository::listForProfile() applies, spelled
        // out here rather than delegated so both are literals the guard reads.
        //
        // Drop it and the folder lists everything that ever reached you: an
        // inbox that never empties, whose count never falls, and which is
        // therefore ignored within a week — the failure that costs the most
        // precisely because the screen still looks like it is working. It also
        // matches the partial unique index migration 112 declares, so the open
        // set is what the schema itself is organised around.
        if ($criteria->awaitingProfileId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM document_route_recipients ar
                                   WHERE ar.tenant_id = :tenant_id
                                     AND ar.document_id = documents.id
                                     AND ar.profile_id = :awaiting_profile_id
                                     AND ar.closed_by_event_id IS NULL)';
            $bindings[':awaiting_profile_id'] = $criteria->awaitingProfileId;
        }

        // "Acted on by me" — the trail, keyed on who did it.
        //
        // No action filter: every verb in migration 112's CHECK vocabulary is
        // something the actor did, `noted` included, and a folder that silently
        // omitted one would be a list of "things you did, except the kind we
        // decided did not count".
        if ($criteria->actedOnByProfileId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM document_route_events ae
                                   WHERE ae.tenant_id = :tenant_id
                                     AND ae.document_id = documents.id
                                     AND ae.actor_profile_id = :acted_on_by)';
            $bindings[':acted_on_by'] = $criteria->actedOnByProfileId;
        }

        // "Passed through my unit" — the trail, keyed on either end of a
        // transition. The unit set is the anchor's SUBTREE, resolved by the view
        // before it reaches here; this fragment only knows it was given units.
        if ($criteria->routedThroughOuIds !== null) {
            // Same reading as `originOuIds` above, and the same reason for
            // spelling it out: an empty set is "nothing matches", never "no
            // filter", and `IN ()` is a syntax error on PostgreSQL rather than a
            // helpful nothing.
            if ($criteria->routedThroughOuIds === []) {
                return $sql . ' AND 1 = 0';
            }
            $placeholders = [];
            foreach (array_values($criteria->routedThroughOuIds) as $i => $ouId) {
                $name = ':through_ou_' . $i;
                $placeholders[] = $name;
                $bindings[$name] = $ouId;
            }
            // The same placeholder list appears on both sides of the OR. PDO
            // binds a repeated named parameter once for every occurrence — which
            // self::VISIBLE_TO_CALLER already relies on for `:tenant_id`, on both
            // engines — so this is one binding per unit rather than two.
            $in = implode(', ', $placeholders);
            $sql .= ' AND EXISTS (SELECT 1 FROM document_route_events pe
                                   WHERE pe.tenant_id = :tenant_id
                                     AND pe.document_id = documents.id
                                     AND (pe.from_ou_id IN (' . $in . ')
                                          OR pe.to_ou_id IN (' . $in . ')))';
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
