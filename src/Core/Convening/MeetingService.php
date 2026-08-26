<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * The acts a sitting goes through: created, agenda built, scheduled, invited,
 * held.
 *
 * WHY A SERVICE AND NOT SIX HANDLER METHODS
 * -----------------------------------------
 * Every act here is a TRANSITION plus a SIDE EFFECT, and the pair has to be
 * decided in one place:
 *
 *   schedule  → the status rule, then a date, then possibly re-announcing to
 *               people who had already accepted the old date
 *   invite    → the body's CURRENT membership, then rows, then notifications for
 *               exactly the people who did not already have one
 *   hold      → the status rule, then a timestamp somebody chose
 *
 * Written in the handler, each of those becomes a rule that exists in one HTTP
 * method and nowhere else — so the CLI, the next client, and the plugin that
 * wants to run a standing committee on a schedule each re-derive it, slightly
 * differently.
 *
 * NOTIFICATIONS ARE SENT AFTER THE WRITE, NEVER INSIDE IT
 * -------------------------------------------------------
 * Every method below completes its database work and only then announces. An
 * announcement that fails must not roll back an invitation that succeeded: the
 * row is the organisation's record, and the message is a consequence of it. This
 * is the same posture {@see \Whity\Core\Document\Routing\DocumentRouter} takes
 * when it dispatches after its own commit, for the same reason.
 */
final class MeetingService
{
    public function __construct(
        private readonly ConveningBodyRepository $bodies,
        private readonly MeetingRepository $meetings,
        private readonly InvitationRepository $invitations,
        private readonly MeetingNotifications $notifications,
    ) {
    }

    /**
     * Open a new sitting on a body, in `draft`.
     *
     * @param array<string, string> $title
     *
     * @return array<string, mixed> The created meeting.
     *
     * @throws ConveningRejectedException When the body does not exist or is
     *         retired.
     */
    public function create(int $tenantId, int $bodyId, array $title, ?int $createdBy): array
    {
        $body = $this->requireBody($tenantId, $bodyId);

        if ($body['is_active'] !== true) {
            // A retired body is kept so its minute-book stays readable; it is not
            // kept so that new sittings can be opened on it. Refused loudly here
            // rather than allowed and puzzled over later, because a meeting on a
            // deactivated body is almost always somebody picking the wrong entry
            // out of a list that still contains both.
            throw ConveningRejectedException::because(
                'That convening body is not active, so no new meeting can be opened on it. '
                . 'Reactivate it first if the body is sitting again.'
            );
        }

        $id = $this->meetings->create($tenantId, (string) $body['body_key'], $bodyId, $title, $createdBy);
        $meeting = $this->meetings->find($tenantId, $id);

        if ($meeting === null) {
            throw new \RuntimeException('Meeting was created but could not be read back.');
        }

        return $meeting;
    }

    /**
     * Fix a date and a place.
     *
     * RE-SCHEDULING IS THE SAME CALL. A date moves; that is ordinary, and a
     * separate `reschedule` endpoint would only mean a client has to know which
     * of two indistinguishable operations it is performing. What differs is the
     * CONSEQUENCE: when the sitting had already been announced, everybody
     * holding an invitation is told it moved — including the people who
     * declined, because somebody who could not make the old date may well make
     * the new one, and quietly leaving them off is how a body loses quorum.
     *
     * @return array{meeting: array<string, mixed>, notified: int}
     *
     * @throws ConveningRejectedException
     */
    public function schedule(int $tenantId, int $meetingId, string $scheduledAt, ?string $location): array
    {
        $meeting = $this->requireMeeting($tenantId, $meetingId);

        if (!MeetingStatus::canSchedule((string) $meeting['status'])) {
            throw ConveningRejectedException::because(
                'A meeting that is "' . (string) $meeting['status'] . '" cannot be scheduled. '
                . 'Only a draft or an already-scheduled meeting can be given a date.'
            );
        }

        $scheduledAt = DecisionRecorder::normalizeTimestamp($scheduledAt, 'scheduled_at');
        $wasScheduled = (string) $meeting['status'] === MeetingStatus::SCHEDULED;

        $this->meetings->schedule($tenantId, $meetingId, $scheduledAt, $location);

        $updated = $this->requireMeeting($tenantId, $meetingId);
        $notified = 0;

        if ($wasScheduled) {
            $body = $this->requireBody($tenantId, (int) $updated['body_id']);
            $everyone = array_map(
                static fn (array $i): int => (int) $i['profile_id'],
                $this->invitations->listForMeeting($tenantId, $meetingId)
            );
            $this->notifications->announceReschedule($tenantId, $everyone, $updated, $body);
            $notified = count($everyone);
        }

        return ['meeting' => $updated, 'notified' => $notified];
    }

