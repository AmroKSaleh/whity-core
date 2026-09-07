<?php

declare(strict_types=1);

namespace Whity\Core\Money;

/**
 * How many minor units make a major one, and the two conversions that need to
 * know.
 *
 * WHY THIS EXISTS
 * ---------------
 * {@see Money} deliberately carries no exponent, and says so: an amount is an
 * integer of minor units, and how many of those make a major unit is a property
 * of the CURRENCY, not of the amount. That is the right call for arithmetic —
 * it is what stops two sums in the same currency from disagreeing about what
 * their own numbers mean — but it leaves a gap at the edges of the system,
 * where a human types "5.000" or reads a total off an invoice.
 *
 * Nothing in this codebase filled that gap, and the assumption everything
 * quietly made was two decimal places. That assumption is FALSE for the
 * currency this platform bills in. The Jordanian dinar has THREE: 1 JOD is
 * 1000 fils, so 5000 minor units is 5.000 JOD, not 50.00. Divide by 100 and
 * every amount shown to a customer is ten times too large — an error that looks
 * like a pricing decision rather than a bug, and that a customer discovers
 * before we do.
 *
 * WHY THE EXCEPTION TABLE IS COMPLETE AND THE DEFAULT IS SAFE
 * -----------------------------------------------------------
 * Listing all ~180 active ISO 4217 codes would be a large table transcribed by
 * hand, and a transcription error in it would be exactly the failure it was
 * meant to prevent. So this stores only the codes whose exponent is NOT two,
 * and treats everything else as two.
 *
 * That inverts the risk in the direction that matters, because the exception
 * lists are SHORT and therefore genuinely completable: seven currencies use
 * three decimal places and seventeen use none. Both lists are enumerated below
 * in full. The residual risk is not "an amount is off by a factor of ten" but
 * "a mistyped code is accepted as a two-decimal currency", which surfaces
 * immediately as a nonsense code on a price and cannot silently misprice
 * anything.
 *
 * The lists are checked against a second source rather than trusted: a test in
 * the web package asserts every entry here against the JavaScript engine's own
 * ISO 4217 data via `Intl.NumberFormat`, so a typo in this table fails CI
 * instead of shipping.
 *
 * NO ext-intl, NO LOCALE
 * ----------------------
 * {@see self::format()} produces a plain, locale-free rendering for logs,
 * fixtures and machine-read output. Presenting an amount to a person — Arabic
 * numerals, RTL placement, the symbol in the right position — is a locale
 * concern that belongs on the client, where `Intl` already knows all of it.
 * This class must not grow a locale parameter; the moment it does, two
 * formatters exist and they disagree.
 */
final class Currency
{
    /**
     * Two decimal places, which is every active ISO 4217 code not listed below.
     */
    public const DEFAULT_EXPONENT = 2;

    /**
     * Every active ISO 4217 currency whose minor unit is not two decimal
     * places. Complete as of ISO 4217:2015 with amendments through 2024.
     *
     * @var array<string, int>
     */
    private const EXPONENT_EXCEPTIONS = [
        // THREE decimal places — 1000 minor units to the major. The Gulf and
        // Levant dinars plus the Tunisian and Libyan ones. JOD is in this list,
        // and is the reason this class exists.
        'BHD' => 3,
        'IQD' => 3,
        'JOD' => 3,
        'KWD' => 3,
        'LYD' => 3,
        'OMR' => 3,
        'TND' => 3,

        // NO minor unit — the amount is already whole. Formatting one of these
        // with two decimal places is not merely ugly, it invents a precision
        // the currency does not have.
        'BIF' => 0,
        'CLP' => 0,
        'DJF' => 0,
        'GNF' => 0,
        'ISK' => 0,
        'JPY' => 0,
        'KMF' => 0,
        'KRW' => 0,
        'PYG' => 0,
        'RWF' => 0,
        'UGX' => 0,
        'UYI' => 0,
        'VND' => 0,
        'VUV' => 0,
        'XAF' => 0,
        'XOF' => 0,
        'XPF' => 0,

        // FOUR. Both are units of account rather than money anybody holds,
        // listed so the table is complete rather than because we expect them.
        'CLF' => 4,
        'UYW' => 4,
    ];

    /** Longest fractional part any listed currency has. */
    private const MAX_EXPONENT = 4;

    /**
     * The currency's ISO 4217 exponent: how many decimal places its minor unit
     * occupies.
     *
     * @throws MoneyException When the code is not three letters.
     */
    public static function exponentFor(string $currency): int
    {
        return self::EXPONENT_EXCEPTIONS[self::normalize($currency)] ?? self::DEFAULT_EXPONENT;
    }

    /**
     * How many minor units make one major unit — 1000 for JOD, 100 for USD,
     * 1 for JPY.
     *
     * @throws MoneyException When the code is not three letters.
     */
    public static function minorUnitsPerMajor(string $currency): int
    {
        return 10 ** self::exponentFor($currency);
    }

