<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * Data-access for `document_route_events` (#947 item 3) — THE TRAIL. The
 * system of record for what happened to a routed document.
 *
 * APPEND-ONLY, BY OMISSION
 * ------------------------
 * There is `append()` and there are reads. There is no `update()`, no
 * `delete()`, no `markCorrected()`, no soft-delete flag — and that absence is
 * the enforcement, not a note about intent. #947 puts it plainly: "the moment
 * somebody most wants to tidy history is exactly when it must be immutable."
 * A store that offers an UPDATE is a store where somebody eventually calls it,
 * at which point the guarantee is a comment in a docblock.
 *
 * The same construction {@see \Whity\Core\Document\DocumentArtifactRepository}
 * uses one table over, deliberately: the two halves of a document's history —
 * what was issued, and what happened to it — should not be immutable to
 * different degrees, because a reader reconciling them cannot tell which one to
 * believe.
 *
 * A CORRECTION IS A NEW EVENT
 * ---------------------------
 * {@see RouteAction::NOTED} is how the record is put right: the mistaken note,
 * the wrong unit, the misspelled name stay where they are and the correction is
 * appended beside them. That is more useful than an edit as well as safer —
 * "this was corrected on the 14th" is a fact somebody may need, and an edit
 * destroys it.
 *
 * Rows are removed only by cascade from `documents` and `tenants` (migration
 * 112). There is no path from this class.
 *
 * WHY ITS OWN TABLE AND NOT `domain_events`
 * -----------------------------------------
 * Migration 112's docblock carries the full argument. In short: a routing event
 * has a FIXED shape core knows (actor, action, from unit, to unit, note), three
 * of whose facts name other tables, and a generic JSONB payload can constrain
 * none of it — while `aggregate_id VARCHAR(191)` can carry no foreign key at
 * all. The spine still sees every event: {@see DocumentRouter} dispatches each
 * appended row through `HookManager::dispatchAsync()`, so the outbox relay
 * carries routing exactly as it carries everything else. One trail, one
 * broadcast, derived from it in one direction.
 *
 * ORDERED BY `id`, NOT BY `occurred_at`
 * -------------------------------------
 * Several events are appended inside one transaction — a forward closes one row
 * and opens several — and they share `occurred_at` to whatever resolution the
 * engine's clock has. `id` is monotonic, so it is the only ordering that is
 * stable across engines and across a restore.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file.
 */
final class RouteEventRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Append one event and return its id. The only write this class has.
     *
     * The action is CHECK-constrained by the schema (migration 112), so a verb
     * outside {@see RouteAction::all()} is refused by the database rather than
     * stored as a row nothing renders.
     *
     * `verdict` (#1014, migration 119) is NULL for every act that said nothing
     * about approval — which is every act on a circulation step, every `noted`,
     * and every row written before that migration. It is never a way of saying
     * "not approved": absence and refusal are different facts, and a reader that
     * conflated them would invent a rejection for every document ever
     * circulated. {@see RouteVerdict}.
     *
     * @param array{route_id: int, step_id?: ?int, actor_profile_id?: ?int, action: string,
     *              from_ou_id?: ?int, to_ou_id?: ?int, note?: ?string, verdict?: ?string} $event
     */
    public function append(int $tenantId, int $documentId, array $event): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_route_events
                 (tenant_id, document_id, route_id, step_id, actor_profile_id,
                  action, from_ou_id, to_ou_id, note, verdict, occurred_at)
             VALUES (:tenant_id, :document_id, :route_id, :step_id, :actor_profile_id,
                     :action, :from_ou_id, :to_ou_id, :note, :verdict, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':document_id' => $documentId,
            ':route_id' => $event['route_id'],
            ':step_id' => $event['step_id'] ?? null,
            ':actor_profile_id' => $event['actor_profile_id'] ?? null,
            ':action' => $event['action'],
            ':from_ou_id' => $event['from_ou_id'] ?? null,
            ':to_ou_id' => $event['to_ou_id'] ?? null,
            ':note' => $event['note'] ?? null,
            ':verdict' => $event['verdict'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * One event, tenant-scoped.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, actor_profile_id,
                    action, from_ou_id, to_ou_id, note, verdict, occurred_at
               FROM document_route_events
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * A document's whole trail, oldest first — across every route on it.
     *
     * Across routes, deliberately: a document circulated twice has one history,
     * and splitting it per route would make the second circulation look like a
     * fresh start for a document that plainly has a past. The route id is on
     * every row for a reader who wants to separate them.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $documentId, int $tenantId, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, actor_profile_id,
                    action, from_ou_id, to_ou_id, note, verdict, occurred_at
               FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id ASC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':document_id', $documentId, PDO::PARAM_INT);
        // Bound as INT explicitly. PDO's default is PARAM_STR, which emulated
        // prepares quote — `LIMIT '25'` is a syntax error on PostgreSQL and
        // silently accepted on SQLite, so the SQLite unit run would pass and the
        // real engine would not.
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * How many events a document's trail holds, so the pagination envelope
     * reports a total the caller can actually reach.
     */
    public function countForDocument(int $documentId, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM document_route_events
              WHERE tenant_id = :tenant_id AND document_id = :document_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many times each step has been REJECTED on this route (#1037).
     *
     * WHAT THIS MAKES VISIBLE. A rejection is what sends a document backwards —
     * "to the author, to fix" is the most common approval design there is, and
     * it produces a cycle the engine fully supports. Nothing counted the laps,
     * so a document on its ninth rejection looked, in every surface, exactly
     * like one on its first: one open inbox item, a long trail nobody reads to
     * the end, and no number anywhere saying it had been round nine times. The
     * failure is not a runaway — `act()` performs one traversal per human act —
     * it is a document quietly ping-ponging for six weeks with nobody able to
     * see why.
     *
     * DERIVED, NOT STORED. The trail already holds this: #1014's `verdict`
     * column records every settled answer, so the count is a GROUP BY over rows
     * that exist. A stored counter would be a second source of truth that can
     * disagree with the trail, and the trail is the auditable one.
     *
     * ONLY REJECTIONS COUNT. An approval moves the document ON; a rejection is
     * the verdict that can send it back, so a step's rejection count IS the
     * number of times it has been round from there. Steps with no rejection are
     * absent from the map rather than present as zero — the caller defaults, and
     * a route that has never been rejected produces an empty array instead of a
     * row per step.
     *
     * @return array<int, int> step id => rejections recorded at it
     */
    public function rejectionCountsByStep(int $routeId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT step_id, COUNT(*) AS rejections
               FROM document_route_events
              WHERE tenant_id = :tenant_id
                AND route_id = :route_id
                AND verdict = :verdict
                AND step_id IS NOT NULL
              GROUP BY step_id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':verdict' => RouteVerdict::REJECTED,
        ]);

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['step_id']] = (int) $row['rejections'];
        }

        return $counts;
    }

    /**
     * Map a raw row to the typed shape the presenter serialises.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'document_id' => (int) $row['document_id'],
            'route_id' => (int) $row['route_id'],
            'step_id' => $row['step_id'] !== null ? (int) $row['step_id'] : null,
            'actor_profile_id' => $row['actor_profile_id'] !== null ? (int) $row['actor_profile_id'] : null,
            'action' => (string) $row['action'],
            'from_ou_id' => $row['from_ou_id'] !== null ? (int) $row['from_ou_id'] : null,
            'to_ou_id' => $row['to_ou_id'] !== null ? (int) $row['to_ou_id'] : null,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            // NULL means "this act said nothing about approval", never "not
            // approved". See append().
            'verdict' => isset($row['verdict']) && $row['verdict'] !== null ? (string) $row['verdict'] : null,
            'occurred_at' => (string) $row['occurred_at'],
        ];
    }
}
