<?php

declare(strict_types=1);

namespace Tests\Core\Money;

use PHPUnit\Framework\TestCase;
use Whity\Core\Money\Currency;
use Whity\Core\Money\MoneyException;

/**
 * The exponent table and the two conversions that need it.
 *
 * THE HEADLINE CASE IS JOD. This platform bills in Jordanian dinars, which have
 * three decimal places, and every piece of code written before this class
 * assumed two. The first test is the one that would have caught that, and it is
 * deliberately spelled out in fils rather than asserted against a constant:
 * a test that says `exponentFor('JOD') === Currency::exponentFor('JOD')` proves
 * nothing at all.
 */
final class CurrencyTest extends TestCase
{
    // ── the exponent table ───────────────────────────────────────────────────

    /**
     * 1 JOD is 1000 fils. Divide by 100 instead and every amount a customer
     * sees is ten times too large.
     */
    public function testTheJordanianDinarHasThreeDecimalPlaces(): void
    {
        self::assertSame(3, Currency::exponentFor('JOD'));
        self::assertSame(1000, Currency::minorUnitsPerMajor('JOD'));
    }

    public function testTheOtherThreeDecimalCurrenciesAreAllPresent(): void
    {
        foreach (['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'] as $code) {
            self::assertSame(3, Currency::exponentFor($code), "{$code} has three decimal places");
        }
    }

    public function testCurrenciesWithNoMinorUnitAreNotGivenOne(): void
    {
        self::assertSame(0, Currency::exponentFor('JPY'));
        self::assertSame(1, Currency::minorUnitsPerMajor('JPY'));
        self::assertSame(0, Currency::exponentFor('KRW'));
        self::assertSame(0, Currency::exponentFor('XOF'));
    }

    public function testAnOrdinaryCurrencyGetsTwoDecimalPlaces(): void
    {
        self::assertSame(2, Currency::exponentFor('USD'));
        self::assertSame(2, Currency::exponentFor('SAR'));
        self::assertSame(2, Currency::exponentFor('EUR'));
        self::assertSame(100, Currency::minorUnitsPerMajor('AED'));
    }

    /** Lower case is a spelling, not a different currency. */
    public function testTheCodeIsCaseAndWhitespaceInsensitive(): void
    {
        self::assertSame(3, Currency::exponentFor(' jod '));
    }

    public function testSomethingThatIsNotAThreeLetterCodeIsRefused(): void
    {
        $this->expectException(MoneyException::class);
        Currency::exponentFor('DOLLARS');
    }

    /**
     * The distinction the class draws between "we hold this exponent" and "we
     * are assuming the ordinary two", which strict surfaces can consult.
     */
    public function testAnAssumedTwoDecimalCurrencyIsNotClaimedAsExplicitlyKnown(): void
    {
        self::assertTrue(Currency::isExplicitlyKnown('JOD'));
        self::assertFalse(Currency::isExplicitlyKnown('USD'));
    }

    // ── formatting ───────────────────────────────────────────────────────────

    public function testFormattingUsesTheCurrencysOwnPrecision(): void
    {
        self::assertSame('5.000 JOD', Currency::format(5000, 'JOD'));
        self::assertSame('50.00 USD', Currency::format(5000, 'USD'));
        self::assertSame('5000 JPY', Currency::format(5000, 'JPY'));
    }

    /** The fractional part is padded, not truncated: 5 fils is 0.005, not 0.5. */
    public function testASmallFractionKeepsItsLeadingZeros(): void
    {
        self::assertSame('0.005 JOD', Currency::format(5, 'JOD'));
        self::assertSame('0.05 USD', Currency::format(5, 'USD'));
        self::assertSame('1.020 JOD', Currency::format(1020, 'JOD'));
    }

    /**
     * The sign is pulled out before the division. Left in, `intdiv(-1, 1000)`
     * is 0 and `-1 % 1000` is -1, which renders one fil as "0.-01".
     */
    public function testANegativeAmountFormatsAsANegativeAmount(): void
    {
        self::assertSame('-0.001 JOD', Currency::format(-1, 'JOD'));
        self::assertSame('-5.500 JOD', Currency::format(-5500, 'JOD'));
        self::assertSame('-5000 JPY', Currency::format(-5000, 'JPY'));
    }

    public function testZeroFormatsWithTheFullPrecision(): void
    {
        self::assertSame('0.000 JOD', Currency::format(0, 'JOD'));
        self::assertSame('0 JPY', Currency::format(0, 'JPY'));
    }

    // ── parsing ──────────────────────────────────────────────────────────────

