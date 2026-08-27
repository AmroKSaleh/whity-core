<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use PDO;

/**
 * What bodies concluded, under their numbers.
 *
 * THERE IS NO UPDATE AND NO DELETE, AND THAT IS THE WHOLE DESIGN
 * --------------------------------------------------------------
 * A decision may already have driven a document through a routing chain — the
 * trail says an approval happened, people were notified, and the document moved.
 * A decision row that could be edited afterwards would let the minute-book say
 * one thing while the trail says another, with no way to tell which is the
 * record. A body that changes its mind takes a NEW decision at a later sitting,
 * which is what bodies actually do and which leaves both facts readable.
 *
 * The one write after creation is {@see attachRoute()}, and it does not change
 * what the decision SAYS — it records what the decision DID, in the same
 * transaction that did it. See its docblock.
 */
final class DecisionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A meeting's decisions, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForMeeting(int $tenantId, int $meetingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, agenda_item_id, decision_number, verdict, rationale,
                    decided_at, recorded_by_profile_id, route_id, route_event_id
               FROM meeting_decisions
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * Every decision taken on one agenda item, oldest first.
     *
     * More than one is possible and is not a defect: a body that deferred an item
     * in March and approved it in June took two decisions about it, and both are
     * on the record.
     *
     * @return list<array<string, mixed>>
     */
    public function listForAgendaItem(int $tenantId, int $agendaItemId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, agenda_item_id, decision_number, verdict, rationale,
                    decided_at, recorded_by_profile_id, route_id, route_event_id
               FROM meeting_decisions
              WHERE tenant_id = :tenant_id AND agenda_item_id = :agenda_item_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':agenda_item_id' => $agendaItemId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, agenda_item_id, decision_number, verdict, rationale,
                    decided_at, recorded_by_profile_id, route_id, route_event_id
               FROM meeting_decisions
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * The decision already holding this number in this tenant, if any.
     *
     * PER TENANT, because that is the scope of the unique index migration 130
     * created — see its class docblock, and
     * {@see DecisionRecorder::record()} for why the scope was kept rather than
     * narrowed when hand-typed numbers arrived.
     *
     * This read is NOT the uniqueness guarantee and must not be mistaken for
     * one. It exists so a caller who supplied a colliding number gets a sentence
     * naming what it collides with instead of a constraint violation, and it is
     * a READ followed by a WRITE — two requests can both pass it. The guarantee
     * is the index; this is the explanation.
     *
     * @return array<string, mixed>|null
     */
    public function findByNumber(int $tenantId, string $decisionNumber): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, agenda_item_id, decision_number, verdict, rationale,
                    decided_at, recorded_by_profile_id, route_id, route_event_id
               FROM meeting_decisions
              WHERE tenant_id = :tenant_id AND decision_number = :decision_number'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':decision_number' => $decisionNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    public function create(
        int $tenantId,
        int $meetingId,
        int $agendaItemId,
        string $decisionNumber,
        string $verdict,
        ?string $rationale,
        string $decidedAt,
        ?int $recordedBy
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO meeting_decisions
                (tenant_id, meeting_id, agenda_item_id, decision_number, verdict, rationale,
                 decided_at, recorded_by_profile_id)
             VALUES (:tenant_id, :meeting_id, :agenda_item_id, :decision_number, :verdict, :rationale,
                 :decided_at, :recorded_by)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':meeting_id' => $meetingId,
            ':agenda_item_id' => $agendaItemId,
            ':decision_number' => $decisionNumber,
            ':verdict' => $verdict,
            ':rationale' => $rationale,
            ':decided_at' => $decidedAt,
            ':recorded_by' => $recordedBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Record that this decision drove a routing act.
     *
     * NOT a correction and not a state change — the decision's verdict, number,
     * rationale and timestamp are untouched. What is written is a pointer at the
     * trail entry the decision produced, and it exists so that a reader can tell
     * these two apart:
     *
     *   - "the body approved this, and the document advanced"        route_id set
     *   - "the body approved this, and nothing moved"                route_id null
     *
     * Without the pointer those render identically on every screen, and the
     * second one is the failure mode this whole subsystem is written against —
     * a stored intention that silently did nothing while reporting success.
     *
     * Called inside the SAME transaction as the routing act
     * ({@see DecisionRecorder}), so it cannot claim an act that rolled back.
     */
    public function attachRoute(int $tenantId, int $id, int $routeId, ?int $routeEventId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE meeting_decisions SET route_id = :route_id, route_event_id = :route_event_id
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            ':route_id' => $routeId,
            ':route_event_id' => $routeEventId,
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
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
            'meeting_id' => (int) $row['meeting_id'],
            'agenda_item_id' => (int) $row['agenda_item_id'],
            'decision_number' => (string) $row['decision_number'],
            'verdict' => (string) $row['verdict'],
            'rationale' => $row['rationale'] !== null ? (string) $row['rationale'] : null,
            'decided_at' => (string) $row['decided_at'],
            'recorded_by_profile_id' => $row['recorded_by_profile_id'] !== null
                ? (int) $row['recorded_by_profile_id']
                : null,
            'route_id' => $row['route_id'] !== null ? (int) $row['route_id'] : null,
            'route_event_id' => $row['route_event_id'] !== null ? (int) $row['route_event_id'] : null,
        ];
    }
}
