<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

/**
 * WHICH PERIOD DOES THIS BELONG TO (#1070).
 *
 * Two operations, and the difference between them is the whole answer to the
 * assignment question:
 *
 *   {@see resolve()}  — DERIVE the period from a date.
 *   {@see validate()} — CHECK a period somebody named.
 *
 * WHY THERE IS NO "OVERRIDE"
 * --------------------------
 * The issue this implements assumed an override would be needed, for the case
 * where a record's system time and its true period differ. That case is real and
 * an override is the wrong instrument for it, because the two things it conflates
 * are separable:
 *
 *  - "the clock says Tuesday, the record belongs to Monday" is answered by
 *    resolving from an EFFECTIVE DATE that a person set, not from `now()`. This
 *    class never reads the clock; the date is always a parameter. Late filing is
 *    then ordinary resolution over the right input, and it needs no override at
 *    all — the honest fix is upstream, in whatever record carries the date.
 *  - "I am working in a period other than the one today falls in" is answered by
 *    the caller NAMING a period, which is {@see validate()}. That is not an
 *    override of a derivation; it is a different input to the same question, and
 *    it is checked rather than trusted.
 *
 * What is left over — a stored flag saying "this record's period was overridden"
 * — buys nothing and costs the one thing that matters. It would let a record
 * dated inside period A be filed into period B, which is the falsification the
 * whole design draws a line against, and it would make date resolution advisory:
 * every consumer would have to know that a period membership might disagree with
 * the dates, and roll-ups would stop being reproducible from the data.
 *
 * THE EVIDENCE, SINCE THIS OVERTURNS A STATED PREMISE
 * --------------------------------------------------
 * One implementation of this concept has run in production. It resolves purely
 * by date, from a caller-supplied date defaulting to today, and returns "no
 * particular period" when no period covers it — refusing, in as many words, to
 * invent one, because attributing work to the nearest period "silently files work
 * under a period it does not belong to". It also accepts an explicitly named
 * period, and that is the mechanism this class calls {@see validate()}. What it
 * has NO trace of is an override marker: no flag, no reason column, no audit of a
 * changed assignment anywhere. Where it does let a stored assignment be corrected
 * after the fact, the correction is untracked — which is a gap to close on the
 * RECORD side by recording the reassignment, not a reason to add an override on
 * the period side.
 *
 * NULL IS AN ANSWER
 * -----------------
 * {@see resolve()} returns null when no period of that kind covers the date, and
 * that is a legitimate result callers must handle. A tenant may not have defined
 * its periods yet, or there may be a genuine gap between two of them. Inventing
 * a nearest match would attribute records to a period they do not belong to, and
 * a wrong attribution is worse than an absent one because nothing reports it.
 *
 * WHY THIS IS A FUNCTION AND NOT A COLUMN
 * ---------------------------------------
 * Nothing in core stores a resolved period on a record. Periods of one kind do
 * not overlap, so the mapping from a date to a period is total and single-valued,
 * and a stored copy of a derivable fact is a second source of truth that goes
 * wrong when a boundary is corrected — the stored value keeps pointing at the old
 * period and nothing says so. A domain that needs the resolved id on its rows for
 * indexing may of course keep one; that is its choice, and it should re-derive
 * rather than trust it when a boundary moves.
 */
final class WindowResolver
{
    public function __construct(
        private readonly TimeWindowRepository $windows,
        private readonly WindowTypeRepository $types,
    ) {
    }

    /**
     * The period of a given kind containing a date, or null when none does.
     *
     * @param string $onDate `YYYY-MM-DD`. Always supplied by the caller: this
     *        class never consults the clock, so a record filed late resolves
     *        against the date it belongs to rather than the date it arrived.
     * @return array<string, mixed>|null
     *
     * @throws WindowRejectedException When the date is malformed or the kind is
     *         unknown to the tenant — both caller errors, distinct from the
     *         legitimate "no period covers this" null.
     */
    public function resolve(int $tenantId, int $windowTypeId, string $onDate): ?array
    {
        $onDate = TimeWindowRepository::normalizeDate($onDate, 'on');

        if ($this->types->find($tenantId, $windowTypeId) === null) {
            throw WindowRejectedException::because('window_type_id does not name a period kind in this tenant');
        }

        $matches = $this->windows->listForTenant($tenantId, $windowTypeId, null, $onDate);

        // At most one, because periods of one kind do not overlap. If the
        // invariant were ever violated this would silently pick the earliest —
        // so it does not: a second match is a bug in the write path and saying so
        // is more useful than answering.
        if (count($matches) > 1) {
            throw WindowRejectedException::because(sprintf(
                'More than one period of this kind contains %s, which should be impossible. '
                . 'Its boundaries need correcting before anything can be scoped to it.',
                $onDate
            ));
        }

        return $matches[0] ?? null;
    }

    /**
     * The period a caller NAMED, checked rather than trusted.
     *
     * Returns null when the id names nothing this tenant can see — which a
     * foreign tenant's id also does, indistinguishably, so the check cannot be
     * used to probe for the existence of another tenant's periods.
     *
     * @return array<string, mixed>|null
     */
    public function validate(int $tenantId, int $windowId): ?array
    {
        return $this->windows->find($tenantId, $windowId);
    }

    /**
     * The period a caller named, refusing it when it is sealed.
     *
     * The one composed helper worth having, because it is the check a domain
     * makes on an ordinary write: is this the period I was given, and may I still
     * write into it. Whether a domain applies this check to a record already
     * mid-flight when the period closes is deliberately its own decision — core
     * provides the question, not the policy.
     *
     * @return array<string, mixed>
     * @throws WindowRejectedException
     */
    public function requireOpen(int $tenantId, int $windowId): array
    {
        $window = $this->validate($tenantId, $windowId);
        if ($window === null) {
            throw WindowRejectedException::because('That period does not exist');
        }
        if ($window['state'] !== WindowState::OPEN) {
            throw WindowRejectedException::because(sprintf(
                "The period '%s' is closed.",
                (string) $window['label']
            ));
        }

        return $window;
    }
}
