<?php

declare(strict_types=1);

namespace Whity\Api;

use DateTimeImmutable;
use Whity\Auth\RoleChecker;
use Whity\Core\Billing\InvoiceRepository;
use Whity\Core\Billing\PaymentReconciler;
use Whity\Core\Money\Currency;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentInstruction;
use Whity\Core\Payment\PaymentLedger;
use Whity\Core\Payment\PaymentProviderException;
use Whity\Core\Payment\PaymentProviderRegistry;
use Whity\Core\Payment\PaymentRequest;
use Whity\Core\Payment\UnsupportedPaymentOperation;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Core\Money\Money;

/**
 * The tenant-facing billing surface: what I owe, and how I pay it.
 *
 *   GET  /api/billing/invoices            → invoices()
 *   GET  /api/billing/invoices/{id}       → invoice()      (lines + payments)
 *   POST /api/billing/invoices/{id}/pay   → pay()
 *   GET  /api/billing/methods             → methods()      (which rails are offered)
 *
 * ONE PAY ENDPOINT FOR EVERY RAIL, and this is the requirement that shapes the
 * whole class. The tempting design is `POST /invoices/{id}/pay/<rail>`, which
 * works today and structurally hardcodes the only rail that exists — so adding
 * a card provider later means a second endpoint, a second client path, a second
 * screen, and a fork in every one of them.
 *
 * Instead the caller names a provider, the registry resolves it, and the
 * response carries a `kind` the client branches on ONCE: send the browser to a
 * URL, show a transfer reference, or report that it is already settled. A new
 * rail produces one of those three and needs nothing here.
 *
 * THE ATTEMPT IS RECORDED BEFORE THE PAYER IS SENT ANYWHERE. A customer who
 * pays and then closes the tab has moved money the platform has no row for,
 * and reconciliation would have nothing to match the provider's callback
 * against. So a pending transaction is written first, against the reference the
 * instruction carries.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO is settle anything. Only a verified
 * provider callback settles an invoice, through {@see PaymentReconciler}. An
 * endpoint that marked an invoice paid because a customer pressed a button
 * would be taking the customer's word for it.
 */
