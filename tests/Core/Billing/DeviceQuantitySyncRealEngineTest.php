<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Api\FakeBillingPortal;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\DeviceQuantitySyncRun;
use Whity\Core\Billing\External\NullBillingPortal;
use Whity\Core\Billing\External\SubscriptionLine;
use Whity\Core\Billing\LicensedDeviceCount;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * What makes "per device" a thing that happens rather than a word on a price.
 *
 * The bug being closed is quiet and expensive in both directions. A tenant buys
 * the add-on with three scanners, activates nine more, and is billed for three —
 * every month, until somebody adds up a year of invoices. And a tenant who
 * retires half their fleet keeps paying for it, which is the half that gets
 * noticed and asked for back.
 *
 * The properties worth the most here are the ones about what does NOT happen: an
 * unreachable billing service must not move anybody's quantity, a zero-device
 * tenant must not be resized to a number the service would refuse, and a
 * matching count must not spend a write — because every write on this path is a
 * proration, and a proration is a charge.
 */
final class DeviceQuantitySyncRealEngineTest extends TestCase
{
    /** The billing service's handle for the per-device price, as sold. */
    private const DEVICE_PRICE = 'price_devices_01';

    /** A price that is sold, but not per device — the tier beside the add-on. */
    private const TIER_PRICE = 'price_tier_01';

