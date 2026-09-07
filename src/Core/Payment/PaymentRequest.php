<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

use Whity\Core\Money\Money;

/**
 * What the platform is asking a provider to collect.
 *
 * `idempotencyKey` IS NOT OPTIONAL. Initiating a payment is the one operation
 * where a retry is genuinely dangerous: the request that appears to have failed
 * may have succeeded at the provider and lost its response, and a naive retry
 * charges the customer twice. Every serious provider accepts an idempotency key
 * for exactly this, and an adapter that ignores it is a bug in the adapter
 * rather than an option for the caller.
 *
 * It is derived from the invoice and attempt rather than random, because a
 * random key regenerated on retry is not an idempotency key at all — it is a
 * second request wearing the same name. {@see self::forInvoiceAttempt()} makes
 * the derivation the easy path.
 */
final class PaymentRequest
{
    /**
     * @param Money                 $amount        Never zero; negative is a refund.
     * @param int                   $tenantId      Whose money.
     * @param ?int                  $invoiceId     What it settles, when known.
     * @param ?string               $paymentMethodRef A stored instrument, for a
     *                                             recurring charge. Absent means
     *                                             the payer chooses.
     * @param ?string               $returnUrl     Where a redirect flow comes back to.
     * @param array<string, string> $metadata      Passed through to the provider.
     */
    public function __construct(
        public readonly Money $amount,
        public readonly int $tenantId,
        public readonly string $idempotencyKey,
        public readonly ?int $invoiceId = null,
        public readonly ?string $paymentMethodRef = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $description = null,
        public readonly array $metadata = [],
    ) {
        if (trim($idempotencyKey) === '') {
            throw new PaymentProviderException(
                'A payment request needs an idempotency key. Without one a retry of a '
                . 'request whose response was lost charges the customer a second time.'
            );
        }

        if ($amount->amount === 0) {
            throw new PaymentProviderException('Refusing to collect nothing.');
        }
    }

    /**
     * The derived key: same invoice, same attempt, same key — so a retry is
     * recognisably the same request, and the NEXT dunning attempt is
     * recognisably a different one.
     */
    public static function forInvoiceAttempt(
        Money $amount,
        int $tenantId,
        int $invoiceId,
        int $attempt,
        ?string $paymentMethodRef = null,
        ?string $returnUrl = null,
        ?string $description = null,
    ): self {
        return new self(
            $amount,
            $tenantId,
            sprintf('inv-%d-attempt-%d', $invoiceId, $attempt),
            $invoiceId,
            $paymentMethodRef,
            $returnUrl,
            $description,
        );
    }
}
