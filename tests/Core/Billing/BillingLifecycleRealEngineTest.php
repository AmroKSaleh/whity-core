<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\DunningSchedule;
use Whity\Core\Billing\DunningService;
use Whity\Core\Billing\InvoiceNumberAllocator;
use Whity\Core\Billing\InvoiceRepository;
use Whity\Core\Billing\InvoiceStateException;
use Whity\Core\Billing\PaymentReconciler;
use Whity\Core\Money\Money;
use Whity\Core\Payment\MockPaymentProvider;
use Whity\Core\Payment\PaymentEvent;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentLedger;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Database\SequenceCounters;

/**
 * The whole billing lifecycle, against real migrations on a real engine.
 *
 * This is the test that decides whether a tenant is over-billed, under-billed
 * or wrongly locked out, so it is written against the actual schema — CHECK
 * constraints, partial unique indexes and all — rather than against mocked
 * models that would agree with whatever the code did.
 *
 * The two journeys are the ones the product actually has:
 *
 *   1. trial → active → invoice issued → paid → still active
 *   2. invoice issued → payment fails → dunning retries → LOCKED → paid →
 *      unlocked
 *
 * plus the awkward cases in between, which is where the money goes missing:
 * partial payments, redelivered webhooks, out-of-order callbacks, and a
 * payment that arrives while the tenant is locked.
 */
