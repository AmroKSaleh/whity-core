<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

use DateTimeImmutable;
use Whity\Core\Money\Money;

/**
 * A payment rail that does not exist, for local development and tests.
 *
 * WHY THIS IS NOT A STUB WITH `return true`
 * -----------------------------------------
 * The whole point of a fake provider is that the code above it can be exercised
 * for real — the full subscription lifecycle, dunning through several failures,
 * a webhook arriving twice — without a sandbox account at anybody. A fake that
 * shortcuts the parts that are awkward tests only the parts that were easy.
 *
 * So this one SIGNS ITS WEBHOOKS, with HMAC-SHA256 over the raw body, exactly
 * as a real provider does. That is not ceremony: it means
 * {@see self::translateWebhook()} has a verification that can genuinely fail,
 * so the endpoint's rejection path is reachable in a test. A mock that accepted
 * anything would leave "what happens when the signature is wrong" permanently
 * unexercised — and that is the path an attacker uses.
 *
 * OUTCOMES ARE SCRIPTED, NOT RANDOM
 * ---------------------------------
 * {@see self::queueOutcome()} takes the sequence a test wants: fail, fail,
 * succeed. That is precisely the shape of a dunning test, and it is why a queue
 * rather than a single settable value. When the queue empties the provider
 * succeeds, so a test that does not care about outcomes does not have to say
 * so.
 *
 * Nothing here touches a database or decides anything about invoices, because
 * the interface forbids it. That is what makes this a complete stand-in rather
 * than one the tests have to work around.
 */
final class MockPaymentProvider implements PaymentProviderAdapter
{
    public const NAME = 'mock';

    /** The header a signature arrives in, mirroring the usual convention. */
    public const SIGNATURE_HEADER = 'X-Mock-Signature';

    /** @var list<array{type: PaymentEventType, reason: ?string}> */
    private array $outcomes = [];

    /** Monotonic, so two payments in one test never share a reference. */
    private int $counter = 0;

    public function __construct(
        private readonly string $webhookSecret = 'mock-secret',
        /** Injected so tests can control time without touching the clock. */
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function capabilities(): PaymentCapabilities
    {
        return PaymentCapabilities::everything();
    }

    // ── scripting ────────────────────────────────────────────────────────────

    /**
     * Queue the outcome of the next payment. Call repeatedly to script a
     * sequence: two failures then a success is a dunning test.
     */
    public function queueOutcome(PaymentEventType $type, ?string $reason = null): self
    {
        $this->outcomes[] = ['type' => $type, 'reason' => $reason];

        return $this;
    }

    /** What a scripted-but-unconsumed queue still holds, for test assertions. */
    public function pendingOutcomes(): int
    {
        return count($this->outcomes);
    }

    // ── the interface ────────────────────────────────────────────────────────

    public function initiatePayment(PaymentRequest $request): PaymentInstruction
    {
        // Derived from the caller's idempotency key rather than a counter, so
        // that a RETRY of the same request produces the SAME reference and
        // collides in the ledger — which is the behaviour a real provider's
        // idempotency gives, and the thing a test needs to be able to rely on.
        $reference = 'mock-' . substr(hash('sha256', $request->idempotencyKey), 0, 24);

        $outcome = array_shift($this->outcomes) ?? ['type' => PaymentEventType::Succeeded, 'reason' => null];

        if ($outcome['type'] === PaymentEventType::Pending) {
            // A pending push: the caller shows a reference and waits to be told.
            return PaymentInstruction::transfer(
                self::NAME,
                $reference,
                strtoupper($reference),
                ['note' => 'Mock transfer; confirm with deliverWebhook().']
            );
        }

        return PaymentInstruction::settled($this->event(
            $outcome['type'],
            $reference,
            $request->amount,
            $request->invoiceId,
            $request->tenantId,
            $outcome['reason'],
        ));
    }

    public function registerPaymentMethod(int $tenantId, array $details): string
    {
        $this->counter++;

        return sprintf('mock-pm-%d-%d', $tenantId, $this->counter);
    }

    public function translateWebhook(string $rawBody, array $headers): array
    {
        $this->assertSignature($rawBody, $headers);

        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new PaymentProviderException('Mock webhook body was not a JSON object.');
        }

        // A verified callback carrying nothing we act on is an EMPTY LIST, not
        // an error — providers send heartbeats and event types we ignore.
        if (!isset($decoded['events']) || !is_array($decoded['events'])) {
            return [];
        }

        $events = [];
        foreach ($decoded['events'] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $events[] = $this->eventFromArray($raw, $rawBody);
        }

        return $events;
    }

