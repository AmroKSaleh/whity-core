<?php

declare(strict_types=1);

namespace Tests\Core\Payment;

use PHPUnit\Framework\TestCase;
use Whity\Core\Payment\Cliq\CliqPaymentReference;
use Whity\Core\Payment\PaymentProviderException;

/**
 * The string a payer types into their bank app.
 *
 * It is the only thing connecting a CliQ transfer to an invoice, and it is
 * typed by a human on a phone. So the tests that matter are about what happens
 * when they get it slightly wrong — which is the ordinary case, not the edge
 * case.
 */
final class CliqPaymentReferenceTest extends TestCase
{
    // ── deterministic ────────────────────────────────────────────────────────

    /**
     * A payer who opens the payment screen twice must not be given two
     * references for one debt: the second push would arrive carrying a
     * reference the first attempt's ledger row has never heard of.
     */
    public function testTheSameInvoiceAndAttemptAlwaysGiveTheSameReference(): void
    {
        self::assertSame(
            CliqPaymentReference::generate('WHT-', 42, 1),
            CliqPaymentReference::generate('WHT-', 42, 1)
        );
    }

    public function testADifferentAttemptGivesADifferentReference(): void
    {
        self::assertNotSame(
            CliqPaymentReference::generate('WHT-', 42, 1),
            CliqPaymentReference::generate('WHT-', 42, 2)
        );
    }

    public function testADifferentInvoiceGivesADifferentReference(): void
    {
        self::assertNotSame(
            CliqPaymentReference::generate('WHT-', 42, 1),
            CliqPaymentReference::generate('WHT-', 43, 1)
        );
    }

    // ── the alphabet ─────────────────────────────────────────────────────────

    /**
     * No 0/O and no 1/I. On a phone keyboard, in a hurry, those are the same
     * character, and a reference nobody can transcribe is a payment nobody can
     * reconcile.
     */
    public function testTheReferenceContainsNoConfusableCharacters(): void
    {
        for ($invoice = 1; $invoice <= 400; $invoice++) {
            $body = substr(CliqPaymentReference::generate('WHT-', $invoice, 1), 4);

            // The check characters are digits by construction, so only the body
            // is examined for letters.
            self::assertDoesNotMatchRegularExpression(
                '/[OI]/',
                substr($body, 0, 8),
                "invoice {$invoice} produced a confusable character"
            );
        }
    }

    // ── typos are rejected, not mismatched ───────────────────────────────────

    public function testAWellFormedReferenceVerifies(): void
    {
        self::assertTrue(CliqPaymentReference::isWellFormed(CliqPaymentReference::generate('WHT-', 42, 1)));
    }

    /**
     * THE CENTRAL PROPERTY. Every single-character substitution must be
     * rejected — otherwise a mistyped reference silently resolves to whichever
     * invoice it happens to match, and somebody else's debt is marked paid.
     *
     * Checked exhaustively over the whole alphabet at every position rather
     * than on one example, because "the checksum catches most typos" is not
     * the claim being made.
     */
    public function testEverySingleCharacterTypoIsRejected(): void
    {
        $reference = CliqPaymentReference::generate('WHT-', 4242, 3);
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        $accepted = [];
        for ($position = 4; $position < strlen($reference); $position++) {
            foreach (str_split($alphabet) as $replacement) {
                if ($reference[$position] === $replacement) {
                    continue;
                }
                $typo = substr_replace($reference, $replacement, $position, 1);
                if (CliqPaymentReference::isWellFormed($typo)) {
                    $accepted[] = $typo;
                }
            }
        }

        self::assertSame([], $accepted, 'a mistyped reference was accepted as valid');
    }

    /**
     * And every transposition of adjacent characters — the other mistake
     * people make when copying a string across two apps.
     */
    public function testEveryAdjacentTranspositionIsRejected(): void
    {
        $reference = CliqPaymentReference::generate('WHT-', 4242, 3);

        $accepted = [];
        for ($position = 4; $position < strlen($reference) - 1; $position++) {
            if ($reference[$position] === $reference[$position + 1]) {
                continue; // swapping equal characters is not a detectable error
            }
            $swapped = $reference;
            [$swapped[$position], $swapped[$position + 1]] = [$swapped[$position + 1], $swapped[$position]];

            if (CliqPaymentReference::isWellFormed($swapped)) {
                $accepted[] = $swapped;
            }
        }

        self::assertSame([], $accepted, 'a transposed reference was accepted as valid');
    }

