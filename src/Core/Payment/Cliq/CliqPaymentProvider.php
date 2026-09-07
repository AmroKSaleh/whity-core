<?php

declare(strict_types=1);

namespace Whity\Core\Payment\Cliq;

use DateTimeImmutable;
use Whity\Core\Money\Money;
use Whity\Core\Payment\PaymentCapabilities;
use Whity\Core\Payment\PaymentEvent;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentInstruction;
use Whity\Core\Payment\PaymentProviderAdapter;
use Whity\Core\Payment\PaymentProviderException;
use Whity\Core\Payment\PaymentRequest;
use Whity\Core\Payment\UnsupportedPaymentOperation;
use Whity\Core\Payment\WebhookVerificationException;

/**
 * CliQ — Jordan's instant bank-transfer network, as a payment rail.
 *
 * HOW THIS RAIL DIFFERS FROM A CARD, AND WHY THAT SHAPES EVERYTHING
 * -----------------------------------------------------------------
 * The platform never moves the money. The payer opens their own bank or wallet
 * app, sends a transfer to our CliQ alias, and types a reference into the
 * narrative field. We find out afterwards — by callback if the bank sends one,
 * by reconciliation if it does not.
 *
 * So this rail cannot renew a subscription unattended, and
 * {@see self::capabilities()} says so rather than letting the dunning machine
 * discover it by failing at renewal. "We will retry the card" and "we must ask
 * the human to send money again" are different schedules and different emails,
 * and the difference is a property of the rail.
 *
 * A PAYMENT STARTS AS PENDING, NEVER AS SETTLED. `initiatePayment()` returns a
 * transfer instruction and nothing has been paid at that moment — the payer has
 * merely been told what to do. Anything that treated this as success would mark
 * invoices paid for people who looked at the screen and closed it.
 *
 * WHAT IS CONFIRMED AND WHAT IS NOT
 * ---------------------------------
 * The SHAPE of this integration is settled and is what the tests exercise:
 * deterministic references with check characters, pending-then-confirmed
 * lifecycle, verification before translation, idempotency on the bank's own
 * transaction identifier, and refusal to settle a transfer whose amount does
 * not match.
 *
 * The FIELD NAMES in {@see CliqWebhookPayload} are not confirmed. They are
 * documented, defaulted to the plainest reading of a JoPACC-style settlement
 * message, and isolated in one class precisely so that correcting them against
 * the real specification or sandbox is a small, local change with a failing
 * test to guide it — rather than a hunt through an adapter that assumed a shape
 * everywhere. Nothing here should reach production without that confirmation,
 * and the class says so too.
 *
 * FAIL CLOSED. With no webhook secret configured, `translateWebhook()` REFUSES
 * every payload. It does not fall back to accepting unverified ones, because a
 * misconfigured instance that quietly trusts anything posted to its callback
 * URL is worse than one that takes no payments — the first loses money to
 * whoever finds the endpoint, and the second is noticed immediately.
 */
final class CliqPaymentProvider implements PaymentProviderAdapter
{
    public const NAME = 'cliq';

    /** Where a signature is expected. Confirm against the bank's specification. */
    public const SIGNATURE_HEADER = 'X-Cliq-Signature';

    /**
     * @param string $alias           The CliQ alias payers send to.
     * @param string $bankName        Shown alongside it, so a payer can tell they
     *                                have the right destination.
     * @param string $webhookSecret   Empty means unconfigured — and every
     *                                payload is then refused, not trusted.
     * @param string $referencePrefix What payers see in their statement.
     */
    public function __construct(
        private readonly string $alias,
        private readonly string $bankName = '',
        private readonly string $webhookSecret = '',
        private readonly string $referencePrefix = 'WHT-',
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): PaymentCapabilities
    {
        // Polling is available: the platform can ask the bank what happened to
        // a reference rather than only waiting to be told. A rail without that
        // is only ever as reliable as its callbacks, and callbacks are lost.
        return PaymentCapabilities::pushTransfer(supportsPolling: true);
    }

    /**
     * Usable once there is an alias to send money to AND a secret to verify
     * callbacks with.
     *
     * Both, not either: an alias without a secret can collect money it can
     * never confirm, which is the shape of a system that takes payments and
     * tells customers they have not paid.
     */
    public function isConfigured(): bool
    {
        return trim($this->alias) !== '' && trim($this->webhookSecret) !== '';
    }

    /**
     * Give the payer a reference and tell them where to send it.
     *
     * NOTHING IS PAID AT THIS POINT. The instruction is an instruction; the
     * caller records a PENDING transaction against the returned reference and
     * waits.
     */
    public function initiatePayment(PaymentRequest $request): PaymentInstruction
    {
        if (trim($this->alias) === '') {
            throw new PaymentProviderException(
                'No CliQ alias is configured on this instance, so there is nowhere for a '
                . 'payer to send money.'
            );
        }

        if ($request->invoiceId === null) {
            // The reference IS the link between the money and the debt. Without
            // an invoice there is nothing to reconcile a transfer against, and
            // a payment that cannot be matched is a payment that will be
            // reported as missing.
            throw new PaymentProviderException(
                'A CliQ payment must name the invoice it settles: the typed reference is '
                . 'the only thing connecting the transfer to a debt.'
            );
        }

        if ($request->amount->amount < 0) {
            throw UnsupportedPaymentOperation::for(
                self::NAME,
                'refunds: money returns over CliQ as a separate transfer the operator '
                . 'initiates from their own bank, and recording it is a manual ledger '
                . 'entry rather than something this rail can be asked to do.'
            );
        }

        $reference = CliqPaymentReference::generate(
            $this->referencePrefix,
            $request->invoiceId,
            $this->attemptFrom($request),
        );

        return PaymentInstruction::transfer(
            self::NAME,
            $reference,
            $reference,
            array_filter([
                'alias' => $this->alias,
                'bank' => $this->bankName,
                'amount_minor' => (string) $request->amount->amount,
                'currency' => $request->amount->currency,
            ], static fn (string $v): bool => $v !== '')
        );
    }

