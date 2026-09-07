<?php

declare(strict_types=1);

namespace Tests\Core\Payment;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Whity\Core\Money\Money;
use Whity\Core\Payment\Cliq\CliqPaymentProvider;
use Whity\Core\Payment\Cliq\CliqPaymentReference;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentInstruction;
use Whity\Core\Payment\PaymentProviderException;
use Whity\Core\Payment\PaymentRequest;
use Whity\Core\Payment\UnsupportedPaymentOperation;
use Whity\Core\Payment\WebhookVerificationException;

/**
 * The CliQ rail.
 *
 * The tests are grouped around the three things that make a push rail
 * different from a card, because each has a failure mode that looks like
 * working software: it cannot renew unattended, a payment starts PENDING and
 * not settled, and the only link between money and debt is a string a human
 * typed.
 *
 * THE PAYLOAD SHAPE IN {@see self::bankCallback()} IS UNCONFIRMED — see
 * {@see \Whity\Core\Payment\Cliq\CliqWebhookPayload}. When JoPACC's real
 * specification is available, this helper is the thing to correct, and these
 * tests then exercise the real shape rather than a guess.
 */
final class CliqPaymentProviderTest extends TestCase
{
    private const SECRET = 'a-shared-secret';
    private const ALIAS = 'WHITY.JO';
    private const TENANT = 5;
    private const INVOICE = 42;

    private function provider(string $secret = self::SECRET, string $alias = self::ALIAS): CliqPaymentProvider
    {
        return new CliqPaymentProvider(
            $alias,
            'Test Bank',
            $secret,
            'WHT-',
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-07 12:00:00'),
        );
    }

    private function request(int $minor = 5000, int $attempt = 1, ?int $invoice = self::INVOICE): PaymentRequest
    {
        return $invoice === null
            ? new PaymentRequest(Money::of($minor, 'JOD'), self::TENANT, 'no-invoice')
            : PaymentRequest::forInvoiceAttempt(Money::of($minor, 'JOD'), self::TENANT, $invoice, $attempt);
    }

    // ── a push rail is not a card ────────────────────────────────────────────

    /**
     * THE PROPERTY THE DUNNING MACHINE DEPENDS ON. Nobody can charge a CliQ
     * payer without the payer acting, so a subscription on this rail cannot
     * renew itself. Discovering that by exception at renewal time — on a real
     * subscription, at the moment we were supposed to collect — is the failure
     * this answers.
     */
    public function testThisRailCannotRenewASubscriptionUnattended(): void
    {
        $capabilities = $this->provider()->capabilities();

        self::assertFalse($capabilities->supportsUnattendedCharge);
        self::assertFalse($capabilities->supportsStoredMethods);
        self::assertTrue($capabilities->usesPushTransfer);
        // Callbacks get lost; a rail that can also be asked is more reliable
        // than one that can only be told.
        self::assertTrue($capabilities->supportsPolling);
    }

    public function testThereIsNoInstrumentToStore(): void
    {
        $this->expectException(UnsupportedPaymentOperation::class);
        $this->provider()->registerPaymentMethod(self::TENANT, []);
    }

    /**
     * An alias with no secret can collect money it can never confirm — a
     * system that takes payments and then tells customers they have not paid.
     */
    public function testUsableRequiresBothAnAliasAndASecret(): void
    {
        self::assertTrue($this->provider()->isConfigured());
        self::assertFalse($this->provider(secret: '')->isConfigured());
        self::assertFalse($this->provider(alias: '')->isConfigured());
    }

    // ── initiating ───────────────────────────────────────────────────────────

    /**
     * NOTHING IS PAID YET. The instruction is an instruction; anything that
     * treated it as success would mark invoices paid for people who looked at
     * the screen and closed it.
     */
    public function testInitiatingProducesATransferInstructionAndNotASettlement(): void
    {
        $instruction = $this->provider()->initiatePayment($this->request());

        self::assertSame(PaymentInstruction::KIND_TRANSFER, $instruction->kind);
        self::assertNull($instruction->event, 'nothing has been paid at this point');
        self::assertSame(self::ALIAS, $instruction->display['alias'] ?? null);
        self::assertSame('Test Bank', $instruction->display['bank'] ?? null);
    }

