<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use Throwable;
use Whity\Core\Notification\NotificationDispatcher;

/**
 * Telling people about a sitting.
 *
 * DELIBERATELY THIN, and the thinness is the design — the same argument
 * {@see \Whity\Core\Document\Routing\RoutingNotifications} makes one subsystem
 * over. This is not a mailer and it is not a channel. It works out what to say
 * and hands each message to {@see NotificationDispatcher}, which has owned
 * per-channel delivery, retry, templating, per-tenant template overrides and
 * per-user preferences since long before convening existed. There is no new mail
 * code in this subsystem and there must not be: a second delivery path is a
 * second place that silently stops working.
 *
 * TWO TYPES, NOT ONE WITH A FLAG
 * ------------------------------
 * `convening.meeting.invited` — "you are asked to a sitting, please answer".
 * `convening.meeting.updated` — "a sitting you are coming to has moved".
 *
 * They are separate types for the reason preference keys make them separate:
 * preferences are keyed on `(type, channel)`, so two types are what lets a person
 * keep the message that asks them for an answer and mute the one that tells them
 * a room changed. One type carrying a discriminator inside `data` could not be
 * muted separately, because the preference layer never opens `data`.
 *
 * NEITHER IS TRANSACTIONAL. `convening.` is not in
 * {@see \Whity\Core\Notification\NotificationPreferenceResolver::DEFAULT_TRANSACTIONAL_PREFIXES},
 * which is correct: a committee invitation is not a password reset, and a person
 * who has turned off e-mail for meetings has said something the platform should
 * honour. The INVITATION RECORD is unaffected either way — the row still says
 * they were invited, because being invited and being notified are different
 * facts and only the first is the organisation's record.
 *
 * CORE SHIPS NO TEMPLATE FOR EITHER TYPE, on purpose. The inline wording below is
 * a fallback the renderer uses only until a tenant writes its own
 * `notification_templates` row, and an operator's phrasing for a committee
 * summons is a decision about their own institution — a seeded English sentence
 * would be the thing they had to discover and undo.
 *
 * EVERY FAILURE IS SWALLOWED AND LOGGED. An invitation that could not be
 * announced is still an invitation: the row is written, the person can find the
 * sitting, and turning a mail failure into a 500 would tell the secretary the
 * invitations did not go out when most of them did.
 */
final class MeetingNotifications
{
    /** "You are asked to a sitting." */
    public const TYPE_INVITED = 'convening.meeting.invited';

    /** "A sitting you are coming to has moved." */
    public const TYPE_UPDATED = 'convening.meeting.updated';

    public function __construct(private readonly NotificationDispatcher $notifications)
    {
    }

    /**
     * Announce a sitting to the people who were newly invited to it.
     *
     * @param list<int>             $profileIds Exactly the people hearing about
     *        this for the first time — {@see InvitationRepository::invite()}
     *        returns that set rather than the set it was asked about, so
     *        re-sending invitations after one person joins does not mail the
     *        whole body again.
     * @param array<string, mixed>  $meeting    A normalized meetings row.
     * @param array<string, mixed>  $body       A normalized convening_bodies row.
     */
    public function announceInvitations(int $tenantId, array $profileIds, array $meeting, array $body): void
    {
        $this->announce($tenantId, $profileIds, $meeting, $body, self::TYPE_INVITED);
    }

    /**
     * Tell people already invited that the sitting has moved.
     *
     * @param list<int>            $profileIds
     * @param array<string, mixed> $meeting
     * @param array<string, mixed> $body
     */
    public function announceReschedule(int $tenantId, array $profileIds, array $meeting, array $body): void
    {
        $this->announce($tenantId, $profileIds, $meeting, $body, self::TYPE_UPDATED);
    }

    /**
     * @param list<int>            $profileIds
     * @param array<string, mixed> $meeting
     * @param array<string, mixed> $body
     */
    private function announce(
        int $tenantId,
        array $profileIds,
        array $meeting,
        array $body,
        string $type
    ): void {
        if ($profileIds === []) {
            return;
        }

        // ONE string out of a locale map, through the subsystem's single
        // implementation of that choice. A subject line has no viewer to ask.
        $bodyName = LocalizedText::preferred(
            $body['name'] ?? null,
            ConveningBodyRepository::FALLBACK_LOCALE,
            'A convening body'
        );
        $title = LocalizedText::preferred(
            $meeting['title'] ?? null,
            MeetingRepository::FALLBACK_LOCALE,
            'A meeting'
        );
        $when = is_string($meeting['scheduled_at'] ?? null) ? (string) $meeting['scheduled_at'] : null;
        $where = is_string($meeting['location'] ?? null) ? (string) $meeting['location'] : null;

        $subject = $type === self::TYPE_INVITED
            ? $bodyName . ': ' . $title
            : $bodyName . ': ' . $title . ' has changed';

        $lines = [
            $type === self::TYPE_INVITED
                ? 'You are invited to a meeting of ' . $bodyName . '.'
                : 'A meeting of ' . $bodyName . ' you were invited to has been rescheduled.',
        ];
        if ($when !== null) {
            $lines[] = 'When: ' . $when;
        }
        if ($where !== null) {
            $lines[] = 'Where: ' . $where;
        }
        $lines[] = 'Please accept or decline so the chair knows who to expect.';

        foreach ($profileIds as $profileId) {
            try {
                $this->notifications->dispatch($tenantId, $profileId, $type, [
                    'subject' => $subject,
                    'body' => implode("\n", $lines),
                    // Enough for a client to open the right screen without a
                    // second request, and nothing that is not already on the
                    // wire elsewhere.
                    'data' => [
                        'meeting_id' => (int) ($meeting['id'] ?? 0),
                        'body_id' => (int) ($body['id'] ?? 0),
                        'body_key' => (string) ($body['body_key'] ?? ''),
                        'scheduled_at' => $when,
                        'location' => $where,
                    ],
                ]);
            } catch (Throwable $e) {
                // FAIL-SOFT, per recipient. One unreachable person must never
                // abort the announcement to the rest of the body — that is the
                // failure mode where a secretary believes nobody was told
                // because one address bounced.
                error_log(
                    '[MeetingNotifications] announcing ' . $type . ' to profile ' . $profileId
                    . ' failed: ' . $e->getMessage()
                );
            }
        }
    }
}