final class BillingApiHandler
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly PaymentLedger $ledger,
        private readonly PaymentReconciler $reconciler,
        private readonly PaymentProviderRegistry $providers,
        private readonly RoleChecker $roleChecker,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** A tenant's invoices, with what is still owed on each. */
    public function invoices(Request $request): Response
    {
        $context = $this->authorize($request, CorePermissions::BILLING_VIEW);
        if ($context instanceof Response) {
            return $context;
        }

        $rows = [];
        foreach ($this->invoices->listForTenant($context) as $invoice) {
            $rows[] = $this->present($context, $invoice);
        }

        return Response::json(['data' => $rows]);
    }

    /**
     * One invoice, its lines, and every movement against it.
     *
     * @param array<string, string> $params
     */
    public function invoice(Request $request, array $params): Response
    {
        $context = $this->authorize($request, CorePermissions::BILLING_VIEW);
        if ($context instanceof Response) {
            return $context;
        }

        $invoiceId = (int) ($params['id'] ?? 0);
        $invoice = $this->invoices->findById($context, $invoiceId);

        if ($invoice === null) {
            return Response::error('Invoice not found', 404);
        }

        return Response::json([
            'data' => $this->present($context, $invoice) + [
                'lines' => $this->invoices->linesFor($context, $invoiceId),
                'payments' => $this->ledger->historyFor($context, $invoiceId),
            ],
        ]);
    }

    /**
     * Which rails this tenant may actually pay with.
     *
     * Only CONFIGURED ones: offering a rail that cannot take money produces a
     * button whose only outcome is an error the customer cannot act on.
     */
    public function methods(Request $request): Response
    {
        $context = $this->authorize($request, CorePermissions::BILLING_VIEW);
        if ($context instanceof Response) {
            return $context;
        }

        $rows = [];
        foreach ($this->providers->available() as $name => $adapter) {
            $capabilities = $adapter->capabilities();
            $rows[] = [
                'provider' => $name,
                'uses_redirect' => $capabilities->usesRedirect,
                'uses_push_transfer' => $capabilities->usesPushTransfer,
                'supports_stored_methods' => $capabilities->supportsStoredMethods,
                'supports_unattended_charge' => $capabilities->supportsUnattendedCharge,
            ];
        }

        return Response::json(['data' => $rows]);
    }

    /**
     * Begin paying an invoice, by whichever rail the caller names.
     *
     * @param array<string, string> $params
     */
    public function pay(Request $request, array $params): Response
    {
        $context = $this->authorize($request, CorePermissions::BILLING_PAY);
        if ($context instanceof Response) {
            return $context;
        }

        $invoiceId = (int) ($params['id'] ?? 0);
        $invoice = $this->invoices->findById($context, $invoiceId);

        if ($invoice === null) {
            return Response::error('Invoice not found', 404);
        }

        if ($invoice['status'] !== InvoiceRepository::STATUS_OPEN) {
            // Paying a draft would be paying something not yet owed; paying a
            // paid or void one would take money for nothing.
            return Response::error(
                "This invoice is {$invoice['status']} and cannot be paid.",
                409,
                ['status' => (string) $invoice['status']]
            );
        }

        $balance = $this->reconciler->balanceFor($context, $invoiceId);
        if ($balance <= 0) {
            return Response::error('This invoice has already been paid in full.', 409);
        }

        $body = JsonBody::parsed($request);
        $providerName = is_string($body['provider'] ?? null) ? trim((string) $body['provider']) : '';

        if ($providerName === '') {
            return Response::error('Validation failed', 422, ['provider' => 'name a payment method']);
        }

        try {
            $adapter = $this->providers->getUsable($providerName);
        } catch (PaymentProviderException) {
            // The message is not passed through: a provider exception can name
            // endpoints and merchant identifiers, and ExceptionLeakageTest
            // forbids interpolating it regardless.
            return Response::error(
                'That payment method is not available on this instance.',
                422,
                ['provider' => $providerName]
            );
        }

        // The ATTEMPT number, so a retry is the same request and the next
        // dunning cycle is a different one. Derived from what has already been
        // tried rather than sent by the client, which could otherwise replay
        // attempt one forever and collide with its own pending row.
        $attempt = $this->ledger->failedAttempts($context, $invoiceId) + 1;

        try {
            $instruction = $adapter->initiatePayment(PaymentRequest::forInvoiceAttempt(
                Money::of($balance, (string) $invoice['currency']),
                $context,
                $invoiceId,
                $attempt,
                returnUrl: is_string($body['return_url'] ?? null) ? $body['return_url'] : null,
                description: 'Invoice ' . (string) ($invoice['number'] ?? $invoiceId),
            ));
        } catch (UnsupportedPaymentOperation) {
            return Response::error(
                'That payment method cannot take this payment.',
                422,
                ['provider' => $providerName]
            );
        } catch (PaymentProviderException) {
            return Response::error('The payment could not be started. Please try again.', 502);
        }

        $this->recordAttempt($context, $invoiceId, $instruction, $invoice);

        return Response::json(['data' => $this->presentInstruction($instruction)], 201);
    }

    /**
     * Write the attempt down BEFORE the customer acts on the instruction.
     *
     * A settled instruction (the mock, or a future stored-card charge) is
     * reconciled immediately, because its outcome is already known. Everything
     * else becomes a PENDING row keyed on the provider's reference, so the
     * callback that eventually arrives collides with it and resolves it rather
     * than inserting a second movement.
     *
     * @param array<string, mixed> $invoice
     */
    private function recordAttempt(
        int $tenantId,
        int $invoiceId,
        PaymentInstruction $instruction,
        array $invoice,
    ): void {
        if ($instruction->kind === PaymentInstruction::KIND_SETTLED && $instruction->event !== null) {
            $this->reconciler->apply($instruction->event, $this->now());

            return;
        }

        $this->ledger->record(
            new \Whity\Core\Payment\PaymentEvent(
                PaymentEventType::Pending,
                $instruction->provider,
                $instruction->externalReference,
                Money::of(
                    $this->reconciler->balanceFor($tenantId, $invoiceId),
                    (string) $invoice['currency']
                ),
                $this->now(),
                $invoiceId,
                $tenantId,
            ),
            $tenantId,
            $invoiceId,
        );
    }

    /** @return array<string, mixed> */
    private function presentInstruction(PaymentInstruction $instruction): array
    {
        return array_filter([
            'kind' => $instruction->kind,
            'provider' => $instruction->provider,
            'reference' => $instruction->reference,
            'redirect_url' => $instruction->url,
            'display' => $instruction->display,
            'settled' => $instruction->kind === PaymentInstruction::KIND_SETTLED,
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }

    /**
     * An invoice as a client sees it.
     *
     * AMOUNTS TRAVEL AS MINOR UNITS AND AS A FORMATTED STRING. The integer is
     * what anything computes with; the string is what a screen shows, and it
     * exists because 5000 JOD is 5.000 and a client that divides by 100 shows
     * every customer an amount ten times too large. The formatted value is
     * locale-free — the client re-formats it for the viewer — but it carries
     * the right NUMBER of decimal places, which is the part a client cannot
     * work out for itself.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<string, mixed>
     */
    private function present(int $tenantId, array $invoice): array
    {
        $currency = (string) $invoice['currency'];
        $invoiceId = (int) $invoice['id'];
        $paid = $this->ledger->amountSettledMinor($tenantId, $invoiceId);
        $total = (int) $invoice['total_minor'];

        return [
            'id' => $invoiceId,
            'number' => $invoice['number'],
            'status' => (string) $invoice['status'],
            'currency' => $currency,
            'subtotal_minor' => (int) $invoice['subtotal_minor'],
            'discount_minor' => (int) $invoice['discount_minor'],
            'tax_minor' => (int) $invoice['tax_minor'],
            'tax_rate_bp' => (int) $invoice['tax_rate_bp'],
            'tax_label' => (string) ($invoice['tax_label'] ?? ''),
            'total_minor' => $total,
            'amount_paid_minor' => $paid,
            'balance_minor' => $total - $paid,
            'total_formatted' => Currency::format($total, $currency),
            'balance_formatted' => Currency::format($total - $paid, $currency),
            'issued_at' => $invoice['issued_at'],
            'due_at' => $invoice['due_at'],
            'paid_at' => $invoice['paid_at'],
            'seller_name' => (string) ($invoice['seller_name'] ?? ''),
            'buyer_name' => (string) ($invoice['buyer_name'] ?? ''),
        ];
    }

    /**
     * The caller's tenant, or a refusal.
     *
     * A tenant sees ITS OWN invoices, so there is no cross-tenant read here and
     * no system-tenant gate: this is the customer-facing surface, unlike the
     * operator's plan and promotion screens.
     *
     * @return int|Response
     */
    private function authorize(Request $request, string $permission): int|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return $tenantId;
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            /** @var DateTimeImmutable $moment */
            $moment = ($this->clock)();

            return $moment;
        }

        return new DateTimeImmutable();
    }
}
