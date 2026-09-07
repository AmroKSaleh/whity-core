<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;
use Whity\Core\Payment\PaymentEvent;
use Whity\Core\Payment\PaymentLedger;

/**
 * Where a payment event stops being a message and becomes a settled invoice.
 *
 * This is the only place that decides an invoice is paid, and it does so by
 * asking one question: does what has actually SETTLED cover what is owed? Not
 * "did a success event arrive", which is a different question with a different
 * answer whenever somebody pays in two instalments, pays the wrong amount, or
 * pays an invoice that a refund later partly reversed.
 *
 * A PARTIAL PAYMENT LEAVES THE INVOICE OPEN, and that is the behaviour that
 * matters most here. The tempting shortcut is to mark an invoice paid when a
 * success event arrives for it — and a payer who typed 50 into their bank app
 * instead of 500 then has a settled invoice and a balance of 450 that nobody
 * will ever chase. The money is recorded either way; only the conclusion
 * differs, and the conclusion is the part that reaches the customer.
 *
 * AN OVERPAYMENT SETTLES THE INVOICE AND LEAVES THE EXCESS VISIBLE. It is not
 * refused: the money has already moved, and refusing it would mean holding a
 * payment the platform cannot explain. The invoice is paid, the ledger shows
 * more than the total, and {@see self::balanceFor()} reports a negative
 * balance, which is a credit somebody can act on.
 *
 * NOTHING HERE TRUSTS THE EVENT'S OWN TENANT. A provider's callback knows its
 * transaction, not our tenancy, so the tenant is resolved from the INVOICE the
 * reference names. An event claiming a tenant would be a tenant id supplied by
 * a remote party, which is the shape of a cross-tenant write.
 */
final class PaymentReconciler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PaymentLedger $ledger,
        private readonly \PDO $pdo,
    ) {
    }

    /**
     * Record an event and re-evaluate whatever invoice it touches.
     *
     * @return array{outcome: string, invoice_id: ?int, settled: bool}
     */
    public function apply(PaymentEvent $event, DateTimeImmutable $now): array
    {
        $invoiceId = $event->invoiceId;

        if ($invoiceId === null) {
            // Money with no invoice: recorded against the tenant if the event
            // named one, and left for a human otherwise. It is NOT dropped —
            // an unattributed payment that vanishes is the worst outcome
            // available, because the customer knows they paid.
            if ($event->tenantId === null) {
                return ['outcome' => 'unattributed', 'invoice_id' => null, 'settled' => false];
            }

            return [
                'outcome' => $this->ledger->record($event, $event->tenantId),
                'invoice_id' => null,
                'settled' => false,
            ];
        }

        $tenantId = $this->tenantOwning($invoiceId);

        if ($tenantId === null) {
            // The reference decoded to an invoice that does not exist. A payer
            // typed a reference from a different deployment, or one we never
            // issued. Recording it against a guessed tenant would be worse than
            // leaving it for a human.
            return ['outcome' => 'unknown_invoice', 'invoice_id' => $invoiceId, 'settled' => false];
        }

        $outcome = $this->ledger->record($event, $tenantId, $invoiceId);

        if ($outcome === PaymentLedger::DUPLICATE) {
            // Already terminal and unchanged. Re-settling would be harmless
            // today and is exactly the kind of "harmless" that stops being so
            // when settlement grows a side effect — an email, a webhook out.
            return ['outcome' => $outcome, 'invoice_id' => $invoiceId, 'settled' => false];
        }

        return [
            'outcome' => $outcome,
            'invoice_id' => $invoiceId,
            'settled' => $this->settleIfCovered($tenantId, $invoiceId, $now),
        ];
    }

    /**
     * Mark the invoice paid when what has settled covers what is owed.
     *
     * @return bool Whether this call is what settled it.
     */
    public function settleIfCovered(int $tenantId, int $invoiceId, DateTimeImmutable $now): bool
    {
        $invoice = $this->invoices->findById($tenantId, $invoiceId);

        if ($invoice === null || $invoice['status'] !== InvoiceRepository::STATUS_OPEN) {
            return false;
        }

        $settled = $this->ledger->amountSettledMinor($tenantId, $invoiceId);

        if ($settled < (int) $invoice['total_minor']) {
            // Partial. The money is recorded; the debt is not discharged.
            return false;
        }

        $this->invoices->markPaid($tenantId, $invoiceId, $now);

        return true;
    }

    /**
     * What is still owed, in minor units. Negative means overpaid.
     */
    public function balanceFor(int $tenantId, int $invoiceId): int
    {
        $invoice = $this->invoices->findById($tenantId, $invoiceId);

        if ($invoice === null) {
            return 0;
        }

        return (int) $invoice['total_minor'] - $this->ledger->amountSettledMinor($tenantId, $invoiceId);
    }

    /**
     * Which tenant owns an invoice — public, because the webhook handler needs
     * it to decide whose access to restore, and duplicating the lookup there
     * would mean two answers to one question.
     */
    public function tenantForInvoice(int $invoiceId): ?int
    {
        return $this->tenantOwning($invoiceId);
    }

    /**
     * Which tenant owns an invoice.
     *
     * A deliberate cross-tenant read, and the only one here: a webhook arrives
     * with no tenant context at all, and this is what establishes it. Every
     * subsequent statement binds the tenant this returns, so the predicate is
     * present everywhere it matters.
     *
     * @tenant-guard-ignore The lookup that ESTABLISHES the tenant cannot bind it.
     */
    private function tenantOwning(int $invoiceId): ?int
    {
        // @tenant-guard-ignore: this is the lookup that ESTABLISHES the tenant.
        // A webhook arrives with no tenant context at all, and every statement
        // after this one binds the tenant it returns.
        $statement = $this->pdo->prepare('SELECT tenant_id FROM invoices WHERE id = :id');
        $statement->execute([':id' => $invoiceId]);
        $tenantId = $statement->fetchColumn();

        return $tenantId === false || $tenantId === null ? null : (int) $tenantId;
    }
}
