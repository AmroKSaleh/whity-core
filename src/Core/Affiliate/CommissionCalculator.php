<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use DateTimeImmutable;

/**
 * What an affiliate earns on one payment — the whole of the arithmetic, alone.
 *
 * Separated from the sweep that uses it because this is the part somebody will
 * one day check by hand against a statement, and it should be possible to read
 * every rule in one place without a database in the way.
 *
 * ── Integer arithmetic, all the way down ───────────────────────────────────
 *
 * Amounts are minor units and the rate is BASIS POINTS (2000 = 20%), so nothing
 * here touches a float. The Jordanian dinar has three decimal places; a rate
 * held as 0.2 and multiplied by 15000 is a rounding argument waiting to happen
 * with a person about their own money.
 *
 * ── It rounds DOWN, deliberately ───────────────────────────────────────────
 *
 * `intdiv` truncates, so a fractional fils is kept rather than paid. That is a
 * choice, not an accident: rounding up pays out money that was never collected,
 * and across thousands of small payments the difference accrues in the
 * direction nobody budgeted for. The most it can ever cost an affiliate is one
 * minor unit per payment, and the rule is stated rather than discovered.
 */
final class CommissionCalculator
{
    /** 100% in basis points. */
    public const FULL_RATE_BP = 10000;

    /**
     * The commission on one payment, in the payment's own currency.
     *
     * @param int $baseMinor The invoice after discounts, excluding tax. Tax is
     *                       not income and is not ours to share.
     * @param int $rateBp    The affiliate's rate at the time this was earned.
     */
    public static function amountFor(int $baseMinor, int $rateBp): int
    {
        // A NEGATIVE OR ZERO BASE EARNS NOTHING rather than producing a negative
        // commission. A credit note or a fully discounted invoice is not a debt
        // owed by the affiliate — clawing money back is what a REVERSAL is for,
        // and it is an explicit act against a specific earlier payment, not a
        // side effect of arithmetic on a strange invoice.
        if ($baseMinor <= 0 || $rateBp <= 0) {
            return 0;
        }

        // Capped at the base. A rate above 100% is a configuration error, and
        // paying out more than the customer paid is the kind of mistake that is
        // only noticed once the money has gone.
        $rate = min($rateBp, self::FULL_RATE_BP);

        return intdiv($baseMinor * $rate, self::FULL_RATE_BP);
    }

    /**
     * When a referral's earning window closes.
     *
     * FROM THE FIRST PAYMENT, not from the referral. A referrer whose customer
     * takes two months to convert is owed twelve months of revenue, not ten —
     * they did the same work either way, and the delay was the customer's.
     */
    public static function windowEnd(DateTimeImmutable $firstPaidAt, int $windowMonths): DateTimeImmutable
    {
        // `modify` rather than second arithmetic: months are not a fixed length,
        // and a window opened on 31 January must not close on 3 March.
        return $firstPaidAt->modify('+' . max(1, $windowMonths) . ' months');
    }

    /**
     * Whether a payment falls inside the window.
     *
     * INCLUSIVE OF THE BOUNDARY. A payment taken at the exact instant the window
     * closes is inside it: the alternative is telling an affiliate their last
     * month did not count because a renewal ran a second late, which is both
     * unfair and impossible for them to verify.
     */
    public static function isWithinWindow(DateTimeImmutable $paidAt, DateTimeImmutable $windowEnd): bool
    {
        return $paidAt <= $windowEnd;
    }
}
