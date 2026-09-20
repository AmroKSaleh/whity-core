<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Affiliate\LocalInvoicePayments;
use Whity\Core\Affiliate\ReferredPayment;

/**
 * Reading a referred workspace's payments out of this deployment's own invoices.
 *
 * ── Why this needs a real database rather than a stub ──────────────────────
 *
 * Everything worth checking here is a fact about the schema, not about PHP. The
 * commission base excludes tax because `total = subtotal - discount + tax` is a
 * CHECK constraint. A refund is invisible in the invoice row because a refund is
 * a negative line in another table. An unpaid invoice and a fully refunded one
 * differ only in a status the refund does not change.
 *
 * A source tested against hand-made arrays would agree with whatever the author
 * believed those tables looked like, which is exactly the belief that needs
 * checking.
 *
 * ── The failure this is mostly here to prevent ─────────────────────────────
 *
 * Local refunds are DETECTED BY ARITHMETIC, not by a flag: there is no
 * `refunded` invoice status and there should not be one, because a refund is a
 * negative row and that is what keeps a balance a SUM. The consequence is that a
 * refunded invoice still reads `paid`, and a source that trusted the status
 * would go on paying commission on money that was handed back — with no
 * symptom anywhere until an affiliate reconciled their own statement.
 */
final class LocalInvoicePaymentsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private LocalInvoicePayments $payments;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1,'a','a'), (2,'b','b')");
        $this->payments = new LocalInvoicePayments($this->pdo);
    }

    // ── The base ────────────────────────────────────────────────────────────

    /**
     * TAX IS NOT INCOME AND IS NOT OURS TO SHARE. It is collected on the state's
     * behalf and passed straight on, so paying a fifth of it to an affiliate
     * would mean paying commission out of a liability — money that is already
     * somebody else's before it arrives.
     */
    public function testTheCommissionBaseExcludesTax(): void
    {
        // 15.000 JOD net, 16% sales tax, so the customer pays 17.400 and the
        // affiliate earns on 15.000.
        $this->paidInvoice('INV-1', subtotal: 15000, discount: 0, tax: 2400);

        $payments = $this->payments->paymentsFor(1);

        self::assertCount(1, $payments);
        self::assertSame(15000, $payments[0]->baseMinor, 'The tax is left out of what is shared.');
    }

    /** A discount reduces what was earned, because it reduced what was paid. */
    public function testTheCommissionBaseIsNetOfDiscount(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, discount: 3000, tax: 0);

        self::assertSame(12000, $this->payments->paymentsFor(1)[0]->baseMinor);
    }

    /** Both at once, which is the ordinary promoted sale. */
    public function testDiscountAndTaxAreBothHandled(): void
    {
        // 20% off 15.000 is 12.000 net; 16% tax on that is 1.920.
        $this->paidInvoice('INV-1', subtotal: 15000, discount: 3000, tax: 1920);

        self::assertSame(12000, $this->payments->paymentsFor(1)[0]->baseMinor);
    }

    /** A fully discounted invoice earns on nothing rather than on its tax. */
    public function testAFullyDiscountedInvoiceHasAZeroBase(): void
    {
        $this->paidInvoice('INV-FREE', subtotal: 15000, discount: 15000, tax: 0);

        self::assertSame(0, $this->payments->paymentsFor(1)[0]->baseMinor);
    }

    /**
     * A FREE INVOICE IS NOT A REFUNDED ONE, though the arithmetic cannot tell
     * them apart on its own — both have nothing settled against them.
     *
     * A zero-total invoice never gets a ledger row at all, because the ledger
     * forbids a zero amount. Read as a refund, every promotional signup would
     * arrive at the sweep asking for a clawback of a commission nobody earned —
     * harmless today only because the accrual skipped it first, which is not a
     * property worth depending on.
     */
    public function testAFreeInvoiceIsNotMistakenForARefundedOne(): void
    {
        $this->paidInvoice('INV-FREE', subtotal: 15000, discount: 15000, tax: 0);

        $payment = $this->payments->paymentsFor(1)[0];

        self::assertFalse($payment->refunded, 'Nothing was paid, so nothing went back.');
        self::assertFalse($payment->partiallyRefunded);
    }

    // ── Which invoices count ────────────────────────────────────────────────

    /**
     * ONLY PAID INVOICES. An open one is a debt, not revenue, and paying
     * commission on it would mean paying out of money that may never arrive —
     * from a customer who, on this path, is about to be chased for it by the
     * dunning machine.
     *
     * @dataProvider invoicesThatAreNotRevenue
     */
    public function testAnInvoiceThatIsNotPaidIsNotAPayment(string $status): void
    {
        $this->invoice('INV-1', status: $status, subtotal: 15000, paidAt: null);

        self::assertSame([], $this->payments->paymentsFor(1));
    }

    /** @return array<string, array{string}> */
    public static function invoicesThatAreNotRevenue(): array
    {
        return [
            'still owed' => ['open'],
            'cancelled' => ['void'],
            'written off' => ['uncollectible'],
        ];
    }

    /** A draft is not even a document yet. */
    public function testADraftIsNotAPayment(): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO invoices (tenant_id, currency, status, subtotal_minor, total_minor)
             VALUES (1, 'JOD', 'draft', 15000, 15000)"
        );
        $statement->execute();

        self::assertSame([], $this->payments->paymentsFor(1));
    }

    /** One workspace's invoices are not another's. */
    public function testOnlyThisWorkspacesInvoicesAreReturned(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000);
        $this->paidInvoice('INV-2', subtotal: 9000, tenantId: 2);

        $payments = $this->payments->paymentsFor(1);

        self::assertCount(1, $payments);
        self::assertSame('INV-1', $payments[0]->reference);
    }

    /** The source says which system the money came from, and it is not guessed. */
    public function testEveryPaymentIsMarkedAsALocalInvoice(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000);

        self::assertSame(ReferredPayment::SOURCE_INVOICE, $this->payments->paymentsFor(1)[0]->source);
    }

    /** The reference is the number on the document, which is what an affiliate can look up. */
    public function testTheReferenceIsTheInvoiceNumber(): void
    {
        $this->paidInvoice('INV-2026-00042', subtotal: 15000);

        self::assertSame('INV-2026-00042', $this->payments->paymentsFor(1)[0]->reference);
    }

    /** The currency is the one the customer was billed in, not a platform default. */
    public function testTheCurrencyIsTheInvoicesOwn(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, currency: 'USD');

        self::assertSame('USD', $this->payments->paymentsFor(1)[0]->currency);
    }

    /** When the money moved, which is not when the invoice was raised. */
    public function testThePaymentDateIsWhenItWasPaid(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, paidAt: '2026-04-17 09:30:00');

        self::assertSame('2026-04-17', $this->payments->paymentsFor(1)[0]->paidAt->format('Y-m-d'));
    }

    // ── Refunds, which the invoice row does not mention ─────────────────────

    /**
     * A FULLY REFUNDED INVOICE STILL SAYS `paid`, AND IS STILL A REFUND.
     *
     * This is the shape of the whole risk on this side. There is no `refunded`
     * status here by design — a refund is a negative row so that a balance stays
     * a SUM — which means the invoice a commission was earned on looks exactly
     * the same before and after the money went back. Only the ledger differs.
     */
    public function testAFullyRefundedInvoiceIsReportedAsRefunded(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 2400);
        $this->refund('INV-1', 17400);

        $payment = $this->payments->paymentsFor(1)[0];

        self::assertTrue($payment->refunded, 'The status still says paid; the money says otherwise.');
        self::assertFalse($payment->partiallyRefunded);
    }

    /** Refunded in instalments still nets to nothing, and is still a full refund. */
    public function testARefundPaidBackInPartsIsStillAFullRefund(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 0);
        $this->refund('INV-1', 5000);
        $this->refund('INV-1', 10000);

        self::assertTrue($this->payments->paymentsFor(1)[0]->refunded);
    }

    /** A goodwill overpayment on the way out is even more certainly not revenue. */
    public function testARefundLargerThanThePaymentIsAFullRefund(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 0);
        $this->refund('INV-1', 16000);

        self::assertTrue($this->payments->paymentsFor(1)[0]->refunded);
    }

    /** Part of it went back: reported as such, and not as a full reversal. */
    public function testAPartialRefundIsReportedSeparately(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 0);
        $this->refund('INV-1', 5000);

        $payment = $this->payments->paymentsFor(1)[0];

        self::assertFalse($payment->refunded, 'Most of the sale stands.');
        self::assertTrue($payment->partiallyRefunded, 'But some of it did not.');
    }

    /** An untouched payment is neither. */
    public function testAnUnrefundedPaymentIsNeitherFlag(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 0);

        $payment = $this->payments->paymentsFor(1)[0];

        self::assertFalse($payment->refunded);
        self::assertFalse($payment->partiallyRefunded);
    }

    /**
     * ONLY SETTLED MOVEMENTS COUNT, on the way out as much as on the way in. A
     * refund that has been initiated and not yet settled is money still with us;
     * treating it as gone would claw back a commission on the strength of
     * somebody having clicked refund.
     *
     * @dataProvider unsettledRefunds
     */
    public function testARefundThatHasNotSettledDoesNotCountYet(string $status): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 0);
        $this->refund('INV-1', 15000, status: $status);

        $payment = $this->payments->paymentsFor(1)[0];

        self::assertFalse($payment->refunded);
        self::assertFalse($payment->partiallyRefunded);
    }

    /** @return array<string, array{string}> */
    public static function unsettledRefunds(): array
    {
        return [
            'still in flight' => ['pending'],
            'it did not go through' => ['failed'],
        ];
    }

    /**
     * A REFUND AGAINST A DIFFERENT INVOICE DOES NOT TOUCH THIS ONE. The settled
     * sum is scoped to the invoice as well as the tenant — a sum that forgot
     * the invoice would make one customer's refund reverse a commission earned
     * on an unrelated sale.
     */
    public function testARefundOnAnotherInvoiceLeavesThisOneAlone(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 0);
        $this->paidInvoice('INV-2', subtotal: 9000, tax: 0);
        $this->refund('INV-2', 9000);

        $byRef = [];
        foreach ($this->payments->paymentsFor(1) as $payment) {
            $byRef[$payment->reference] = $payment;
        }

        self::assertFalse($byRef['INV-1']->refunded);
        self::assertTrue($byRef['INV-2']->refunded);
    }

    /**
     * A LEDGER ROW FILED UNDER THE WRONG TENANT DOES NOT REACH THIS INVOICE.
     *
     * Nothing in the schema ties a transaction's tenant to its invoice's — the
     * two foreign keys point at different tables and neither knows about the
     * other — so a mis-tenanted row is storable, and the only thing standing
     * between it and this arithmetic is the tenant predicate in the subquery.
     *
     * Without it, a refund misfiled against another workspace reverses THIS
     * workspace's commission. The predicate reads like redundancy next to
     * `invoice_id`, which is exactly why it invites deletion by somebody
     * tidying; this is the test that says no.
     */
    public function testARefundFiledUnderAnotherTenantDoesNotCountHere(): void
    {
        $this->paidInvoice('INV-1', subtotal: 15000, tax: 0);

        // The right invoice, the wrong tenant — the shape a cross-tenant bug
        // elsewhere would leave behind.
        $this->transaction('INV-1', -15000, 'succeeded', tenantId: 2, invoiceOwner: 1);

        $payment = $this->payments->paymentsFor(1)[0];

        self::assertFalse($payment->refunded, 'Another tenant\'s row must not reverse this sale.');
        self::assertFalse($payment->partiallyRefunded);
    }

    // ── Order ───────────────────────────────────────────────────────────────

    /**
     * OLDEST FIRST. The sweep sorts for itself, but the earning window is opened
     * from the first payment and frozen permanently — so this is the one
     * ordering mistake that cannot be corrected by a later run.
     */
    public function testPaymentsComeBackOldestFirst(): void
    {
        $this->paidInvoice('INV-LATER', subtotal: 15000, paidAt: '2026-05-01 10:00:00');
        $this->paidInvoice('INV-FIRST', subtotal: 15000, paidAt: '2026-03-01 10:00:00');

        $references = array_map(
            static fn (ReferredPayment $p): string => $p->reference,
            $this->payments->paymentsFor(1)
        );

        self::assertSame(['INV-FIRST', 'INV-LATER'], $references);
    }

    /** A workspace that has paid nothing has no payments, which is not an error. */
    public function testAWorkspaceWithNoInvoicesHasNoPayments(): void
    {
        self::assertSame([], $this->payments->paymentsFor(1));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function paidInvoice(
        string $number,
        int $subtotal,
        int $discount = 0,
        int $tax = 0,
        int $tenantId = 1,
        string $currency = 'JOD',
        string $paidAt = '2026-03-01 12:00:00',
    ): void {
        $this->invoice($number, 'paid', $subtotal, $discount, $tax, $tenantId, $currency, $paidAt);

        $total = $subtotal - $discount + $tax;

        // The money that settled it. Without this a "paid" invoice reads as
        // fully refunded, which is correct arithmetic on an incorrect fixture —
        // and exactly the trap this class is about.
        //
        // A FULLY DISCOUNTED INVOICE GETS NO ROW, because the ledger forbids a
        // zero-amount one: a record of nothing is not a record. That is not a
        // quirk of the fixture, it is what the real table looks like, and it is
        // why "nothing settled" cannot mean "refunded" on its own.
        if ($total !== 0) {
            $this->transaction($number, $total, 'succeeded', $tenantId, $currency);
        }
    }

    private function invoice(
        string $number,
        string $status,
        int $subtotal,
        int $discount = 0,
        int $tax = 0,
        int $tenantId = 1,
        string $currency = 'JOD',
        ?string $paidAt = '2026-03-01 12:00:00',
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO invoices (tenant_id, number, series, status, currency,
                                   subtotal_minor, discount_minor, tax_minor, total_minor, paid_at)
             VALUES (:tenant, :number, :series, :status, :currency,
                     :subtotal, :discount, :tax, :total, :paid_at)'
        );
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':number', $number);
        // A series per tenant, because (series, number) is globally unique and
        // two workspaces in one test would otherwise collide on 'INV-1'.
        $statement->bindValue(':series', 'tenant-' . $tenantId);
        $statement->bindValue(':status', $status);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':subtotal', $subtotal, PDO::PARAM_INT);
        $statement->bindValue(':discount', $discount, PDO::PARAM_INT);
        $statement->bindValue(':tax', $tax, PDO::PARAM_INT);
        $statement->bindValue(':total', $subtotal - $discount + $tax, PDO::PARAM_INT);
        $statement->bindValue(':paid_at', $paidAt, $paidAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }

    /** Money going back out: a negative row, which is the only kind of refund here. */
    private function refund(string $number, int $amount, string $status = 'succeeded'): void
    {
        $this->transaction($number, -$amount, $status);
    }

    /**
     * @param int|null $invoiceOwner Which tenant's invoice to attach this to,
     *                               when that is deliberately NOT the tenant the
     *                               row is filed under. Only the mis-tenanted
     *                               test passes it; everything else files the
     *                               row where it belongs.
     */
    private function transaction(
        string $invoiceNumber,
        int $amountMinor,
        string $status,
        int $tenantId = 1,
        string $currency = 'JOD',
        ?int $invoiceOwner = null,
    ): void {
        $statement = $this->pdo->prepare('SELECT id FROM invoices WHERE number = :n AND tenant_id = :t');
        $statement->execute([':n' => $invoiceNumber, ':t' => $invoiceOwner ?? $tenantId]);
        $invoiceId = (int) $statement->fetchColumn();

        $statement = $this->pdo->prepare(
            'INSERT INTO payment_transactions (tenant_id, invoice_id, provider, status,
                                               amount_minor, currency, occurred_at)
             VALUES (:tenant, :invoice, :provider, :status, :amount, :currency, :at)'
        );
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':invoice', $invoiceId, PDO::PARAM_INT);
        $statement->bindValue(':provider', 'mock');
        $statement->bindValue(':status', $status);
        $statement->bindValue(':amount', $amountMinor, PDO::PARAM_INT);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':at', '2026-03-01 12:00:00');
        $statement->execute();
    }
}
