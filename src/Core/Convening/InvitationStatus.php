<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * What an invited person has said about a sitting.
 *
 * FOUR VALUES, AND THE ABSENCE OF A FIFTH
 * ---------------------------------------
 * `invited` is the state an invitation is CREATED in and means "has not
 * answered". It is not a synonym for `declined`, and the distinction is the
 * reason `responded_at` is a separate nullable column: a chair counting heads
 * before a sitting needs to tell somebody who said no from somebody who has not
 * looked at their inbox, and those are different problems with different fixes.
 *
 * `tentative` is here because it is what people actually mean when they answer,
 * and a system offering only yes and no gets `no` from everybody who is not sure
 * — which is the same information loss as having no reply at all, except that it
 * looks like a definite answer.
 *
 * THERE IS NO `attended`. Attendance is what happened AT the sitting, not what
 * somebody said before it, and the two disagree constantly: people accept and do
 * not come, and people who declined turn up. Modelling both on one column would
 * mean the acceptance a chair planned around gets overwritten by the attendance
 * afterwards, and the planning record would be gone. If attendance is ever
 * wanted it is a second fact on the row, not a fifth value on this one.
 */
final class InvitationStatus
{
    /** Sent; no answer yet. NOT a decline. */
    public const INVITED = 'invited';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    /** Answered, without committing. A real answer, and not a failure to give one. */
    public const TENTATIVE = 'tentative';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::INVITED, self::ACCEPTED, self::DECLINED, self::TENTATIVE];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * The answers a person may give.
     *
     * `invited` is excluded: it is the state the system puts the row in, and a
     * person "answering" with it would be un-answering, which is a state change
     * nothing in the vocabulary means. Somebody who wants to take back an
     * acceptance answers `declined` or `tentative` — both of which are answers,
     * and both of which stamp `responded_at`.
     *
     * @return list<string>
     */
    public static function responses(): array
    {
        return [self::ACCEPTED, self::DECLINED, self::TENTATIVE];
    }

    public static function isResponse(string $status): bool
    {
        return in_array($status, self::responses(), true);
    }
}