    // ── the reference says which debt it is for ──────────────────────────────

    /**
     * A CliQ settlement message carries the BANK's transaction id and nothing
     * that says which invoice the money settles. Without this, a confirmed
     * transfer arrives attached to no debt — and the fallback, searching by
     * amount, matches the wrong invoice the moment two tenants owe the same
     * figure, which on a subscription platform is most of them.
     */
    public function testAReferenceSaysWhichInvoiceAndAttemptItWasFor(): void
    {
        foreach ([[1, 1], [42, 3], [999999, 255], [4242, 1]] as [$invoice, $attempt]) {
            $decoded = CliqPaymentReference::decode(
                CliqPaymentReference::generate('WHT-', $invoice, $attempt)
            );

            self::assertSame(['invoice' => $invoice, 'attempt' => $attempt], $decoded);
        }
    }

    /** Whatever the operator's prefix, the payload sits in the same place. */
    public function testDecodingDoesNotDependOnThePrefixLength(): void
    {
        foreach (['AB', 'WHT-', 'ACMECORP'] as $prefix) {
            self::assertSame(
                42,
                CliqPaymentReference::decode(CliqPaymentReference::generate($prefix, 42, 1))['invoice'] ?? null,
                $prefix
            );
        }
    }

    /** Anything that is not ours decodes to nothing rather than to invoice 0. */
    public function testSomethingThatIsNotOursDecodesToNothing(): void
    {
        foreach (['', 'salary september', 'WHT-ZZZZZZZZ99', 'WHT-'] as $garbage) {
            self::assertNull(CliqPaymentReference::decode($garbage), $garbage);
        }
    }

    /**
     * A mistyped reference must not decode to SOME invoice. This is the whole
     * point of the check characters expressed at the layer that uses them: the
     * typo is refused before anything looks up a debt.
     */
    public function testAMistypedReferenceDecodesToNothingRatherThanToAnotherInvoice(): void
    {
        $good = CliqPaymentReference::generate('WHT-', 4242, 1);

        for ($position = 4; $position < strlen($good); $position++) {
            $replacement = $good[$position] === 'A' ? 'B' : 'A';
            $typo = substr_replace($good, $replacement, $position, 1);

            self::assertNull(CliqPaymentReference::decode($typo), $typo);
        }
    }

    public function testSomethingThatIsNotOneOfOursIsRejected(): void
    {
        foreach (['', 'HELLO', 'WHT-', 'salary payment', 'WHT-ZZZZZZZZ99'] as $garbage) {
            self::assertFalse(CliqPaymentReference::isWellFormed($garbage), $garbage);
        }
    }

    // ── what payers actually type ────────────────────────────────────────────

    /**
     * Bank apps and humans both put spaces in narrative fields. Refusing money
     * that arrived because of presentation would be absurd.
     */
    public function testSpacingIsForgiven(): void
    {
        $reference = CliqPaymentReference::generate('WHT-', 42, 1);
        $spaced = substr($reference, 0, 6) . ' ' . substr($reference, 6);

        self::assertTrue(CliqPaymentReference::isWellFormed($spaced));
        self::assertSame($reference, CliqPaymentReference::canonicalize($spaced));
    }

    public function testLowerCaseIsForgiven(): void
    {
        $reference = CliqPaymentReference::generate('WHT-', 42, 1);

        self::assertTrue(CliqPaymentReference::isWellFormed(strtolower($reference)));
        self::assertSame($reference, CliqPaymentReference::canonicalize(strtolower($reference)));
    }

    // ── the prefix ───────────────────────────────────────────────────────────

    public function testTheOperatorsPrefixIsCarried(): void
    {
        self::assertStringStartsWith('ACME-', CliqPaymentReference::generate('acme-', 42, 1));
    }

    /**
     * Digits and punctuation in a prefix invite exactly the mistakes the check
     * characters exist to catch, so they are refused at configuration time
     * rather than tolerated at payment time.
     */
    public function testAPrefixThatInvitesTyposIsRefused(): void
    {
        foreach (['W1', 'A B', 'TOOLONGAPREFIX', '', '123'] as $bad) {
            try {
                CliqPaymentReference::generate($bad, 42, 1);
                self::fail("prefix '{$bad}' should have been refused");
            } catch (PaymentProviderException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAReferenceForNoRealInvoiceIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);
        CliqPaymentReference::generate('WHT-', 0, 1);
    }
}
