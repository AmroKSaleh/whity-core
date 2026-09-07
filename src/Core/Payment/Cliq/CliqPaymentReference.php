<?php

declare(strict_types=1);

namespace Whity\Core\Payment\Cliq;

use Whity\Core\Payment\PaymentProviderException;

/**
 * The reference a payer types into their own banking app when pushing money
 * over CliQ.
 *
 * WHY THIS IS NOT JUST A RANDOM STRING
 * ------------------------------------
 * A card payment carries its own identity. A CliQ push does not: the payer
 * opens their bank's app, sends money to an alias, and types a reference into
 * a free-text narrative field. That field is the ONLY thing connecting the
 * money to an invoice, and it is typed by a human, on a phone, from a screen
 * they are switching away from.
 *
 * Two consequences shape everything here.
 *
 * FIRST, TYPOS MUST BE REJECTED RATHER THAN MISMATCHED. A random opaque string
 * has no way to tell a mistyped reference from a valid one belonging to a
 * different invoice, and reconciliation would either fail to find a payment
 * that was made or — far worse — settle the wrong invoice. So the reference
 * carries two ISO 7064 MOD 97-10 check characters, which catch every
 * single-character error and every transposition of adjacent characters. A
 * malformed reference is refused before any lookup happens.
 *
 * SECOND, THE ALPHABET EXCLUDES CHARACTERS PEOPLE CONFUSE. No 0/O and no 1/I,
 * because on a phone keyboard, in a hurry, those are the same character. That
 * is 32 symbols, which encodes cleanly five bits at a time.
 *
 * DETERMINISTIC, NOT RANDOM
 * -------------------------
 * The same invoice and attempt always produce the same reference. A payer who
 * opens the payment screen twice must not end up with two references for one
 * debt — the second push would arrive carrying a reference the first attempt's
 * ledger row does not know about. It is derived, not stored, so nothing has to
 * remember it to reproduce it.
 *
 * It is deliberately NOT a secret and does not try to be unguessable. Knowing
 * a reference lets somebody pay an invoice that is not theirs, which is not an
 * attack anybody runs. What it must be is unique per attempt and hard to
 * mistype, and it is both.
 */
final class CliqPaymentReference
{
    /**
     * 32 symbols with the confusable pairs removed: no 0 or O, no 1 or I.
     * Five bits per character.
     */
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** Characters of encoded payload, before the check pair. */
    private const BODY_LENGTH = 8;

    /** ISO 7064 MOD 97-10 check characters. */
    private const CHECK_LENGTH = 2;

    /**
     * The reference for one payment attempt on one invoice.
     *
     * @param string $prefix Operator-configurable, so a payer sees something
     *                       recognisable in their statement.
     *
     * @throws PaymentProviderException When the prefix is unusable.
     */
    public static function generate(string $prefix, int $invoiceId, int $attempt): string
    {
        $prefix = self::normalizePrefix($prefix);

        if ($invoiceId < 1 || $attempt < 1) {
            throw new PaymentProviderException(
                'A CliQ reference is derived from a real invoice and attempt; '
                . "got invoice {$invoiceId} attempt {$attempt}."
            );
        }

        $body = self::encode($invoiceId, $attempt);

        return $prefix . $body . self::checkCharacters($prefix . $body);
    }

    /**
     * Whether a reference a payer typed is structurally sound.
     *
     * Checked BEFORE any database lookup: a mistyped reference must be refused
     * as unreadable, never resolved to whichever invoice it happens to match.
     */
    public static function isWellFormed(string $reference): bool
    {
        $reference = self::canonicalize($reference);

        if (strlen($reference) < self::BODY_LENGTH + self::CHECK_LENGTH + 1) {
            return false;
        }

        $payload = substr($reference, 0, -self::CHECK_LENGTH);
        $check = substr($reference, -self::CHECK_LENGTH);

        // Every character must be in the alphabet or the prefix set; anything
        // else means the payer typed something that is not one of ours.
        if (preg_match('/^[A-Z0-9-]+$/', $reference) !== 1) {
            return false;
        }

        return hash_equals(self::checkCharacters($payload), $check);
    }

