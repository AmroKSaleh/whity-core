<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * What a {@see DocumentView} answers when asked to resolve for one caller
 * (#978): either the filter to run, or the reason this particular person cannot
 * anchor it.
 *
 * THE DISTINCTION THIS TYPE EXISTS TO KEEP
 * ----------------------------------------
 * There are three ways a folder can fail to show documents, and conflating any
 * two of them is the bug #978 is about:
 *
 *  1. THE FACT SOURCE DOES NOT EXIST. "Awaiting me" cannot be computed on an
 *     installation that has not run migration 112, because there are no
 *     recipient rows to read. The view is ABSENT — it never reaches this type,
 *     because {@see DocumentViewRegistry} filters it out. Rendering it empty
 *     would state "nothing awaits you", which is false and which the reader has
 *     no way to check.
 *
 *  2. THIS CALLER CANNOT ANCHOR IT. "Raised by my unit" is perfectly
 *     computable; this particular person belongs to no unit. That is {@see
 *     unanchored()}, and it is rendered as a DISABLED control carrying the
 *     reason — the house position from #951, where a hidden control made three
 *     unrelated causes look identical. Hiding it would leave someone unable to
 *     tell "I have no unit" from "the feature is gone".
 *
 *  3. THE ANSWER IS GENUINELY EMPTY. The view resolved, the query ran, nothing
 *     matched. That is a normal empty state (#756) and needs no special type.
 *
 * A single "returns nothing" would collapse all three into the one message that
 * is wrong for at least two of them.
 *
 * Immutable — worker-safe.
 */
final class DocumentViewResolution
{
    private function __construct(
        public readonly ?DocumentCriteria $criteria,
        public readonly ?string $unavailableReason,
    ) {
    }

    /** The view resolved: run this filter. */
    public static function of(DocumentCriteria $criteria): self
    {
        return new self($criteria, null);
    }

    /**
     * The view is computable in principle but not for THIS caller, for the
     * given reason.
     *
     * The reason is written for the person reading it — "You do not belong to
     * an organizational unit" — not for a log, because it is going on the
     * screen next to the control it disables.
     */
    public static function unanchored(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isAvailable(): bool
    {
        return $this->criteria !== null;
    }
}
