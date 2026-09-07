<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

/**
 * The rail has no such concept — asking CliQ to store a card, or to charge one
 * unattended.
 *
 * Distinct from {@see PaymentProviderException} because the two need opposite
 * handling. A provider refusing a charge is a payment failure: it counts toward
 * dunning, it may succeed on retry, and the customer should hear about it. This
 * is a programming error: retrying will never help, and it means a caller asked
 * for something {@see PaymentCapabilities} would have told it was impossible.
 *
 * Conflating them is how a rail that simply cannot renew unattended ends up
 * generating dunning failures and eventually locking out a tenant who never did
 * anything wrong.
 */
final class UnsupportedPaymentOperation extends PaymentProviderException
{
    public static function for(string $provider, string $operation): self
    {
        return new self(sprintf(
            'The %s rail does not support %s. Check capabilities() before asking: '
            . 'this is not a payment failure and retrying will never help.',
            $provider,
            $operation
        ));
    }
}