    private PDO $pdo;
    private FakeBillingPortal $portal;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'one', 'one')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->portal = new FakeBillingPortal();

        $this->catalogue();
        $this->billed(1);
    }

    /**
     * THE TEST THIS SWEEP EXISTS FOR. Twelve scanners are in service and the
     * subscription still says three.
     */
    public function testASubscriptionIsResizedToTheDevicesInService(): void
    {
        $this->devices(1, activated: 12);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 3)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['checked']);
        self::assertSame(1, $result['changed']);
        self::assertSame(12, $this->portal->lastQuantity);
        self::assertSame('sub_devices', $this->portal->lastResizedSubscription);
    }

    /**
     * AND DOWNWARDS, which is the direction a customer notices. Retiring units
     * has to stop the bill for them.
     */
    public function testRetiredDevicesShrinkTheSubscription(): void
    {
        $this->devices(1, activated: 10);
        $this->retireDevices(1, 6);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 10)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['changed']);
        self::assertSame(4, $this->portal->lastQuantity);
    }

    /**
     * A MATCHING COUNT SPENDS NO WRITE. Every write on this path prorates, and a
     * proration is a charge — a sweep that re-sent the same number four times a
     * day would invoice a customer for standing still.
     */
    public function testAnAlreadyCorrectQuantityIsNotRewritten(): void
    {
        $this->devices(1, activated: 5);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 5)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['unchanged']);
        self::assertSame(0, $result['changed']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * AN OUTAGE MUST NOT MOVE ANYBODY'S BILL. Being unable to ask what somebody
     * is billed for is not learning that it is wrong, and a sweep that resized
     * on silence would turn the billing service's bad day into wrong numbers on
     * our customers' invoices.
     */
    public function testAnUnreachableServiceResizesNobody(): void
    {
        $this->devices(1, activated: 12);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 3)];
        $this->portal->failWith = BillingPortalException::unreachable('timeout');

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['unreachable']);
        self::assertSame(0, $result['changed']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * RATE LIMITING STOPS THE WHOLE SWEEP. Carrying on would spend the rest of
     * the batch against an allowance already exhausted — starving the checkout a
     * customer is waiting on, and getting no answers either.
     */
    public function testRateLimitingStopsTheSweepAndIsReported(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'two', 'two')");
        $this->billed(2);
        $this->devices(1, activated: 4);
        $this->devices(2, activated: 4);
        $this->portal->failWith = BillingPortalException::rateLimited('slow down');

        $result = $this->sweeper()->run($this->now());

        self::assertTrue($result['rate_limited']);
        // One attempt, then a stop: the second tenant was never asked about.
        self::assertSame(1, $result['unreachable']);
    }

    /**
     * ZERO IS NEVER SENT. The billing service's minimum quantity is one, so a
     * tenant whose fleet has gone to nothing cannot be resized to match — the
     * request would be refused, and rounding it up to one would quietly invent a
     * device. It is counted for a human instead, because what they need is the
     * subscription cancelled.
     */
    public function testATenantWithNoDevicesInServiceIsFlaggedRatherThanResized(): void
    {
        $this->devices(1, activated: 3);
        $this->retireDevices(1, 3);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 3)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['zero_devices']);
        self::assertSame(0, $result['changed']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * THE TIER IS NOT THE ADD-ON. A tenant holding only a subscription to a
     * plan that is not per device has nothing here to resize — and resizing
     * their tier to their device count would multiply their monthly fee by the
     * size of their fleet.
     */
    public function testATierSubscriptionIsNeverResized(): void
    {
        $this->devices(1, activated: 7);
        $this->portal->subscriptions = [
            new SubscriptionLine('sub_tier', self::TIER_PRICE, 'active', true, 1),
        ];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(0, $result['checked']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * THE ADD-ON IS PICKED OUT FROM BESIDE THE TIER. The ordinary shape of a
     * paying customer is two subscriptions, and only one of them counts devices.
     */
    public function testTheDeviceSubscriptionIsFoundAlongsideATier(): void
    {
        $this->devices(1, activated: 7);
        $this->portal->subscriptions = [
            new SubscriptionLine('sub_tier', self::TIER_PRICE, 'active', true, 1),
            $this->deviceSubscription(quantity: 2),
        ];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['changed']);
        self::assertSame('sub_devices', $this->portal->lastResizedSubscription);
        self::assertSame(7, $this->portal->lastQuantity);
    }

    /**
     * A CANCELLED SUBSCRIPTION IS LEFT ALONE. Putting a quantity on an agreement
     * the customer has already left asks the billing service to bill under it.
     */
    public function testALapsedDeviceSubscriptionIsNotResized(): void
    {
        $this->devices(1, activated: 7);
        $this->portal->subscriptions = [
            new SubscriptionLine('sub_devices', self::DEVICE_PRICE, 'canceled', false, 2),
        ];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(0, $result['checked']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * TWO LIVE DEVICE SUBSCRIPTIONS ARE A PROBLEM FOR A HUMAN. The tenant is
     * already being billed twice for one fleet; resizing both to the device
     * count would double the error rather than correct it.
     */
    public function testTwoLiveDeviceSubscriptionsAreLeftAlone(): void
    {
        $this->devices(1, activated: 7);
        $this->portal->subscriptions = [
            $this->deviceSubscription(quantity: 2),
            new SubscriptionLine('sub_devices_2', self::DEVICE_PRICE, 'active', true, 5),
        ];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(0, $result['checked']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * THE BILLING BASIS IS OBEYED, not assumed. A tenant billed from
     * PROVISIONING pays for every unit it has been shipped, activated or not —
     * that is a term in their contract, and counting only activations would
     * under-bill them by however many units are still in their store room.
     */
    public function testTheProvisionedBasisCountsUnactivatedUnits(): void
    {
        $this->devices(1, activated: 2);
        $this->devices(1, provisionedOnly: 6);
        $this->setBasis(1, 'provisioned');
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 1)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(8, $this->portal->lastQuantity);
        self::assertSame(1, $result['changed']);
    }

    /**
     * THE DEFAULT BASIS IGNORES WHAT WAS NEVER PUT INTO SERVICE. The same eight
     * units, counted the other way, are two — and a sweep that could not tell
     * the difference would bill a customer for hardware in a box.
     */
    public function testTheActivatedBasisIgnoresUnactivatedUnits(): void
    {
        $this->devices(1, activated: 2);
        $this->devices(1, provisionedOnly: 6);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 1)];

        $this->sweeper()->run($this->now());

        self::assertSame(2, $this->portal->lastQuantity);
    }

    /**
     * RETIREMENT STOPS THE BILL ON EVERY BASIS, not just the default one.
     *
     * Found by mutation: the `provisioned` arm's retirement exclusion could be
     * deleted and every test still passed. A customer who pays from provisioning
     * — the reading that bills for units in their store room — would have gone
     * on paying for every unit they ever returned, permanently, and the number
     * would only ever have gone up.
     */
    public function testRetiredUnitsStopBeingBilledOnTheProvisionedBasisToo(): void
    {
        $this->devices(1, provisionedOnly: 9);
        $this->retireDevices(1, 5);
        $this->setBasis(1, 'provisioned');
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 9)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['changed']);
        self::assertSame(4, $this->portal->lastQuantity);
    }

    /**
     * A RETROSPECTIVE BASIS HAS NO FORWARD ANSWER, and is left alone rather than
     * approximated. `active_in_period` counts units seen between two dates; how
     * many will be seen before the month ends is not knowable now. The billing
     * service applies decreases only at renewal, so a running tally would ratchet
     * the customer's bill upward permanently and never come back down.
     */
    public function testARetrospectivelyBilledTenantIsSkipped(): void
    {
        $this->devices(1, activated: 9);
        $this->setBasis(1, LicensedDeviceCount::RETROSPECTIVE_BASIS);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 1)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['changed']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * A QUANTITY THE SERVICE DID NOT REPORT IS NOT GUESSED AT. With nothing to
     * compare against, sending the count would re-bill every tenant on every
     * sweep for a number that may never have moved.
     */
    public function testAMissingQuantityIsNotTreatedAsOne(): void
    {
        $this->devices(1, activated: 4);
        $this->portal->subscriptions = [
            new SubscriptionLine('sub_devices', self::DEVICE_PRICE, 'active', true, null),
        ];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $this->portal->quantityCalls);
    }

    /**
     * A DEPLOYMENT THAT BILLS NOBODY SWEEPS NOBODY, and does it without an
     * error — a self-hosted install runs this cron too, and one that failed four
     * times a day would teach an operator to ignore it.
     */
    public function testAnUnconfiguredDeploymentDoesNothingQuietly(): void
    {
        $this->devices(1, activated: 4);

        $run = new DeviceQuantitySyncRun(
            $this->pdo,
            new NullBillingPortal(),
            new LicensedDeviceCount($this->pdo, $this->settings),
            new NullLogger()
        );

        self::assertSame(0, $run->run($this->now())['checked']);
    }

    /**
     * A FAILED RESIZE IS NOT COUNTED AS A CHANGE. The number reported back is
     * what somebody reads to decide whether the sweep is working, and a run that
     * claimed twelve changes while the service refused all twelve would hide
     * exactly the failure worth seeing.
     */
    public function testARefusedResizeIsReportedAsSkippedNotChanged(): void
    {
        $this->devices(1, activated: 12);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 3)];
        $this->portal->failResizeWith = BillingPortalException::refused('422');

        $result = $this->sweeper()->run($this->now());

        self::assertSame(0, $result['changed']);
        self::assertSame(1, $result['skipped']);
    }

    /**
     * A TENANT WITH NO DEVICES ON FILE COSTS NO REQUEST. The batch is one call
     * per candidate against the allowance a customer's checkout is waiting on,
     * so a tenant who has never had a fleet is not a candidate.
     */
    public function testATenantWithNoDevicesIsNeverAskedAbout(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (3, 'three', 'three')");
        $this->billed(3);
        // Tenant 1 has devices; tenant 3 has none at all.
        $this->devices(1, activated: 2);
        $this->portal->subscriptions = [$this->deviceSubscription(quantity: 2)];

        $result = $this->sweeper()->run($this->now());

        self::assertSame(1, $result['checked']);
        self::assertSame(0, $result['skipped']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function sweeper(): DeviceQuantitySyncRun
    {
        return new DeviceQuantitySyncRun(
            $this->pdo,
            $this->portal,
            new LicensedDeviceCount($this->pdo, $this->settings),
            new NullLogger()
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-12 12:00:00');
    }

    private function deviceSubscription(int $quantity): SubscriptionLine
    {
        return new SubscriptionLine('sub_devices', self::DEVICE_PRICE, 'active', true, $quantity);
    }

    /**
     * Two plans sold externally: a tier and a per-device add-on. Only the second
     * has `is_per_device`, and that flag is the whole basis for telling a
     * tenant's subscriptions apart.
     */
    private function catalogue(): void
    {
        $this->pdo->exec(
            "INSERT INTO plans (id, plan_key, name, is_active) VALUES
             (1, 'pro', 'Pro', true), (2, 'devices', 'Devices', true)"
        );
        $statement = $this->pdo->prepare(
            'INSERT INTO plan_prices
                (plan_id, currency, unit_amount, billing_period, is_active, is_per_seat, is_per_device, external_ref)
             VALUES (:plan, :currency, :amount, :period, :active, :seat, :device, :ref)'
        );
        $statement->execute([
            ':plan' => 1, ':currency' => 'JOD', ':amount' => 15000, ':period' => 'month',
            ':active' => 1, ':seat' => 0, ':device' => 0, ':ref' => self::TIER_PRICE,
        ]);
        $statement->execute([
            ':plan' => 2, ':currency' => 'JOD', ':amount' => 20000, ':period' => 'month',
            ':active' => 1, ':seat' => 0, ':device' => 1, ':ref' => self::DEVICE_PRICE,
        ]);
    }

    /** A tenant the billing service knows about, so the sweep considers them. */
    private function billed(int $tenantId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_plan (tenant_id, status, external_ref, assigned_at)
             VALUES (:t, :status, :ref, CURRENT_TIMESTAMP)'
        );
        $statement->execute([':t' => $tenantId, ':status' => 'active', ':ref' => 'sub_tier']);
    }

    /** Devices in service, or provisioned and never put into service. */
    private function devices(int $tenantId, int $activated = 0, int $provisionedOnly = 0): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO licensed_devices (tenant_id, serial_number, status, provisioned_at, activated_at)
             VALUES (:t, :serial, :status, :provisioned, :activated)'
        );

        for ($i = 0; $i < $activated; $i++) {
            $statement->execute([
                ':t' => $tenantId,
                ':serial' => 'ACT-' . $tenantId . '-' . uniqid('', true),
                ':status' => 'active',
                ':provisioned' => '2026-01-01 00:00:00',
                ':activated' => '2026-02-01 00:00:00',
            ]);
        }

        for ($i = 0; $i < $provisionedOnly; $i++) {
            $statement->execute([
                ':t' => $tenantId,
                ':serial' => 'PRV-' . $tenantId . '-' . uniqid('', true),
                ':status' => 'provisioned',
                ':provisioned' => '2026-01-01 00:00:00',
                ':activated' => null,
            ]);
        }
    }

    /** Retire the oldest `$count` of a tenant's devices. */
    private function retireDevices(int $tenantId, int $count): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE licensed_devices SET retired_at = :when, status = :status
              WHERE id IN (
                    SELECT id FROM licensed_devices
                     WHERE tenant_id = :t AND retired_at IS NULL
                     ORDER BY id ASC LIMIT ' . (int) $count . ')'
        );
        $statement->execute([
            ':when' => '2026-08-01 00:00:00',
            ':status' => 'retired',
            ':t' => $tenantId,
        ]);
    }

    private function setBasis(int $tenantId, string $basis): void
    {
        (new TenantSettingsRepository($this->pdo))->set(
            $tenantId,
            SettingsRegistry::LICENSING_BILLING_BASIS,
            $basis
        );
    }
}