    public function testAWholeNumberBecomesTheFullMinorUnitAmount(): void
    {
        self::assertSame(5000, Currency::parse('5', 'JOD'));
        self::assertSame(500, Currency::parse('5', 'USD'));
        self::assertSame(5, Currency::parse('5', 'JPY'));
    }

    /** "5.5" is five and a half dinars — 5500 fils, not 55 and not 5.005. */
    public function testAShortFractionIsPaddedNotLeftAligned(): void
    {
        self::assertSame(5500, Currency::parse('5.5', 'JOD'));
        self::assertSame(5050, Currency::parse('5.05', 'JOD'));
        self::assertSame(5005, Currency::parse('5.005', 'JOD'));
    }

    public function testAFullPrecisionAmountIsRead(): void
    {
        self::assertSame(5000, Currency::parse('5.000', 'JOD'));
        self::assertSame(1234567, Currency::parse('1234.567', 'JOD'));
    }

    public function testANegativeAmountIsRead(): void
    {
        self::assertSame(-5500, Currency::parse('-5.5', 'JOD'));
    }

    /**
     * THE CENTRAL REFUSAL. "5.0005" JOD is not 5.001 and not 5.000 — it is a
     * number the person did not mean, and rounding it silently is how an amount
     * nobody authorised lands on an invoice.
     */
    public function testMorePrecisionThanTheCurrencyHasIsRefusedRatherThanRounded(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageMatches('/Refusing to round/');
        Currency::parse('5.0005', 'JOD');
    }

    /** The same refusal is stricter for a currency with fewer places. */
    public function testAThreeDecimalAmountIsRefusedForATwoDecimalCurrency(): void
    {
        self::assertSame(505, Currency::parse('5.05', 'USD'));

        $this->expectException(MoneyException::class);
        Currency::parse('5.005', 'USD');
    }

    public function testAnyFractionAtAllIsRefusedForACurrencyWithNoMinorUnit(): void
    {
        $this->expectException(MoneyException::class);
        Currency::parse('500.5', 'JPY');
    }

    /**
     * "1.234,56" and "1,234.56" are the same string to different halves of the
     * world and mean amounts a thousand times apart. Neither is guessed at.
     */
    public function testSeparatorsAreRefusedRatherThanGuessedAt(): void
    {
        $this->expectException(MoneyException::class);
        Currency::parse('1,234.56', 'USD');
    }

    public function testAnEmptyAmountIsRefused(): void
    {
        $this->expectException(MoneyException::class);
        Currency::parse('   ', 'JOD');
    }

    public function testSomethingThatIsNotANumberIsRefused(): void
    {
        $this->expectException(MoneyException::class);
        Currency::parse('five', 'JOD');
    }

    /**
     * Wrapping past PHP_INT_MAX would turn a large payment into a negative one,
     * which on an invoice is a refund.
     */
    public function testAnAmountTooLargeForAnIntegerIsRefusedNotWrapped(): void
    {
        $this->expectException(MoneyException::class);
        $this->expectExceptionMessageMatches('/exceeds what an integer holds/');
        Currency::parse('99999999999999999999', 'JOD');
    }

    /** The boundary itself is readable, so the guard is not off by one. */
    public function testTheLargestRepresentableAmountIsAccepted(): void
    {
        self::assertSame(PHP_INT_MAX, Currency::parse((string) PHP_INT_MAX, 'JPY'));
    }

    // ── the two directions agree ─────────────────────────────────────────────

    /**
     * Round-tripping is the property that matters: whatever `format` writes,
     * `parse` must read back as the same number of minor units. A drift between
     * them is an amount that changes when it is displayed and re-entered.
     *
     * @dataProvider roundTrippableAmounts
     */
    public function testFormattingAndParsingAreInverses(int $minorUnits, string $currency): void
    {
        $formatted = Currency::format($minorUnits, $currency);
        // Strip the trailing code; parse takes the number alone.
        $numeric = substr($formatted, 0, strrpos($formatted, ' ') ?: 0);

        self::assertSame($minorUnits, Currency::parse($numeric, $currency), $formatted);
    }

    /** @return iterable<string, array{int, string}> */
    public static function roundTrippableAmounts(): iterable
    {
        yield 'JOD zero'          => [0, 'JOD'];
        yield 'JOD one fil'       => [1, 'JOD'];
        yield 'JOD whole'         => [5000, 'JOD'];
        yield 'JOD awkward'       => [1234567, 'JOD'];
        yield 'JOD negative'      => [-5500, 'JOD'];
        yield 'USD cents'         => [5, 'USD'];
        yield 'USD large'         => [999999999, 'USD'];
        yield 'JPY whole'         => [5000, 'JPY'];
        yield 'JPY negative'      => [-42, 'JPY'];
        yield 'CLF four places'   => [12345, 'CLF'];
    }
}
