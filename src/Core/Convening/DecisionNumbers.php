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
 *
 * ALL OF THE ABOVE IS THE FALLBACK. THE INSTITUTION'S OWN NUMBER WINS
 * -------------------------------------------------------------------
 * A decision number is how a reviewer, years later, verifies that a decision
 * was real: they take the number off a letter and look it up in the body's
 * minute book. The minute book is kept by the institution, in the
 * institution's format, and its numbers are assigned BY HAND — often weeks
 * after the sitting, by whoever types the minute. A number this system invented
 * appears in no minute book and verifies nothing.
 *
 * So {@see \Whity\Core\Convening\DecisionRecorder::record()} accepts one, and
 * uses it verbatim when it is given. The counter above stays as the answer for
 * the caller who has nothing to supply — a deployment with no separate minute
 * book, an import, an integration — because the alternative to a generated
 * number is not "no number", it is a NULL in the column every screen quotes.
 *
 * WHAT A SUPPLIED NUMBER IS ALLOWED TO LOOK LIKE: anything. `CE-CM-2026-014`,
 * `ق.ع/٢٠٢٦/١٤`, `14/2026`, `Res. 14 (2026)`. {@see validateSupplied()} bounds
 * the LENGTH and refuses characters that are not text; it deliberately imposes
 * no shape, because every institution that numbers decisions by hand has
 * already chosen one, and a platform that insisted on its own would be asking
 * them to keep two.
 */
final class DecisionNumbers
{
    /**
     * Longest decision number the column holds, in CHARACTERS.
     *
     * `meeting_decisions.decision_number` is VARCHAR(64), and PostgreSQL counts
     * a varchar's width in characters rather than bytes. Measured the same way
     * here — with `mb_strlen`, not `strlen` — because a byte bound would refuse
     * a 40-character Arabic number that fits the column perfectly well, and the
     * Arabic case is not an edge case on this platform.
     */
    public const MAX_LENGTH = 64;

    public function __construct(private readonly SequenceAllocator $sequences)
    {
    }

