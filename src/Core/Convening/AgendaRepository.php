<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use PDO;

/**
 * The agenda: what a body will consider, in order, and which document each item
 * is about.
 *
 * ALLOCATING A DOCUMENT TO A MEETING IS WHAT THIS TABLE IS FOR
 * -----------------------------------------------------------
 * An agenda item with a `document_id` is the join between "the body decided X"
 * and "this document is waiting for a decision". Everything else in this
 * subsystem is a minute-book; this pointer is what makes the minute-book able to
 * move a document.
 *
 * ADDING TO A MEETING THAT HAS ALREADY BEEN HELD IS POSSIBLE AND IS NOT SILENT
 * ----------------------------------------------------------------------------
 * A sitting that is `draft` or `scheduled` ACCUMULATES items: that is how
 * somebody builds next month's agenda, and it needs no ceremony. A sitting that
 * is `held` is a record of something that happened, and attaching an item to it
 * is asserting that the body considered that item — which is sometimes exactly
 * right (a paper tabled at the meeting and minuted afterwards) and sometimes a
 * person on the wrong screen.
 *
 * So it is allowed and it must be ASKED FOR: {@see add()} refuses a held meeting
 * unless the caller passes `$allowHeld`. The API surfaces that as an explicit
 * flag on the request rather than a different endpoint, because the two are the
 * same act with different consequences, and a second endpoint would be found by
 * whoever hit the refusal and used from then on without the question ever being
 * put to a person.
 *
 * A cancelled meeting is refused outright, with no override. There is no reading
 * of "the body considered this at the sitting that did not happen".
 *
 * REORDERING IS TWO PHASES, BECAUSE THE UNIQUE CONSTRAINT IS REAL
 * ---------------------------------------------------------------
 * `UNIQUE (meeting_id, position)` is what makes an agenda an ORDER rather than a
 * bag with hints. Rewriting positions in place collides the moment any item moves
 * into a slot another item still holds, so {@see reorder()} parks every affected
 * row on a negative position first and then writes the final ones. Negative
 * because no legitimate position is ever negative, so the parking space cannot
 * collide with a real value — and because a failure between the phases leaves
 * rows that are visibly wrong rather than plausibly wrong.
 *
 * The alternative — dropping the constraint so the naive update works — is the
 * one thing that must not happen: a constraint that has to be removed to let the
 * ordinary edit through was never enforcing anything.
 */
