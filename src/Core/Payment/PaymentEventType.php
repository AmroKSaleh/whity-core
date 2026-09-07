<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

/**
 * What a payment event says happened. Three outcomes, deliberately.
 *
 * Every provider has its own vocabulary — `charge.succeeded`, `PAID`,
 * `TRANSFER_CONFIRMED`, `capture_completed`, a numeric status code — and every
 * one of them eventually adds a state. Normalising to three at the adapter
 * boundary means the subscription machine, the invoice balance and the dunning
 * schedule are written once, against these, and a new provider cannot introduce
 * a state the rest of the system has never heard of.
 *
 * PENDING IS NOT A FAILURE, and keeping it distinct is the whole reason there
 * are three rather than two. A bank push sits in this state for real minutes;
 * treating that as failure starts a dunning retry against money that is on its
 * way, and treating it as success marks an invoice paid before it is.
 */
enum PaymentEventType: string
{
    /** Money moved. This is the only one that settles an invoice. */
    case Succeeded = 'payment.succeeded';

    /**
     * It did not move, and will not without a new attempt. THIS is what
     * dunning counts.
     */
    case Failed = 'payment.failed';

    /**
     * Initiated, outcome not yet known. Neither settles nor counts against the
     * customer — it is the state a transfer occupies while it is in flight.
     */
    case Pending = 'payment.pending';

    /**
     * The ledger `status` this event writes, keeping one vocabulary across the
     * enum and the `payment_transactions.status` CHECK rather than two that
     * have to be kept in step by hand.
     */
    public function ledgerStatus(): string
    {
        return match ($this) {
            self::Succeeded => 'succeeded',
            self::Failed => 'failed',
            self::Pending => 'pending',
        };
    }

    /** Whether this outcome may still change on its own, without a new attempt. */
    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }
}
