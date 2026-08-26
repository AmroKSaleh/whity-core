<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

/**
 * The closed vocabulary of A PERIOD'S STATE, and the acts that move between the
 * two (#1070).
 *
 * TWO STATES, CHECK-CONSTRAINED IN MIGRATION 126
 * ----------------------------------------------
 * A period is either accruing or sealed, and there is deliberately no third
 * value. Intermediate states are the first thing a status column grows — and
 * every one of them makes "closed" mean less, because a consumer then has to
 * know which of several nearly-closed states still admits a change. Whatever
 * intermediate condition a domain needs (a period that has stopped accepting new
 * records but is still being corrected, say) is a fact about that domain's
 * records, not about the period, and it belongs where those records are.
 *
 * A THIRD STATE IS A SCHEMA CHANGE, AND THAT IS THE POINT
 * ------------------------------------------------------
 * The values are constrained by an inline CHECK, which migration 119 established
 * cannot be widened on SQLite — the offline/desktop engine and the engine the
 * test schema is built on — without rebuilding the table. So adding a state
 * costs a migration and a conversation, which is the correct price for changing
 * what a seal means.
 *
 * STATE IS NOT THE SAME QUESTION AS A RECORD'S OWN READINESS
 * ---------------------------------------------------------
 * Conflating them produces a confusing model. A record has its own progress —
 * drafted, submitted, whatever its domain calls it — and that is about ONE
 * record's readiness. This is about THE PERIOD'S books. A record is normally
 * finished while its period is still open; the period closing is a separate,
 * later, coarser act performed by somebody else.
 *
 * WHAT CLOSING DOES AND DOES NOT DO HERE
 * --------------------------------------
 * Closing writes {@see CLOSED} and appends a {@see ACT_CLOSED} row to the trail.
 * It does not touch, freeze, cancel or rewrite any record scoped to the period,
 * and core takes no position on what a closed period forbids — see
 * {@see \Whity\Core\TimeWindow\TimeWindowRepository::close()} for why that
 * position is deliberately not taken here.
 */
final class WindowState
{
    /**
     * ACCRUING. Records may be filed into the period and corrected freely by
     * whoever their domain authorises. The ordinary state of a period that has
     * not been sealed.
     */
    public const OPEN = 'open';

    /**
     * SEALED. The books for this period are closed.
     *
     * Reversible, on the record — see {@see ACT_REOPENED}. "Closed" is therefore
     * a statement about the present, not a promise about the future, and the
     * trail is what tells you whether it has ever been otherwise.
     */
    public const CLOSED = 'closed';

    /**
     * The seal. Recorded with the actor, the moment, and an optional reason.
     *
     * A reason is optional here because closing a period on schedule is the
     * ordinary case and demanding a justification for the ordinary case trains
     * people to type nothing meaningful.
     */
    public const ACT_CLOSED = 'closed';

    /**
     * The unseal. Recorded with the actor, the moment, and a REQUIRED reason.
     *
     * Refusing reopening outright sounds safer and is not. An institution that
     * genuinely must correct a sealed period will do it anyway — in a
     * spreadsheet, or by editing the boundary dates so the period no longer
     * contains the record — and a workaround leaves no record at all. A reopen
     * that names who, when and why is strictly better than one that happens
     * somewhere this platform cannot see.
     */
    public const ACT_REOPENED = 'reopened';

    /**
     * Every state a period may be in, in lifecycle order.
     *
     * @return list<string>
     */
    public static function states(): array
    {
        return [self::OPEN, self::CLOSED];
    }

    /**
     * Every act the trail records, in lifecycle order.
     *
     * @return list<string>
     */
    public static function acts(): array
    {
        return [self::ACT_CLOSED, self::ACT_REOPENED];
    }

    public static function isState(string $value): bool
    {
        return in_array($value, self::states(), true);
    }

    public static function isAct(string $value): bool
    {
        return in_array($value, self::acts(), true);
    }

    /**
     * Static vocabulary only — never instantiated.
     */
    private function __construct()
    {
    }
}
