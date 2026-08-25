<?php

declare(strict_types=1);

namespace Whity\Core\Document\RouteTemplate;

use PDO;
use Whity\Core\Document\Routing\RouteSatisfaction;

/**
 * Data-access for the three route-TEMPLATE tables (#1027, migration 120).
 *
 * A template is a DESIGN — the record the node-based editor edits — and this
 * class reads and writes designs and nothing else. It resolves no rules, counts
 * no people and knows nothing about any document. Those are the engine's
 * questions and they are answered elsewhere, against the organisation as it
 * stands at the instant of asking.
 *
 * THERE IS NO PERSON COLUMN IN ANY OF THESE TABLES
 * ------------------------------------------------
 * A step carries `rule_kind` + `rule_config`, exactly as `document_route_steps`
 * does, so "all 1,000 instructors" is ONE row and stays one row. That is not a
 * convention this class is trusted to keep — there is nowhere in the schema to
 * put a thousand of anything, which is what makes the guarantee real rather than
 * aspirational. The editor above it therefore CANNOT materialise a group into a
 * thousand nodes even if a future author tried: the save would have nothing to
 * write them to.
 *
 * WHY SAVING A GRAPH IS A WHOLESALE REPLACE, NOT A DIFF
 * -----------------------------------------------------
 * {@see replaceGraph()} deletes every step of the template and re-inserts the
 * ones it was given, inside one transaction. That is deliberate, and cheaper to
 * reason about than the alternative in three ways:
 *
 *  1. THE EDITOR'S UNIT OF WORK IS THE WHOLE CANVAS. An author drags four nodes,
 *     deletes one, adds an edge and presses save. Expressing that as a diff means
 *     the client computing which of those was an update and which a create — a
 *     computation whose only consumer is the server, done on the side of the wire
 *     that cannot verify it.
 *
 *  2. EDGES ARE ADDRESSED BY `position`, NOT BY ID. A newly drawn node has no id
 *     yet, so an edge to it cannot name one. Positions are the author's own
 *     stable handles and are what the wire format uses, which means a replace
 *     needs no id round-trip and no client-side id allocation.
 *
 *  3. NOTHING OUTSIDE THE TEMPLATE POINTS AT A TEMPLATE STEP. Only template
 *     edges do, and they are re-created in the same transaction. An INSTANCE step
 *     is a different row in a different table, written when a document is issued;
 *     the append-only trail points at THAT, and this class cannot reach it. So
 *     the id churn a replace causes is invisible — which is exactly why the same
 *     approach would be indefensible on `document_route_steps`.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file — including the statements that could have leaned on the template
 * join instead. A template id from another tenant must not resolve, and is
 * reported as ABSENT rather than forbidden, the posture
 * {@see \Whity\Core\Document\DocumentVisibilityPolicy} and
 * {@see \Whity\Core\Group\UserGroupRepository} already take, because template ids
 * are enumerable integers and a 403 would confirm which of them exist.
 */