    /**
     * CliQ has no stored instrument. The payer's bank holds the relationship,
     * and there is nothing for the platform to keep.
     */
    public function registerPaymentMethod(int $tenantId, array $details): string
    {
        throw UnsupportedPaymentOperation::for(
            self::NAME,
            'stored payment methods: a CliQ push is initiated by the payer in their own '
            . 'bank app, so there is no instrument for the platform to hold or charge'
        );
    }

    /**
     * A bank settlement callback, verified and then translated.
     *
     * @return list<PaymentEvent>
     */
    public function translateWebhook(string $rawBody, array $headers): array
    {
        $this->assertSignature($rawBody, $headers);

        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new PaymentProviderException('CliQ callback body was not a JSON object.');
        }

        $events = [];
        foreach (CliqWebhookPayload::transfersIn($decoded) as $transfer) {
            $event = $this->eventFor($transfer, $rawBody);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * One transfer, or null when it is not ours to act on.
     *
     * A TRANSFER CARRYING A REFERENCE THAT IS NOT OURS IS IGNORED, NOT FAILED.
     * The same alias receives money for all sorts of reasons, and turning a
     * stranger's transfer into a failed-payment event would feed the dunning
     * machine a failure belonging to nobody.
     *
     * @param array<string, mixed> $transfer
     */
    private function eventFor(array $transfer, string $rawBody): ?PaymentEvent
    {
        $reference = CliqPaymentReference::canonicalize(CliqWebhookPayload::reference($transfer));

        if ($reference === '' || !CliqPaymentReference::isWellFormed($reference)) {
            // Either somebody else's transfer, or a payer who mistyped. Both
            // are for a human to look at, and neither is a payment failure.
            return null;
        }

        $bankReference = CliqWebhookPayload::bankTransactionId($transfer);
        if (trim($bankReference) === '') {
            // Without the bank's own identifier there is nothing to make the
            // ledger idempotent on, and a redelivery would credit twice.
            throw new PaymentProviderException(
                'A CliQ transfer arrived with no bank transaction identifier. Refusing it '
                . 'rather than recording a movement that a redelivery would duplicate.'
            );
        }

        $status = CliqWebhookPayload::status($transfer);
        $amount = Money::of(
            CliqWebhookPayload::amountMinor($transfer),
            CliqWebhookPayload::currency($transfer),
        );

        // WHICH DEBT THIS IS FOR comes from the reference the payer typed, and
        // it has to: the bank's message says what moved and between whom, never
        // which of our invoices it settles. The alternative — searching for an
        // invoice by amount — matches the wrong one the moment two tenants owe
        // the same figure, which on a subscription platform is most of them.
        $decoded = CliqPaymentReference::decode($reference);

        return new PaymentEvent(
            $status,
            self::NAME,
            $bankReference,
            $amount,
            CliqWebhookPayload::occurredAt($transfer) ?? $this->now(),
            $decoded['invoice'] ?? null,
            null,
            $status === PaymentEventType::Failed ? CliqWebhookPayload::reason($transfer) : null,
            null,
            $rawBody,
        );
    }

    /**
     * The attempt number, from the caller's idempotency key.
     *
     * Derived rather than passed so that the reference and the key cannot
     * disagree: a retry of one request must produce the SAME reference, or the
     * payer ends up with two references for one debt and the second push
     * arrives against a ledger row that does not expect it.
     */
    private function attemptFrom(PaymentRequest $request): int
    {
        if (preg_match('/attempt-(\d+)$/', $request->idempotencyKey, $match) === 1) {
            return max(1, (int) $match[1]);
        }

        return 1;
    }

    /** @param array<string, string> $headers */
    private function assertSignature(string $rawBody, array $headers): void
    {
        if (trim($this->webhookSecret) === '') {
            // FAIL CLOSED. An instance that trusts anything posted to its
            // callback URL loses money to whoever finds it; one that refuses
            // is noticed the same day.
            throw new WebhookVerificationException(
                'No CliQ webhook secret is configured, so no callback can be proven to '
                . 'have come from the bank. Refusing rather than trusting it.'
            );
        }

        $provided = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, self::SIGNATURE_HEADER) === 0) {
                $provided = $value;
                break;
            }
        }

        if ($provided === null) {
            throw new WebhookVerificationException('CliQ callback carried no signature.');
        }

        // Over the RAW body: signatures are over bytes, and a round trip
        // through decode/encode reorders keys and changes them.
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        // hash_equals, not ===: comparing a MAC in variable time leaks it a
        // byte at a time to anyone willing to send enough requests.
        if (!hash_equals($expected, $provided)) {
            throw new WebhookVerificationException('CliQ callback signature did not match.');
        }
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
