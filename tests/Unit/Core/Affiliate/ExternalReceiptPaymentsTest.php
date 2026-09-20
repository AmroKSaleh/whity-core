<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Affiliate;

use PHPUnit\Framework\TestCase;
use Tests\Api\FakeBillingPortal;
use Whity\Core\Affiliate\ExternalReceiptPayments;
use Whity\Core\Affiliate\ReferredPayment;
use Whity\Core\Affiliate\ReferredPaymentSourceException;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\Receipt;

/**
 * Reading a referred workspace's payments out of the billing service.
 *
 * Most of the money arrives here rather than through our own invoice table, so
 * this is the source that decides what the affiliate programme actually pays.
 *
 * ── The two facts about the other side that everything here turns on ───────
 *
 * 1. THE BILLING SERVICE HAS NO TAX FIELD, so an invoice's total is already
 *    `subtotal - discount` and the commission base is the whole of it. That is
 *    a fact about their schema, not an assumption about ours, and it is the
 *    reason a receipt with no breakdown can honestly fall back to its total.
 *
 * 2. A FULL REFUND CLEARS `paid_at` AND SETS `status` TO `refunded`; a partial
 *    one leaves both alone and only reduces `amount_paid`. The first of those
 *    is a trap — the obvious reading of a missing payment date is "never paid",
 *    which would skip the receipt and leave a commission standing on money that
 *    was handed back, silently and for good.
 *
 * 3. `refunded_at` IS SET ON EVERY REFUND, partial ones included, and is what
 *    both of those are now read from. It exists because of (2): the billing
 *    service added it rather than leave a consumer inferring a reversal from a
 *    hole. It also removes a false positive we had — an underpaid invoice and a
 *    partly refunded one both leave less settled than the invoice is for, and
 *    the arithmetic could not tell them apart.
 */
final class ExternalReceiptPaymentsTest extends TestCase
{
    // ── The base ────────────────────────────────────────────────────────────

    public function testTheBaseIsTheInvoiceAfterItsDiscount(): void
    {
        $payments = $this->read([
            $this->receipt('INV-1', status: 'paid', subtotal: 15000, discount: 3000, total: 12000, paid: 12000),
        ]);

        self::assertCount(1, $payments);
        self::assertSame(12000, $payments[0]->baseMinor);
    }

    /**
     * A RECEIPT WITH NO BREAKDOWN EARNS ON ITS FACE VALUE, not on zero. An older
     * payload shape, or a field the service stops sending, would otherwise
     * accrue nothing — a silent underpayment that the affiliate finds in their
     * own statement before we find it in ours.
     */
    public function testAReceiptWithoutABreakdownEarnsOnItsTotal(): void
    {
        $payments = $this->read([
            $this->receipt('INV-1', status: 'paid', subtotal: 0, discount: 0, total: 15000, paid: 15000),
        ]);

        self::assertSame(15000, $payments[0]->baseMinor);
    }

    public function testTheReferenceIsTheInvoiceNumber(): void
    {
        $payments = $this->read([$this->receipt('INV-2026-0042')]);

        self::assertSame('INV-2026-0042', $payments[0]->reference);
    }

    public function testEveryPaymentIsMarkedAsComingFromTheBillingService(): void
    {
        $payments = $this->read([$this->receipt('INV-1')]);

        self::assertSame(ReferredPayment::SOURCE_EXTERNAL, $payments[0]->source);
    }

    public function testTheCurrencyIsTheOneTheCustomerWasBilledIn(): void
    {
        $payments = $this->read([$this->receipt('INV-1', currency: 'USD')]);

        self::assertSame('USD', $payments[0]->currency);
    }

    // ── Which receipts are payments ─────────────────────────────────────────

    /**
     * NO PAYMENT DATE MEANS NO MONEY MOVED — for everything except a refund.
     *
     * @dataProvider receiptsThatWereNeverPaid
     */
    public function testAReceiptThatWasNeverPaidIsNotAPayment(string $status): void
    {
        $payments = $this->read([$this->receipt('INV-1', status: $status, paidAt: null, paid: 0)]);

        self::assertSame([], $payments);
    }

    /** @return array<string, array{string}> */
    public static function receiptsThatWereNeverPaid(): array
    {
        return [
            'not issued yet' => ['draft'],
            'still owed' => ['open'],
            'cancelled' => ['void'],
            'written off' => ['uncollectible'],
        ];
    }

    /** A workspace that has bought nothing has no payments, which is not an error. */
    public function testAWorkspaceWithNoReceiptsHasNoPayments(): void
    {
        self::assertSame([], $this->read([]));
    }

    // ── The cleared payment date ────────────────────────────────────────────