final class BillingLifecycleRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private PDO $pdo;
    private InvoiceRepository $invoices;
    private PaymentLedger $ledger;
    private PaymentReconciler $reconciler;
    private DunningService $dunning;
    private SubscriptionService $subscriptions;
    private InvoiceNumberAllocator $numbers;

    /** Days 1, 3, 7; locked on day 14. */
    private DunningSchedule $schedule;

    /**
     * The wall reads the clock to decide whether a grace deadline has passed,
     * so the test drives it. Without this the wall would compare the test's
     * 2026-09 dates against real time and reach conclusions about a different
     * month than the one being tested.
     */
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'acme', 'acme')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'other', 'other')");

        $this->invoices = new InvoiceRepository($this->pdo);
        $this->ledger = new PaymentLedger($this->pdo);
        $this->reconciler = new PaymentReconciler($this->invoices, $this->ledger, $this->pdo);
        $this->now = new DateTimeImmutable('2026-09-01 00:00:00');
        $this->subscriptions = new SubscriptionService(
            new SubscriptionRepository($this->pdo),
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo),
            ),
            fn (): int => $this->now->getTimestamp(),
        );
        $this->dunning = new DunningService($this->invoices, $this->ledger, $this->subscriptions);
        $this->numbers = new InvoiceNumberAllocator(new SequenceCounters($this->pdo));
        $this->schedule = DunningSchedule::fromSettings('1,3,7', 14);
    }

    // ═══ journey one: it just works ══════════════════════════════════════════

    /**
     * Trial, then active, then an invoice, then paid — and the tenant is never
     * interrupted.
     */
    public function testATrialBecomesAPaidSubscriptionWithoutInterruption(): void
    {
        $this->subscriptions->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_TRIALING,
            'current_period_end' => '2026-10-01 00:00:00',
        ]);
        self::assertTrue($this->subscriptions->decide(self::TENANT, true)->allowed);

        $invoiceId = $this->issuedInvoice(minor: 5000);

        $this->subscriptions->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_ACTIVE,
        ]);

        $result = $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-1', 5000, $invoiceId),
            $this->at('2026-09-05')
        );

        self::assertTrue($result['settled']);
        self::assertSame(
            InvoiceRepository::STATUS_PAID,
            $this->invoiceRow($invoiceId)['status']
        );
        self::assertTrue($this->subscriptions->decide(self::TENANT, true)->allowed);
    }

    /** The number is a formatted string from the configured series. */
    public function testAnIssuedInvoiceCarriesAFormattedNumberFromItsSeries(): void
    {
        $invoiceId = $this->issuedInvoice();
        $invoice = $this->invoiceRow($invoiceId);

        self::assertSame('INV-2026-00001', $invoice['number']);
        self::assertSame('invoice:2026', $invoice['series']);
    }

    /** And the next one continues the sequence rather than restarting it. */
    public function testTheSequenceContinuesAcrossInvoicesAndTenants(): void
    {
        $this->issuedInvoice();
        $second = $this->issuedInvoice(tenantId: self::OTHER_TENANT);

        // Shared scope: one sequence for the platform, because the OPERATOR is
        // the seller. Two tenants do not each start at one.
        self::assertSame(
            'INV-2026-00002',
            $this->invoiceRow($second, self::OTHER_TENANT)['number']
        );
    }

    // ═══ journey two: it does not ════════════════════════════════════════════

    /**
     * THE JOURNEY THAT MATTERS. Fails, retries on schedule, locks on day 14,
     * and comes back the moment the money arrives.
     */
    public function testAFailedPaymentDunsThenLocksThenUnlocksOnPayment(): void
    {
        $this->subscriptions->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_ACTIVE,
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);

        $invoiceId = $this->issuedInvoice(minor: 5000, dueAt: '2026-09-01');
        $invoice = fn (): array => $this->invoiceRow($invoiceId);

        // Day 0 — due today, nothing owed yet.
        self::assertSame(
            DunningService::ACTION_NONE,
            $this->dunning->assess($invoice(), $this->schedule, $this->at('2026-09-01'))['action']
        );

        // Day 2 — the first attempt is due.
        $decision = $this->dunning->assess($invoice(), $this->schedule, $this->at('2026-09-02 09:00'));
        self::assertSame(DunningService::ACTION_ATTEMPT, $decision['action']);
        self::assertSame(1, $decision['attempt']);
        self::assertSame(5000, $decision['balance_minor']);

        // It fails. The ledger records the failure, and the invoice stays open.
        $this->reconciler->apply(
            $this->event(PaymentEventType::Failed, 'mock-a1', 5000, $invoiceId, 'card declined'),
            $this->at('2026-09-02 09:01')
        );
        self::assertSame(InvoiceRepository::STATUS_OPEN, $invoice()['status']);

        // Day 4 — one failure recorded, so the SECOND attempt is due.
        $decision = $this->dunning->assess($invoice(), $this->schedule, $this->at('2026-09-04 09:00'));
        self::assertSame(2, $decision['attempt']);

        $this->reconciler->apply(
            $this->event(PaymentEventType::Failed, 'mock-a2', 5000, $invoiceId, 'card declined'),
            $this->at('2026-09-04 09:01')
        );

        // Day 8 — third and last attempt, then the policy is spent.
        self::assertSame(
            3,
            $this->dunning->assess($invoice(), $this->schedule, $this->at('2026-09-08 09:00'))['attempt']
        );
        $this->reconciler->apply(
            $this->event(PaymentEventType::Failed, 'mock-a3', 5000, $invoiceId, 'card declined'),
            $this->at('2026-09-08 09:01')
        );

        // Day 10 — out of attempts, and STILL NOT LOCKED. This gap is the point
        // of separating the two: a human has a few days left to pay.
        self::assertSame(
            DunningService::ACTION_NONE,
            $this->dunning->assess($invoice(), $this->schedule, $this->at('2026-09-10'))['action']
        );
        self::assertTrue($this->subscriptions->decide(self::TENANT, true)->allowed);

        // Day 16 — past the lock day.
        self::assertSame(
            DunningService::ACTION_LOCK,
            $this->dunning->assess($invoice(), $this->schedule, $this->at('2026-09-16'))['action']
        );

        $this->dunning->lock(self::TENANT, $this->at('2026-09-16'));
        $this->travelTo('2026-09-16 00:00:01');
        self::assertFalse(
            $this->subscriptions->decide(self::TENANT, true)->allowed,
            'the existing payment wall does the locking; there is no second mechanism'
        );

        // And then they pay.
        $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-good', 5000, $invoiceId),
            $this->at('2026-09-17')
        );
        self::assertSame(InvoiceRepository::STATUS_PAID, $invoice()['status']);

        self::assertTrue($this->dunning->restoreIfNothingOverdue(self::TENANT, $this->at('2026-09-17')));
        self::assertTrue($this->subscriptions->decide(self::TENANT, true)->allowed);
    }

    /**
     * A tenant with two overdue invoices who pays one is still overdue.
     * Restoring on the first payment would let a customer stay unlocked forever
     * by always paying the oldest.
     */
    public function testPayingOneOfTwoOverdueInvoicesDoesNotUnlock(): void
    {
        $this->subscriptions->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_ACTIVE,
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);

        $first = $this->issuedInvoice(minor: 5000, dueAt: '2026-09-01');
        $second = $this->issuedInvoice(minor: 3000, dueAt: '2026-09-01');

        $this->dunning->lock(self::TENANT, $this->at('2026-09-16'));
        $this->travelTo('2026-09-20');

        $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-first', 5000, $first),
            $this->at('2026-09-20')
        );

        self::assertFalse($this->dunning->restoreIfNothingOverdue(self::TENANT, $this->at('2026-09-20')));
        self::assertFalse($this->subscriptions->decide(self::TENANT, true)->allowed);

        $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-second', 3000, $second),
            $this->at('2026-09-21')
        );

        self::assertTrue($this->dunning->restoreIfNothingOverdue(self::TENANT, $this->at('2026-09-21')));
    }

    // ═══ where money goes missing ════════════════════════════════════════════

    /**
     * A PAYER WHO TYPES 50 INSTEAD OF 500 must not end up with a settled
     * invoice and a balance nobody will chase. The money is recorded either
     * way; only the conclusion differs, and the conclusion is what reaches the
     * customer.
     */
    public function testAPartialPaymentIsRecordedButLeavesTheInvoiceOpen(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000);

        $result = $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-part', 2000, $invoiceId),
            $this->at('2026-09-05')
        );

        self::assertFalse($result['settled']);
        self::assertSame(InvoiceRepository::STATUS_OPEN, $this->invoiceRow($invoiceId)['status']);
        self::assertSame(2000, $this->ledger->amountSettledMinor(self::TENANT, $invoiceId));
        self::assertSame(3000, $this->reconciler->balanceFor(self::TENANT, $invoiceId));
    }

    /** Two instalments that together cover it do settle it. */
    public function testTwoPartialPaymentsTogetherSettleTheInvoice(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000);

        $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-p1', 2000, $invoiceId),
            $this->at('2026-09-05')
        );
        $second = $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-p2', 3000, $invoiceId),
            $this->at('2026-09-06')
        );

        self::assertTrue($second['settled']);
        self::assertSame(0, $this->reconciler->balanceFor(self::TENANT, $invoiceId));
    }

    /**
     * THE REDELIVERY. Every provider does it, and the second delivery must not
     * credit the account again.
     */
    public function testARedeliveredWebhookDoesNotPayTheInvoiceTwice(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000);
        $event = $this->event(PaymentEventType::Succeeded, 'mock-dup', 5000, $invoiceId);

        $first = $this->reconciler->apply($event, $this->at('2026-09-05'));
        $second = $this->reconciler->apply($event, $this->at('2026-09-05'));

        self::assertSame(PaymentLedger::APPLIED, $first['outcome']);
        self::assertSame(PaymentLedger::DUPLICATE, $second['outcome']);
        self::assertTrue($first['settled']);
        self::assertFalse(
            $second['settled'],
            'a duplicate must not re-run settlement: harmless today, and exactly the '
            . 'kind of harmless that stops being so when settling grows a side effect'
        );
        self::assertSame(5000, $this->ledger->amountSettledMinor(self::TENANT, $invoiceId));
        self::assertCount(1, $this->ledger->historyFor(self::TENANT, $invoiceId));
    }

    /**
     * OUT-OF-ORDER CALLBACKS. A `pending` redelivered after the `succeeded`
     * that superseded it is routine, and flipping the invoice back to unpaid
     * would dun — and eventually lock out — a tenant who has paid.
     */
    public function testALatePendingCallbackCannotUnsettleAPaidInvoice(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000);

        $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-race', 5000, $invoiceId),
            $this->at('2026-09-05')
        );

        // The stale notification finally gets through.
        $result = $this->reconciler->apply(
            $this->event(PaymentEventType::Pending, 'mock-race', 5000, $invoiceId),
            $this->at('2026-09-05')
        );

        self::assertSame(PaymentLedger::DUPLICATE, $result['outcome']);
        self::assertSame(5000, $this->ledger->amountSettledMinor(self::TENANT, $invoiceId));
        self::assertSame(
            InvoiceRepository::STATUS_PAID,
            $this->invoiceRow($invoiceId)['status']
        );
    }

    /** A pending transfer that later confirms DOES settle, on the same reference. */
    public function testAPendingTransferThatConfirmsSettlesWithoutASecondRow(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000);

        $this->reconciler->apply(
            $this->event(PaymentEventType::Pending, 'cliq-77', 5000, $invoiceId),
            $this->at('2026-09-05')
        );
        self::assertSame(0, $this->ledger->amountSettledMinor(self::TENANT, $invoiceId), 'in flight is not paid');

        $confirmed = $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'cliq-77', 5000, $invoiceId),
            $this->at('2026-09-05 10:00')
        );

        self::assertTrue($confirmed['settled']);
        self::assertCount(1, $this->ledger->historyFor(self::TENANT, $invoiceId), 'one movement, not two');
    }

    /** Money that arrived for an invoice we never issued is kept for a human. */
    public function testAPaymentForAnUnknownInvoiceIsNotSilentlyDropped(): void
    {
        $result = $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'cliq-stray', 5000, 999999),
            $this->at('2026-09-05')
        );

        self::assertSame('unknown_invoice', $result['outcome']);
    }

    /**
     * A pending attempt is money in flight, not a failure — counting it would
     * advance the retry schedule against money that is on its way.
     */
    public function testAPendingAttemptDoesNotAdvanceTheRetrySchedule(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000, dueAt: '2026-09-01');

        $this->reconciler->apply(
            $this->event(PaymentEventType::Pending, 'cliq-inflight', 5000, $invoiceId),
            $this->at('2026-09-02')
        );

        $decision = $this->dunning->assess(
            $this->invoiceRow($invoiceId),
            $this->schedule,
            $this->at('2026-09-02 12:00')
        );

        self::assertSame(1, $decision['attempt'], 'still the first attempt, not the second');
    }

    /**
     * A PROVIDER'S CALLBACK MAY NOT NAME ITS OWN TENANT. The event arrives from
     * outside; letting it choose a tenant would be a remote party supplying the
     * tenant id on a write, which is the shape of a cross-tenant leak. The
     * tenant comes from the invoice the reference resolves to, and nowhere else.
     */
    public function testAnEventClaimingAnotherTenantIsRecordedAgainstTheInvoicesOwner(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000, tenantId: self::TENANT);

        $event = new PaymentEvent(
            PaymentEventType::Succeeded,
            'mock',
            'mock-crossing',
            Money::of(5000, 'JOD'),
            new DateTimeImmutable('2026-09-05 12:00:00'),
            $invoiceId,
            self::OTHER_TENANT,
        );

        $this->reconciler->apply($event, $this->at('2026-09-05'));

        self::assertSame(5000, $this->ledger->amountSettledMinor(self::TENANT, $invoiceId));
        self::assertSame(
            0,
            $this->ledger->amountSettledMinor(self::OTHER_TENANT, $invoiceId),
            'nothing was written under the tenant the event named'
        );
    }

    /**
     * A PAID INVOICE IS NEVER DUNNED, however long ago it was due. Without this
     * a tenant who paid on day two is locked out on day fifteen because the
     * dates alone still say overdue.
     */
    public function testAPaidInvoiceIsNotDunnedHoweverOverdueItsDatesLook(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000, dueAt: '2026-09-01');

        $this->reconciler->apply(
            $this->event(PaymentEventType::Succeeded, 'mock-early', 5000, $invoiceId),
            $this->at('2026-09-02')
        );

        $decision = $this->dunning->assess(
            $this->invoiceRow($invoiceId),
            $this->schedule,
            $this->at('2026-09-30')
        );

        self::assertSame(DunningService::ACTION_NONE, $decision['action']);
        self::assertSame(0, $decision['balance_minor']);
    }

    /** A voided invoice likewise: it is not a debt, whatever its due date says. */
    public function testAVoidedInvoiceIsNotDunned(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000, dueAt: '2026-09-01');
        $this->invoices->void(self::TENANT, $invoiceId, 'issued in error', $this->at('2026-09-02'));

        $decision = $this->dunning->assess(
            $this->invoiceRow($invoiceId),
            $this->schedule,
            $this->at('2026-09-30')
        );

        self::assertSame(DunningService::ACTION_NONE, $decision['action']);
    }

    // ═══ the invoice is evidence ═════════════════════════════════════════════

    /**
     * THE SNAPSHOT. The tenant renames itself and last year's invoice must keep
     * saying what it said — this is the failure that is unrecoverable after the
     * fact, because the original numbers are simply gone.
     */
    public function testRenamingATenantDoesNotRewriteItsIssuedInvoices(): void
    {
        $invoiceId = $this->issuedInvoice();

        $this->pdo->exec("UPDATE tenants SET name = 'Acme Holdings International' WHERE id = 1");

        self::assertSame(
            'Acme Ltd',
            $this->invoiceRow($invoiceId)['buyer_name'],
            'the invoice says what it said when it was issued'
        );
    }

    public function testAnIssuedInvoiceCannotBeEdited(): void
    {
        $invoiceId = $this->issuedInvoice();

        $this->expectException(InvoiceStateException::class);
        $this->expectExceptionMessageMatches('/credit note/');
        $this->invoices->addLine(self::TENANT, $invoiceId, 'Sneaky extra', 1, 9999);
    }

    public function testAnInvoiceCannotBeIssuedTwice(): void
    {
        $invoiceId = $this->issuedInvoice();

        $this->expectException(InvoiceStateException::class);
        $this->invoices->issue(
            self::TENANT,
            $invoiceId,
            'invoice:2026',
            'INV-2026-09999',
            $this->at('2026-09-01'),
            $this->at('2026-09-15')
        );
    }

    /** An empty invoice would spend a sequence number saying nothing. */
    public function testAnEmptyInvoiceIsNotWorthANumber(): void
    {
        $draft = $this->invoices->createDraft(self::TENANT, 'JOD');

        $this->expectException(InvoiceStateException::class);
        $this->expectExceptionMessageMatches('/no lines/');
        $this->invoices->issue(
            self::TENANT,
            $draft,
            'invoice:2026',
            'INV-2026-00001',
            $this->at('2026-09-01'),
            $this->at('2026-09-15')
        );
    }

    // ═══ tax ═════════════════════════════════════════════════════════════════

    /**
     * TAX IS ON THE DISCOUNTED AMOUNT. A discount reduces the consideration, so
     * it reduces the tax with it; taxing the undiscounted figure overcharges
     * the customer on their own promotion.
     */
    public function testTaxIsChargedOnWhatIsActuallyPayable(): void
    {
        $draft = $this->invoices->createDraft(self::TENANT, 'JOD');
        // 10.000 JOD, 2.000 off, 16% on the remaining 8.000 = 1.280.
        $this->invoices->addLine(self::TENANT, $draft, 'Plan', 1, 10000, taxRateBp: 1600, discountMinor: 2000);

        $invoice = $this->invoiceRow($draft);

        self::assertSame(10000, $invoice['subtotal_minor']);
        self::assertSame(2000, $invoice['discount_minor']);
        self::assertSame(1280, $invoice['tax_minor']);
        self::assertSame(9280, $invoice['total_minor']);
    }

    /** Mixed rates on one invoice — a zero-rated line beside a taxed one. */
    public function testLinesMayCarryDifferentTaxRates(): void
    {
        $draft = $this->invoices->createDraft(self::TENANT, 'JOD');
        $this->invoices->addLine(self::TENANT, $draft, 'Support', 1, 10000, taxRateBp: 1600);
        $this->invoices->addLine(self::TENANT, $draft, 'Export', 1, 5000, taxRateBp: 0);

        $invoice = $this->invoiceRow($draft);

        self::assertSame(15000, $invoice['subtotal_minor']);
        self::assertSame(1600, $invoice['tax_minor']);
        self::assertSame(16600, $invoice['total_minor']);
    }

    // ═══ numbering ═══════════════════════════════════════════════════════════

    /**
     * The gap report is what "are we gapless" is honestly answerable with. A
     * rolled-back issue leaves a specific missing number somebody can account
     * for, which is what a tax authority actually wants.
     */
    public function testTheGapReportNamesTheNumbersNoInvoiceCarries(): void
    {
        $this->issuedInvoice();
        $this->issuedInvoice();

        // A third number is allocated and never used — an issue that failed.
        $this->numbers->allocate(
            self::TENANT,
            'INV-{YYYY}-{SEQ:5}',
            InvoiceNumberAllocator::SCOPE_SHARED,
            InvoiceNumberAllocator::RESET_YEARLY,
            $this->at('2026-09-01')
        );

        self::assertSame(
            [3],
            $this->numbers->gapReportFor(self::TENANT, 'invoice:2026', [1, 2], perTenant: false)
        );
    }

    /** A yearly reset is a new series, not a counter somebody has to zero. */
    public function testAYearlyResetStartsANewSeriesRatherThanZeroingACounter(): void
    {
        $first = $this->numbers->allocate(
            self::TENANT,
            'INV-{YYYY}-{SEQ:5}',
            InvoiceNumberAllocator::SCOPE_SHARED,
            InvoiceNumberAllocator::RESET_YEARLY,
            $this->at('2026-12-31')
        );
        $second = $this->numbers->allocate(
            self::TENANT,
            'INV-{YYYY}-{SEQ:5}',
            InvoiceNumberAllocator::SCOPE_SHARED,
            InvoiceNumberAllocator::RESET_YEARLY,
            $this->at('2027-01-01')
        );

        self::assertSame('INV-2026-00001', $first['number']);
        self::assertSame('INV-2027-00001', $second['number']);
        self::assertNotSame($first['series'], $second['series']);
    }

    /** Per-tenant scope gives each tenant its own sequence, both from one. */
    public function testPerTenantScopeGivesEachTenantItsOwnSequence(): void
    {
        $a = $this->numbers->allocate(
            self::TENANT,
            '{SEQ:4}',
            InvoiceNumberAllocator::SCOPE_PER_TENANT,
            InvoiceNumberAllocator::RESET_NEVER,
            $this->at('2026-09-01')
        );
        $b = $this->numbers->allocate(
            self::OTHER_TENANT,
            '{SEQ:4}',
            InvoiceNumberAllocator::SCOPE_PER_TENANT,
            InvoiceNumberAllocator::RESET_NEVER,
            $this->at('2026-09-01')
        );

        self::assertSame('0001', $a['number']);
        self::assertSame('0001', $b['number']);
        self::assertNotSame($a['series'], $b['series'], 'different counters, so the index still holds');
    }

    // ═══ the mock rail drives the whole thing ════════════════════════════════

    /**
     * The mock provider is a complete stand-in: a scripted failure then a
     * success moves a real invoice through the real ledger.
     */
    public function testTheMockRailDrivesARealInvoiceToPaid(): void
    {
        $invoiceId = $this->issuedInvoice(minor: 5000, dueAt: '2026-09-01');

        $mock = new MockPaymentProvider();
        $mock->queueOutcome(PaymentEventType::Failed, 'insufficient funds')
            ->queueOutcome(PaymentEventType::Succeeded);

        foreach ([1, 2] as $attempt) {
            $instruction = $mock->initiatePayment(
                \Whity\Core\Payment\PaymentRequest::forInvoiceAttempt(
                    Money::of(5000, 'JOD'),
                    self::TENANT,
                    $invoiceId,
                    $attempt
                )
            );
            $event = $instruction->event;
            self::assertNotNull($event, 'the mock settles synchronously');
            $this->reconciler->apply($event, $this->at('2026-09-0' . (1 + $attempt)));
        }

        self::assertSame(
            InvoiceRepository::STATUS_PAID,
            $this->invoiceRow($invoiceId)['status']
        );
        self::assertCount(2, $this->ledger->historyFor(self::TENANT, $invoiceId));
        self::assertSame(1, $this->ledger->failedAttempts(self::TENANT, $invoiceId));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * An invoice row, insisting it is there.
     *
     * `findById` is nullable and every caller here has just created the row, so
     * a bare dereference reads fine and reports "offset does not exist on null"
     * when something upstream broke — a message about the test rather than
     * about the invoice. This says which invoice went missing.
     *
     * @return array<string, mixed>
     */
    private function invoiceRow(int $invoiceId, int $tenantId = self::TENANT): array
    {
        $invoice = $this->invoices->findById($tenantId, $invoiceId);
        self::assertNotNull($invoice, "invoice {$invoiceId} should exist for tenant {$tenantId}");

        return $invoice;
    }

    private function issuedInvoice(
        int $minor = 5000,
        string $dueAt = '2026-09-15',
        int $tenantId = self::TENANT,
    ): int {
        $draft = $this->invoices->createDraft($tenantId, 'JOD');
        $this->invoices->addLine($tenantId, $draft, 'Professional plan, September 2026', 1, $minor);

        $allocated = $this->numbers->allocate(
            $tenantId,
            'INV-{YYYY}-{SEQ:5}',
            InvoiceNumberAllocator::SCOPE_SHARED,
            InvoiceNumberAllocator::RESET_YEARLY,
            $this->at('2026-09-01')
        );

        $this->invoices->issue(
            $tenantId,
            $draft,
            $allocated['series'],
            $allocated['number'],
            $this->at('2026-09-01'),
            $this->at($dueAt),
            seller: ['name' => 'Whity Operator', 'tax_id' => 'JO-123'],
            buyer: ['name' => 'Acme Ltd', 'tax_id' => 'JO-999'],
        );

        return $draft;
    }

    private function event(
        PaymentEventType $type,
        string $reference,
        int $minor,
        ?int $invoiceId,
        ?string $reason = null,
    ): PaymentEvent {
        return new PaymentEvent(
            $type,
            'mock',
            $reference,
            Money::of($minor, 'JOD'),
            new DateTimeImmutable('2026-09-05 12:00:00'),
            $invoiceId,
            null,
            $reason,
        );
    }

    private function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    /** Move the clock the payment wall reads. */
    private function travelTo(string $when): DateTimeImmutable
    {
        $this->now = new DateTimeImmutable($when);

        return $this->now;
    }
}
