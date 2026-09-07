<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use PDO;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * The sittings themselves: created, scheduled, held, cancelled.
 *
 * MEETING NUMBERS COME FROM THE PLATFORM COUNTER, NOT FROM `MAX(n) + 1`
 * ---------------------------------------------------------------------
 * `meeting_number` is allocated through {@see SequenceAllocator} under the name
 * `convening.meeting.{body_key}`, exactly as decision numbers are (see
 * {@see DecisionNumbers}, which carries the full argument). The short form: a
 * `SELECT MAX(meeting_number) + 1` is correct until two people create a meeting
 * in the same second, at which point it hands out the same number twice, and the
 * unique constraint turns that into a 500 for whichever request lost. The
 * counter is one statement and cannot.
 *
 * Numbering is PER BODY. Two bodies each holding their fourteenth sitting is the
 * ordinary case, and a tenant-wide sequence would make one body's numbering jump
 * every time an unrelated body met.
 *
 * WHAT THIS CLASS DOES NOT DO
 * ---------------------------
 * It does not decide whether a transition is legal — {@see MeetingStatus} owns
 * that, and {@see MeetingService} is where the two meet. This is a repository:
 * it reads and writes rows, and every method that changes a status takes the
 * status it is moving TO as an argument that has already been checked. Putting
 * the transition rules here as well would give the same invariant two homes.
 */
final class MeetingRepository
{
    public const FALLBACK_LOCALE = 'en';

    public function __construct(
        private readonly PDO $db,
        private readonly SequenceAllocator $sequences,
    ) {
    }

