<?php

declare(strict_types=1);

namespace Whity\Core\Licensing;

use InvalidArgumentException;
use Random\RandomException;

/**
 * The code somebody types to activate a licensed device.
 *
 * WHAT MAKES THIS DIFFERENT FROM AN INVOICE REFERENCE
 * ---------------------------------------------------
 * {@see \Whity\Core\Payment\Cliq\CliqPaymentReference} solves a neighbouring
 * problem and the check-character construction here is deliberately the same,
 * because the human factors are identical: read off a surface, typed on a
 * phone, by someone already half-distracted.
 *
 * But a payment reference ENCODES its subject — the invoice id is recoverable
 * from the string, which is exactly right when the sender and the system both
 * already know the invoice. An activation code must not. It is a BEARER
 * CREDENTIAL: whoever holds it can consume something worth money, and per the
 * deployment driving this, the holder may be an end user or a student with no
 * account and no relationship to the tenant at the moment they type it.
 *
 * Two consequences follow, and they are the whole design.
 *
 * FIRST, THE BODY IS RANDOM, NOT DERIVED. If a code encoded a device id, then
 * holding one code would tell you how to construct its neighbours — and a
 * classroom that received codes for devices 41 through 70 could mint the rest
 * of the batch. The body here is 10 characters of CSPRNG output over a
 * 32-character alphabet: 50 bits, which is not guessable at any rate a
 * rate-limited endpoint will permit.
 *
 * SECOND, VALIDITY IS NOT AUTHORISATION. `isWellFormed()` says only that the
 * check characters agree, which means the string survived being typed. It says
 * nothing about whether the code exists, belongs to anyone, has expired, has
 * been revoked, or has redemptions left. Those are database questions and this
 * class deliberately cannot answer them — the separation exists so that no
 * caller can mistake "looks right" for "may proceed".
 *
 * The check characters earn their place by turning the overwhelmingly common
 * failure — a typo — into a refusal instead of a lookup miss. Without them a
 * mistyped code is indistinguishable from an unknown one, so every typo either
 * reads as "invalid code" (unhelpful, and identical to the message an attacker
 * probing at random receives) or, far worse, collides with a real code
 * belonging to somebody else.
 */
final class ActivationCode
{
    /**
     * No 0/O and no 1/I. On a phone keyboard, in a hurry, off a sticker, those
     * are the same character — and a code that cannot be transcribed reliably
     * generates support tickets rather than activations.
     */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * 10 random characters over 32 symbols is 50 bits. At a rate limit of even
     * a few attempts per second, exhaustive search is not a threat model; the
     * realistic risks are leaked codes and shoulder-surfing, which length does
     * not address and revocation does.
     */
    private const BODY_LENGTH = 10;

    private const CHECK_LENGTH = 2;

    /** Grouped for transcription, stripped on canonicalisation. */
    private const GROUP_SIZE = 4;

    /**
     * Mint a new code. Cryptographically random; never derived from the device
     * or the tenant, for the reason in the class docblock.
     *
     * @throws RandomException if the platform CSPRNG is unavailable — which
     *                         must fail loudly rather than fall back to
     *                         something predictable.
     */
    public static function generate(): string
    {
        $body = '';
        for ($i = 0; $i < self::BODY_LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::group($body . self::checkCharacters($body));
    }

    /**
     * Does this string survive transcription — nothing more.
     *
     * Returns false for a typo and for random noise alike. It does NOT mean the
     * code exists or may be used; see the class docblock.
     */
    public static function isWellFormed(string $code): bool
    {
        $canonical = self::canonicalize($code);

        if (strlen($canonical) !== self::BODY_LENGTH + self::CHECK_LENGTH) {
            return false;
        }

        foreach (str_split($canonical) as $character) {
            if (!str_contains(self::ALPHABET, $character)) {
                return false;
            }
        }

        $body = substr($canonical, 0, self::BODY_LENGTH);
        $check = substr($canonical, self::BODY_LENGTH);

        return hash_equals(self::checkCharacters($body), $check);
    }

    /**
     * The form stored and compared: upper case, no separators, no spaces.
     *
     * Lookups MUST use this. A code typed with the printed dashes, or pasted
     * with a trailing space from a PDF, is the same code — and storing the
     * decorated form would make two equal codes unequal to the database.
     */
    public static function canonicalize(string $code): string
    {
        $upper = strtoupper($code);

        // Anything outside the alphabet is decoration by definition, since the
        // alphabet is the complete set of characters a valid code contains.
        return (string) preg_replace('/[^' . self::ALPHABET . ']/', '', $upper);
    }

    /** The printed form: groups of four, for reading aloud and typing. */
    public static function format(string $code): string
    {
        return self::group(self::canonicalize($code));
    }

    private static function group(string $canonical): string
    {
        return trim(chunk_split($canonical, self::GROUP_SIZE, '-'), '-');
    }

    /**
     * ISO 7064 MOD 97-10 — the scheme IBAN uses, and the same construction as
     * {@see \Whity\Core\Payment\Cliq\CliqPaymentReference::checkCharacters()}.
     *
     * EVERY CHARACTER CONTRIBUTES EXACTLY TWO DIGITS. This is not cosmetic and
     * it is the detail that a previous implementation in this codebase got
     * wrong. IBAN gives letters two digits and digits one; copying that voids
     * the guarantee for a mixed alphabet, because substituting a letter for a
     * digit changes the LENGTH of the numeric string and shifts every later
     * position by a power of ten. An exhaustive test found two typos that
     * verified as valid before it was fixed.
     *
     * With fixed width the proof holds: a substitution changes one two-digit
     * group, so the delta is (a-b)·100^k and, with values spanning 0–35, is a
     * multiple of 97 only when a = b. A transposition of adjacent characters
     * gives (a-b)·99·100^k, and 99 ≡ 2 (mod 97) is invertible, so again only
     * when a = b. Every single-character error and every adjacent transposition
     * is therefore caught — which is the entire reason for choosing a standard
     * scheme over inventing one.
     */
    private static function checkCharacters(string $body): string
    {
        $digits = '';
        foreach (str_split($body) as $character) {
            $value = ctype_digit($character)
                ? (int) $character
                : ord($character) - ord('A') + 10;
            $digits .= str_pad((string) $value, 2, '0', STR_PAD_LEFT);
        }

        // Append '00', take mod 97, check value is 98 minus it. The standard
        // construction, so verifying is recomputing rather than a separate
        // inverse that nobody can check by inspection.
        $remainder = 0;
        foreach (str_split($digits . '00') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        $check = 98 - $remainder;

        // The check value is 1..97, and 97 needs two characters from the
        // alphabet just as the others do — so it is encoded in the same base
        // rather than printed as decimal, which would put characters outside
        // the alphabet into the code.
        return self::ALPHABET[intdiv($check, 32)] . self::ALPHABET[$check % 32];
    }

    private function __construct()
    {
        throw new InvalidArgumentException('ActivationCode is a static helper.');
    }
}
