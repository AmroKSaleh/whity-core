<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * In what capacity somebody was in the room.
 *
 * THREE VALUES, AND NONE OF THEM AUTHORIZES ANYTHING
 * --------------------------------------------------
 * This is a DESCRIPTION of why a person was present, in the same sense that
 * {@see MemberRole} is a seat rather than a permission. Nothing in the platform
 * branches on it: it is not a vote weight, it is not an input to any count that
 * claims to be a quorum, and holding `member` here grants nothing that holding
 * `guest` does not.
 *
 * It exists because an attendance list on which a substitute is
 * indistinguishable from a member cannot answer the question anybody actually
 * asks it afterwards — "was this body properly constituted when it decided
 * that?" — and a reader forced to guess will guess that everybody listed was a
 * member.
 *
 * WHY `guest` IS ONE VALUE AND NOT FOUR
 * -------------------------------------
 * A co-opted expert, an observer from another unit, a secretariat officer
 * taking the minute and somebody's accompanying adviser are all, to this
 * record, the same fact: present, not a member, not standing in for one. The
 * distinction between them is *why they were asked*, which is prose, and prose
 * belongs in `note` where it can be read rather than in a vocabulary every
 * client would then have to render four labels for. A value is worth adding to
 * this list when something would BEHAVE differently for it; none of those four
 * would.
 *
 * WHY THERE IS NO `absent`
 * ------------------------
 * Attendance records presence. Absence is the invited set minus the attended
 * set, derived at read time, and storing it as well would give one fact two
 * homes that can disagree — the objection migration 108 makes to a materialised
 * document status. Somebody's APOLOGY is already held: it is `declined` on their
 * invitation ({@see InvitationStatus}), which is a different and earlier fact.
 */
final class AttendanceCapacity
{
    /** Sits on the body, and was there in that right. */
    public const MEMBER = 'member';

    /** Attended in place of a member who could not. */
    public const SUBSTITUTE = 'substitute';

    /** Present without a seat: co-opted, observing, or taking the minute. */
    public const GUEST = 'guest';

    /**
     * The capacity assumed when a caller does not say.
     *
     * `member` rather than `guest`, because the overwhelming majority of an
     * attendance list is the body's own membership, and a default of `guest`
     * would mean the ordinary case is the one somebody has to type. What makes
     * that safe is that the default is only ever a LABEL — it grants nothing,
     * and it is not consulted by any count.
     */
    public const DEFAULT = self::MEMBER;

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::MEMBER, self::SUBSTITUTE, self::GUEST];
    }

    public static function isValid(string $capacity): bool
    {
        return in_array($capacity, self::all(), true);
    }
}