    /**
     * The body's sittings, most recent first.
     *
     * ORDERED BY id DESCENDING, not by a date. A draft has no date at all, and
     * ordering on a nullable column puts every draft into one indistinct heap at
     * whichever end the engine happens to sort nulls — which differs between
     * PostgreSQL and SQLite, so the list would not even be the same list on the
     * two engines this platform runs on.
     *
     * @param list<string> $statuses Restrict to these statuses; empty means all.
     *
     * @return list<array<string, mixed>>
     */
    public function listForBody(int $tenantId, int $bodyId, array $statuses = []): array
    {
        $sql = 'SELECT id, tenant_id, body_id, meeting_number, title, scheduled_at, held_at,
                       location, status, created_by_profile_id, created_at
                  FROM meetings
                 WHERE tenant_id = :tenant_id AND body_id = :body_id';
        $params = [':tenant_id' => $tenantId, ':body_id' => $bodyId];

        if ($statuses !== []) {
            $placeholders = [];
            foreach (array_values($statuses) as $i => $status) {
                $placeholders[] = ':status_' . $i;
                $params[':status_' . $i] = $status;
            }
            $sql .= ' AND status IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * Every sitting in the tenant, most recent first, optionally narrowed.
     *
     * @param list<string> $statuses
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?int $bodyId = null, array $statuses = []): array
    {
        if ($bodyId !== null) {
            return $this->listForBody($tenantId, $bodyId, $statuses);
        }

        $sql = 'SELECT id, tenant_id, body_id, meeting_number, title, scheduled_at, held_at,
                       location, status, created_by_profile_id, created_at
                  FROM meetings
                 WHERE tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if ($statuses !== []) {
            $placeholders = [];
            foreach (array_values($statuses) as $i => $status) {
                $placeholders[] = ':status_' . $i;
                $params[':status_' . $i] = $status;
            }
            $sql .= ' AND status IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

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
            'SELECT id, tenant_id, body_id, meeting_number, title, scheduled_at, held_at,
                    location, status, created_by_profile_id, created_at
               FROM meetings
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * Create a sitting in `draft`.
     *
     * Always `draft`, never straight to `scheduled`, even when the caller sent a
     * date. Scheduling is its own act with its own audit meaning ("this is now
     * fixed, tell people"), and collapsing it into creation would mean a sitting
     * could become scheduled as a side effect of somebody starting to type an
     * agenda. {@see MeetingService::schedule()} is the one door.
     *
     * @param array<string, string> $title Locale => label, already normalized.
     */
    public function create(int $tenantId, string $bodyKey, int $bodyId, array $title, ?int $createdBy): int
    {
        // Allocated BEFORE the insert and inside whatever transaction the caller
        // has open. Gaps are possible (a rolled-back create burns a number) and
        // that is the documented trade in SequenceCounters: unique and
        // monotonic, not gapless. A minute-book with a missing number is a
        // curiosity; two sittings numbered 14 is a defect.
        $number = $this->sequences->next($tenantId, self::counterName($bodyKey));

        $stmt = $this->db->prepare(
            'INSERT INTO meetings
                (tenant_id, body_id, meeting_number, title, status, created_by_profile_id, created_at)
             VALUES (:tenant_id, :body_id, :meeting_number, :title, :status, :created_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':body_id' => $bodyId,
            ':meeting_number' => $number,
            ':title' => LocalizedText::encode($title),
            ':status' => MeetingStatus::DRAFT,
            ':created_by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Fix a date and a place, and move to `scheduled`.
     *
     * The status is written in the SAME statement as the date. Two statements
     * would leave a window in which a sitting is `scheduled` with no date, and
     * every screen that reads "scheduled" as "has a date" would render a blank.
     */
    public function schedule(int $tenantId, int $id, string $scheduledAt, ?string $location): void
    {
        $stmt = $this->db->prepare(
            'UPDATE meetings
                SET scheduled_at = :scheduled_at, location = :location, status = :status
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            ':scheduled_at' => $scheduledAt,
            ':location' => $location,
            ':status' => MeetingStatus::SCHEDULED,
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
    }

    /**
     * Record that the sitting took place.
     *
     * `held_at` is passed in rather than defaulted to NOW() by the column,
     * because a body routinely minutes a sitting the following morning and a
     * server-stamped `held_at` would put every such meeting on the wrong day —
     * a day that then appears in the minute-book and in every decision's
     * provenance.
     */
    public function hold(int $tenantId, int $id, string $heldAt): void
    {
        $stmt = $this->db->prepare(
            'UPDATE meetings SET held_at = :held_at, status = :status
              WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            ':held_at' => $heldAt,
            ':status' => MeetingStatus::HELD,
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
    }

    public function cancel(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE meetings SET status = :status WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            ':status' => MeetingStatus::CANCELLED,
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);
    }

    /**
     * The counter a body's meeting numbers come from.
     *
     * Keyed on the body KEY rather than its id, so a counter reads as what it is
     * in a database dump. The key is immutable
     * ({@see ConveningBodyRepository::update()}), which is what makes that safe.
     *
     * COLON-separated, not dot-separated: {@see \Whity\Database\SequenceCounters}
     * constrains a counter name to lowercase letters, digits, underscore, colon
     * and hyphen, because the name is half a primary key. A dotted name is
     * refused outright rather than stored — which is the right failure, and worth
     * a line here so the next counter added does not have to discover it.
     */
    public static function counterName(string $bodyKey): string
    {
        return 'convening:meeting:' . $bodyKey;
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
            'body_id' => (int) $row['body_id'],
            'meeting_number' => (int) $row['meeting_number'],
            'title' => LocalizedText::decode($row['title'] ?? null, self::FALLBACK_LOCALE),
            // Beside the map, for surfaces that can hold only one string. See
            // LocalizedText::preferred().
            'display_title' => LocalizedText::preferred(
                LocalizedText::decode($row['title'] ?? null, self::FALLBACK_LOCALE),
                self::FALLBACK_LOCALE,
                '#' . (string) $row['meeting_number']
            ),
            'scheduled_at' => $row['scheduled_at'] !== null ? (string) $row['scheduled_at'] : null,
            'held_at' => $row['held_at'] !== null ? (string) $row['held_at'] : null,
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'status' => (string) $row['status'],
            'created_by_profile_id' => $row['created_by_profile_id'] !== null
                ? (int) $row['created_by_profile_id']
                : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
