<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

/**
 * What the caller must do next to get paid — one type covering every rail.
 *
 * WHY ONE TYPE AND NOT THREE METHODS. The three shapes a payment can take are
 * genuinely different: a card checkout sends the browser somewhere, a bank
 * push shows the payer a reference to type into their own banking app, and a
 * stored-instrument charge is simply done by the time the call returns.
 *
 * The tempting API is a method per shape — `createCheckoutSession()`,
 * `createTransferReference()`, `chargeStoredMethod()` — and it structurally
 * hardcodes the rails. Every endpoint then branches on which provider it has,
 * every screen branches again, and adding the card PSP means touching all of
 * them. That is exactly the outcome this seam exists to avoid.
 *
 * So one call returns one object, and the caller branches on `kind` once. A new
 * provider produces one of the three shapes that already exist; if it ever
 * genuinely needs a fourth, that is a deliberate change here rather than an
 * accident spread across the codebase.
 *
 * THE PENDING TRANSACTION IS PART OF THE INSTRUCTION. Every kind carries an
 * `externalReference`, because a payment that has been initiated must be
 * recorded before the customer is sent anywhere. Otherwise a customer who pays
 * and then closes the tab has moved money the platform has no row for, and
 * reconciliation has nothing to match the provider's webhook against.
 */
final class PaymentInstruction
{
    /** Send the payer to `url`; the provider calls back when they are done. */
    public const KIND_REDIRECT = 'redirect';

    /**
     * Show the payer `reference` and `instructions`; they push the money from
     * their own bank or wallet app, and reconciliation matches it later.
     */
    public const KIND_TRANSFER = 'transfer';

    /**
     * Already done — a stored-instrument charge, or the mock provider. The
     * outcome is in `event`.
     */
    public const KIND_SETTLED = 'settled';

    /**
     * @param string                $kind        One of the KIND_* constants.
     * @param string                $provider    Which rail produced this.
     * @param string                $externalReference The provider's id for the
     *                                           attempt, so a row can be written
     *                                           BEFORE the customer is sent away.
     * @param ?string               $url         KIND_REDIRECT only.
     * @param ?string               $reference   KIND_TRANSFER only — what the
     *                                           payer types (an alias, an IBAN,
     *                                           a payment id).
     * @param array<string, string> $display     Anything a screen should show
     *                                           alongside it, already
     *                                           translated-key-free: values, not
     *                                           sentences.
     * @param ?PaymentEvent         $event       KIND_SETTLED only.
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $externalReference,
        public readonly ?string $url = null,
        public readonly ?string $reference = null,
        public readonly array $display = [],
        public readonly ?PaymentEvent $event = null,
    ) {
    }

    /** @param array<string, string> $display */
    public static function redirect(
        string $provider,
        string $externalReference,
        string $url,
        array $display = [],
    ): self {
        if (!str_starts_with($url, 'https://')) {
            // A checkout URL carries the customer and, in some flows, an
            // authorisation token in its query string. Over plain HTTP that is
            // handed to whoever is on the network path.
            throw new PaymentProviderException(
                "The {$provider} adapter returned a non-HTTPS checkout URL. Refusing to "
                . 'send a payer to it.'
            );
        }

        return new self(self::KIND_REDIRECT, $provider, $externalReference, $url, null, $display);
    }

    /** @param array<string, string> $display */
    public static function transfer(
        string $provider,
        string $externalReference,
        string $reference,
        array $display = [],
    ): self {
        if (trim($reference) === '') {
            throw new PaymentProviderException(
                "The {$provider} adapter returned an empty transfer reference. A payer "
                . 'cannot push money to nothing, and the reconciliation has nothing to match.'
            );
        }

        return new self(self::KIND_TRANSFER, $provider, $externalReference, null, $reference, $display);
    }

    public static function settled(PaymentEvent $event): self
    {
        return new self(
            self::KIND_SETTLED,
            $event->provider,
            $event->externalReference,
            null,
            null,
            [],
            $event,
        );
    }
}
