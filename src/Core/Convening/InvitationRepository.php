<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use PDO;

/**
 * Who was asked to a sitting, and what they said.
 *
 * ISSUING IS IDEMPOTENT, AND THAT IS LOAD-BEARING
 * -----------------------------------------------
 * {@see invite()} is `INSERT … ON CONFLICT DO NOTHING` on `(meeting_id,
 * profile_id)`. Sending invitations twice — because somebody joined the body, or
 * because the first attempt half-failed, or because a person pressed the button
 * again — must not create a second invitation, must not reset an answer somebody
 * already gave, and must not re-stamp `sent_at` on a row that was already sent.
 * A chair who has three acceptances and re-sends should still have three
 * acceptances.
 *
 * WHICH MEANS THE METHOD REPORTS WHAT IT ACTUALLY DID. It returns the profile ids
 * it newly invited, not the ids it was given, so the caller notifies exactly the
 * people who are hearing about the sitting for the first time. A caller that
 * notified everybody it asked for would mail the whole body again every time one
 * person was added — which is how a useful notification becomes one people
 * filter.
 *
 * TENANT PREDICATE ON EVERY STATEMENT, including the ones a meeting id already
 * narrows: the guard polices the statement it can see.
 */
final class InvitationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * A meeting's invitations, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForMeeting(int $tenantId, int $meetingId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, profile_id, status, sent_at, responded_at
               FROM meeting_invitations
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':meeting_id' => $meetingId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForProfile(int $tenantId, int $meetingId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, meeting_id, profile_id, status, sent_at, responded_at
               FROM meeting_invitations
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id AND profile_id = :profile_id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':meeting_id' => $meetingId,
            ':profile_id' => $profileId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * Invite a set of people, skipping anybody already invited.
     *
     * @param list<int> $profileIds
     *
     * @return list<int> The profile ids that were NEWLY invited — see the class
     *         docblock for why this is not simply the input.
     */
    public function invite(int $tenantId, int $meetingId, array $profileIds): array
    {
        $newlyInvited = [];

        $stmt = $this->db->prepare(
            'INSERT INTO meeting_invitations (tenant_id, meeting_id, profile_id, status, sent_at)
             VALUES (:tenant_id, :meeting_id, :profile_id, :status, NOW())
             ON CONFLICT (meeting_id, profile_id) DO NOTHING'
        );

        foreach (array_values(array_unique($profileIds)) as $profileId) {
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':meeting_id' => $meetingId,
                ':profile_id' => $profileId,
                ':status' => InvitationStatus::INVITED,
            ]);

            // rowCount() is 0 on the DO NOTHING branch on both engines, which is
            // exactly the discrimination this method is built on. Asked for and
            // already held is not an error and is not news.
            if ($stmt->rowCount() > 0) {
                $newlyInvited[] = $profileId;
            }
        }

        return $newlyInvited;
    }

    /**
     * Record somebody's answer.
     *
     * `responded_at` is stamped in the SAME statement, because "has answered" and
     * "what they answered" are one fact and two statements could leave a row
     * saying `accepted` with no answer time — which every attendance report would
     * read as a system-set value rather than a person's.
     *
     * An answer may be CHANGED: somebody who accepted and then cannot attend says
     * `declined`, and `responded_at` moves to when they said so. There is nothing
     * to be gained from freezing the first answer and a great deal to be lost —
     * the chair would plan around a person who has told them otherwise.
     *
     * @throws ConveningRejectedException When the status is not an answer a
     *         person can give.
     */
    public function respond(int $tenantId, int $meetingId, int $profileId, string $status): bool
    {
        if (!InvitationStatus::isResponse($status)) {
            throw ConveningRejectedException::because(
                "'{$status}' is not an answer to an invitation; expected one of: "
                . implode(', ', InvitationStatus::responses()) . '.'
            );
        }

        $stmt = $this->db->prepare(
            'UPDATE meeting_invitations SET status = :status, responded_at = NOW()
              WHERE tenant_id = :tenant_id AND meeting_id = :meeting_id AND profile_id = :profile_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':tenant_id' => $tenantId,
            ':meeting_id' => $meetingId,
            ':profile_id' => $profileId,
        ]);

        return $stmt->rowCount() > 0;
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
            'profile_id' => (int) $row['profile_id'],
            'status' => (string) $row['status'],
            'sent_at' => $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            'responded_at' => $row['responded_at'] !== null ? (string) $row['responded_at'] : null,
        ];
    }
}