final class AgendaRepository
{
    public const FALLBACK_LOCALE = 'en';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A meeting's items in agenda order.
     *
     * @return list<array<string, mixed>>
     */
    public function listForMeeting(int $tenantId, int $meetingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, position, title, document_id, notes, created_at
               FROM meeting_agenda_items
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id
              ORDER BY position ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * Every agenda item in the tenant that names this document, oldest first.
     *
     * THE REVERSE READ — "which bodies has this document been in front of?" —
     * which is the question a document's own page asks and the one that makes
     * this subsystem visible from outside itself.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $tenantId, int $documentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, position, title, document_id, notes, created_at
               FROM meeting_agenda_items
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

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
            'SELECT id, tenant_id, meeting_id, position, title, document_id, notes, created_at
               FROM meeting_agenda_items
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * Put an item on a meeting's agenda.
     *
     * @param array<string, string> $title      Locale => label, already normalized.
     * @param string                $status     The meeting's current status.
     * @param bool                  $allowHeld  The caller has explicitly said it
     *        means to attach to a sitting that already happened. See the class
     *        docblock — this is the whole of the "not silent" requirement.
     *
     * @throws ConveningRejectedException When the meeting cannot take items.
     */
    public function add(
        int $tenantId,
        int $meetingId,
        string $status,
        array $title,
        ?int $documentId,
        ?string $notes,
        bool $allowHeld = false
    ): int {
        if ($status === MeetingStatus::CANCELLED) {
            throw ConveningRejectedException::because(
                'This meeting was cancelled, so nothing can be added to its agenda. There is no '
                . 'reading of "the body considered this at the sitting that did not happen".'
            );
        }

        if ($status === MeetingStatus::HELD && !$allowHeld) {
            throw ConveningRejectedException::because(
                'This meeting has already been held. Adding an item to it records that the body '
                . 'considered that item at a sitting that is over — which is right for a paper tabled '
                . 'on the day and minuted afterwards, and wrong if you meant the next meeting. Send '
                . '"allow_held": true to confirm you mean this meeting.'
            );
        }

        $position = $this->nextPosition($tenantId, $meetingId);

        $stmt = $this->db->prepare(
            'INSERT INTO meeting_agenda_items
                (tenant_id, meeting_id, position, title, document_id, notes, created_at)
             VALUES (:tenant_id, :meeting_id, :position, :title, :document_id, :notes, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':meeting_id' => $meetingId,
            ':position' => $position,
            ':title' => LocalizedText::encode($title),
            ':document_id' => $documentId,
            ':notes' => $notes,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Remove an item, and CLOSE THE GAP it leaves.
     *
     * Positions stay contiguous because an agenda with a hole in it is read by
     * every human as an item somebody forgot to list, and by the reorder code as
     * a set it has to renumber anyway. Both phases run in one transaction.
     *
     * @throws ConveningRejectedException When the item has decisions against it.
     */
    public function remove(int $tenantId, int $id): void
    {
        $item = $this->find($tenantId, $id);
        if ($item === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM meeting_decisions
              WHERE tenant_id = :tenant_id AND agenda_item_id = :item_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':item_id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw ConveningRejectedException::because(
                'This item has a recorded decision against it and cannot be removed. A decision may '
                . 'already have approved or rejected a document; deleting what it was about would '
                . 'leave the decision quoting an item nobody can read.'
            );
        }

        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }

        try {
            $delete = $this->db->prepare(
                'DELETE FROM meeting_agenda_items WHERE tenant_id = :tenant_id AND id = :id'
            );
            $delete->execute([':tenant_id' => $tenantId, ':id' => $id]);

            $close = $this->db->prepare(
                'UPDATE meeting_agenda_items SET position = position - 1
                  WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id AND position > :position'
            );
            $close->execute([
                ':tenant_id' => $tenantId,
                ':meeting_id' => (int) $item['meeting_id'],
                ':position' => (int) $item['position'],
            ]);

            if ($owned) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Rewrite the whole agenda's order from a list of item ids.
     *
     * THE WHOLE AGENDA, not a move-one-item operation. A caller sending a partial
     * list is describing an order that does not include some of the items, and
     * the only two things that could mean — leave them where they are, or put
     * them at the end — are both guesses. So the list must name every item
     * exactly once and is refused otherwise, which is also what makes the
     * operation idempotent and safe to retry.
     *
     * @param list<int> $orderedIds
     *
     * @throws ConveningRejectedException When the list is not a permutation of
     *         the meeting's items.
     */
    public function reorder(int $tenantId, int $meetingId, array $orderedIds): void
    {
        $current = $this->listForMeeting($tenantId, $meetingId);
        $currentIds = array_map(static fn (array $i): int => (int) $i['id'], $current);

        $given = array_values(array_unique($orderedIds));
        sort($given);
        $expected = $currentIds;
        sort($expected);

        if ($given !== $expected) {
            throw ConveningRejectedException::because(
                'The order must list every item on this agenda exactly once. It currently holds '
                . count($currentIds) . ' item(s); anything else would leave items in a position '
                . 'nobody chose.'
            );
        }

        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }

        try {
            // PHASE 1 — park every row on a negative position. `UNIQUE
            // (meeting_id, position)` is real, so writing the final positions
            // directly collides the instant any item moves into a slot another
            // item still holds. Negative because no legitimate position ever is,
            // so the parking space cannot collide with a live value.
            $park = $this->db->prepare(
                'UPDATE meeting_agenda_items SET position = -position
                  WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id'
            );
            $park->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

            // PHASE 2 — the order the caller asked for, 1-based.
            $set = $this->db->prepare(
                'UPDATE meeting_agenda_items SET position = :position
                  WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id AND id = :id'
            );
            foreach (array_values($orderedIds) as $index => $itemId) {
                $set->execute([
                    ':position' => $index + 1,
                    ':tenant_id' => $tenantId,
                    ':meeting_id' => $meetingId,
                    ':id' => $itemId,
                ]);
            }

            if ($owned) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function nextPosition(int $tenantId, int $meetingId): int
    {
        // MAX + 1 is correct HERE, where it is not correct for a meeting or a
        // decision number, and the difference is worth stating rather than
        // leaving as an inconsistency: a position is scoped to one meeting and
        // is freely rewritten by reorder(), so a collision under concurrency is
        // a unique-constraint failure the caller retries, not a duplicate
        // IDENTIFIER handed to two records that both keep it. A sequence counter
        // per meeting would also never be reclaimable after a remove().
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(position), 0) FROM meeting_agenda_items
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

        return ((int) $stmt->fetchColumn()) + 1;
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
            'position' => (int) $row['position'],
            'title' => LocalizedText::decode($row['title'] ?? null, self::FALLBACK_LOCALE),
            // Beside the map, for surfaces that can hold only one string. See
            // LocalizedText::preferred().
            'display_title' => LocalizedText::preferred(
                LocalizedText::decode($row['title'] ?? null, self::FALLBACK_LOCALE),
                self::FALLBACK_LOCALE,
                'Item ' . (string) $row['position']
            ),
            'document_id' => $row['document_id'] !== null ? (int) $row['document_id'] : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
