<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use DateTimeImmutable;
use PDO;
use Whity\Core\Billing\InvoiceRepository;

/**
 * What a referred workspace has paid on invoices this deployment raised itself.
 *
 * ── The commission base is not the invoice total ───────────────────────────
 *
 * `total = subtotal - discount + tax`, and an affiliate earns on none of the
 * tax. Tax is money collected on the state's behalf and passed on; sharing a
 * fifth of it would mean paying commission out of a liability. So the base is
 * `subtotal - discount` read straight off the invoice — both are snapshots
 * taken at issue, so a rate change or a retired promotion next year cannot
 * restate what somebody already earned.
 *
 * ── Only PAID invoices, and what "refunded" means here ─────────────────────
 *
 * This deployment has no `refunded` invoice status and should not grow one: a
 * refund is a NEGATIVE row in `payment_transactions`, so a balance stays a SUM
 * and no report can get the sign wrong by forgetting to branch. The consequence
 * is that a refund leaves the invoice sitting at `paid` while the money quietly
 * goes back out.
 *
 * So a refund is detected by arithmetic rather than by a flag: an invoice is
 * `paid`, and what has actually SETTLED against it is now less than its total.
 * The comparison is only meaningful on a paid invoice — an open one is under-
 * settled because nobody has paid it yet, which is not a refund and must not
 * claw anything back.
 *
 * ── Why the invoice number and not its id ──────────────────────────────────
 *
 * The number is half the idempotency key, so it has to be stable for the life
 * of the payment — and it is: allocated once at issue, never edited, because an
 * invoice is evidence. It is also the thing an affiliate can look up when they
 * ask why a line is on their statement, which an internal row id is not.
 */
final class LocalInvoicePayments implements ReferredPaymentSource
{
    /** Only settled movements count. A transfer in flight is not money. */
    private const SETTLED = 'succeeded';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<ReferredPayment>
     */
    public function paymentsFor(int $tenantId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.number,
                    i.currency,
                    i.paid_at,
                    i.subtotal_minor,
                    i.discount_minor,
                    i.total_minor,
                    (SELECT COALESCE(SUM(t.amount_minor), 0)
                       FROM payment_transactions t
                      WHERE t.invoice_id = i.id
                        AND t.tenant_id = i.tenant_id
                        AND t.status = :settled) AS settled_minor
               FROM invoices i
              WHERE i.tenant_id = :tenant
                AND i.status = :paid
                AND i.paid_at IS NOT NULL
                -- A paid invoice always has a number, by CHECK constraint.
                -- Demanded anyway: a row without one would produce a commission
                -- keyed on an empty string, and the SECOND such invoice would
                -- collide with the first and silently never be paid for.
                AND i.number IS NOT NULL
              ORDER BY i.paid_at ASC, i.id ASC'
        );
        $statement->bindValue(':settled', self::SETTLED);
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':paid', InvoiceRepository::STATUS_PAID);
        $statement->execute();

        $payments = [];

        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $total = (int) $row['total_minor'];
            $settled = (int) $row['settled_minor'];

            $payments[] = new ReferredPayment(
                ReferredPayment::SOURCE_INVOICE,
                (string) $row['number'],
                max(0, (int) $row['subtotal_minor'] - (int) $row['discount_minor']),
                (string) $row['currency'],
                new DateTimeImmutable((string) $row['paid_at']),
                // NOTHING LEFT SETTLED means the money went back in full. `<= 0`
                // rather than `=== 0` because a refund larger than the payment
                // is possible — a goodwill overpayment on the way out — and a
                // negative balance is even more certainly not revenue.
                //
                // `$total > 0` FIRST, and it is not defensive padding. A fully
                // discounted invoice is marked paid with no transaction against
                // it at all — the ledger forbids a zero-amount row, because a
                // record of nothing is not a record — so it settles to zero
                // through never having been paid rather than through being
                // refunded. Without this guard every free invoice arrives asking
                // for a clawback of a commission that was never earned.
                refunded: $total > 0 && $settled <= 0,
                // Some of it went back. Counted and left alone; see
                // ReferredPayment::$partiallyRefunded for why neither available
                // answer is right.
                partiallyRefunded: $settled > 0 && $settled < $total,
            );
        }

        return $payments;
    }
}
