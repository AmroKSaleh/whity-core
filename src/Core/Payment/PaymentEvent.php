<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

use DateTimeImmutable;
use Whity\Core\Money\Money;

/**
 * One movement of money, in the platform's own vocabulary rather than a
 * provider's.
 *
 * Adapters produce these; nothing downstream — the ledger, the invoice
 * balance, the dunning schedule, the subscription state machine — ever sees a
 * provider payload. That is the seam the whole design rests on: when the card
 * PSP is chosen, it produces these, and nothing else changes.
 *
 * `externalReference` IS REQUIRED, AND THAT IS THE POINT
 * -----------------------------------------------------
 * It is the provider's own identifier for the movement, and it is what makes
 * the ledger idempotent — `(provider, external_reference)` is a unique index,
 * so a redelivered webhook collides instead of crediting the account twice.
 *
 * An event without one cannot be deduplicated, so it is refused HERE rather
 * than accepted and dropped later. The alternative — a nullable reference —
 * looks harmless and produces a system that silently double-credits exactly
 * when a provider is retrying, which is exactly when it matters. An adapter for
 * a provider that genuinely sends no identifier must synthesise a deterministic
 * one from the payload, and that is a decision the adapter should be forced to
 * make out loud.
 *
 * `occurredAt` IS THE PROVIDER'S TIME, NOT OURS
 * ---------------------------------------------
 * When the money moved, not when we heard about it. A webhook delayed by an
 * hour, or replayed the next morning after an outage, must not date the payment
 * to its own arrival — a payment made inside a grace period would otherwise
 * look like one made after it expired, and the tenant gets locked out for
 * paying on time.
 *
 * `rawPayload` IS CARRIED BUT NEVER READ
 * --------------------------------------
 * It is stored so a dispute months later can be reconciled against what the
 * provider actually sent. Nothing in the platform parses it for business logic;
 * the normalised fields above are what everything reads. Keeping it on the
 * event rather than fetching it later means the evidence exists even if the
 * provider's dashboard does not.
 */
final class PaymentEvent
{
    /**
     * @param string $provider          Which rail produced this ('cliq', 'mock', …).
     * @param string $externalReference The provider's identifier. Never empty.
     * @param Money  $amount            Negative for a refund.
     * @param ?int   $invoiceId         The invoice this settles, when known.
     * @param ?int   $tenantId          Whose money it is, when the provider says.
     * @param ?string $failureReason    The provider's words, for the operator.
     * @param ?string $paymentMethodRef The instrument, when there was one.
     *
     * @throws PaymentProviderException When the reference is empty.
     */
    public function __construct(
        public readonly PaymentEventType $type,
        public readonly string $provider,
        public readonly string $externalReference,
        public readonly Money $amount,
        public readonly DateTimeImmutable $occurredAt,
        public readonly ?int $invoiceId = null,
        public readonly ?int $tenantId = null,
        public readonly ?string $failureReason = null,
        public readonly ?string $paymentMethodRef = null,
        public readonly string $rawPayload = '',
    ) {
        if (trim($provider) === '') {
            throw new PaymentProviderException(
                'A payment event must say which provider produced it: the ledger keys '
                . 'idempotency on (provider, reference), and two rails may legitimately '
                . 'use the same identifier.'
            );
        }

        if (trim($externalReference) === '') {
            throw new PaymentProviderException(sprintf(
                'The %s adapter produced a payment event with no external reference. '
                . 'That reference is what makes a redelivered webhook a no-op instead of '
                . 'a second credit, so an event without one is refused rather than stored '
                . 'and silently duplicated. An adapter for a provider that sends no '
                . 'identifier must synthesise a deterministic one from the payload.',
                $provider
            ));
        }
    }

    /** Only a settled event moves an invoice toward paid. */
    public function settles(): bool
    {
        return $this->type === PaymentEventType::Succeeded;
    }

    /** What dunning counts. */
    public function isFailure(): bool
    {
        return $this->type === PaymentEventType::Failed;
    }

    /**
     * The same movement, re-typed — for a pending transfer that later confirms.
     *
     * The reference is deliberately carried over unchanged: the confirmation is
     * the SAME movement, so it must collide with the pending row in the ledger
     * and update it, not insert a second row that doubles the balance.
     */
    public function resolvedAs(PaymentEventType $type, ?string $failureReason = null): self
    {
        return new self(
            $type,
            $this->provider,
            $this->externalReference,
            $this->amount,
            $this->occurredAt,
            $this->invoiceId,
            $this->tenantId,
            $failureReason ?? $this->failureReason,
            $this->paymentMethodRef,
            $this->rawPayload,
        );
    }
}