    public function testTheInstructionCarriesAVerifiableReference(): void
    {
        $instruction = $this->provider()->initiatePayment($this->request());

        self::assertNotNull($instruction->reference);
        self::assertTrue(CliqPaymentReference::isWellFormed($instruction->reference));
        // The same string the ledger keys on, so the pending row can be written
        // before the payer is sent anywhere.
        self::assertSame($instruction->reference, $instruction->externalReference);
    }

    /**
     * The reference is derived from the caller's idempotency key, so a retry
     * of one request cannot hand the payer a second reference for one debt.
     */
    public function testARetryOfOneRequestReusesTheReference(): void
    {
        $provider = $this->provider();

        self::assertSame(
            $provider->initiatePayment($this->request(attempt: 1))->reference,
            $provider->initiatePayment($this->request(attempt: 1))->reference
        );
        self::assertNotSame(
            $provider->initiatePayment($this->request(attempt: 1))->reference,
            $provider->initiatePayment($this->request(attempt: 2))->reference
        );
    }

    public function testAPaymentWithNoInvoiceIsRefused(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageMatches('/must name the invoice/');
        $this->provider()->initiatePayment($this->request(invoice: null));
    }

    public function testWithNoAliasThereIsNowhereToSendMoney(): void
    {
        $this->expectException(PaymentProviderException::class);
        $this->provider(alias: '')->initiatePayment($this->request());
    }

    /**
     * A refund over CliQ is a transfer the operator makes from their own bank.
     * Unsupported rather than failed, so it can never count toward dunning.
     */
    public function testARefundIsNotSomethingThisRailCanBeAskedToDo(): void
    {
        $this->expectException(UnsupportedPaymentOperation::class);
        $this->provider()->initiatePayment(new PaymentRequest(
            Money::of(-5000, 'JOD'),
            self::TENANT,
            'refund-1',
            self::INVOICE
        ));
    }

    // ── verification, and failing closed ─────────────────────────────────────

    /**
     * FAIL CLOSED. An instance that trusts anything posted to its callback URL
     * loses money to whoever finds the endpoint. One that refuses is noticed
     * the same day.
     */
    public function testWithNoSecretConfiguredEveryCallbackIsRefused(): void
    {
        $unconfigured = $this->provider(secret: '');
        $callback = $this->bankCallback();

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessageMatches('/No CliQ webhook secret/');
        // Signed with the real secret, and still refused — there is no
        // fallback path that accepts an unverified payload.
        $unconfigured->translateWebhook($callback['body'], $callback['headers']);
    }

    public function testATamperedCallbackIsRefused(): void
    {
        $callback = $this->bankCallback(amount: '5.000');

        $this->expectException(WebhookVerificationException::class);
        $this->provider()->translateWebhook(
            str_replace('5.000', '5000.000', $callback['body']),
            $callback['headers']
        );
    }

    public function testAnUnsignedCallbackIsRefused(): void
    {
        $this->expectException(WebhookVerificationException::class);
        $this->provider()->translateWebhook($this->bankCallback()['body'], []);
    }

    // ── translating ──────────────────────────────────────────────────────────

    public function testAConfirmedTransferBecomesASettlingEvent(): void
    {
        $callback = $this->bankCallback(status: 'ACSC', amount: '5.000');

        $events = $this->provider()->translateWebhook($callback['body'], $callback['headers']);

        self::assertCount(1, $events);
        self::assertTrue($events[0]->settles());
        // The BANK's identifier, not our reference — it is what the ledger's
        // idempotency index keys on, and it is the bank that redelivers.
        self::assertSame('BANKTXN-1', $events[0]->externalReference);
        self::assertSame('cliq', $events[0]->provider);
    }

    /**
     * The event has to say which debt it settles, and only the typed reference
     * can say. The bank's message names the transaction, not our invoice.
     */
    public function testAConfirmedTransferNamesTheInvoiceItSettles(): void
    {
        $callback = $this->bankCallback();

        $event = $this->provider()->translateWebhook($callback['body'], $callback['headers'])[0];

        self::assertSame(self::INVOICE, $event->invoiceId);
    }

    /**
     * THE MOST EXPENSIVE MISTAKE AVAILABLE HERE. "5.000" JOD is 5000 fils.
     * Reading it as 5 undercharges by a factor of a thousand; reading 5000 as
     * dinars overcharges by the same.
     */
    public function testADecimalAmountIsReadAtTheCurrencysOwnPrecision(): void
    {
        $callback = $this->bankCallback(amount: '5.000');

        $events = $this->provider()->translateWebhook($callback['body'], $callback['headers']);

        self::assertSame(5000, $events[0]->amount->amount);
        self::assertSame('JOD', $events[0]->amount->currency);
    }

