<?php

declare(strict_types=1);

namespace Tests\Core\Payment;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Whity\Core\Money\Money;
use Whity\Core\Payment\CardPaymentProviderAdapter;
use Whity\Core\Payment\MockPaymentProvider;
use Whity\Core\Payment\PaymentEvent;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentInstruction;
use Whity\Core\Payment\PaymentProviderException;
use Whity\Core\Payment\PaymentProviderRegistry;
use Whity\Core\Payment\PaymentRequest;
use Whity\Core\Payment\UnsupportedPaymentOperation;
use Whity\Core\Payment\WebhookVerificationException;

/**
 * The seam every payment rail sits behind.
 *
 * The tests that matter most here are about what the design makes IMPOSSIBLE,
 * not what it makes convenient: an event that cannot be deduplicated, a webhook
 * whose signature was never checked, an unconfigured provider counting as a
 * payment failure. Each of those is a bug that leaves the system looking like
 * it works.
 */
final class PaymentProviderSeamTest extends TestCase
{
    private const TENANT = 7;

    private function jod(int $minor): Money
    {
        return Money::of($minor, 'JOD');
    }

    private function request(int $minor = 5000, int $attempt = 1): PaymentRequest
    {
        return PaymentRequest::forInvoiceAttempt($this->jod($minor), self::TENANT, 42, $attempt);
    }

    /**
     * The event off a settled instruction, insisting it is there.
     *
     * Written as an assertion rather than a nullsafe chain because
     * `assertTrue($x?->settles())` passes vacuously in one reading and fails
     * confusingly in the other: if the instruction were not settled at all, the
     * useful message is "there was no event", not "false is not true".
     */
    private function settledEvent(PaymentInstruction $instruction): PaymentEvent
    {
        $event = $instruction->event;
        self::assertNotNull($event, 'expected a settled instruction carrying an event');

        return $event;
    }

    // ── an event must be deduplicable ────────────────────────────────────────

    /**
     * The reference is what makes `(provider, external_reference)` collide when
     * a provider redelivers. An event without one cannot be deduplicated, so it
     * is refused where it is built rather than accepted and silently
     * double-credited later.
     */
    public function testAnEventWithNoProviderReferenceIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageMatches('/no external reference/');

