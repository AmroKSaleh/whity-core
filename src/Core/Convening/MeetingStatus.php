<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * Where a sitting has got to, and which moves are legal from there.
 *
 * FOUR STATES, AND THE TRANSITIONS ARE THE POINT
 * ----------------------------------------------
 *
 *   draft  ──schedule──▶ scheduled ──hold──▶ held
 *     │                      │                 │
 *     └──────cancel──────────┴─────────────────┘  (only from draft/scheduled)
 *
 * `draft` is a sitting somebody is BUILDING: it has an agenda accumulating on it
 * and no date. `scheduled` has a date and a place. `held` is a person asserting
 * the sitting took place, and it is where decisions become recordable.
 *
 * `held` IS TERMINAL. Nothing un-holds a meeting, because the decisions minuted
 * at it may already have advanced somebody's document through a routing chain,
 * and a state that could be walked back would leave the minute-book saying one
 * thing and the document trail another. A sitting recorded in error is corrected
 * the way the rest of this platform corrects things — by a further record, not by
 * an eraser.
 *
 * `cancelled` is reachable from `draft` and `scheduled` and from nowhere else,
 * for the same reason: a meeting that HAPPENED cannot be un-happened. It is a
 * state rather than a deletion because a called-off sitting is a fact the
 * minute-book needs, and deleting the row would take its agenda with it.
 *
 * WHY A DIRECT draft ─▶ held IS ALLOWED
 * -------------------------------------
 * Because it happens. A body that convenes at short notice and minutes what it
 * decided has held a meeting that was never scheduled, and refusing to record it
 * without first inventing a schedule for a sitting that is already over would
 * produce a `scheduled_at` nobody chose. See {@see canHold()}.
 */
final class MeetingStatus
{
    /** Accumulating an agenda. No date yet. */
    public const DRAFT = 'draft';

    /** Has a date and a place; invitations may go out. */
    public const SCHEDULED = 'scheduled';

    /** Took place. Decisions are recordable, and the state is terminal. */
    public const HELD = 'held';

    /** Called off before it happened. Terminal, and its agenda is kept. */
    public const CANCELLED = 'cancelled';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DRAFT, self::SCHEDULED, self::HELD, self::CANCELLED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }

    /**
     * Is the sitting still being ARRANGED — i.e. may its agenda be edited
     * without anybody being told they are amending a record?
     *
     * This is the predicate {@see AgendaRepository} consults, and the reason it
     * exists rather than each caller testing two constants is that "still open
     * for arrangement" is one idea with one answer, and a third arranging state
     * would otherwise have to be found in every call site.
     */
    public static function isOpenForAgenda(string $status): bool
    {
        return $status === self::DRAFT || $status === self::SCHEDULED;
    }

    public static function canSchedule(string $status): bool
    {
        // Re-scheduling an already-scheduled sitting is legal and ordinary — a
        // date moves. What is refused is scheduling one that is over or called
        // off.
        return $status === self::DRAFT || $status === self::SCHEDULED;
    }

    public static function canHold(string $status): bool
    {
        // From `draft` too: see the class docblock — a body that convened at
        // short notice held a meeting that was never scheduled, and the minute
        // must not require a fabricated date first.
        return $status === self::DRAFT || $status === self::SCHEDULED;
    }

    public static function canCancel(string $status): bool
    {
        return $status === self::DRAFT || $status === self::SCHEDULED;
    }

    /**
     * May who was in the room be recorded?
     *
     * ONLY FROM `held`, and this is the tightest predicate in the class.
     *
     * Attendance is a record of a sitting that HAPPENED. Taken before the
     * meeting it is not attendance at all — it is a guess about who will come,
     * and the platform already holds that under a name that says so
     * ({@see InvitationStatus::ACCEPTED}, which is a prediction). Allowing it on
     * a `scheduled` meeting would produce a list that is indistinguishable, on
     * every screen and in every export, from a list of people who actually
     * turned up; and because `held` is terminal and attendance is a
     * REPLACEMENT, nothing would ever force that guess to be revisited. The
     * minute would carry an attendance nobody took.
     *
     * `draft` for the same reason and more so — there is not even a date.
     *
     * `cancelled` is refused because a sitting that was called off had nobody
     * present at it. A body that met anyway held a meeting, and holding it is
     * the assertion being made; it is one call and it is the honest one.
     *
     * "AT OR AFTER hold" is what this permits, and the "at" half matters: the
     * hold and the attendance are two calls, not one, and a secretary minuting
     * a sitting as it finishes makes them seconds apart. There is deliberately
     * no window after which the list closes — an institution types its minute
     * weeks later, which is the same reason `held_at` and `decided_at` are
     * caller-supplied.
     */
    public static function canRecordAttendance(string $status): bool
    {
        return $status === self::HELD;
    }
}