    /**
     * A BARE JSON NUMBER IS AMBIGUOUS AND IS REFUSED. `"amount": 5` cannot say
     * whether it means five dinars or five fils, and the two differ by a
     * thousand. JSON does not even preserve the distinction — 5.0 decodes to
     * the integer 5 on some configurations — so an adapter that took integers
     * as minor units would silently misread a decimal by three orders of
     * magnitude, with no error anywhere.
     *
     * @dataProvider ambiguousAmounts
     */
    public function testABareNumericAmountIsRefusedAsAmbiguous(int|float $amount): void
    {
        $callback = $this->bankCallback(amount: $amount);

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageMatches('/major or minor units/');
        $this->provider()->translateWebhook($callback['body'], $callback['headers']);
    }

    /** @return iterable<string, array{int|float}> */
    public static function ambiguousAmounts(): iterable
    {
        yield 'integer' => [5000];
        yield 'float' => [5.5];
        yield 'float that decodes as an integer' => [5.0];
    }

    /**
     * THE CURRENCY IS READ, NOT ASSUMED. This network settles in dinars, which
     * makes 'JOD' a tempting constant — and a transfer that arrived in
     * something else would then be relabelled as dinars and parsed at three
     * decimal places instead of two. "5.00 USD" is 500 minor units; read as JOD
     * it becomes 5000, and the invoice is over-credited tenfold in a currency
     * nobody sent.
     */
    public function testAnAmountInAnotherCurrencyKeepsThatCurrencyAndItsPrecision(): void
    {
        $callback = $this->bankCallback(amount: '5.00', currency: 'USD');

        $event = $this->provider()->translateWebhook($callback['body'], $callback['headers'])[0];

        self::assertSame('USD', $event->amount->currency);
        // Two decimal places, not three: 500 minor units, not 5000.
        self::assertSame(500, $event->amount->amount);
    }

    public function testAnAmountThatIsNotThereAtAllIsRefused(): void
    {
        $callback = $this->bankCallback(amount: '');

        $this->expectException(PaymentProviderException::class);
        $this->provider()->translateWebhook($callback['body'], $callback['headers']);
    }

    /**
     * The bank says when the money moved. A callback delayed an hour, or
     * replayed after an outage, must not date the payment to its own arrival —
     * a payment made inside a grace period would look like one made after it
     * expired, and the tenant gets locked out for paying on time.
     */
    public function testThePaymentIsDatedByTheBankNotByUs(): void
    {
        $callback = $this->bankCallback(valueDate: '2026-09-01T09:30:00+03:00');

        $event = $this->provider()->translateWebhook($callback['body'], $callback['headers'])[0];

        self::assertSame('2026-09-01T09:30:00+03:00', $event->occurredAt->format(DATE_ATOM));
    }

    /**
     * The same alias receives money for all sorts of reasons. A stranger's
     * transfer must not become a failed-payment event, because that would feed
     * the dunning machine a failure belonging to nobody.
     */
    public function testATransferThatIsNotOursIsIgnoredRatherThanFailed(): void
    {
        $callback = $this->bankCallback(reference: 'salary september');

        self::assertSame([], $this->provider()->translateWebhook($callback['body'], $callback['headers']));
    }

    /** A mistyped reference is for a human to look at, not a payment failure. */
    public function testAMistypedReferenceIsIgnoredRatherThanMatched(): void
    {
        $good = CliqPaymentReference::generate('WHT-', self::INVOICE, 1);
        $typo = substr_replace($good, $good[5] === 'A' ? 'B' : 'A', 5, 1);
        $callback = $this->bankCallback(reference: $typo);

        self::assertSame([], $this->provider()->translateWebhook($callback['body'], $callback['headers']));
    }

    /**
     * Without the bank's own identifier there is nothing to make the ledger
     * idempotent on, so a redelivery would credit twice.
     */
    public function testATransferWithNoBankIdentifierIsRefused(): void
    {
        $callback = $this->bankCallback(transactionId: '');

        $this->expectException(PaymentProviderException::class);
        $this->expectExceptionMessageMatches('/no bank transaction identifier/');
        $this->provider()->translateWebhook($callback['body'], $callback['headers']);
    }