    /**
     * Whether this code is one whose exponent we hold explicitly, as opposed to
     * one we are assuming is an ordinary two-decimal currency.
     *
     * Offered for surfaces that want to be strict about what they will sell in;
     * {@see self::exponentFor()} deliberately does not consult it, because
     * refusing to format an amount that is already stored helps nobody.
     */
    public static function isExplicitlyKnown(string $currency): bool
    {
        return isset(self::EXPONENT_EXCEPTIONS[self::normalize($currency)]);
    }

    /**
     * A plain, locale-free rendering: "5.000 JOD", "50.00 USD", "500 JPY".
     *
     * For logs, fixtures and machine-read output. Anything a person reads
     * should be formatted on the client, where the locale is known — see the
     * class docblock.
     *
     * @throws MoneyException When the code is not three letters.
     */
    public static function format(int $minorUnits, string $currency): string
    {
        $currency = self::normalize($currency);
        $exponent = self::exponentFor($currency);

        if ($exponent === 0) {
            return $minorUnits . ' ' . $currency;
        }

        // Sign is handled separately so the fractional padding below works on
        // the magnitude: intdiv(-1, 1000) is 0, which would render -1 fils as
        // "0.-01" if the minus were left in the arithmetic.
        $sign = $minorUnits < 0 ? '-' : '';
        $magnitude = abs($minorUnits);
        $divisor = 10 ** $exponent;

        $major = intdiv($magnitude, $divisor);
        $minor = $magnitude % $divisor;

        return $sign . $major . '.' . str_pad((string) $minor, $exponent, '0', STR_PAD_LEFT)
            . ' ' . $currency;
    }

    /**
     * Read a human-entered major-unit amount into minor units.
     *
     * MORE PRECISION THAN THE CURRENCY HAS IS REFUSED, NOT ROUNDED. "5.0005"
     * JOD is not 5.001 and it is not 5.000; it is a number the person typing it
     * did not mean, and quietly rounding it is how an amount nobody authorised
     * ends up on an invoice. The same reasoning refuses "50.5" as a minor-unit
     * integer elsewhere in billing.
     *
     * Separators are refused for the same reason. "1.234,56" and "1,234.56" are
     * the same string to different halves of the world and mean amounts a
     * thousand times apart, so this accepts exactly one form — digits, an
     * optional single '.', an optional leading '-' — and asks the caller to
     * normalise before it gets here.
     *
     * Everything is done on strings. Going through a float would reintroduce
     * precisely the representation error the integer-minor-unit convention
     * exists to avoid.
     *
     * @throws MoneyException When the input is malformed or too precise.
     */
    public static function parse(string $amount, string $currency): int
    {
        $currency = self::normalize($currency);
        $exponent = self::exponentFor($currency);
        $trimmed = trim($amount);

        if ($trimmed === '') {
            throw new MoneyException("Cannot read an empty amount as {$currency}.");
        }

        if (preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $trimmed, $match) !== 1) {
            throw new MoneyException(sprintf(
                'Cannot read "%s" as %s: expected digits with at most one "." and an optional '
                . 'leading "-". Group and decimal separators are ambiguous between locales and '
                . 'are refused rather than guessed at.',
                $amount,
                $currency
            ));
        }

        [, $sign, $whole, $fraction] = $match + [3 => ''];

        if (strlen($fraction) > $exponent) {
            throw new MoneyException(sprintf(
                'Cannot read "%s" as %s: %s has %d decimal place%s, and this has %d. '
                . 'Refusing to round an amount somebody typed.',
                $amount,
                $currency,
                $currency,
                $exponent,
                $exponent === 1 ? '' : 's',
                strlen($fraction)
            ));
        }

        // Pad rather than multiply: "5.5" in a three-decimal currency is 5500,
        // and padding to the exponent gets there without any arithmetic that
        // could overflow midway.
        $digits = $whole . str_pad($fraction, $exponent, '0', STR_PAD_RIGHT);

        // A value wider than the platform integer would wrap silently into a
        // negative amount, which on an invoice is a refund.
        if (!self::fitsInPlatformInt($digits)) {
            throw new MoneyException(sprintf(
                'Cannot read "%s" as %s: %s minor units exceeds what an integer holds.',
                $amount,
                $currency,
                $digits
            ));
        }

        return (int) ($sign . $digits);
    }

    /**
     * Whether a run of digits fits in a PHP int, compared as a string so the
     * check itself cannot overflow.
     */
    private static function fitsInPlatformInt(string $digits): bool
    {
        $digits = ltrim($digits, '0');
        $limit = (string) PHP_INT_MAX;

        if ($digits === '') {
            return true;
        }

        return strlen($digits) < strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0);
    }

    /**
     * @throws MoneyException When the code is not three letters.
     */
    private static function normalize(string $currency): string
    {
        $normalized = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $normalized) !== 1) {
            throw new MoneyException("Not an ISO 4217 currency code: '{$currency}'");
        }

        return $normalized;
    }

    /**
     * The exception table, for the parity test that checks it against the
     * JavaScript engine's own ISO 4217 data.
     *
     * @return array<string, int>
     */
    public static function exponentExceptions(): array
    {
        return self::EXPONENT_EXCEPTIONS;
    }

    /** The widest fractional part any listed currency has. */
    public static function maxExponent(): int
    {
        return self::MAX_EXPONENT;
    }
}
