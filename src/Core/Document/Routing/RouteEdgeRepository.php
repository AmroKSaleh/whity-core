<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * Data-access for `document_route_edges` (#1014) — WHERE A VERDICT SENDS A
 * DOCUMENT.
 *
 * This is the table migration 112 left a seam for and declined to design:
 * "an edges table (`from_step_id`, `to_step_id`, condition) and one rewritten
 * method, `RouteStepRepository::findNext()`… the condition vocabulary is
 * deliberately not guessed at now — it is the thing an editor constrains, and
 * inventing it before the editor exists is how it ends up with a verb the editor
 * cannot draw." The editor exists now (#1027), the verb it draws is the verdict,
 * and that is the whole vocabulary: `approved`, `rejected`.
 *
 * WHAT IS NOT HERE, AND WHY THAT MATTERS FOR EXISTING ROUTES
 * ----------------------------------------------------------
 * There is no unconditional edge. A plain `forwarded` still finds its successor
 * by ORDINAL through {@see RouteStepRepository::findNext()}, so every route
 * authored before migration 119 behaves after it exactly as it did before — the
 * new table is empty for all of them and every query here answers "no edge".
 * Branching is opt-in per step, per verdict, and nothing infers it.
 *
 * NO UPDATE, NO DELETE
 * --------------------
 * Edges are written once, with their route, inside {@see DocumentRouter::issue()}'s
 * transaction — the same construction {@see RouteStepRepository} has, and for
 * the reason {@see RouteRepository} records: a route is created COMPLETE, and
 * amending a circulation already under way is a new route on the same document.
 * An editable graph under a running route would let an author change where a
 * rejection goes AFTER somebody rejected, which the trail would then describe
 * incorrectly for ever.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file.
 */
final class RouteEdgeRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Draw one edge and return its id.
     *
     * `UNIQUE (from_step_id, verdict)` (migration 119) means a caller that
     * declared two destinations for one verdict gets an integrity error rather
     * than a route whose rejection path is decided by insertion order.
     */
    public function create(int $tenantId, int $routeId, int $fromStepId, int $toStepId, string $verdict): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_route_edges
                 (tenant_id, route_id, from_step_id, to_step_id, verdict, created_at)
             VALUES (:tenant_id, :route_id, :from_step_id, :to_step_id, :verdict, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':from_step_id' => $fromStepId,
            ':to_step_id' => $toStepId,
            ':verdict' => $verdict,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The step a verdict sends this step's document to, or null when the author
     * drew no such edge.
     *
     * THE ENGINE'S ONE LOOKUP. What the caller does with a null is where the two
     * verdicts stop being symmetric, and {@see DocumentRouter} is the only place
     * that decides it: an approval with no edge continues to the next ordinal,
     * and a rejection with no edge goes NOWHERE. A rejection must never inherit
     * the approval's destination — that is #1014's whole subject.
     *
     * @return array<string, mixed>|null
     */
    public function findTarget(int $tenantId, int $fromStepId, string $verdict): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, route_id, from_step_id, to_step_id, verdict, created_at
               FROM document_route_edges
              WHERE tenant_id = :tenant_id
                AND from_step_id = :from_step_id
                AND verdict = :verdict'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':from_step_id' => $fromStepId,
            ':verdict' => $verdict,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * A route's whole edge set — what an editor reads back to redraw the graph.
     *
     * Ordered by `(from_step_id, verdict)` so the same route always serialises
     * the same way: a client diffing two reads of an unchanged route must not
     * see a change because the planner picked a different order.
     *
     * @return list<array<string, mixed>>
     */
    public function listForRoute(int $routeId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, route_id, from_step_id, to_step_id, verdict, created_at
               FROM document_route_edges
              WHERE tenant_id = :tenant_id AND route_id = :route_id
              ORDER BY from_step_id ASC, verdict ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'route_id' => (int) $row['route_id'],
            'from_step_id' => (int) $row['from_step_id'],
            'to_step_id' => (int) $row['to_step_id'],
            'verdict' => (string) $row['verdict'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
