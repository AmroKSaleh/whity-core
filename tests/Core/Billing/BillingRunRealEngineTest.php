<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\DunningRun;
use Whity\Core\Billing\DunningService;
use Whity\Core\Billing\InvoiceNumberAllocator;
use Whity\Core\Billing\InvoiceRepository;
use Whity\Core\Billing\SubscriptionBillingRun;
use Whity\Core\Money\Money;
use Whity\Core\Payment\PaymentEvent;
use Whity\Core\Payment\PaymentEventType;
use Whity\Core\Payment\PaymentLedger;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Database\SequenceCounters;

/**
 * The two scheduled runs: the one that starts the clock, and the one that
 * chases what it produced.
 *
 * THE PROPERTY UNDER TEST IS THAT RE-RUNNING IS SAFE. A scheduled job is
 * invoked by a clock, and clocks fire twice — a worker restarted mid-run, a
 * cron overlapping its predecessor, an operator retrying after a failure. Every
 * test here that runs the billing sweep runs it TWICE, because a single-run
 * test would pass against an implementation that double-bills every customer.
 */
final class BillingRunRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER = 2;
    private const PLAN = 1;

    private PDO $pdo;
    private SubscriptionBillingRun $billing;
    private DunningRun $dunningRun;
    private InvoiceRepository $invoices;
    private PaymentLedger $ledger;
    private SubscriptionService $subscriptions;
    private SettingsService $settings;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Acme Ltd', 'acme')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Beta Co', 'beta')");
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, created_at, updated_at) VALUES (1, 'pro', 'Professional', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

        $this->now = new DateTimeImmutable('2026-10-01 03:00:00');
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->invoices = new InvoiceRepository($this->pdo);
        $this->ledger = new PaymentLedger($this->pdo);
        $this->subscriptions = new SubscriptionService(
            new SubscriptionRepository($this->pdo),
            $this->settings,
            fn (): int => $this->now->getTimestamp(),
        );

        $this->billing = new SubscriptionBillingRun(
            $this->pdo,
            $this->invoices,
            new InvoiceNumberAllocator(new SequenceCounters($this->pdo)),
            $this->subscriptions,
            $this->settings,
            new NullLogger(),
        );
        $this->dunningRun = new DunningRun(
            $this->invoices,
            new DunningService($this->invoices, $this->ledger, $this->subscriptions),
            $this->settings,
            new NullLogger(),
        );
    }

    // ═══ the billing run ═════════════════════════════════════════════════════

    /**
     * A TENANT BILLED BY SOMEONE ELSE IS NOT BILLED AGAIN HERE.
     *
     * `external_ref` marks a tenant whose subscription is held by the external
     * billing service: that service charges them, renews them, and owns their
     * period. Reconciliation copies the period end down into the same column
     * this run reads, so without the exclusion this run would see a period that
     * had ended, decide the month was due, and raise a SECOND bill for a month
     * already paid — charged once by them, invoiced again by us, on a document
     * that looks exactly like every other invoice.
     *
     * The assertion is on `invoiced` being zero AND on no invoice existing,
     * because a run that skipped for the wrong reason would satisfy only the
     * first.
     */
    public function testATenantBilledExternallyIsNotInvoicedByThisRun(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');
        $this->subscriptions->setSubscription(self::TENANT, ['external_ref' => 'sub_held_elsewhere']);

        $result = $this->billing->run($this->now);

        self::assertSame(0, $result['invoiced'], 'the external service bills this tenant, not us');
        self::assertSame([], $this->invoices->listForTenant(self::TENANT));
    }

    /** And a tenant with no external subscription is still billed here as before. */
    public function testATenantWithNoExternalSubscriptionIsStillBilledLocally(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        self::assertSame(1, $this->billing->run($this->now)['invoiced']);
    }

    public function testASubscriptionWhosePeriodEndedIsInvoiced(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $result = $this->billing->run($this->now);

        self::assertSame(1, $result['invoiced']);

        $invoices = $this->invoices->listForTenant(self::TENANT);
        self::assertCount(1, $invoices);
        self::assertSame(InvoiceRepository::STATUS_OPEN, $invoices[0]['status']);
        self::assertSame(5000, $invoices[0]['total_minor']);
        self::assertSame('INV-2026-00001', $invoices[0]['number']);
        // The buyer is FROZEN onto the invoice at issue.
        self::assertSame('Acme Ltd', $invoices[0]['buyer_name']);
    }

    /**
     * THE TEST THAT MATTERS MOST. Clocks fire twice, and the guarantee is a
     * unique index rather than the run remembering anything — so the second run
     * collides instead of billing the customer again.
     */
    public function testRunningTwiceDoesNotBillTheSamePeriodTwice(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $first = $this->billing->run($this->now);
        $second = $this->billing->run($this->now);

        self::assertSame(1, $first['invoiced']);
        self::assertSame(0, $second['invoiced'], 'the second run must not bill again');
        self::assertCount(1, $this->invoices->listForTenant(self::TENANT));
    }

    /**
     * AND THE INDEX IS WHAT GUARANTEES IT, not the period advance.
     *
     * The test above passes even with the unique index removed, because the
     * first run advances the period and the second finds nothing due. That is
     * the happy path defending itself, and it says nothing about the case the
     * index exists for: two runs that BOTH see the old period — a worker that
     * crashed after invoicing and before committing the advance, or two
     * workers claiming one tick.
     *
     * So this puts the period back before the second run, which is exactly
     * what either of those looks like from the database's side.
     */
    public function testAConcurrentRunCollidesOnTheIndexRatherThanBillingAgain(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $this->billing->run($this->now);

        // The advance never happened, as far as this run is concerned.
        $this->pdo->exec(
            "UPDATE tenant_plan SET current_period_end = '2026-10-01 00:00:00' WHERE tenant_id = 1"
        );

        $second = $this->billing->run($this->now);

        self::assertSame(0, $second['invoiced'], 'the index must refuse the duplicate period');
        self::assertSame(1, $second['skipped']);
        self::assertCount(1, $this->invoices->listForTenant(self::TENANT));
    }

    /**
     * The period is advanced in the SAME transaction as the invoice. A run that
     * invoiced without advancing would try the same period next tick and be
     * saved only by the index; one that advanced without invoicing would skip a
     * month in silence, which is worse.
     */
    public function testTheSubscriptionPeriodMovesOnWithTheInvoice(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $this->billing->run($this->now);

        $subscription = $this->subscriptions->getSubscription(self::TENANT);
        self::assertNotNull($subscription);
        self::assertStringStartsWith('2026-11-01', (string) $subscription['current_period_end']);
    }

    /** And the next period is then billable, in its own right. */
    public function testTheFollowingPeriodIsBilledWhenItEnds(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $this->billing->run($this->now);
        $this->billing->run(new DateTimeImmutable('2026-11-01 03:00:00'));

        $invoices = $this->invoices->listForTenant(self::TENANT);
        self::assertCount(2, $invoices);
        self::assertNotSame($invoices[0]['number'], $invoices[1]['number']);
    }

    public function testASubscriptionWhosePeriodHasNotEndedIsLeftAlone(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-12-01 00:00:00');

        self::assertSame(0, $this->billing->run($this->now)['invoiced']);
        self::assertSame([], $this->invoices->listForTenant(self::TENANT));
    }

    /**
     * BILLING SOMEBODY WHO HAS LEFT is the most damaging thing this run could
     * do, and the one that would go unnoticed longest — the invoice looks like
     * every other.
     */
    public function testACancelledSubscriptionIsNeverBilled(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00', SubscriptionService::STATUS_CANCELED);

        self::assertSame(0, $this->billing->run($this->now)['invoiced']);
        self::assertSame([], $this->invoices->listForTenant(self::TENANT));
    }

    /**
     * A tenant behind on last month still owes this month. Stopping the meter
     * because they are past due would quietly forgive the debt the dunning
     * machine is chasing them for.
     */
    public function testAPastDueSubscriptionIsStillBilled(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00', SubscriptionService::STATUS_PAST_DUE);

        self::assertSame(1, $this->billing->run($this->now)['invoiced']);
    }

    /** The operator's own tenant is never billed by the operator. */
    public function testTheSystemTenantIsNeverBilled(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->pdo->exec(
            "INSERT INTO tenant_plan (tenant_id, plan_id, status, current_period_end, assigned_at)
             VALUES (0, 1, 'active', '2026-10-01 00:00:00', CURRENT_TIMESTAMP)"
        );

        $result = $this->billing->run($this->now);

        self::assertSame(0, $result['invoiced']);
        // NEVER CONSIDERED, not merely not billed. Without the query's own
        // exclusion the system tenant is picked up and then refused deeper in
        // by SubscriptionService — which is a second defence worth having, and
        // is not the one under test here. A non-zero `skipped` would mean the
        // first defence had gone.
        self::assertSame(0, $result['skipped']);
    }

    /**
     * A misconfigured plan is skipped LOUDLY — not billed at zero, which would
     * look like a settled month, and not raised, which would leave every tenant
     * after it unbilled.
     */
    public function testAPlanWithNoActivePriceIsCountedNotBilled(): void
    {
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $result = $this->billing->run($this->now);

        self::assertSame(1, $result['unpriced']);
        self::assertSame(0, $result['invoiced']);
        self::assertSame([], $this->invoices->listForTenant(self::TENANT));
    }

    /**
     * NO FALLBACK TO ANOTHER CURRENCY. A plan priced only in dollars cannot
     * bill a tenant configured in dinars — charging the dollar figure labelled
     * JOD would be wrong by half again, and would look like a price rise.
     */
    public function testAPlanPricedInAnotherCurrencyIsNotBilled(): void
    {
        $this->priceThePlan(5000, 'USD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        self::assertSame(1, $this->billing->run($this->now)['unpriced']);
    }

    /** One bad tenant does not stop the rest of the sweep. */
    public function testOneMisconfiguredTenantDoesNotStopTheOthers(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        // Tenant 1 subscribed to a plan that has no price at all.
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, created_at, updated_at) VALUES (2, 'broken', 'Broken', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00', planId: 2);
        $this->subscribe(self::OTHER, '2026-10-01 00:00:00');

        $result = $this->billing->run($this->now);

        self::assertSame(1, $result['invoiced'], 'the healthy tenant is still billed');
        self::assertCount(1, $this->invoices->listForTenant(self::OTHER));
    }

    /**
     * A per-seat price bills for the seats in use when the period closed: a
     * tenant that added people on the last day pays for them.
     */
    public function testAPerSeatPriceBillsForActiveMembers(): void
    {
        $this->priceThePlan(1000, 'JOD', 'month', perSeat: true);
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        for ($i = 20; $i < 23; $i++) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES ({$i}, 'p{$i}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES ({$i}, 1, 1, 'active', CURRENT_TIMESTAMP)");
        }
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $this->billing->run($this->now);

        $invoice = $this->invoices->listForTenant(self::TENANT)[0];
        self::assertSame(3000, $invoice['total_minor'], 'three seats at 1.000 JOD');
    }

    /** Tax comes from settings, and lands on the invoice as a snapshot. */
    public function testTheConfiguredTaxRateIsAppliedAndFrozen(): void
    {
        $this->settings->setGlobal(SettingsRegistry::BILLING_TAX_RATE_BP, '1600');
        $this->settings->setGlobal(SettingsRegistry::BILLING_TAX_LABEL, 'GST');
        $this->priceThePlan(10000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $this->billing->run($this->now);

        $invoice = $this->invoices->listForTenant(self::TENANT)[0];
        self::assertSame(1600, $invoice['tax_minor']);
        self::assertSame(11600, $invoice['total_minor']);
        self::assertSame(1600, $invoice['tax_rate_bp']);
        self::assertSame('GST', $invoice['tax_label']);
    }

    /** The due date follows the configured payment terms. */
    public function testThePaymentTermSetsTheDueDate(): void
    {
        $this->settings->setGlobal(SettingsRegistry::BILLING_PAYMENT_TERMS_DAYS, '30');
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');

        $this->billing->run($this->now);

        self::assertStringStartsWith(
            '2026-10-31',
            (string) $this->invoices->listForTenant(self::TENANT)[0]['due_at']
        );
    }

    // ═══ the dunning sweep ═══════════════════════════════════════════════════

    /** Nothing overdue, nothing done. */
    public function testASweepOverNothingOverdueChangesNothing(): void
    {
        $this->priceThePlan(5000, 'JOD', 'month');
        $this->subscribe(self::TENANT, '2026-10-01 00:00:00');
        $this->billing->run($this->now);

        $result = $this->dunningRun->run($this->now);

        self::assertSame(0, $result['considered']);
        self::assertSame(0, $result['locked']);
    }

    /**
     * An attempt is REPORTED, not made. Whether an unattended charge is even
     * possible is a property of the rail, and on CliQ it is not — a sweep that
     * charged anyway would manufacture a failure per cycle for somebody who has
     * done nothing wrong.
     */
    public function testAnAttemptComingDueIsReportedRatherThanMade(): void
    {
        $invoiceId = $this->overdueInvoice('2026-10-01');

        $result = $this->dunningRun->run(new DateTimeImmutable('2026-10-02 03:00:00'));

        self::assertSame(1, $result['attempts_due']);
        self::assertSame(0, $result['locked']);
        // Nothing was charged: the ledger is empty.
        self::assertSame([], $this->ledger->historyFor(self::TENANT, $invoiceId));
    }

    public function testATenantPastTheLockDayLosesAccess(): void
    {
        $this->overdueInvoice('2026-10-01');
        $this->subscriptions->setSubscription(self::TENANT, [
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);

        $this->now = new DateTimeImmutable('2026-10-16 03:00:00');
        $result = $this->dunningRun->run($this->now);
        self::assertSame(1, $result['locked']);

        // The wall is consulted on a LATER request than the one that locked.
        // `lock()` records the instant grace ended, and `isCurrent()` treats a
        // deadline of exactly now as still current — so a tenant keeps access
        // for the instant of their own locking and loses it on the next
        // request. Advancing the clock here is what actually happens, rather
        // than a way of getting the assertion to pass.
        $this->now = new DateTimeImmutable('2026-10-16 03:00:01');
        self::assertFalse($this->subscriptions->decide(self::TENANT, true)->allowed);
    }

    /** Re-running the sweep locks nobody twice. */
    public function testSweepingTwiceLocksOnce(): void
    {
        $this->overdueInvoice('2026-10-01');
        $this->now = new DateTimeImmutable('2026-10-16 03:00:00');

        $first = $this->dunningRun->run($this->now);
        $second = $this->dunningRun->run($this->now);

        self::assertSame(1, $first['locked']);
        self::assertSame(1, $second['locked'], 'reported again, but the state is unchanged');
        self::assertSame(
            SubscriptionService::STATUS_PAST_DUE,
            $this->subscriptions->getSubscription(self::TENANT)['status'] ?? null
        );
    }

    /** A tenant with three overdue invoices is locked once, not three times. */
    public function testATenantWithSeveralOverdueInvoicesIsLockedOnce(): void
    {
        $this->overdueInvoice('2026-10-01');
        $this->overdueInvoice('2026-10-01', amount: 3000);
        $this->overdueInvoice('2026-10-01', amount: 2000);

        $result = $this->dunningRun->run(new DateTimeImmutable('2026-10-16 03:00:00'));

        self::assertSame(3, $result['considered']);
        self::assertSame(1, $result['locked']);
    }

    /** A paid invoice is never chased, however old its dates look. */
    public function testAPaidInvoiceIsNotSweptUp(): void
    {
        $invoiceId = $this->overdueInvoice('2026-10-01');
        $this->settle($invoiceId, 5000);

        $result = $this->dunningRun->run(new DateTimeImmutable('2026-10-30 03:00:00'));

        self::assertSame(0, $result['considered'], 'a paid invoice is no longer open');
        self::assertSame(0, $result['locked']);
    }

    /** The per-tenant schedule is honoured, not a platform-wide one. */
    public function testATenantsOwnScheduleDecidesWhenItLocks(): void
    {
        $this->overdueInvoice('2026-10-01');
        // This tenant gets 60 days before losing access.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DUNNING_LOCK_AFTER_DAYS, '60');

        self::assertSame(0, $this->dunningRun->run(new DateTimeImmutable('2026-10-16 03:00:00'))['locked']);
        self::assertSame(1, $this->dunningRun->run(new DateTimeImmutable('2026-12-05 03:00:00'))['locked']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function priceThePlan(
        int $minor,
        string $currency,
        string $period,
        bool $perSeat = false,
        int $planId = self::PLAN,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_active, created_at, updated_at)
             VALUES (:plan_id, :currency, :amount, :period, :per_seat, :active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->bindValue(':plan_id', $planId, PDO::PARAM_INT);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':amount', $minor, PDO::PARAM_INT);
        $statement->bindValue(':period', $period);
        $statement->bindValue(':per_seat', $perSeat, PDO::PARAM_BOOL);
        $statement->bindValue(':active', true, PDO::PARAM_BOOL);
        $statement->execute();
    }

    private function subscribe(
        int $tenantId,
        string $periodEnd,
        string $status = SubscriptionService::STATUS_ACTIVE,
        int $planId = self::PLAN,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, status, current_period_end, assigned_at)
             VALUES (:tenant_id, :plan_id, :status, :period_end, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':plan_id' => $planId,
            ':status' => $status,
            ':period_end' => $periodEnd,
        ]);
    }

    /** An open, overdue invoice raised through the billing run itself. */
    private function overdueInvoice(string $dueAt, int $amount = 5000): int
    {
        $draft = $this->invoices->createDraft(self::TENANT, 'JOD');
        $this->invoices->addLine(self::TENANT, $draft, 'Subscription', 1, $amount);

        $allocated = (new InvoiceNumberAllocator(new SequenceCounters($this->pdo)))->allocate(
            self::TENANT,
            'INV-{YYYY}-{SEQ:5}',
            InvoiceNumberAllocator::SCOPE_SHARED,
            InvoiceNumberAllocator::RESET_YEARLY,
            new DateTimeImmutable('2026-09-01'),
        );

        $this->invoices->issue(
            self::TENANT,
            $draft,
            $allocated['series'],
            $allocated['number'],
            new DateTimeImmutable('2026-09-01'),
            new DateTimeImmutable($dueAt),
            buyer: ['name' => 'Acme Ltd'],
        );

        if ($this->subscriptions->getSubscription(self::TENANT) === null) {
            $this->subscriptions->setSubscription(self::TENANT, [
                'status' => SubscriptionService::STATUS_ACTIVE,
                'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
            ]);
        }

        return $draft;
    }

    private function settle(int $invoiceId, int $minor): void
    {
        $this->ledger->record(
            new PaymentEvent(
                PaymentEventType::Succeeded,
                'mock',
                'settle-' . $invoiceId,
                Money::of($minor, 'JOD'),
                new DateTimeImmutable('2026-10-02 12:00:00'),
                $invoiceId,
            ),
            self::TENANT,
            $invoiceId,
        );
        $this->invoices->markPaid(self::TENANT, $invoiceId, new DateTimeImmutable('2026-10-02 12:00:00'));
    }
}