    /**
     * Accept a number the institution supplied, or refuse it.
     *
     * WHAT IS REFUSED, AND WHY EACH ONE
     * ---------------------------------
     *  - EMPTY. A blank number is not a number, and storing one would put a
     *    decision in the minute-book under nothing. A caller who has no number
     *    yet omits the field entirely and gets an allocated one.
     *  - TOO LONG for the column. Refused here as a sentence rather than at the
     *    column as a PostgreSQL 22001, which surfaces as a 500 and tells the
     *    person who typed it nothing.
     *  - C0/C1 CONTROL CHARACTERS, including newline and tab. A number is one
     *    line quoted inside other text; a newline in it corrupts every letter,
     *    CSV and log line it is ever pasted into, and a tab is invisible in the
     *    one place it matters.
     *  - INVALID UTF-8. Not text, and it would come back out of the column as
     *    something nobody can quote.
     *
     * WHAT IS DELIBERATELY NOT REFUSED
     * --------------------------------
     *  - ANY PARTICULAR SHAPE. No separator is required, no year, no body code,
     *    no digits-only rule. See the class docblock.
     *  - FORMAT CHARACTERS (`\p{Cf}`). U+200F RIGHT-TO-LEFT MARK and U+061C
     *    ARABIC LETTER MARK are how a mixed Arabic/Latin string is made to
     *    render in the order its author meant. Refusing them would reject a
     *    number that displays correctly in favour of one that displays
     *    backwards, on a platform where Arabic support is a requirement.
     *  - ARABIC-INDIC DIGITS. `٢٠٢٦` is the year 2026 written the way the
     *    institution writes it. Any rule expressed over `0-9` would refuse the
     *    real input this feature exists to accept.
     *
     * Leading and trailing whitespace is TRIMMED rather than refused — it is a
     * paste artefact, not a decision about the number — and the interior is
     * left exactly as typed.
     *
     * @throws ConveningRejectedException
     */
    public static function validateSupplied(string $raw): string
    {
        $number = trim($raw);

        if ($number === '') {
            throw ConveningRejectedException::because(
                'decision_number was sent but is empty. Omit it entirely to have one allocated from '
                . "this body's sequence, or send the number the minute book actually carries."
            );
        }

        // `!== 0` and not `=== 1`: preg_match returns false on a subject that is
        // not valid UTF-8, and a byte sequence that is not text must be refused
        // rather than fall through the "no match" branch into the column.
        if (preg_match('/\p{Cc}/u', $number) !== 0) {
            throw ConveningRejectedException::because(
                'decision_number contains control characters or is not valid text. A decision number '
                . 'is one line that gets quoted inside letters and exports; a newline or a tab in it '
                . 'corrupts every one of them.'
            );
        }

        if (mb_strlen($number) > self::MAX_LENGTH) {
            throw ConveningRejectedException::because(
                'decision_number must be ' . self::MAX_LENGTH . ' characters or fewer; that one is '
                . mb_strlen($number) . '.'
            );
        }

        return $number;
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
     * If a supplied number looks like one this body's counter could later mint,
     * move the counter past it.
     *
     * THE BUG THIS EXISTS TO PREVENT
     * ------------------------------
     * A secretary types `standards-board/2026/14` from the minute book. The
     * counter for that body and year is at 3. Eleven allocations later the
     * counter reaches 14 and hands out a string that already exists — and the
     * caller who gets it did nothing wrong, supplied nothing, and receives a
     * refusal (or, without the unique index, a duplicate) for somebody else's
     * typing. That is the sequence and the minute book DESYNCHRONISED: two
     * decisions with one number, and the one that surfaces the problem is the
     * innocent one.
     *
     * So a supplied number is checked against the shape this body's counter
     * PRODUCES for that year, and when it matches, the counter is advanced past
     * its sequence. After that the automatic series cannot reach it.
     *
     * WHY ONLY THE CANONICAL SHAPE
     * ----------------------------
     * Because that is the only shape the counter can ever produce. A number like
     * `CE-CM-2026-014` is not reachable by {@see allocate()} for any counter
     * value, so there is nothing to reserve and nothing to collide with — the
     * per-tenant unique index remains the guard against two HAND-TYPED numbers
     * clashing, which is a different problem with a different answer (a refusal
     * the person can act on). Trying to divine "the 14 inside this string" from
     * an arbitrary institutional format would mean guessing, and a wrong guess
     * would burn eleven numbers out of a real series for no reason.
     *
     * WHY IT IS NOT EXACT, AND WHY THAT IS FINE
     * -----------------------------------------
     * This is a `peek()` followed by a `next()`, which is a read-then-write and
     * therefore racy — the construction {@see SequenceAllocator} exists to
     * discourage. It is used here anyway, deliberately, because the race can
     * only ever OVERSHOOT: `next()` is atomic and only ever advances, so two
     * concurrent reservations both land above the floor and the worst outcome is
     * a gap. Gaps are what the allocator promises (unique and monotonic, not
     * gapless) and what the minute-book trade already accepts. What cannot
     * happen is the counter ending up below the floor, which is the only outcome
     * that would matter.
     *
     * Participates in the caller's transaction, so a decision that rolls back
     * takes its reservation with it.
     */
    public function reserveIfAllocatable(int $tenantId, string $bodyKey, int $year, string $number): void
    {
        $sequence = self::sequenceOf($number, $bodyKey, $year);
        if ($sequence === null) {
            return;
        }

        $counter = self::counterName($bodyKey, $year);
        $current = $this->sequences->peek($tenantId, $counter);

        if ($current >= $sequence) {
            return;
        }

        $this->sequences->next($tenantId, $counter, $sequence - $current);
    }

    /**
     * The sequence inside a number, IF that number is one this body's counter
     * could have produced for this year — otherwise null.
     *
     * Exact-match on `{body_key}/{year}/{digits}`, the shape {@see allocate()}
     * writes, with the body key and the year required to be THIS body's and THIS
     * year's. A number belonging to another body is not this counter's business,
     * and neither is one from another year: they are different counters.
     *
     * ASCII digits only, and that is not an oversight. `allocate()` builds its
     * sequence with `sprintf('%d')`, which emits `14` and never `١٤`, so an
     * Arabic-Indic numeral in this position proves the string did NOT come from
     * the counter and cannot collide with it. Public because it is the whole
     * content of the claim above, and a claim that can only be tested through
     * two other methods is a claim nothing tests.
     */
    public static function sequenceOf(string $number, string $bodyKey, int $year): ?int
    {
        $prefix = sprintf('%s/%d/', $bodyKey, $year);

        if (!str_starts_with($number, $prefix)) {
            return null;
        }

        $tail = substr($number, strlen($prefix));

        // No leading zeros, and no zero: the counter's first value is 1 and
        // `allocate()` emits `14`, never `014`. Both of those are hand-typed
        // numbers that merely RESEMBLE an allocated one, and reserving against
        // `014` would burn fourteen numbers out of a live series for a string
        // the counter can never produce.
        if (preg_match('/^[1-9]\d*$/', $tail) !== 1) {
            return null;
        }

        return (int) $tail;
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