    /**
     * A FULLY REFUNDED RECEIPT IS STILL A PAYMENT, even though its payment date
     * is gone.
     *
     * The billing service clears `paid_at` when an invoice is refunded to zero —
     * `amount_paid` is then zero and the two must agree. Reading that absence as
     * "never paid" skips the receipt, so the clawback never happens and the
     * affiliate keeps commission on a sale that was reversed. Nothing anywhere
     * reports it: the sweep re-reads the same history every night and reaches
     * the same wrong conclusion, forever.
     *
     * `refunded_at` is what it is dated from now. We used to guess at `due_at`,
     * which was a number nobody had recorded.
     */
    public function testAFullyRefundedReceiptSurvivesItsClearedPaymentDate(): void
    {
        $payments = $this->read([
            $this->receipt(
                'INV-1',
                status: 'refunded',
                total: 15000,
                paid: 0,
                paidAt: null,
                refundedAt: '2026-04-20T10:00:00+03:00'
            ),
        ]);

        self::assertCount(1, $payments, 'A refund that vanishes is a commission that is never taken back.');
        self::assertTrue($payments[0]->refunded);
        self::assertSame('2026-04-20', $payments[0]->paidAt->format('Y-m-d'), 'Dated from when it was refunded.');
    }

    /**
     * AN UNPAID INVOICE IS NOT A PAYMENT, and never becomes one. It has no
     * `paid_at` for the same reason a refunded one does not, and no
     * `refunded_at` at all — which is exactly the distinction that field exists
     * to draw.
     */
    public function testAnUnpaidReceiptIsNotAPayment(): void
    {
        $payments = $this->read([
            $this->receipt('INV-1', status: 'open', total: 15000, paid: 0, paidAt: null, dueAt: '2026-03-01T10:00:00+03:00'),
        ]);

        self::assertSame([], $payments, 'An unpaid invoice must not become a payment.');
    }

    /**
     * A PARTLY REFUNDED RECEIPT KEEPS ITS PAYMENT DATE, and that is the date to
     * use. It carries BOTH `paid_at` and `refunded_at`; dating the payment from
     * the refund would move a sale to when part of it came back, which is a
     * different month and possibly a different earning window.
     */
    public function testAPartlyRefundedReceiptIsStillDatedFromWhenItWasPaid(): void
    {
        $payments = $this->read([
            $this->receipt(
                'INV-1',
                status: 'paid',
                total: 15000,
                paid: 10000,
                paidAt: '2026-03-01T10:00:00+03:00',
                refundedAt: '2026-04-20T10:00:00+03:00'
            ),
        ]);

        self::assertSame('2026-03-01', $payments[0]->paidAt->format('Y-m-d'));
    }

    /**
     * With no date of any kind there is nothing to stamp a reversal with. The
     * expected cause is an invoice reversed before the billing service had a
     * `refunded_at` column — it is set going forward, not backfilled.
     */
    public function testARefundedReceiptWithNoDateAtAllIsSkipped(): void
    {
        $payments = $this->read([
            $this->receipt('INV-1', status: 'refunded', total: 15000, paid: 0, paidAt: null, dueAt: null),
        ]);

        self::assertSame([], $payments);
    }

    // ── Partial refunds ─────────────────────────────────────────────────────

    /**
     * A PARTIAL REFUND LOOKS EXACTLY LIKE A PAID INVOICE except for two things:
     * `amount_paid` drops, and `refunded_at` is set. The status stays `paid` and
     * the payment date stays put, so without that date a source would go on
     * paying full commission on a partly reversed sale.
     */
    public function testAPartlyRefundedReceiptIsFlagged(): void
    {
        $payments = $this->read([
            $this->receipt(
                'INV-1',
                status: 'paid',
                subtotal: 15000,
                total: 15000,
                paid: 10000,
                refundedAt: '2026-04-20T10:00:00+03:00'
            ),
        ]);

        self::assertFalse($payments[0]->refunded, 'Most of the sale stands.');
        self::assertTrue($payments[0]->partiallyRefunded, 'But some of it does not.');
    }

    /**
     * AN UNDERPAID INVOICE IS NOT A REFUNDED ONE, and this is the false positive
     * that reading `refunded_at` removes.
     *
     * Both leave less settled than the invoice is for, so the arithmetic this
     * used to do — "amount paid is below the total, therefore money came back" —
     * cannot tell them apart. Somebody paying half of what they owe would have
     * been reported as a partial refund, and an operator would have gone looking
     * for a clawback nobody made. A date that is only ever set when money moves
     * OUTWARD says exactly one thing.
     */
    public function testAnUnderpaidReceiptIsNotMistakenForARefund(): void
    {
        $payments = $this->read([
            $this->receipt(
                'INV-1',
                status: 'paid',
                subtotal: 15000,
                total: 15000,
                paid: 9000,
                refundedAt: null
            ),
        ]);

        self::assertFalse($payments[0]->partiallyRefunded, 'Underpaid is not refunded.');
        self::assertFalse($payments[0]->refunded);
    }

    public function testAFullyPaidReceiptIsNeitherKindOfRefund(): void
    {
        $payments = $this->read([
            $this->receipt('INV-1', status: 'paid', subtotal: 15000, total: 15000, paid: 15000),
        ]);

        self::assertFalse($payments[0]->refunded);
        self::assertFalse($payments[0]->partiallyRefunded);
    }

