<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

/**
 * What a rail can actually do.
 *
 * This exists because the difference between rails is not cosmetic. A card on
 * file can be charged on a schedule with nobody present; a bank push cannot —
 * the payer has to open their own app and send the money, every time. Whether a
 * subscription can renew unattended is therefore a property of the rail, and it
 * has to be answerable BEFORE a payment is attempted.
 *
 * Without this, the discovery happens by exception at renewal time, on a real
 * subscription, at the moment the platform was supposed to collect. With it,
 * the dunning machine can tell the difference between "we will retry the card"
 * and "we must ask the human", which are different emails and different
 * schedules.
 */
final class PaymentCapabilities
{
    private function __construct(
        /** Can this rail charge a stored instrument with nobody present? */
        public readonly bool $supportsUnattendedCharge,
        /** Can it store an instrument at all? */
        public readonly bool $supportsStoredMethods,
        /** Does paying involve sending the payer somewhere? */
        public readonly bool $usesRedirect,
        /** Does the payer push the money themselves, out of band? */
        public readonly bool $usesPushTransfer,
        /**
         * Can the platform ask "what happened to this?" rather than only
         * waiting to be told? Reconciliation needs this when a webhook is lost,
         * and a rail without it can only ever be as reliable as its callbacks.
         */
        public readonly bool $supportsPolling,
    ) {
    }

    /** A card-style rail: stored instruments, unattended renewals, redirects. */
    public static function card(bool $supportsPolling = true): self
    {
        return new self(true, true, true, false, $supportsPolling);
    }

    /**
     * A push rail: the payer moves the money from their own bank or wallet, so
     * nothing can be collected without them, and reconciliation is by polling
     * or callback.
     */
    public static function pushTransfer(bool $supportsPolling = true): self
    {
        return new self(false, false, false, true, $supportsPolling);
    }

    /** Everything, for the fake. */
    public static function everything(): self
    {
        return new self(true, true, true, true, true);
    }
}