        new PaymentEvent(
            PaymentEventType::Succeeded,
            'mock',
            '   ',
            $this->jod(5000),
            new DateTimeImmutable()
        );
    }

    public function testAnEventWithNoProviderIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);

        new PaymentEvent(PaymentEventType::Succeeded, '', 'ref-1', $this->jod(5000), new DateTimeImmutable());
    }

    /**
     * A pending transfer that later confirms is the SAME movement, so it keeps
     * its reference and collides with the pending row rather than inserting a
     * second one that doubles the balance.
     */
    public function testResolvingAPendingEventKeepsItsReference(): void
    {
        $pending = new PaymentEvent(
            PaymentEventType::Pending,
            'cliq',
            'cliq-991',
            $this->jod(5000),
            new DateTimeImmutable('2026-09-01 10:00:00')
        );

        $confirmed = $pending->resolvedAs(PaymentEventType::Succeeded);

        self::assertSame('cliq-991', $confirmed->externalReference);
        self::assertSame($pending->occurredAt, $confirmed->occurredAt, 'and the provider’s time, not ours');
        self::assertTrue($confirmed->settles());
        self::assertFalse($pending->settles(), 'the original is unchanged');
    }

    public function testOnlySuccessSettlesAndOnlyFailureCounts(): void
    {
        self::assertTrue(PaymentEventType::Succeeded->isTerminal());
        self::assertTrue(PaymentEventType::Failed->isTerminal());
        // The distinction that stops dunning from retrying money already in flight.
        self::assertFalse(PaymentEventType::Pending->isTerminal());

        self::assertSame('succeeded', PaymentEventType::Succeeded->ledgerStatus());
        self::assertSame('pending', PaymentEventType::Pending->ledgerStatus());
    }

    // ── requests cannot be un-idempotent ─────────────────────────────────────

    public function testAPaymentRequestWithoutAnIdempotencyKeyIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageMatches('/idempotency key/');

        new PaymentRequest($this->jod(5000), self::TENANT, '');
    }

    /**
     * The derived key is what makes a RETRY recognisably the same request and
     * the NEXT dunning attempt recognisably a different one. A random key would
     * be neither.
     */
    public function testTheDerivedKeyIsStablePerAttemptAndDiffersBetweenAttempts(): void
    {
        $first = PaymentRequest::forInvoiceAttempt($this->jod(5000), self::TENANT, 42, 1);
        $retry = PaymentRequest::forInvoiceAttempt($this->jod(5000), self::TENANT, 42, 1);
        $second = PaymentRequest::forInvoiceAttempt($this->jod(5000), self::TENANT, 42, 2);

        self::assertSame($first->idempotencyKey, $retry->idempotencyKey);
        self::assertNotSame($first->idempotencyKey, $second->idempotencyKey);
    }

    public function testCollectingNothingIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);
        new PaymentRequest($this->jod(0), self::TENANT, 'k');
    }

    // ── instructions ─────────────────────────────────────────────────────────

    /**
     * A checkout URL carries the customer, and sometimes an authorisation
     * token, in its query string. Over plain HTTP that is handed to whoever is
     * on the network path.
     */
    public function testANonHttpsCheckoutUrlIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);
        PaymentInstruction::redirect('card', 'ref', 'http://pay.example.test/checkout');
    }

    public function testAnEmptyTransferReferenceIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);
        PaymentInstruction::transfer('cliq', 'ref', '  ');
    }

    /**
     * Every kind carries the provider's reference, so the attempt can be
     * recorded BEFORE the payer is sent anywhere — otherwise a customer who
     * pays and closes the tab has moved money with no row to match it to.
     */
    public function testEveryInstructionKindCarriesAReferenceToRecordFirst(): void
    {
        $redirect = PaymentInstruction::redirect('card', 'ext-1', 'https://pay.example.test/c');
        $transfer = PaymentInstruction::transfer('cliq', 'ext-2', 'ALIAS-9');
        $settled = PaymentInstruction::settled(new PaymentEvent(
            PaymentEventType::Succeeded,
            'mock',
            'ext-3',
            $this->jod(100),
            new DateTimeImmutable()
        ));

        self::assertSame('ext-1', $redirect->externalReference);
        self::assertSame('ext-2', $transfer->externalReference);
        self::assertSame('ext-3', $settled->externalReference);
    }

    // ── the mock is a real stand-in ──────────────────────────────────────────

    public function testTheMockScriptsASequenceOfOutcomes(): void
    {
        $mock = new MockPaymentProvider();
        $mock->queueOutcome(PaymentEventType::Failed, 'insufficient funds')
            ->queueOutcome(PaymentEventType::Failed, 'insufficient funds')
            ->queueOutcome(PaymentEventType::Succeeded);

        $first = $mock->initiatePayment($this->request(attempt: 1));
        $second = $mock->initiatePayment($this->request(attempt: 2));
        $third = $mock->initiatePayment($this->request(attempt: 3));

        self::assertTrue($this->settledEvent($first)->isFailure());
        self::assertSame('insufficient funds', $this->settledEvent($first)->failureReason);
        self::assertTrue($this->settledEvent($second)->isFailure());
        self::assertTrue($this->settledEvent($third)->settles());
        self::assertSame(0, $mock->pendingOutcomes());
    }

    /** An unscripted mock succeeds, so tests that do not care need say nothing. */
    public function testAnUnscriptedMockSucceeds(): void
    {
        $instruction = (new MockPaymentProvider())->initiatePayment($this->request());

        self::assertTrue($this->settledEvent($instruction)->settles());
    }

    /**
     * A RETRY of the same request produces the SAME reference, which is what a
     * real provider's idempotency gives and what makes the ledger collide
     * rather than double-credit.
     */
    public function testRetryingOneRequestReusesTheProviderReference(): void
    {
        $mock = new MockPaymentProvider();

        $first = $mock->initiatePayment($this->request(attempt: 1));
        $retry = $mock->initiatePayment($this->request(attempt: 1));
        $next = $mock->initiatePayment($this->request(attempt: 2));

        self::assertSame($first->externalReference, $retry->externalReference);
        self::assertNotSame($first->externalReference, $next->externalReference);
    }

    // ── verification cannot be skipped ───────────────────────────────────────

    public function testACorrectlySignedWebhookTranslatesToEvents(): void
    {
        $mock = new MockPaymentProvider('shhh');
        $signed = $mock->signedWebhook(PaymentEventType::Succeeded, 'mock-1', $this->jod(5000), 42, self::TENANT);

        $events = $mock->translateWebhook($signed['body'], $signed['headers']);

        self::assertCount(1, $events);
        self::assertTrue($events[0]->settles());
        self::assertSame(5000, $events[0]->amount->amount);
        self::assertSame('JOD', $events[0]->amount->currency);
        self::assertSame(42, $events[0]->invoiceId);
        // The raw payload travels with the event so a dispute months later can
        // be reconciled against what the provider actually sent.
        self::assertSame($signed['body'], $events[0]->rawPayload);
    }

    /**
     * THE PATH A FORGERY TAKES. It is reachable in a test only because the mock
     * signs for real; a fake that accepted anything would leave this
     * permanently unexercised.
     */
    public function testATamperedBodyIsRefused(): void
    {
        $mock = new MockPaymentProvider('shhh');
        $signed = $mock->signedWebhook(PaymentEventType::Succeeded, 'mock-1', $this->jod(5000));

        $this->expectException(WebhookVerificationException::class);
        $mock->translateWebhook(
            str_replace('5000', '500000', $signed['body']),
            $signed['headers']
        );
    }

    public function testAWebhookSignedWithTheWrongSecretIsRefused(): void
    {
        $attacker = new MockPaymentProvider('guessed-secret');
        $signed = $attacker->signedWebhook(PaymentEventType::Succeeded, 'mock-1', $this->jod(5000));

        $this->expectException(WebhookVerificationException::class);
        (new MockPaymentProvider('the-real-secret'))->translateWebhook($signed['body'], $signed['headers']);
    }

    public function testAnUnsignedWebhookIsRefused(): void
    {
        $mock = new MockPaymentProvider('shhh');
        $signed = $mock->signedWebhook(PaymentEventType::Succeeded, 'mock-1', $this->jod(5000));

        $this->expectException(WebhookVerificationException::class);
        $mock->translateWebhook($signed['body'], []);
    }

    /** HTTP header names are case-insensitive; a provider sending lower case is not unsigned. */
    public function testTheSignatureHeaderIsMatchedCaseInsensitively(): void
    {
        $mock = new MockPaymentProvider('shhh');
        $signed = $mock->signedWebhook(PaymentEventType::Succeeded, 'mock-1', $this->jod(5000));

        $events = $mock->translateWebhook($signed['body'], ['x-mock-signature' => $mock->sign($signed['body'])]);

        self::assertCount(1, $events);
    }

    /**
     * A verified callback we do not act on is an EMPTY LIST, not an error.
     * Providers send heartbeats and event types the platform ignores, and
     * treating those as failures fills the log with alarms nobody reads.
     */
    public function testAVerifiedCallbackWeDoNotActOnYieldsNothing(): void
    {
        $mock = new MockPaymentProvider('shhh');
        $body = (string) json_encode(['type' => 'heartbeat']);

        self::assertSame([], $mock->translateWebhook($body, [MockPaymentProvider::SIGNATURE_HEADER => $mock->sign($body)]));
    }

    /** One callback may carry several movements, and none may be dropped. */
    public function testABatchedCallbackYieldsEveryMovement(): void
    {
        $mock = new MockPaymentProvider('shhh');
        $body = (string) json_encode([
            'events' => [
                ['type' => 'payment.succeeded', 'reference' => 'a', 'amount_minor' => 1000, 'currency' => 'JOD'],
                ['type' => 'payment.succeeded', 'reference' => 'b', 'amount_minor' => 2000, 'currency' => 'JOD'],
                ['type' => 'payment.failed', 'reference' => 'c', 'amount_minor' => 3000, 'currency' => 'JOD'],
            ],
        ]);

        $events = $mock->translateWebhook($body, [MockPaymentProvider::SIGNATURE_HEADER => $mock->sign($body)]);

        self::assertCount(3, $events);
        self::assertSame(['a', 'b', 'c'], array_map(static fn ($e) => $e->externalReference, $events));
    }

    // ── the card extension point ─────────────────────────────────────────────

    /**
     * The claim "we will add an adapter later" is otherwise uncheckable. This
     * makes it testable: the stub satisfies the interface today, so choosing a
     * provider is replacing four bodies rather than discovering the interface
     * lacks a method.
     */
    public function testTheCardStubSatisfiesTheInterfaceAndSaysItIsNotUsable(): void
    {
        $card = new CardPaymentProviderAdapter();

        self::assertSame('card', $card->name());
        self::assertFalse($card->isConfigured());
        // Honest about the SHAPE of a card rail even while unconfigured.
        self::assertTrue($card->capabilities()->supportsUnattendedCharge);
        self::assertTrue($card->capabilities()->supportsStoredMethods);
    }

    /**
     * AN UNCONFIGURED PROVIDER MUST NOT LOOK LIKE A PAYMENT FAILURE. A payment
     * failure counts toward dunning and eventually locks a tenant out, and a
     * tenant must never be locked out because we have not finished choosing a
     * PSP.
     */
    public function testTheCardStubRefusesInAWayDunningWillNotCount(): void
    {
        $card = new CardPaymentProviderAdapter();

        foreach ([
            fn () => $card->initiatePayment($this->request()),
            fn () => $card->registerPaymentMethod(self::TENANT, []),
            fn () => $card->translateWebhook('{}', []),
        ] as $call) {
            try {
                $call();
                self::fail('Expected the unconfigured card rail to refuse');
            } catch (UnsupportedPaymentOperation $e) {
                self::assertStringContainsString('does not support', $e->getMessage());
            }
        }
    }

    /**
     * Returning an empty list would make a webhook endpoint answer 200 to
     * anything sent at this provider's path — including a forgery — while
     * looking like it worked.
     */
    public function testTheCardStubRefusesWebhooksRatherThanSilentlyAcceptingThem(): void
    {
        $this->expectException(UnsupportedPaymentOperation::class);
        (new CardPaymentProviderAdapter())->translateWebhook('{"anything":true}', []);
    }

    // ── the registry ─────────────────────────────────────────────────────────

    public function testRegisteredIsNotTheSameAsUsable(): void
    {
        $registry = new PaymentProviderRegistry([new MockPaymentProvider(), new CardPaymentProviderAdapter()]);

        self::assertArrayHasKey('card', $registry->all(), 'the extension point stays visible');
        self::assertArrayNotHasKey('card', $registry->available(), 'but is never offered to a customer');
        self::assertArrayHasKey('mock', $registry->available());
    }

    /**
     * A webhook must reach its adapter whether or not the rail is currently
     * offered, because callbacks arrive for payments started earlier. A
     * checkout must not.
     */
    public function testAWebhookCanReachAnUnusableRailButACheckoutCannot(): void
    {
        $registry = new PaymentProviderRegistry([new CardPaymentProviderAdapter()]);

        self::assertInstanceOf(CardPaymentProviderAdapter::class, $registry->get('card'));

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageMatches('/not configured/');
        $registry->getUsable('card');
    }

    /**
     * Two rails under one name would make ledger rows ambiguous about which
     * produced them, and the (provider, reference) index would treat two
     * providers as one.
     */
    public function testTwoRailsCannotShareAName(): void
    {
        $registry = new PaymentProviderRegistry([new MockPaymentProvider()]);

        $this->expectException(PaymentProviderException::class);
        $registry->register(new MockPaymentProvider('a different secret'));
    }

    public function testAnUnknownRailIsRefusedRatherThanReturnedAsNull(): void
    {
        $this->expectException(PaymentProviderException::class);
        (new PaymentProviderRegistry())->get('paytabs');
    }

    /**
     * What the dunning machine asks to tell "we will retry the card" apart from
     * "we must ask the human" — different schedules, different emails.
     */
    public function testUnattendedCapableExcludesUnconfiguredAndPushOnlyRails(): void
    {
        $registry = new PaymentProviderRegistry([new MockPaymentProvider(), new CardPaymentProviderAdapter()]);

        // The card rail COULD renew unattended, but is not configured, so it is
        // not among the rails that can do it today.
        self::assertSame(['mock'], array_keys($registry->unattendedCapable()));
    }
}