    /** An overpayment is not a refund in either direction. */
    public function testAnOverpaidReceiptIsNotAPartialRefund(): void
    {
        $payments = $this->read([
            $this->receipt('INV-1', status: 'paid', subtotal: 15000, total: 15000, paid: 16000),
        ]);

        self::assertFalse($payments[0]->partiallyRefunded);
    }

    /**
     * A full refund is a clawback, never also a partial one — even though a full
     * reversal sets `refunded_at` just as a partial one does. The status is what
     * separates them.
     */
    public function testAFullRefundIsNotAlsoAPartialOne(): void
    {
        $payments = $this->read([
            $this->receipt(
                'INV-1',
                status: 'refunded',
                total: 15000,
                paid: 0,
                paidAt: null,
                refundedAt: '2026-04-20T10:00:00+03:00'
            ),
        ]);

        self::assertTrue($payments[0]->refunded);
        self::assertFalse($payments[0]->partiallyRefunded);
    }

    // ── When the service cannot be reached ──────────────────────────────────

    /**
     * AN UNREACHABLE SERVICE THROWS RATHER THAN RETURNING NOTHING. "We could not
     * ask" and "they have never paid" produce the same empty list and need
     * opposite responses, and the difference is a commission somebody is owed.
     */
    public function testAFailedCallThrowsRatherThanLookingLikeNoPayments(): void
    {
        $portal = new FakeBillingPortal();
        $portal->failWith = BillingPortalException::unreachable('The billing service is unreachable.');

        $this->expectException(ReferredPaymentSourceException::class);
        (new ExternalReceiptPayments($portal))->paymentsFor(7);
    }

    /**
     * AN UNCONFIGURED PORTAL IS NOT A FAILURE. A self-hosted deployment that
     * sells through nobody has no receipts to read, and making that an error
     * would break a sweep that is working perfectly on its own invoices.
     */
    public function testNoBillingServiceMeansNoPaymentsRatherThanAnError(): void
    {
        $portal = new FakeBillingPortal();
        $portal->configured = false;
        // Answering would be wrong even if it could: a portal with no service
        // behind it must not be asked at all.
        $portal->failWith = BillingPortalException::unreachable('Nobody should have called this.');

        self::assertSame([], (new ExternalReceiptPayments($portal))->paymentsFor(7));
    }

    /**
     * ONE UNREADABLE DATE MUST NOT COST EVERY LATER RECEIPT. A timestamp this
     * side cannot parse throws from the constructor, and letting that escape
     * would abandon a whole workspace's history over one malformed field.
     */
    public function testAnUnreadableDateSkipsOneReceiptAndKeepsTheRest(): void
    {
        $payments = $this->read([
            $this->receipt('INV-BAD', paidAt: 'the day before yesterday'),
            $this->receipt('INV-GOOD'),
        ]);

        self::assertCount(1, $payments);
        self::assertSame('INV-GOOD', $payments[0]->reference);
    }

    // ── The subject the service is asked about ──────────────────────────────

    /**
     * THE WORKSPACE IS NAMED THE WAY THE BILLING SERVICE KNOWS IT. A bare tenant
     * id would either miss entirely or, once seats and tenants' own customers
     * can pay, collide with a different kind of payer holding the same number —
     * and read somebody else's payment history as this workspace's.
     */
    public function testTheServiceIsAskedAboutThePrefixedSubject(): void
    {
        $portal = new FakeBillingPortal();
        $portal->receipts = [$this->receipt('INV-1')];

        (new ExternalReceiptPayments($portal))->paymentsFor(42);

        self::assertSame(['tenant-42'], $portal->receiptSubjects);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @param list<Receipt> $receipts
     *
     * @return list<ReferredPayment>
     */
    private function read(array $receipts): array
    {
        $portal = new FakeBillingPortal();
        $portal->receipts = $receipts;

        return (new ExternalReceiptPayments($portal))->paymentsFor(7);
    }

    private function receipt(
        string $number,
        string $status = 'paid',
        int $subtotal = 15000,
        int $discount = 0,
        int $total = 15000,
        int $paid = 15000,
        string $currency = 'JOD',
        ?string $paidAt = '2026-03-01T10:00:00+03:00',
        ?string $dueAt = '2026-02-25T10:00:00+03:00',
        ?string $refundedAt = null,
    ): Receipt {
        // BUILT THROUGH fromPayload, not through the constructor, so the field
        // names the billing service actually sends are part of what is pinned
        // here. A constructor call would keep passing after a rename that made
        // every real receipt read as zero.
        return Receipt::fromPayload([
            'number' => $number,
            'status' => $status,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'amount_paid' => $paid,
            'currency' => $currency,
            'paid_at' => $paidAt,
            'due_at' => $dueAt,
            'refunded_at' => $refundedAt,
        ]);
    }
}
