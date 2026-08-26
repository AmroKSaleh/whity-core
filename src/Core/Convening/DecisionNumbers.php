<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Where a decision's human-readable number comes from.
 *
 * THE FORMAT
 * ----------
 *     {body_key}/{year}/{sequence}        e.g.  standards-board/2026/14
 *
 * Three parts because a decision number is quoted OUTSIDE this system — in
 * correspondence, in a covering letter, in a phone call — and each part answers a
 * question the reader will otherwise have to ask. Which body took it, when, and
 * which one it was. A bare integer would be unambiguous to the database and
 * useless to a person holding a piece of paper.
 *
 * WHY THE SEQUENCE COMES FROM `sequence_counters` AND NOT FROM `MAX(seq) + 1`
 * ---------------------------------------------------------------------------
 * Core has exactly one implementation of "hand out the next number"
 * ({@see \Whity\Database\SequenceCounters}), and it is already tenant-scoped,
 * already cascade-deleted with the tenant, and already the subject of the
 * argument this class would otherwise have to have again:
 *
 *  - `SELECT MAX(...) + 1` is a READ followed by a WRITE, and two requests that
 *    read before either writes both get the same number. On a platform running
 *    eight FrankenPHP workers that is not a rare race, it is a busy Tuesday.
 *  - The counter's allocation is ONE statement (`INSERT … ON CONFLICT DO UPDATE
 *    … RETURNING`), so two callers cannot both receive the same value.
 *  - It is UNIQUE AND MONOTONIC, NOT GAPLESS, and the interface says so at
 *    length. A rolled-back decision burns a number. That is the right trade for
 *    a minute-book: a missing number is a curiosity somebody can explain, and two
 *    decisions numbered 14 is a defect nobody can.
 *
 * WHY THE COUNTER IS PER BODY AND PER YEAR
 * ----------------------------------------
 * Per BODY because two bodies each taking their fourteenth decision is the
 * ordinary case, and a tenant-wide counter would make one body's numbering leap
 * whenever an unrelated body met.
 *
 * Per YEAR because the year is already IN the number, and a sequence that did not
 * restart would produce `standards-board/2027/312` — a string whose middle and
 * last parts disagree about how much has happened. Restarting is also what every
 * institution that numbers decisions this way actually does.
 *
 * WHICH YEAR: the year of the DECISION, taken from the timestamp the caller is
 * recording it under, never from `date('Y')` at the moment the code runs. A body
 * minuting December's meeting in January must produce December's numbers, and a
 * server clock consulted independently of the record would silently produce
 * January's.
 */
final class DecisionNumbers
{
    public function __construct(private readonly SequenceAllocator $sequences)
    {
    }

    /**
     * Allocate the next decision number for a body in a given year.
     *
     * Participates in whatever transaction the caller has open — which is
     * deliberate and is why {@see DecisionRecorder} allocates inside the same
     * transaction that writes the row: a decision that fails to record must not
     * leave its number pointing at nothing that exists.
     *
     * @param string $bodyKey  The body's immutable key.
     * @param int    $year     The year of the decision, from its own timestamp.
     */
    public function allocate(int $tenantId, string $bodyKey, int $year): string
    {
        $sequence = $this->sequences->next($tenantId, self::counterName($bodyKey, $year));

        return sprintf('%s/%d/%d', $bodyKey, $year, $sequence);
    }

    /**
     * The counter this body's numbers for this year come from.
     *
     * Public because it is the name an operator will look for in
     * `sequence_counters` when asked why numbering jumped, and a name that only
     * exists inside a private method is a name nobody can find.
     *
     * Keyed on the body KEY rather than its id so the row reads as what it is in
     * a database dump. The key is immutable
     * ({@see ConveningBodyRepository::update()}), which is what makes that safe:
     * a renameable key would leave the old counter stranded and restart numbering
     * from 1 under the new name.
     *
     * COLON-separated because {@see \Whity\Database\SequenceCounters} constrains
     * a counter name to lowercase letters, digits, underscore, colon and hyphen —
     * the name is half a primary key, so a dotted one is refused rather than
     * stored.
     */
    public static function counterName(string $bodyKey, int $year): string
    {
        return sprintf('convening:decision:%s:%d', $bodyKey, $year);
    }

    /**
     * The year to number a decision under, from the timestamp it is recorded at.
     *
     * Falls back to the current year only when the timestamp cannot be read at
     * all, which is a state the callers above already refuse — the fallback
     * exists so this method has no failure mode of its own, not as a path
     * anything is expected to take.
     */
    public static function yearOf(string $decidedAt): int
    {
        $timestamp = strtotime($decidedAt);

        return $timestamp === false ? (int) date('Y') : (int) date('Y', $timestamp);
    }
}