    // ── simulating the provider calling us back ──────────────────────────────

    /**
     * The raw body and headers a webhook would arrive with, correctly signed.
     *
     * Returned rather than delivered, so a test drives the real endpoint with
     * them instead of this class pretending to be one.
     *
     * @param array<string, mixed> $extra
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public function signedWebhook(
        PaymentEventType $type,
        string $externalReference,
        Money $amount,
        ?int $invoiceId = null,
        ?int $tenantId = null,
        ?string $failureReason = null,
        array $extra = [],
    ): array {
        $body = (string) json_encode([
            'events' => [
                [
                    'type' => $type->value,
                    'reference' => $externalReference,
                    'amount_minor' => $amount->amount,
                    'currency' => $amount->currency,
                    'invoice_id' => $invoiceId,
                    'tenant_id' => $tenantId,
                    'failure_reason' => $failureReason,
                    'occurred_at' => $this->now()->format(DATE_ATOM),
                ] + $extra,
            ],
        ]);

        return [
            'body' => $body,
            'headers' => [self::SIGNATURE_HEADER => $this->sign($body)],
        ];
    }

    /** The signature this provider would put on a body. */
    public function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->webhookSecret);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    private function assertSignature(string $rawBody, array $headers): void
    {
        // Header lookup is case-insensitive because HTTP header names are, and
        // a provider that sends lower case would otherwise appear unsigned.
        $provided = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, self::SIGNATURE_HEADER) === 0) {
                $provided = $value;
                break;
            }
        }

        if ($provided === null) {
            throw new WebhookVerificationException('Mock webhook carried no signature.');
        }

        // hash_equals, not ===: a timing-variable comparison of a MAC leaks it
        // one byte at a time to anyone willing to send enough requests.
        if (!hash_equals($this->sign($rawBody), $provided)) {
            throw new WebhookVerificationException('Mock webhook signature did not match.');
        }
    }

    /** @param array<string, mixed> $raw */
    private function eventFromArray(array $raw, string $rawBody): PaymentEvent
    {
        $type = PaymentEventType::tryFrom(is_string($raw['type'] ?? null) ? $raw['type'] : '');
        if ($type === null) {
            throw new PaymentProviderException('Mock webhook carried an unknown event type.');
        }

        $reference = is_string($raw['reference'] ?? null) ? $raw['reference'] : '';
        $amount = Money::of(
            is_int($raw['amount_minor'] ?? null) ? $raw['amount_minor'] : 0,
            is_string($raw['currency'] ?? null) ? $raw['currency'] : 'JOD',
        );

        $occurredAt = is_string($raw['occurred_at'] ?? null)
            ? new DateTimeImmutable($raw['occurred_at'])
            : $this->now();

        return new PaymentEvent(
            $type,
            self::NAME,
            $reference,
            $amount,
            $occurredAt,
            is_int($raw['invoice_id'] ?? null) ? $raw['invoice_id'] : null,
            is_int($raw['tenant_id'] ?? null) ? $raw['tenant_id'] : null,
            is_string($raw['failure_reason'] ?? null) ? $raw['failure_reason'] : null,
            null,
            $rawBody,
        );
    }

    private function event(
        PaymentEventType $type,
        string $reference,
        Money $amount,
        ?int $invoiceId,
        ?int $tenantId,
        ?string $reason,
    ): PaymentEvent {
        return new PaymentEvent(
            $type,
            self::NAME,
            $reference,
            $amount,
            $this->now(),
            $invoiceId,
            $tenantId,
            $reason,
        );
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
