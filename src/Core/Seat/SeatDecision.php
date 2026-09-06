<?php

declare(strict_types=1);

namespace Whity\Core\Seat;

/**
 * The answer to "may this tenant take one more person", and the numbers behind
 * it.
 *
 * WHY IT IS NOT A BOOLEAN. Three outcomes matter and two of them allow the
 * addition: `off` is not counting at all, `warn` counted and the tenant is at
 * its limit, `block` refuses. A caller that only asked "allowed?" could not tell
 * the first two apart, so the tenant that has quietly run out of seats would
 * look exactly like the tenant whose instance does not sell them — and nobody
 * would ever be told to buy more.
 *
 * The numbers travel with the answer for the same reason a refusal has to be
 * actionable: "you have used 50 of 50 seats" is a sentence somebody can do
 * something about, and "seat limit reached" is not.
 */
final class SeatDecision
{
    private function __construct(
        /** Whether the addition may proceed. */
        public readonly bool $allowed,
        /** True when the tenant is AT or OVER its limit, however it was resolved. */
        public readonly bool $atLimit,
        /** The effective enforcement mode this was decided under. */
        public readonly string $mode,
        /** Seats bought, or -1 for unlimited / not counted. */
        public readonly int $limit,
        /** Seats held at the moment of the decision. */
        public readonly int $used,
    ) {
    }

    public static function allowed(string $mode, int $limit, int $used): self
    {
        return new self(true, false, $mode, $limit, $used);
    }

    /** At the limit, but the instance is not refusing additions. */
    public static function warned(string $mode, int $limit, int $used): self
    {
        return new self(true, true, $mode, $limit, $used);
    }

    public static function blocked(string $mode, int $limit, int $used): self
    {
        return new self(false, true, $mode, $limit, $used);
    }

    /**
     * A refusal a person can act on: what the limit is and what is using it.
     *
     * Deliberately free of an instruction to upgrade. Who sells seats, and
     * whether more can be bought at all, differs per deployment — a sovereign
     * instance with a fixed allocation has nobody to upgrade with, and telling
     * its administrator to buy more would be advice that goes nowhere.
     */
    public function message(): string
    {
        return "This tenant is using {$this->used} of its {$this->limit} seats.";
    }
}
