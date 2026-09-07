<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use PDO;

/**
 * Who was in the room, and what they had said beforehand.
 *
 * THE WRITE IS A REPLACEMENT, AND THAT IS THE HARD PART
 * ----------------------------------------------------
 * {@see replace()} deletes this meeting's attendance and writes the set it was
 * given, in one transaction. That is the right shape for the act — a secretary
 * reads a list off a sign-in sheet and says "these are the people who were
 * here", which is a statement about the WHOLE set and not a stream of
 * additions — and it is the same shape
 * {@see \Whity\Api\FormFieldsApiHandler::replace()} has for the same reason.
 *
 * It is also the reason every guard in this class exists. A replacement endpoint
 * DELETES WHATEVER THE PAYLOAD OMITS, so a caller that sent an empty list
 * because its own read had not landed would erase a minuted attendance and
 * report success. Two things stand between that and the data:
 *
 *  - HERE: the delete and the inserts are one transaction, so a payload that
 *    fails validation halfway through leaves the stored list exactly as it was
 *    rather than truncated to the rows that happened to parse first.
 *  - IN THE RENDERER: a sourced `fieldArray` refuses to submit until it has
 *    actually loaded the stored rows for the record it is bound to — see the
 *    type's entry in {@see \Whity\Sdk\Frontend\Blocks\BlockContract}. That is
 *    the guard against the empty submit, and it is deliberately not restated
 *    here: the server cannot tell "nobody attended" from "the client had not
 *    loaded yet", and inventing a rule that refuses an empty list would refuse
 *    the real and ordinary case of a sitting that was abandoned for want of
 *    attendance.
 *
 * WHY THE READ JOINS THE INVITATIONS
 * ----------------------------------
 * Every row comes back carrying `was_invited` and `invitation_status` — what
 * this person SAID before the sitting, beside what they DID. Those two are
 * separate facts by construction ({@see InvitationStatus} refuses to hold both
 * on one column) and the interesting rows are exactly the ones where they
 * disagree: somebody who accepted and did not come, somebody who declined and
 * turned up anyway. A read that returned attendance alone would make both look
 * like the ordinary case, and the only surface on which anybody would notice is
 * a spreadsheet somebody built by hand.
 *
 * The join is LEFT and the two derived fields are honest about the third state:
 * `was_invited` false with a null status is a person who holds no invitation at
 * all, which is a co-opted member or a guest and is the case
 * {@see \Database\Migrations\CreateMeetingAttendance} exists for.
 *
 * TENANT PREDICATE ON EVERY STATEMENT, including the ones a meeting id already
 * narrows: the guard polices the statement it can see.
 */
final class AttendanceRepository
{
    /** Longest name this table holds for somebody with no profile. */
    public const NAME_MAX = 255;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A meeting's attendance, in the order it was recorded.
     *
     * @return list<array<string, mixed>>
     */
    public function listForMeeting(int $tenantId, int $meetingId): array
    {
        // The invitation is matched on the meeting AND the tenant, not on the
        // profile alone: without `i.tenant_id = a.tenant_id` the join condition
        // would be satisfiable by another tenant's invitation row for a profile
        // id that happens to collide, which is the transitive-predicate shape
        // the tenant guard recognises and the reason it is spelled out.
        $stmt = $this->db->prepare(
            'SELECT a.id, a.tenant_id, a.meeting_id, a.profile_id, a.attendee_name, a.capacity,
                    a.note, a.recorded_at, a.recorded_by_profile_id,
                    i.status AS invitation_status
               FROM meeting_attendees a
               LEFT JOIN meeting_invitations i
                      ON i.tenant_id = a.tenant_id
                     AND i.meeting_id = a.meeting_id
                     AND i.profile_id = a.profile_id
              WHERE a.tenant_id = :tenant_id AND a.meeting_id = :meeting_id
              ORDER BY a.id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * Replace this meeting's attendance with the given set.
     *
     * @param list<array{profile_id: ?int, attendee_name: ?string, capacity: string, note: ?string}> $attendees
     *        Already validated by {@see AttendanceEntry::parseSet()} — this
     *        method writes rows and does not police the vocabulary, exactly as
     *        every other repository here leaves the rules to the layer above.
     *
     * @return int How many rows were written.
     */
    public function replace(int $tenantId, int $meetingId, array $attendees, ?int $recordedBy): int
    {
        $owned = !$this->db->inTransaction();
        if ($owned) {
            $this->db->beginTransaction();
        }

        try {
            $delete = $this->db->prepare(
                'DELETE FROM meeting_attendees WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id'
            );
            $delete->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

            $insert = $this->db->prepare(
                'INSERT INTO meeting_attendees
                    (tenant_id, meeting_id, profile_id, attendee_name, capacity, note,
                     recorded_at, recorded_by_profile_id)
                 VALUES (:tenant_id, :meeting_id, :profile_id, :attendee_name, :capacity, :note,
                     NOW(), :recorded_by)'
            );

            foreach ($attendees as $attendee) {
                $insert->execute([
                    ':tenant_id' => $tenantId,
                    ':meeting_id' => $meetingId,
                    ':profile_id' => $attendee['profile_id'],
                    ':attendee_name' => $attendee['attendee_name'],
                    ':capacity' => $attendee['capacity'],
                    ':note' => $attendee['note'],
                    ':recorded_by' => $recordedBy,
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

        return count($attendees);
    }

    /**
     * How many rows this meeting's attendance holds.
     *
     * Separate from {@see listForMeeting()} because the summary
     * {@see \Whity\Api\MeetingsApiHandler::attendance()} reports is a count of
     * ROWS and nothing else, and a caller counting a list it also renders is a
     * caller that will one day count the filtered list by accident.
     */
    public function countForMeeting(int $tenantId, int $meetingId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM meeting_attendees
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $status = $row['invitation_status'] ?? null;

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'meeting_id' => (int) $row['meeting_id'],
            'profile_id' => $row['profile_id'] !== null ? (int) $row['profile_id'] : null,
            'attendee_name' => $row['attendee_name'] !== null ? (string) $row['attendee_name'] : null,
            'capacity' => (string) $row['capacity'],
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'recorded_at' => (string) $row['recorded_at'],
            'recorded_by_profile_id' => $row['recorded_by_profile_id'] !== null
                ? (int) $row['recorded_by_profile_id']
                : null,
            // DERIVED, and both halves are needed. `was_invited` false with a
            // null status is somebody who holds no invitation — a guest or a
            // co-opted member — and it must not read as "invited, and said
            // nothing", which is what a bare null status would look like.
            'was_invited' => $status !== null,
            // WHAT THEY SAID, beside what they did. An `accepted` here on a row
            // that exists is the ordinary case; a `declined` here is somebody
            // who turned up anyway, and that is a fact a minute has to be able
            // to show.
            'invitation_status' => $status !== null ? (string) $status : null,
        ];
    }
}