    /**
     * Invite the body's CURRENT membership to the sitting.
     *
     * MEMBERSHIP IS RESOLVED NOW, NOT STORED EARLIER. The people invited are the
     * people sitting on the body at the moment the invitations go out — the same
     * rule-not-roster principle {@see \Whity\Core\Document\Routing\DocumentRouter}
     * enforces on route steps, and for the same reason: a list captured when the
     * meeting was created would be stale by the time it was used, and it would
     * still render, and still report success.
     *
     * IDEMPOTENT. Somebody who already holds an invitation is not re-invited, is
     * not re-notified, and does not have their answer reset. So this is safe to
     * call again after a person joins the body, which is exactly when a secretary
     * will call it.
     *
     * A DRAFT MEETING IS REFUSED. An invitation with no date in it is a message
     * people cannot act on, and it burns the one announcement most likely to be
     * read.
     *
     * @return array{invited: list<int>, already_invited: int}
     *
     * @throws ConveningRejectedException
     */
    public function invite(int $tenantId, int $meetingId): array
    {
        $meeting = $this->requireMeeting($tenantId, $meetingId);
        $status = (string) $meeting['status'];

        if ($status === MeetingStatus::DRAFT) {
            throw ConveningRejectedException::because(
                'This meeting has no date yet, so an invitation to it would tell people nothing they '
                . 'can act on. Schedule it first.'
            );
        }
        if ($status === MeetingStatus::CANCELLED) {
            throw ConveningRejectedException::because('This meeting was cancelled; nobody can be invited to it.');
        }
        if ($status === MeetingStatus::HELD) {
            throw ConveningRejectedException::because(
                'This meeting has already been held, so there is nobody left to invite.'
            );
        }

        $body = $this->requireBody($tenantId, (int) $meeting['body_id']);
        $members = $this->bodies->currentMembers($tenantId, (int) $body['id']);

        if ($members === []) {
            throw ConveningRejectedException::because(
                'Nobody currently sits on this body, so there is nobody to invite. Appoint its '
                . 'members first.'
            );
        }

        $profileIds = array_map(static fn (array $m): int => (int) $m['profile_id'], $members);
        $newlyInvited = $this->invitations->invite($tenantId, $meetingId, $profileIds);

        // AFTER the write, and only to the people who are hearing about it for
        // the first time. See the class docblock.
        $this->notifications->announceInvitations($tenantId, $newlyInvited, $meeting, $body);

        return [
            'invited' => $newlyInvited,
            'already_invited' => count($profileIds) - count($newlyInvited),
        ];
    }

    /**
     * Somebody's answer to their own invitation.
     *
     * The caller's identity is checked by the API handler against the SESSION,
     * not against a permission: being invited IS the authorization (the same
     * argument migration 113 makes about acting on a route). What this method
     * enforces is the other half — that there is an invitation to answer at all,
     * so a stray meeting id produces a refusal rather than a silent no-op that
     * reads as success.
     *
     * @return array{meeting: array<string, mixed>, invitation: array<string, mixed>}
     *
     * @throws ConveningRejectedException
     */
    public function respond(int $tenantId, int $meetingId, int $profileId, string $status): array
    {
        $meeting = $this->requireMeeting($tenantId, $meetingId);

        if (!$this->invitations->respond($tenantId, $meetingId, $profileId, $status)) {
            throw ConveningRejectedException::because(
                'You have no invitation to this meeting to answer.'
            );
        }

        $invitation = $this->invitations->findForProfile($tenantId, $meetingId, $profileId);
        if ($invitation === null) {
            throw new \RuntimeException('Invitation was answered but could not be read back.');
        }

        return ['meeting' => $meeting, 'invitation' => $invitation];
    }

    /**
     * Record that the sitting took place.
     *
     * `$heldAt` is supplied rather than defaulted to now(), because a body
     * routinely minutes yesterday's sitting and a server-stamped date would put
     * every one of them on the wrong day — a day that then dates each decision
     * and, through {@see DecisionNumbers}, chooses the year its NUMBER is minted
     * under.
     *
     * @return array<string, mixed> The held meeting.
     *
     * @throws ConveningRejectedException
     */
    public function hold(int $tenantId, int $meetingId, ?string $heldAt): array
    {
        $meeting = $this->requireMeeting($tenantId, $meetingId);

        if (!MeetingStatus::canHold((string) $meeting['status'])) {
            throw ConveningRejectedException::because(
                'A meeting that is "' . (string) $meeting['status'] . '" cannot be held. A meeting '
                . 'that has already been held stays held — a body that met again held a new meeting.'
            );
        }

        $stamp = DecisionRecorder::normalizeTimestamp(
            // Defaulting to NOW only when the caller says nothing at all, which
            // is the "we are minuting this as it finishes" case and the one time
            // the server clock is the right answer.
            $heldAt ?? date('Y-m-d H:i:s'),
            'held_at'
        );

        $this->meetings->hold($tenantId, $meetingId, $stamp);

        return $this->requireMeeting($tenantId, $meetingId);
    }

    /**
     * @return array<string, mixed> The cancelled meeting.
     *
     * @throws ConveningRejectedException
     */
    public function cancel(int $tenantId, int $meetingId): array
    {
        $meeting = $this->requireMeeting($tenantId, $meetingId);

        if (!MeetingStatus::canCancel((string) $meeting['status'])) {
            throw ConveningRejectedException::because(
                'A meeting that is "' . (string) $meeting['status'] . '" cannot be cancelled. A '
                . 'meeting that happened cannot be un-happened.'
            );
        }

        $this->meetings->cancel($tenantId, $meetingId);

        return $this->requireMeeting($tenantId, $meetingId);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConveningRejectedException
     */
    private function requireBody(int $tenantId, int $bodyId): array
    {
        $body = $this->bodies->find($tenantId, $bodyId);
        if ($body === null) {
            throw ConveningRejectedException::because('That convening body does not exist in this tenant.');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConveningRejectedException
     */
    private function requireMeeting(int $tenantId, int $meetingId): array
    {
        $meeting = $this->meetings->find($tenantId, $meetingId);
        if ($meeting === null) {
            throw ConveningRejectedException::because('That meeting does not exist in this tenant.');
        }

        return $meeting;
    }
}
