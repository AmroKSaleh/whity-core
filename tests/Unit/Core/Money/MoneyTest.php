<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Money;

use PHPUnit\Framework\TestCase;
use Whity\Core\Money\Money;
use Whity\Core\Money\MoneyException;

/**
 * Money: an amount of one currency, in minor units, with no floats anywhere.
 *
 * THE ROUNDING TESTS ARE THE POINT OF THIS FILE. A percentage is the one place a
 * billing system has to turn a fraction into an integer, and the rule it uses is
 * a number somebody will eventually argue about. So the cases below pin it at
 * the boundaries in both directions.
 *
 * A NOTE ON WHY THE IMPLEMENTATION IS INTEGER-ONLY, because the usual claim is
 * wrong and this file originally repeated it. "Floats misround percentages" was
 * asserted here and then checked: across 20 million combinations (amounts
 * 0–200,000 at 1–100%), `round($amount * $percent / 100)` agreed with exact
 * integer half-up EVERY time. Zero disagreements.
 *
 * What floats do lose is PRECISION above 2^53 — 9007199254740993 comes back as
 * ...992 — which a three-decimal currency reaches in earnest, and that case is
 * pinned below. The integer form is kept for that and because it states its
 * rounding rule rather than inheriting one, not for a misrounding nobody could
 * produce.
 */
final class MoneyTest extends TestCase
{
    public function testAnAmountKeepsItsCurrency(): void
    {
        $m = Money::of(4900, 'SAR');
        self::assertSame(4900, $m->amount);
        self::assertSame('SAR', $m->currency);
    }

    public function testCurrencyIsNormalisedToUpperCase(): void
    {
        self::assertSame('SAR', Money::of(1, ' sar ')->currency);
    }

    public function testANonIsoCurrencyIsRefused(): void
    {
        $this->expectException(MoneyException::class);
        Money::of(1, 'RIYAL');
    }

    // ── the invariant a type exists to enforce ───────────────────────────────

    /**
     * Two currencies cannot be added. With `(int, string)` parameters this was
     * a plausible number in the wrong denomination; here it is an exception.
     */
    public function testTwoCurrenciesCannotBeCombined(): void
    {
        $this->expectException(MoneyException::class);
        Money::of(100, 'SAR')->plus(Money::of(100, 'USD'));
    }

    public function testNorCompared(): void
    {
        $this->expectException(MoneyException::class);
        Money::of(100, 'SAR')->isGreaterThan(Money::of(100, 'USD'));
    }

    public function testEqualityConsidersTheCurrency(): void
    {
        self::assertTrue(Money::of(100, 'SAR')->equals(Money::of(100, 'SAR')));
        self::assertFalse(Money::of(100, 'SAR')->equals(Money::of(100, 'USD')));
    }

    // ── arithmetic ───────────────────────────────────────────────────────────

    public function testAddingAndSubtracting(): void
    {
        $a = Money::of(4900, 'SAR');
        self::assertSame(5900, $a->plus(Money::of(1000, 'SAR'))->amount);
        self::assertSame(3900, $a->minus(Money::of(1000, 'SAR'))->amount);
    }

    public function testMultiplyingBySeats(): void
    {
        self::assertSame(49000, Money::of(4900, 'SAR')->multipliedBy(10)->amount);
        self::assertSame(0, Money::of(4900, 'SAR')->multipliedBy(0)->amount);
    }

    /**
     * A discount must not turn a bill into a refund. Refunds are a different
     * transaction with different rules, and letting one happen by subtraction is
     * how money moves in a direction nobody authorised.
     */
    public function testANegativeAmountClampsToZero(): void
    {
        $owed = Money::of(3000, 'SAR')->minus(Money::of(5000, 'SAR'));
        self::assertSame(-2000, $owed->amount, 'subtraction itself is honest');
        self::assertSame(0, $owed->clampedToZero()->amount, 'what is owed is not');
    }

    // ── rounding: the reason this class exists ───────────────────────────────

    public function testAWholePercentageOfARoundAmount(): void
    {
        self::assertSame(490, Money::of(4900, 'SAR')->percentage(10)->amount);
        self::assertSame(2450, Money::of(4900, 'SAR')->percentage(50)->amount);
    }

    /** Exactly a half rounds UP — the answer somebody can check by hand. */
    public function testExactlyAHalfRoundsUp(): void
    {
        // 10% of 5 is 0.5
        self::assertSame(1, Money::of(5, 'SAR')->percentage(10)->amount);
        // 50% of 5 is 2.5
        self::assertSame(3, Money::of(5, 'SAR')->percentage(50)->amount);
        // 50% of 1 is 0.5
        self::assertSame(1, Money::of(1, 'SAR')->percentage(50)->amount);
    }

    public function testBelowAHalfRoundsDown(): void
    {
        // 10% of 4 is 0.4
        self::assertSame(0, Money::of(4, 'SAR')->percentage(10)->amount);
        // 33% of 1 is 0.33
        self::assertSame(0, Money::of(1, 'SAR')->percentage(33)->amount);
    }

    public function testJustAboveAHalfRoundsUp(): void
    {
        // 10% of 4999 is 499.9
        self::assertSame(500, Money::of(4999, 'SAR')->percentage(10)->amount);
    }

    /**
     * Awkward fractions, pinned so the rule cannot drift.
     *
     * These were originally labelled "cases a float gets wrong". They are not —
     * a float agrees on all of them, as it does on every combination checked.
     * They are kept because they are the fractions most likely to be reasoned
     * about incorrectly by a person changing this code.
     */
    public function testAwkwardFractionsAreStable(): void
    {
        // 70% of 815 is exactly 570.5 → up.
        self::assertSame(571, Money::of(815, 'SAR')->percentage(70)->amount);
        // 29% of 115 is 33.35 → down.
        self::assertSame(33, Money::of(115, 'SAR')->percentage(29)->amount);
    }

    public function testTheEdgesOfThePercentageRange(): void
    {
        self::assertSame(0, Money::of(4900, 'SAR')->percentage(0)->amount);
        self::assertSame(4900, Money::of(4900, 'SAR')->percentage(100)->amount);
    }

    public function testAPercentageOutsideTheRangeIsRefused(): void
    {
        $this->expectException(MoneyException::class);
        Money::of(100, 'SAR')->percentage(101);
    }

    /**
     * THE REAL REASON FOR INTEGER ARITHMETIC. Above 2^53 a double cannot
     * represent consecutive integers: 9007199254740993 becomes ...992 on the way
     * through one, silently. A three-decimal currency reaches that range in
     * earnest, and this is the failure the float version actually has.
     */
    public function testLargeAmountsStayExactWhereAFloatWouldNot(): void
    {
        $exact = 9_007_199_254_740_993;
        self::assertNotSame($exact, (int) (float) $exact, 'a float genuinely loses this one');

        $big = Money::of($exact, 'KWD');
        self::assertSame($exact, $big->amount);
        self::assertSame($exact, $big->percentage(100)->amount);
    }

    // ── immutability ─────────────────────────────────────────────────────────

    /**
     * Every operation returns a NEW amount. A mutating `plus()` would let a
     * total change under a caller that had already read it — and a quote is
     * read several times on its way to a screen.
     */
    public function testOperationsDoNotMutateTheOriginal(): void
    {
        $original = Money::of(1000, 'SAR');
        $original->plus(Money::of(500, 'SAR'));
        $original->percentage(50);
        $original->multipliedBy(3);

        self::assertSame(1000, $original->amount);
    }
}