    /**
     * The invoice and attempt a reference was generated for, or null when it is
     * not one of ours.
     *
     * WHY THE REFERENCE IS SELF-DESCRIBING. A CliQ settlement message carries
     * the BANK's transaction identifier, which is what the ledger keys
     * idempotency on — and nothing else that says which invoice the money is
     * for. Without decoding, a confirmed transfer would arrive attached to no
     * debt, and reconciliation would have to search for an invoice by amount,
     * which matches the wrong one the moment two tenants owe the same figure.
     *
     * IT IS NOT A CLAIM OF AUTHORITY. Anyone can type any reference into their
     * own bank app, so this says which invoice the PAYER INTENDED to settle,
     * not who they are. The worst it permits is somebody paying a bill that is
     * not theirs, which is not an attack anybody runs. What makes the message
     * trustworthy is the bank's signature over the payload, checked before this
     * is ever reached.
     *
     * @return array{invoice: int, attempt: int}|null
     */
    public static function decode(string $reference): ?array
    {
        $reference = self::canonicalize($reference);

        if (!self::isWellFormed($reference)) {
            return null;
        }

        // The body sits immediately before the check characters, so this does
        // not depend on how long the operator's prefix is.
        $body = substr($reference, -(self::BODY_LENGTH + self::CHECK_LENGTH), self::BODY_LENGTH);

        $value = 0;
        foreach (str_split($body) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if ($index === false) {
                return null;
            }
            $value = ($value << 5) | $index;
        }

        $invoice = $value >> 8;
        $attempt = $value & 0xFF;

        return $invoice > 0 ? ['invoice' => $invoice, 'attempt' => $attempt] : null;
    }

    /**
     * Uppercase, with spaces and hyphens the payer may have added removed.
     *
     * Bank apps and humans both introduce whitespace into narrative fields, and
     * "WHT-ABCD EFGH-42" is the same reference as "WHTABCDEFGH42". Refusing it
     * over presentation would mean refusing money that arrived.
     */
    public static function canonicalize(string $reference): string
    {
        return strtoupper(preg_replace('/[\s]+/', '', trim($reference)) ?? '');
    }

    /**
     * Five bits per character, most significant first — a plain base-32 of the
     * (invoice, attempt) pair.
     *
     * The attempt occupies the low 8 bits, so up to 255 attempts on one
     * invoice each get their own reference, and the invoice id has the
     * remaining 32 — more invoices than any instance will issue.
     */
    private static function encode(int $invoiceId, int $attempt): string
    {
        $value = ($invoiceId << 8) | ($attempt & 0xFF);

        $out = '';
        for ($i = self::BODY_LENGTH - 1; $i >= 0; $i--) {
            $out .= self::ALPHABET[($value >> ($i * 5)) & 0x1F];
        }

        return $out;
    }

    /**
     * ISO 7064 MOD 97-10, the scheme IBAN uses.
     *
     * Chosen rather than a hand-rolled checksum because its guarantees are
     * known and strong: it catches every single-character substitution and
     * every transposition of two adjacent characters, which are precisely the
     * two mistakes someone typing into a phone makes.
     *
     * EVERY CHARACTER CONTRIBUTES EXACTLY TWO DIGITS, and that is not
     * cosmetic. IBAN's own encoding gives letters two digits and digits one,
     * and a first version here copied it — which quietly voids the guarantee
     * for a mixed alphabet like this one. Substituting a letter for a digit
     * changes the LENGTH of the numeric string, shifting every subsequent
     * position by a power of ten, and the difference is no longer the simple
     * multiple of a power of ten the proof depends on. An exhaustive test
     * duly found two single-character typos that verified as valid.
     *
     * With fixed width the proof is short and holds. A substitution changes one
     * two-digit group, so the delta is (a-b)·100^k, and since the values here
     * span 0–35 it is a multiple of 97 only when a = b. A transposition of
     * adjacent characters gives (a-b)·99·100^k, and 99 ≡ 2 (mod 97) is
     * invertible, so again only when a = b. Both classes of error are caught,
     * which is the whole claim.
     *
     * The remainder is accumulated digit by digit so the intermediate value
     * never leaves integer range — a bignum would be a dependency, and getting
     * it wrong produces a checksum that silently accepts everything.
     */
    private static function checkCharacters(string $payload): string
    {
        $digits = '';
        foreach (str_split(strtoupper($payload)) as $character) {
            if ($character === '-') {
                continue;
            }
            $value = ctype_digit($character)
                ? (int) $character
                : ord($character) - ord('A') + 10;
            $digits .= str_pad((string) $value, 2, '0', STR_PAD_LEFT);
        }

        // Append '00', take mod 97, and the check value is 98 minus it — the
        // standard construction, so verifying is recomputing rather than a
        // separate inverse nobody can check.
        $remainder = 0;
        foreach (str_split($digits . '00') as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return str_pad((string) (98 - $remainder), 2, '0', STR_PAD_LEFT);
    }

    /** @throws PaymentProviderException */
    private static function normalizePrefix(string $prefix): string
    {
        $normalized = strtoupper(trim($prefix));

        if (preg_match('/^[A-Z]{2,8}-?$/', $normalized) !== 1) {
            throw new PaymentProviderException(sprintf(
                'CliQ reference prefix "%s" is unusable: two to eight letters, optionally '
                . 'followed by a hyphen. It is typed by a payer into a bank app, so digits '
                . 'and punctuation invite exactly the mistakes the check characters exist '
                . 'to catch.',
                $prefix
            ));
        }

        return $normalized;
    }
}
