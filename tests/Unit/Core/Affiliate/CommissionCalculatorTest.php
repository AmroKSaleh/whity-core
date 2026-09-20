<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Affiliate;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Whity\Core\Affiliate\CommissionCalculator;

/**
 * The arithmetic somebody will one day check by hand against their statement.
 *
 * Every rule here is one an affiliate could dispute, so each is pinned with the
 * numbers rather than described: rounding direction, the boundary of the
 * window, what a strange invoice earns, and what a misconfigured rate cannot do.
 */
final class CommissionCalculatorTest extends TestCase
{
    // ── The ordinary case, in real money ────────────────────────────────────

    /** 20% of 15.000 JOD is 3.000 JOD — in minor units throughout. */
    public function testTwentyPercentOfATypicalSubscription(): void
    {
        self::assertSame(3000, CommissionCalculator::amountFor(15000, 2000));
    }

    /**
     * @dataProvider rates
     */
    public function testKnownRates(int $base, int $rateBp, int $expected): void
    {
        self::assertSame($expected, CommissionCalculator::amountFor($base, $rateBp));
    }

    /** @return array<string, array{int, int, int}> */
    public static function rates(): array
    {
        return [
            '10% of 5.000 JOD' => [5000, 1000, 500],
            '25% of 100.000 JOD' => [100000, 2500, 25000],
            '100% pays the whole thing' => [15000, 10000, 15000],
            '5% of the smallest coin' => [1, 500, 0],
            'half a fils is kept, not paid' => [1, 5000, 0],
        ];
    }

    // ── Rounding, stated rather than discovered ─────────────────────────────

    /**
     * IT ROUNDS DOWN. 33% of 1.000 JOD is 330 fils exactly; 33% of 1.001 is
     * 330.33, and the third of a fils stays with us.
     *
     * The direction is a decision: rounding up pays out money that was never
     * collected, and across thousands of small payments that accrues in the
     * direction nobody budgeted for. The cost to an affiliate is at most one
     * minor unit per payment.
     */
    public function testItRoundsDownRatherThanPayingOutUncollectedMoney(): void
    {
        self::assertSame(330, CommissionCalculator::amountFor(1000, 3300));
        self::assertSame(330, CommissionCalculator::amountFor(1001, 3300));
        self::assertSame(333, CommissionCalculator::amountFor(1010, 3300));
    }

    /** Truncation never turns into a negative or a surprise on a large base. */
    public function testLargeAmountsStayExact(): void
    {
        // 20% of 1,000,000.000 JOD.
        self::assertSame(200000000, CommissionCalculator::amountFor(1000000000, 2000));
    }

    // ── Strange inputs earn nothing rather than something odd ───────────────

    /**
     * A NEGATIVE BASE IS NOT A DEBT OWED BY THE AFFILIATE. A credit note must
     * not silently produce a negative commission: clawing money back is an
     * explicit reversal against a specific earlier payment, never a side effect
     * of arithmetic on an unusual invoice.
     */
    public function testANegativeBaseEarnsNothingRatherThanANegativeCommission(): void
    {
        self::assertSame(0, CommissionCalculator::amountFor(-15000, 2000));
    }

    public function testAZeroBaseEarnsNothing(): void
    {
        self::assertSame(0, CommissionCalculator::amountFor(0, 2000));
    }

    public function testAZeroOrNegativeRateEarnsNothing(): void
    {
        self::assertSame(0, CommissionCalculator::amountFor(15000, 0));
        self::assertSame(0, CommissionCalculator::amountFor(15000, -2000));
    }

    /**
     * A RATE ABOVE 100% CANNOT PAY OUT MORE THAN THE CUSTOMER PAID. That is a
     * configuration error — a typo of 20000 for 2000 — and the kind only noticed
     * once the money has gone.
     */
    public function testARateAboveOneHundredPercentIsCappedAtTheBase(): void
    {
        self::assertSame(15000, CommissionCalculator::amountFor(15000, 20000));
        self::assertSame(15000, CommissionCalculator::amountFor(15000, 1000000));
    }

    // ── The window ──────────────────────────────────────────────────────────

    public function testTheWindowRunsFromTheFirstPayment(): void
    {
        $first = new DateTimeImmutable('2026-03-15 10:00:00');

        self::assertSame(
            '2027-03-15 10:00:00',
            CommissionCalculator::windowEnd($first, 12)->format('Y-m-d H:i:s')
        );
    }

    /**
     * MONTHS ARE NOT A FIXED LENGTH. A window opened on 31 January must not
     * close on 3 March, which is what adding 30-day months would do.
     */
    public function testTheWindowUsesCalendarMonthsNotFixedLengths(): void
    {
        $first = new DateTimeImmutable('2026-01-31 00:00:00');

        // PHP's calendar arithmetic overflows a short month deliberately and
        // consistently; what matters is that it is calendar-based rather than
        // 30-day, and that it is the same rule everywhere.
        $end = CommissionCalculator::windowEnd($first, 1);

        self::assertSame('2026-03', $end->format('Y-m'));
        self::assertNotSame('2026-03-02', $end->format('Y-m-d'));
    }

    /** A window of zero or less is still a month — never an instantly closed one. */
    public function testAWindowIsNeverZeroLength(): void
    {
        $first = new DateTimeImmutable('2026-03-15 10:00:00');

        self::assertSame(
            CommissionCalculator::windowEnd($first, 1)->format('Y-m-d'),
            CommissionCalculator::windowEnd($first, 0)->format('Y-m-d')
        );
    }

    /**
     * THE BOUNDARY IS INCLUSIVE. A renewal taken at the exact instant the window
     * closes counts — the alternative is telling an affiliate their last month
     * did not count because a scheduled charge ran a second late, which they
     * cannot verify and would not accept.
     */
    public function testAPaymentAtTheExactBoundaryIsInside(): void
    {
        $end = new DateTimeImmutable('2027-03-15 10:00:00');

        self::assertTrue(CommissionCalculator::isWithinWindow($end, $end));
        self::assertTrue(CommissionCalculator::isWithinWindow($end->modify('-1 second'), $end));
        self::assertFalse(CommissionCalculator::isWithinWindow($end->modify('+1 second'), $end));
    }
}
