<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\BillingSubject;
use Whity\Core\Billing\External\Receipt;

/**
 * What a referred workspace has paid to the external billing service.
 *
 * Most of the money arrives here rather than through our own invoice table: the
 * billing service charges the customer, renews them, and owns their period. Its
 * receipts are the only record of those payments this side will ever see.
 *
 * ── The most recent fifty, which is not the whole history ──────────────────
 *
 * The customer object carries the fifty most recent invoices, and the interface
 * this implements asks for everything. That is a real limit, and it is the right
 * trade rather than a compromise: reading it is one HTTP call, the accrual is
 * idempotent so re-reading costs a collision, and a referral's earning window is
 * months — fifty monthly invoices is four years of them. A workspace could only
 * outrun it by billing weekly for a year while nothing swept, and in that case
 * the older payments were already accrued on an earlier pass and stay accrued:
 * commissions are never deleted for falling off the end of a page.
 *
 * ── Unreachable is not "never paid" ────────────────────────────────────────
 *
 * A failed call throws rather than returning nothing. The two are
 * indistinguishable to the sweep and need opposite responses — see
 * {@see ReferredPaymentSourceException}.
 *
 * An UNCONFIGURED portal is different and genuinely means no payments: a
 * self-hosted deployment that sells through nobody has no receipts to read, and
 * making that an error would break a sweep that is working correctly.
 */
final class ExternalReceiptPayments implements ReferredPaymentSource
{
    public function __construct(
        private readonly BillingPortal $portal,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return list<ReferredPayment>
     */
    public function paymentsFor(int $tenantId): array
    {
        if (!$this->portal->isConfigured()) {
            return [];
        }

        try {
            $receipts = $this->portal->receiptsFor(BillingSubject::forTenant($tenantId));
        } catch (BillingPortalException $e) {
            throw new ReferredPaymentSourceException(
                'The billing service could not be asked what this workspace has paid.',
                0,
                $e
            );
        }

        $payments = [];

        foreach ($receipts as $receipt) {
            $paidAt = $this->occurredAt($receipt);
            if ($paidAt === null) {
                // Draft, open, void, uncollectible: no money ever moved, so
                // there is nothing to earn on and nothing to take back.
                continue;
            }

            $payments[] = new ReferredPayment(
                ReferredPayment::SOURCE_EXTERNAL,
                $receipt->number,
                $receipt->commissionBaseMinor(),
                $receipt->currency,
                $paidAt,
                refunded: $receipt->isRefunded(),
                partiallyRefunded: $receipt->isPartiallyRefunded(),
            );
        }

        return $payments;
    }

    /**
     * When the money moved, or null if it never did.
     *
     * ── A FULL REFUND ERASES THE PAYMENT DATE, and that is a trap ──────────
     *
     * The billing service clears `paid_at` when an invoice is refunded to zero
     * — reasonably, from its side: nothing is paid any more. From this side the
     * obvious reading is "never paid", which would skip the receipt, never
     * reverse the commission, and leave an affiliate holding money for a sale
     * that was given back. Silently, for good, with a green suite: the refund
     * simply stops existing on the only pass that would have caught it.
     *
     * So a refunded receipt falls back to its issue date, the closest thing left
     * to when this happened. The fallback is deliberately NOT general: for an
     * open or draft invoice a missing `paid_at` means exactly what it says, and
     * dating it from `due_at` would invent a payment nobody made.
     *
     * The date is near-cosmetic for a reversal in any case — a clawback finds
     * its original by invoice number, not by calendar — but it is what the
     * ledger row is stamped with, and a reversal dated years off is one somebody
     * has to explain.
     *
     * PARSED DEFENSIVELY BECAUSE IT CROSSES A WIRE. A timestamp we cannot read
     * throws from DateTimeImmutable's constructor, which would abort the whole
     * sweep over one malformed field on one invoice.
     */
    private function occurredAt(Receipt $receipt): ?DateTimeImmutable
    {
        $raw = $receipt->paidAt ?? ($receipt->isRefunded() ? $receipt->issuedAt : null);

        if ($raw === null) {
            if ($receipt->isRefunded()) {
                // Refunded, and no date of any kind survived. Named rather than
                // dropped quietly: it is a commission that will not be clawed
                // back, and somebody has to know which one.
                $this->logger->warning('A refunded receipt carried no date; its commission cannot be reversed', [
                    'receipt' => $receipt->number,
                ]);
            }

            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (\Exception) {
            $this->logger->warning('A receipt carried a date this side could not read', [
                'receipt' => $receipt->number,
                'value' => $raw,
            ]);

            return null;
        }
    }
}
