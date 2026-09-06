<?php

declare(strict_types=1);

namespace Whity\Core\Promotion;

/**
 * Whether a promotion may be used here and now — and, when not, WHY.
 *
 * THE REASON IS THE POINT. "That code is not valid" is the message every badly
 * built checkout gives, and it is the same sentence for a code that never
 * existed, one that expired last week, one the tenant has already used, one that
 * ran out, and one that simply does not apply to the plan being bought. Those
 * need five different things from the person reading them, and four of them are
 * recoverable.
 *
 * The reasons are a closed set rather than free text so a caller can decide what
 * to show and what to keep to itself. `NOT_FOUND` and `INACTIVE` are deliberately
 * indistinguishable to a customer — telling a stranger that a code exists but is
 * switched off is an invitation to guess at more of them — but an operator
 * screen wants them apart, so the distinction is preserved here and flattened at
 * the boundary rather than thrown away early.
 */
final class PromotionEligibility
{
    /** No promotion with that code, or none automatic. */
    public const NOT_FOUND = 'not_found';
    /** Exists, switched off. */
    public const INACTIVE = 'inactive';
    /** Its window has not opened. */
    public const NOT_STARTED = 'not_started';
    /** Its window has closed. */
    public const EXPIRED = 'expired';
    /** The overall allocation is gone — the early bird's usual ending. */
    public const FULLY_REDEEMED = 'fully_redeemed';
    /** This tenant has taken it as often as it is allowed to. */
    public const ALREADY_REDEEMED = 'already_redeemed';
    /** Real, live, available — but not for the plan being bought. */
    public const PLAN_NOT_ELIGIBLE = 'plan_not_eligible';
    /**
     * A fixed-amount promotion in one currency, against a price in another.
     *
     * Refused rather than converted: converting needs a rate nobody stored and
     * that has since moved, and a discount that quietly became a different
     * amount of money is worse than one that did not apply.
     */
    public const CURRENCY_MISMATCH = 'currency_mismatch';

    /**
     * @param string|null               $reason    One of the constants above, or
     *                                             null when eligible.
     * @param array<string, mixed>|null $promotion The promotion row, present
     *                                             only when eligible.
     */
    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $reason,
        public readonly ?array $promotion,
    ) {
    }

    /** @param array<string, mixed> $promotion */
    public static function yes(array $promotion): self
    {
        return new self(true, null, $promotion);
    }

    public static function no(string $reason): self
    {
        return new self(false, $reason, null);
    }

    /**
     * Whether a CUSTOMER should be told the promotion exists at all.
     *
     * False for the two reasons that would confirm a guess: a code that is not
     * there and one that is switched off look the same from outside, so
     * somebody probing for codes learns nothing from either.
     */
    public function isDisclosableToCustomer(): bool
    {
        return $this->reason !== self::NOT_FOUND && $this->reason !== self::INACTIVE;
    }
}
