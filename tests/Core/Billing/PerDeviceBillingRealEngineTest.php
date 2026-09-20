<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\InvoiceNumberAllocator;
use Whity\Core\Billing\InvoiceRepository;
use Whity\Core\Billing\SubscriptionBillingRun;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Database\SequenceCounters;

/**
 * Counting devices for an invoice.
 *
 * THESE ARE MONEY TESTS. Every assertion below is a number that appears on a
 * bill somebody pays, and the failure mode is not a crash — it is an invoice
 * that is quietly wrong by one unit, every month, until a customer notices.
 *
 * The three bases produce DIFFERENT answers from identical data, which is the
 * whole reason the basis is configuration. So each is asserted against the same
 * fixture: if a change made two of them agree, that would be the bug.
 */
final class PerDeviceBillingRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SubscriptionBillingRun $billing;
    private InvoiceRepository $invoices;
    private SettingsService $settings;

    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const PLAN = 1;

    /** The period under test: March 2026. */
    private const PERIOD_START = '2026-03-01 00:00:00';
    private const PERIOD_END = '2026-03-31 23:59:59';

    /** The moment the run fires: just after the March period closed. */
    private const RUN_AT = '2026-04-01 03:00:00';

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'A Customer', 'a-customer')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Another', 'another')");
        $this->pdo->exec("INSERT INTO plans (id, plan_key, name, created_at, updated_at)
                          VALUES (1, 'pro', 'Professional', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        SchemaFromMigrations::syncSequences($this->pdo);

        $now = new DateTimeImmutable(self::RUN_AT);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->invoices = new InvoiceRepository($this->pdo);
        $this->billing = new SubscriptionBillingRun(
            $this->pdo,
            $this->invoices,
            new InvoiceNumberAllocator(new SequenceCounters($this->pdo)),
            new SubscriptionService(
                new SubscriptionRepository($this->pdo),
                $this->settings,
                static fn (): int => $now->getTimestamp(),
            ),
            $this->settings,
            new NullLogger(),
        );
    }

    // ── the three bases disagree, on purpose ────────────────────────────────

    /**
     * THE CENTRAL TEST. One fixture, three bases, three different numbers — and
     * if any two of them ever matched, the basis setting would be decorative.
     */
    public function testTheThreeBasesCountTheSameFixtureDifferently(): void
    {
        // In service all period, and used.
        $this->device('SN-USED', provisioned: '2026-01-05', activated: '2026-01-06', lastSeen: '2026-03-20');
        // In service, but never checked in during March.
        $this->device('SN-IDLE', provisioned: '2026-01-05', activated: '2026-01-06', lastSeen: '2026-02-02');
        // Stock: a serial imported, never activated.
        $this->device('SN-STOCK', provisioned: '2026-02-10', activated: null, lastSeen: null);

        self::assertSame(2, $this->billableDevices('activated'), 'two units are in service');
        self::assertSame(3, $this->billableDevices('provisioned'), 'stock on a shelf counts on this basis');
        self::assertSame(1, $this->billableDevices('active_in_period'), 'only one was actually used');
    }

    // ── period boundaries ───────────────────────────────────────────────────

    /**
     * A unit activated on the LAST DAY of the period is billed for it. The
     * per-seat count already works this way, and an invoice that missed it
     * would undercharge silently.
     */
    public function testAUnitActivatedOnTheFinalDayIsBilled(): void
    {
        $this->device('SN-LATE', provisioned: '2026-03-31', activated: '2026-03-31');

        self::assertSame(1, $this->billableDevices('activated'));
    }

    /** A unit activated AFTER the period closed belongs to the next invoice. */
    public function testAUnitActivatedAfterThePeriodIsNotBilled(): void
    {
        $this->device('SN-NEXT-MONTH', provisioned: '2026-04-02', activated: '2026-04-02');

        self::assertSame(0, $this->billableDevices('activated'), 'this belongs on April\'s invoice, not March\'s');
    }

    /**
     * A unit retired BEFORE the period began was in service for none of it.
     * Billing it would charge for hardware the customer had already returned.
     */
    public function testAUnitRetiredBeforeThePeriodIsNotBilled(): void
    {
        $this->device('SN-GONE', provisioned: '2026-01-01', activated: '2026-01-02', retired: '2026-02-15');

        self::assertSame(0, $this->billableDevices('activated'));
        self::assertSame(0, $this->billableDevices('provisioned'));
    }

    /**
     * But a unit retired DURING the period WAS in service for part of it, and
     * is billed. Dropping it would let a customer avoid a month's charge by
     * retiring on the last day.
     */
    public function testAUnitRetiredDuringThePeriodIsStillBilled(): void
    {
        $this->device('SN-MIDWAY', provisioned: '2026-01-01', activated: '2026-01-02', retired: '2026-03-20');

        self::assertSame(1, $this->billableDevices('activated'));
    }

    // ── isolation ───────────────────────────────────────────────────────────

    /**
     * ANOTHER TENANT'S HARDWARE IS NOT ON THIS INVOICE. Obvious, and worth a
     * test precisely because it is: a count query that lost its tenant
     * predicate would bill one customer for another's estate and the number
     * would look plausible.
     */
    public function testAnotherTenantsDevicesAreNeverCounted(): void
    {
        $this->device('SN-MINE', activated: '2026-01-06');
        $this->device('SN-THEIRS', activated: '2026-01-06', tenantId: self::OTHER_TENANT);
        $this->device('SN-THEIRS-2', activated: '2026-01-06', tenantId: self::OTHER_TENANT);

        self::assertSame(1, $this->billableDevices('activated'));
    }

    // ── the unrecognised value ──────────────────────────────────────────────

    /**
     * A TYPO IN THE SETTING MUST NOT WIDEN AN INVOICE. An unknown basis falls
     * back to `activated`, which never counts more than `provisioned` would —
     * so a misconfiguration undercharges rather than billing a customer for
     * stock they have not put into service.
     */
    public function testAnUnknownBasisFallsBackToActivatedRatherThanTheWidestCount(): void
    {
        $this->device('SN-IN-SERVICE', provisioned: '2026-01-05', activated: '2026-01-06');
        $this->device('SN-ON-SHELF', provisioned: '2026-01-05', activated: null);

        self::assertSame(
            $this->billableDevices('activated'),
            $this->billableDevices('nonsense-value'),
            'an unrecognised basis must behave as activated'
        );
        self::assertLessThan(
            $this->billableDevices('provisioned'),
            $this->billableDevices('nonsense-value'),
            'a typo must never bill MORE than the narrowest reading'
        );
    }

    // ── the schema refuses an ambiguous price ───────────────────────────────

    /**
     * A price cannot be per-seat AND per-device. The billing run would have to
     * pick a multiplier, and whichever it picked would be wrong half the time —
     * on an invoice somebody already paid.
     */
    public function testAPriceCannotMultiplyBySeatsAndDevicesAtOnce(): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('SQLite cannot add a CHECK constraint after table creation.');
        }

        $this->pdo->exec("INSERT INTO plans (plan_key, name, is_active, created_at, updated_at)
                          VALUES ('p', 'Plan', true, NOW(), NOW())");
        $plans = $this->pdo->query("SELECT id FROM plans WHERE plan_key = 'p'");
        self::assertNotFalse($plans);
        $planId = (int) $plans->fetchColumn();

        $this->expectException(\PDOException::class);
        $this->pdo->exec("
            INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_per_seat, is_per_device, is_active, created_at, updated_at)
            VALUES ({$planId}, 'JOD', 1000, 'month', true, true, true, NOW(), NOW())
        ");
    }

    // ── through the real billing run ────────────────────────────────────────
    //
    // Everything above counts rows with SQL this test owns. These run the
    // ACTUAL SubscriptionBillingRun, because the counting being right is worth
    // nothing if the count never reaches the invoice — a suite that only
    // checked the arithmetic would stay green while `is_per_device` was read
    // from a column nobody selected and every unit billed as one.

    /** The device count becomes the invoiced quantity, and the invoice total. */
    public function testAPerDevicePriceBillsOneUnitPerDeviceOnTheInvoice(): void
    {
        $this->device('SN-1', activated: '2026-01-06');
        $this->device('SN-2', activated: '2026-01-06');
        $this->device('SN-3', activated: '2026-02-11');
        $this->perDevicePrice(1000);
        $this->subscribe();

        $result = $this->billing->run(new DateTimeImmutable(self::RUN_AT));

        self::assertSame(1, $result['invoiced']);

        $invoices = $this->invoices->listForTenant(self::TENANT);
        self::assertCount(1, $invoices);
        self::assertSame(3000, (int) $invoices[0]['total_minor'], 'three units at 1000 each');

        $lines = $this->invoices->linesFor(self::TENANT, (int) $invoices[0]['id']);
        self::assertCount(1, $lines);
        self::assertSame(3, (int) $lines[0]['quantity'], 'the quantity IS the device count');
    }

    /**
     * NO DEVICES IS NOT A BILL, AND NOT A STUCK SUBSCRIPTION. A tenant who has
     * bought a per-device plan but put nothing into service owes nothing — and
     * the period must still move, or the run would re-examine the same empty
     * March every night and the tenant would never be billed for the month they
     * finally activate something.
     */
    public function testNoDevicesRaisesNoInvoiceButStillAdvancesThePeriod(): void
    {
        $this->perDevicePrice(1000);
        $this->subscribe();

        $result = $this->billing->run(new DateTimeImmutable(self::RUN_AT));

        self::assertSame(1, $result['nothing_to_bill']);
        self::assertSame(0, $result['invoiced']);
        self::assertSame([], $this->invoices->listForTenant(self::TENANT));
        self::assertNotSame(
            '2026-04-01 00:00:00',
            $this->currentPeriodEnd(),
            'the period must advance, or this subscription is examined forever'
        );

        // And the next period bills normally once hardware appears.
        $this->device('SN-FIRST', activated: '2026-04-10');
        $second = $this->billing->run(new DateTimeImmutable('2026-05-01 03:00:00'));

        self::assertSame(1, $second['invoiced'], 'April bills once a device is in service');
    }

    /**
     * THE SETTING IS LOAD-BEARING ON A REAL INVOICE, not just in the count
     * helper above: the same estate bills two different totals depending on
     * the basis, which is the entire reason the basis is configurable.
     */
    public function testTheBasisSettingChangesTheInvoicedTotal(): void
    {
        $this->device('SN-IN-SERVICE', provisioned: '2026-01-05', activated: '2026-01-06');
        $this->device('SN-ON-SHELF', provisioned: '2026-01-05', activated: null);
        $this->perDevicePrice(1000);
        $this->subscribe();

        $this->settings->setGlobal(SettingsRegistry::LICENSING_BILLING_BASIS, 'provisioned');

        $this->billing->run(new DateTimeImmutable(self::RUN_AT));

        $invoices = $this->invoices->listForTenant(self::TENANT);
        self::assertCount(1, $invoices);
        self::assertSame(
            2000,
            (int) $invoices[0]['total_minor'],
            'on the provisioned basis the shelved unit bills too — 1000 more than activated would'
        );
    }

    /**
     * ONE CUSTOMER'S DEAL IS NOT EVERY CUSTOMER'S. The basis is a term in a
     * contract, so a tenant's own setting beats the deployment default — read
     * globally, the first per-device customer's terms would be imposed on the
     * second, and the invoice would look entirely plausible while being wrong.
     */
    public function testATenantsOwnBasisBeatsTheDeploymentDefault(): void
    {
        $this->device('SN-IN-SERVICE', provisioned: '2026-01-05', activated: '2026-01-06');
        $this->device('SN-ON-SHELF', provisioned: '2026-01-05', activated: null);
        $this->perDevicePrice(1000);
        $this->subscribe();

        $this->settings->setGlobal(SettingsRegistry::LICENSING_BILLING_BASIS, 'activated');
        $this->settings->setTenant(self::TENANT, SettingsRegistry::LICENSING_BILLING_BASIS, 'provisioned');

        $this->billing->run(new DateTimeImmutable(self::RUN_AT));

        $invoices = $this->invoices->listForTenant(self::TENANT);
        self::assertCount(1, $invoices);
        self::assertSame(
            2000,
            (int) $invoices[0]['total_minor'],
            "the tenant's own basis applies, not the deployment-wide one"
        );
    }

    /**
     * WHICH PRICE WINS IS NOT DECIDED BY WHO TYPED FIRST. A plan may carry a
     * flat and a per-device price at once; the per-device one is inserted FIRST
     * here, so an implementation that tie-broke on id would bill it. The run
     * prefers the price that multiplies by the least, and this test exists to
     * make that a decision somebody can change on purpose rather than an
     * accident of insertion order that moves the moment rows are reordered.
     */
    public function testWhichPriceWinsDoesNotDependOnInsertionOrder(): void
    {
        $this->device('SN-1', activated: '2026-01-06');
        $this->device('SN-2', activated: '2026-01-06');
        $this->device('SN-3', activated: '2026-01-06');

        $this->perDevicePrice(1000);   // inserted first: the LOWER id
        $this->flatPrice(5000);
        $this->subscribe();

        $this->billing->run(new DateTimeImmutable(self::RUN_AT));

        $invoices = $this->invoices->listForTenant(self::TENANT);
        self::assertCount(1, $invoices);
        self::assertSame(
            5000,
            (int) $invoices[0]['total_minor'],
            'the flat price is billed despite the per-device row having the lower id'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The same counting SQL the billing run uses, per basis.
     *
     * Duplicated deliberately rather than reaching into the private method: if
     * the run's query changes and this does not, one of the two is wrong and
     * the disagreement is the signal. A test that called the production method
     * would agree with it whatever it did.
     */
    private function billableDevices(string $basis): int
    {
        $sql = match ($basis) {
            'provisioned' => 'SELECT COUNT(*) FROM licensed_devices
                               WHERE tenant_id = :tenant_id
                                 AND provisioned_at <= :period_end
                                 AND (retired_at IS NULL OR retired_at >= :period_start)',
            'active_in_period' => 'SELECT COUNT(*) FROM licensed_devices
                                    WHERE tenant_id = :tenant_id
                                      AND last_seen_at IS NOT NULL
                                      AND last_seen_at >= :period_start
                                      AND last_seen_at <= :period_end',
            default => 'SELECT COUNT(*) FROM licensed_devices
                         WHERE tenant_id = :tenant_id
                           AND activated_at IS NOT NULL
                           AND activated_at <= :period_end
                           AND (retired_at IS NULL OR retired_at >= :period_start)',
        };

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            ':tenant_id' => self::TENANT,
            ':period_start' => self::PERIOD_START,
            ':period_end' => self::PERIOD_END,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function perDevicePrice(int $minor): void
    {
        $this->price($minor, perDevice: true);
    }

    private function flatPrice(int $minor): void
    {
        $this->price($minor, perDevice: false);
    }

    private function price(int $minor, bool $perDevice): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO plan_prices
                (plan_id, currency, unit_amount, billing_period, is_per_seat, is_per_device, is_active, created_at, updated_at)
             VALUES (:plan_id, :currency, :amount, :period, :per_seat, :per_device, :active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $statement->bindValue(':plan_id', self::PLAN, PDO::PARAM_INT);
        $statement->bindValue(':currency', 'JOD');
        $statement->bindValue(':amount', $minor, PDO::PARAM_INT);
        $statement->bindValue(':period', 'month');
        $statement->bindValue(':per_seat', false, PDO::PARAM_BOOL);
        $statement->bindValue(':per_device', $perDevice, PDO::PARAM_BOOL);
        $statement->bindValue(':active', true, PDO::PARAM_BOOL);
        $statement->execute();
    }

    /** An active subscription whose March period closed on the run date. */
    private function subscribe(): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, status, current_period_end, assigned_at)
             VALUES (:tenant_id, :plan_id, :status, :period_end, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            ':tenant_id' => self::TENANT,
            ':plan_id' => self::PLAN,
            ':status' => SubscriptionService::STATUS_ACTIVE,
            ':period_end' => '2026-04-01 00:00:00',
        ]);
    }

    private function currentPeriodEnd(): string
    {
        $statement = $this->pdo->prepare(
            'SELECT current_period_end FROM tenant_plan WHERE tenant_id = :tenant_id'
        );
        $statement->execute([':tenant_id' => self::TENANT]);

        return (string) $statement->fetchColumn();
    }

    private function device(
        string $serial,
        ?string $provisioned = '2026-01-01',
        ?string $activated = null,
        ?string $lastSeen = null,
        ?string $retired = null,
        int $tenantId = self::TENANT,
    ): void {
        $status = $retired !== null ? 'retired' : ($activated !== null ? 'active' : 'provisioned');

        $statement = $this->pdo->prepare('
            INSERT INTO licensed_devices
                (tenant_id, serial_number, status, provisioned_at, activated_at, last_seen_at, retired_at)
            VALUES (:tenant, :sn, :status, :provisioned, :activated, :seen, :retired)
        ');
        $statement->execute([
            ':tenant' => $tenantId,
            ':sn' => $serial,
            ':status' => $status,
            ':provisioned' => $provisioned !== null ? $provisioned . ' 00:00:00' : null,
            ':activated' => $activated !== null ? $activated . ' 00:00:00' : null,
            ':seen' => $lastSeen !== null ? $lastSeen . ' 00:00:00' : null,
            ':retired' => $retired !== null ? $retired . ' 00:00:00' : null,
        ]);
    }
}