    /**
     * AN UNKNOWN STATUS IS PENDING, NOT FAILED. We do not understand it; it
     * may well be a success we could not read, and guessing "failed" starts
     * dunning against money that has arrived.
     */
    public function testAnUnrecognisedStatusIsTreatedAsPendingNotFailed(): void
    {
        $callback = $this->bankCallback(status: 'SOMETHING_NEW');

        $event = $this->provider()->translateWebhook($callback['body'], $callback['headers'])[0];

        self::assertSame(PaymentEventType::Pending, $event->type);
        self::assertFalse($event->isFailure());
    }

    public function testARejectedTransferBecomesAFailureCarryingTheBanksReason(): void
    {
        $callback = $this->bankCallback(status: 'RJCT', reason: 'Beneficiary account closed');

        $event = $this->provider()->translateWebhook($callback['body'], $callback['headers'])[0];

        self::assertTrue($event->isFailure());
        self::assertSame('Beneficiary account closed', $event->failureReason);
    }

    /** One settlement message may carry many transfers, and none may be dropped. */
    public function testEveryTransferInABatchIsTranslated(): void
    {
        $reference = CliqPaymentReference::generate('WHT-', self::INVOICE, 1);
        $body = (string) json_encode([
            'transfers' => [
                $this->transfer('BANKTXN-1', $reference, '5.000', 'ACSC'),
                $this->transfer('BANKTXN-2', $reference, '2.500', 'ACSC'),
                $this->transfer('BANKTXN-3', 'not ours', '9.000', 'ACSC'),
            ],
        ]);

        $events = $this->provider()->translateWebhook($body, $this->sign($body));

        // Three in, two out: the third is somebody else's money.
        self::assertCount(2, $events);
        self::assertSame(['BANKTXN-1', 'BANKTXN-2'], array_map(
            static fn ($e): string => $e->externalReference,
            $events
        ));
        self::assertSame([5000, 2500], array_map(static fn ($e): int => $e->amount->amount, $events));
    }

    /** A bare record, not wrapped in an envelope — banks send both. */
    public function testASingleUnwrappedTransferIsUnderstood(): void
    {
        $body = (string) json_encode(
            $this->transfer('BANKTXN-9', CliqPaymentReference::generate('WHT-', self::INVOICE, 1), '1.000', 'ACSC')
        );

        self::assertCount(1, $this->provider()->translateWebhook($body, $this->sign($body)));
    }

    /** A verified callback carrying nothing we act on is empty, not an error. */
    public function testAVerifiedCallbackWithNoTransfersYieldsNothing(): void
    {
        $body = (string) json_encode(['heartbeat' => true]);

        self::assertSame([], $this->provider()->translateWebhook($body, $this->sign($body)));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * A bank settlement callback.
     *
     * THE FIELD NAMES HERE ARE THE UNCONFIRMED PART of this integration. When
     * JoPACC's specification is available this is the helper to correct, and
     * every test above then exercises the real shape.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    private function bankCallback(
        string $transactionId = 'BANKTXN-1',
        ?string $reference = null,
        string|int|float $amount = '5.000',
        string $status = 'ACSC',
        ?string $reason = null,
        ?string $valueDate = null,
        string $currency = 'JOD',
    ): array {
        $body = (string) json_encode([
            'transfers' => [
                $this->transfer(
                    $transactionId,
                    $reference ?? CliqPaymentReference::generate('WHT-', self::INVOICE, 1),
                    $amount,
                    $status,
                    $reason,
                    $valueDate,
                    $currency,
                ),
            ],
        ]);

        return ['body' => $body, 'headers' => $this->sign($body)];
    }

    /** @return array<string, mixed> */
    private function transfer(
        string $transactionId,
        string $reference,
        string|int|float $amount,
        string $status,
        ?string $reason = null,
        ?string $valueDate = null,
        string $currency = 'JOD',
    ): array {
        return [
            'transactionId' => $transactionId,
            'remittanceInformation' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'reasonDescription' => $reason,
            'valueDate' => $valueDate,
        ];
    }

    /** @return array<string, string> */
    private function sign(string $body): array
    {
        return [CliqPaymentProvider::SIGNATURE_HEADER => hash_hmac('sha256', $body, self::SECRET)];
    }
}