final class RouteTemplateRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A page of this tenant's templates, by name.
     *
     * Ordered by name because that is how a picker reads, and because it is the
     * order the unique constraint's index already provides — no sort, no second
     * index.
     *
     * Carries a STEP COUNT but no resolved-people count. The first is a covered
     * aggregate over an index; the second would be a full rule resolution per
     * step per row, so a page of forty templates would commission hundreds of
     * fan-out queries to decorate a screen nobody asked a membership question on.
     * The people-count is available one step at a time, from the preview, where
     * somebody has asked for it — the same line {@see \Whity\Core\Group\UserGroupRepository::listForTenant()}
     * draws for the same reason.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.tenant_id, t.name, t.description, t.created_by, t.created_at, t.updated_at,
                    (SELECT COUNT(*)
                       FROM document_route_template_steps s
                      WHERE s.template_id = t.id AND s.tenant_id = t.tenant_id) AS step_count
               FROM document_route_templates t
              WHERE t.tenant_id = :tenant_id
              ORDER BY t.name ASC, t.id ASC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalizeTemplate(...), $rows);
    }

    /**
     * How many templates this tenant has, for the pagination envelope.
     *
     * A separate count rather than a window function: the two engines disagree
     * about `COUNT(*) OVER ()` on an empty result, and a total that is absent
     * when a page is empty is a total a client cannot render.
     */
    public function countForTenant(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS c FROM document_route_templates WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['c'];
    }

    /**
     * One template, tenant-scoped. Null when it does not exist or belongs
     * elsewhere — every caller turns null into a 404.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.tenant_id, t.name, t.description, t.created_by, t.created_at, t.updated_at,
                    (SELECT COUNT(*)
                       FROM document_route_template_steps s
                      WHERE s.template_id = t.id AND s.tenant_id = t.tenant_id) AS step_count
               FROM document_route_templates t
              WHERE t.id = :id AND t.tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalizeTemplate($row) : null;
    }

    /**
     * A template by name within a tenant, for the pre-flight duplicate check.
     *
     * The UNIQUE constraint is the authority — this exists only so a duplicate is
     * a 409 naming the template somebody already made, rather than a driver
     * integrity error the caller cannot read. A concurrent create still lands on
     * the constraint, which is correct: the check narrows the common case and the
     * constraint closes the race.
     *
     * @return array<string, mixed>|null
     */
    public function findByName(string $name, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, description, created_by, created_at, updated_at
               FROM document_route_templates
              WHERE tenant_id = :tenant_id AND name = :name'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':name' => $name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalizeTemplate($row) : null;
    }

    /**
     * The steps of one template, in authoring order.
     *
     * @return list<array<string, mixed>>
     */
    public function stepsFor(int $templateId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, template_id, position, rule_kind, rule_config, label,
                    decision, decision_quorum, satisfied_by, canvas_x, canvas_y
               FROM document_route_template_steps
              WHERE tenant_id = :tenant_id AND template_id = :template_id
              ORDER BY position ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':template_id' => $templateId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalizeStep(...), $rows);
    }

    /**
     * The edges of one template, addressed by the POSITIONS they connect.
     *
     * The join is what turns ids back into positions. It costs one query and
     * saves every caller from holding an id map: the wire format speaks
     * positions, the editor speaks positions, and the ids exist only so the
     * foreign keys can cascade.
     *
     * @return list<array{from: int, to: int, verdict: string}>
     */
    public function edgesFor(int $templateId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.position AS from_position, t.position AS to_position, e.verdict
               FROM document_route_template_edges e
               JOIN document_route_template_steps f ON f.id = e.from_step_id
               JOIN document_route_template_steps t ON t.id = e.to_step_id
              WHERE e.tenant_id = :tenant_id AND e.template_id = :template_id
              ORDER BY f.position ASC, e.verdict ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':template_id' => $templateId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'from' => (int) $row['from_position'],
                'to' => (int) $row['to_position'],
                'verdict' => (string) $row['verdict'],
            ],
            $rows
        );
    }

    /**
     * Create an EMPTY template — a name and nothing drawn yet.
     *
     * Deliberately separate from {@see replaceGraph()}. A template with no steps
     * is a legitimate state (it is what the editor opens onto), and folding the
     * two would make "create" a call that must always carry a graph, which is not
     * how anybody starts one.
     */
    public function create(int $tenantId, string $name, ?string $description, ?int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_route_templates
                 (tenant_id, name, description, created_by, created_at, updated_at)
             VALUES (:tenant_id, :name, :description, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':description' => $description,
            ':created_by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Rename or re-describe a template, and return whether a row changed.
     */
    public function update(int $id, int $tenantId, string $name, ?string $description): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE document_route_templates
                SET name = :name, description = :description, updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':name' => $name,
            ':description' => $description,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a template, and return whether a row went.
     *
     * Steps and edges CASCADE from the schema. Routes already issued from this
     * design are untouched and unaffected: they carry their own `document_route_steps`
     * rows, written when the document was issued, and nothing in a running
     * circulation reads back through a template. Deleting a design cannot change
     * the history of anything that followed it.
     */
    public function delete(int $id, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM document_route_templates WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Replace a template's whole graph — every step and every edge — atomically.
     *
     * See the class docblock for why this is a replace rather than a diff.
     *
     * Edges name POSITIONS and are resolved to ids here, after the steps are
     * inserted, using the map this method built. It is not re-read from the
     * database: a `SELECT ... WHERE position IN (...)` would be a second source
     * of truth for a fact the insert loop already knows, and it would be one
     * round trip per save away from being wrong under a concurrent write.
     *
     * An edge naming a position with no step is the caller's error and must be
     * refused BEFORE this is reached — {@see RouteTemplateGraph::validate()} is
     * where that happens, so the transaction here cannot half-apply a graph.
     *
     * @param list<array{position: int, rule_kind: string, rule_config: array<string, mixed>, label: ?string, decision: bool, decision_quorum: ?string, satisfied_by: string, canvas_x: int, canvas_y: int}> $steps
     * @param list<array{from: int, to: int, verdict: string}> $edges
     */
    public function replaceGraph(int $templateId, int $tenantId, array $steps, array $edges): void
    {
        $this->db->beginTransaction();

        try {
            // Edges cascade from steps, so deleting the steps clears them too.
            // The delete is spelled with both predicates rather than relying on
            // the template id alone: the guard reads this file, and a tenant
            // predicate that is only implied by a foreign key is one the guard
            // cannot see and a future editor could drop.
            $delete = $this->db->prepare(
                'DELETE FROM document_route_template_steps
                  WHERE tenant_id = :tenant_id AND template_id = :template_id'
            );
            $delete->execute([':tenant_id' => $tenantId, ':template_id' => $templateId]);

            $insertStep = $this->db->prepare(
                'INSERT INTO document_route_template_steps
                     (tenant_id, template_id, position, rule_kind, rule_config, label,
                      decision, decision_quorum, satisfied_by, canvas_x, canvas_y, created_at)
                 VALUES (:tenant_id, :template_id, :position, :rule_kind, :rule_config, :label,
                         :decision, :decision_quorum, :satisfied_by, :canvas_x, :canvas_y, NOW())'
            );

            /** @var array<int, int> $idByPosition */
            $idByPosition = [];
            foreach ($steps as $step) {
                $insertStep->execute([
                    ':tenant_id' => $tenantId,
                    ':template_id' => $templateId,
                    ':position' => $step['position'],
                    ':rule_kind' => $step['rule_kind'],
                    // `{}` rather than `[]` for an empty config: PHP cannot tell
                    // an empty map from an empty list, `[]` is not a valid jsonb
                    // OBJECT, and every read would then decode a list where the
                    // resolver expects a map. The same choice, for the same
                    // reason, as `RouteStepRepository::create()`.
                    ':rule_config' => $step['rule_config'] === []
                        ? '{}'
                        : (string) json_encode($step['rule_config']),
                    ':label' => $step['label'],
                    // Bound as an int, not a bool. The two engines disagree about
                    // how a PHP bool binds to a BOOLEAN column — SQLite stores it
                    // as '' for false, which reads back as neither 0 nor false —
                    // and #1014 notes the same hazard on the read side.
                    ':decision' => $step['decision'] ? 1 : 0,
                    ':decision_quorum' => $step['decision_quorum'],
                    // #1054. A plain string on both engines, so no int-binding
                    // dance like `decision` above needs.
                    ':satisfied_by' => $step['satisfied_by'],
                    ':canvas_x' => $step['canvas_x'],
                    ':canvas_y' => $step['canvas_y'],
                ]);
                $idByPosition[$step['position']] = (int) $this->db->lastInsertId();
            }

            $insertEdge = $this->db->prepare(
                'INSERT INTO document_route_template_edges
                     (tenant_id, template_id, from_step_id, to_step_id, verdict, created_at)
                 VALUES (:tenant_id, :template_id, :from_step_id, :to_step_id, :verdict, NOW())'
            );

            foreach ($edges as $edge) {
                $insertEdge->execute([
                    ':tenant_id' => $tenantId,
                    ':template_id' => $templateId,
                    ':from_step_id' => $idByPosition[$edge['from']],
                    ':to_step_id' => $idByPosition[$edge['to']],
                    ':verdict' => $edge['verdict'],
                ]);
            }

            $touch = $this->db->prepare(
                'UPDATE document_route_templates
                    SET updated_at = NOW()
                  WHERE id = :id AND tenant_id = :tenant_id'
            );
            $touch->execute([':id' => $templateId, ':tenant_id' => $tenantId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Map a raw template row to the typed shape.
     *
     * `step_count` is absent on the rows {@see findByName()} reads, because the
     * duplicate check has no use for it; it is reported as 0 there rather than
     * omitted, so every caller reads one shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeTemplate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'name' => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'step_count' => isset($row['step_count']) ? (int) $row['step_count'] : 0,
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Map a raw step row to the typed shape, decoding the config exactly once.
     *
     * A config that will not decode comes back as an empty map rather than as a
     * fatal: the row exists, the step is real, and the resolver's own validation
     * is what turns "this rule cannot work with this config" into a message.
     * Failing here would make one corrupt row un-listable, taking the whole
     * template with it.
     *
     * `decision` is read through a tolerant test rather than a bare `(bool)`
     * cast. The same BOOLEAN comes back as `false`, as `'0'` or as `''`
     * depending on the engine and on ATTR_STRINGIFY_FETCHES, and `(bool) '0'` is
     * false while `(bool) '00'` is true — a cast that is right by accident on one
     * driver and wrong on another.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeStep(array $row): array
    {
        $decoded = json_decode((string) ($row['rule_config'] ?? '{}'), true);
        $decision = $row['decision'];

        return [
            'id' => (int) $row['id'],
            'template_id' => (int) $row['template_id'],
            'position' => (int) $row['position'],
            'rule_kind' => (string) $row['rule_kind'],
            'rule_config' => is_array($decoded) ? $decoded : [],
            'label' => $row['label'] !== null ? (string) $row['label'] : null,
            'decision' => $decision === true || $decision === 1 || $decision === '1' || $decision === 't',
            'decision_quorum' => $row['decision_quorum'] !== null ? (string) $row['decision_quorum'] : null,
            // #1054. Normalised through the vocabulary, and a value outside it
            // falls back to `act`: a design whose stored value cannot be read
            // must convert into a stage that visibly waits for somebody, never
            // into one that tells everybody and moves on.
            'satisfied_by' => isset($row['satisfied_by'])
                && RouteSatisfaction::isValid((string) $row['satisfied_by'])
                    ? (string) $row['satisfied_by']
                    : RouteSatisfaction::fallback(),
            'canvas_x' => (int) $row['canvas_x'],
            'canvas_y' => (int) $row['canvas_y'],
        ];
    }
}
