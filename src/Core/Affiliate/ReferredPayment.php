<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use DateTimeImmutable;

/**
 * One payment a referred workspace made, in the only terms a commission needs.
 *
 * WHY A SHARED SHAPE RATHER THAN TWO ACCRUAL PATHS: the money can come from an
 * invoice this deployment raised or from one the billing service raised, and
 * those are read completely differently — one is a table, the other is an HTTP
 * call returning somebody else's object. What a commission needs from either is
 * identical: how much, in what currency, when, and a reference stable enough to
 * accrue against exactly once.
 *
 * Two accrual paths would have to agree about the window, the rate, the
 * rounding and the idempotency key, and would drift the first time one of them
 * was fixed.
 */
final class ReferredPayment
{
    /** Raised by this deployment's own billing run. */
    public const SOURCE_INVOICE = 'invoice';

    /** Raised by the external billing service. */
    public const SOURCE_EXTERNAL = 'external';

    public function __construct(
        /** 'invoice' or 'external' — which system this came from. */
        public readonly string $source,
        /**
         * Stable for the life of the payment.
         *
         * HALF THE IDEMPOTENCY KEY, with the referral. The accrual sweep
         * re-reads a workspace's whole payment history every run, so if this
         * reference changed between runs the same payment would be paid for
         * twice. An invoice id or number, never a row position or a timestamp.
         */
        public readonly string $reference,
        /**
         * What the commission is calculated on: after discounts, excluding tax.
         */
        public readonly int $baseMinor,
        public readonly string $currency,
        public readonly DateTimeImmutable $paidAt,
        /**
         * Whether this payment has since been refunded.
         *
         * Carried on the payment rather than discovered separately, because the
         * accrual sweep re-reads history anyway — so the refund arrives on the
         * same pass that would otherwise have left the commission standing.
         */
        public readonly bool $refunded = false,
        /**
         * Some of the money went back, but not all of it.
         *
         * SEPARATE FROM `refunded` BECAUSE IT CANNOT BE ACTED ON. The ledger
         * holds one commission per payment and reverses it whole; it has no way
         * to express "give back a third". Folding a partial refund into
         * `refunded` would claw back the whole commission on a customer who kept
         * most of what they bought, and ignoring it would pay on money that was
         * returned.
         *
         * So it is carried, counted, and left for a person — the only honest
         * option until the ledger learns to reverse in part.
         */
        public readonly bool $partiallyRefunded = false,
    ) {
    }
}
