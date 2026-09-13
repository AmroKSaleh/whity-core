<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\LicensedDeviceCount;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Licensing\ActivationService;
use Whity\Core\Licensing\DeviceAllowance;
use Whity\Core\Licensing\LicensingException;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * The tier's device cap, where a device actually enters service.
 *
 * A limit that appears on a pricing screen and is enforced by nothing is worse
 * than not selling it, because somebody is paying for it. Two code paths put a
 * device into service and they could not be less alike — an operator bulk
 * importing serials with a session and a permission, and a customer redeeming a
 * code with no account at all — so the cap has to hold in both.
 *
 * THE ONE THAT WOULD HAVE HURT MOST: a refused activation must not burn the
 * code. The code is claimed atomically BEFORE the tenant is even known, so a
 * naive check-then-throw would leave a customer who hit their cap holding a
 * dead code — they would raise the limit, come back, and find it already spent.
 */
final class DeviceAllowanceRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private EntitlementService $entitlements;
    private DeviceAllowance $allowance;
    private ActivationService $activations;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->allowance = new DeviceAllowance(
            $this->entitlements,
            new LicensedDeviceCount($this->pdo, $settings),
        );
        $this->activations = new ActivationService(
            $this->pdo,
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-13 12:00:00'),
            $this->allowance,
        );
    }

    // ── The allowance itself ────────────────────────────────────────────────

    public function testNoCapMeansNoLimit(): void
    {
        $this->devices(activated: 50);

        self::assertNull($this->allowance->remaining(self::TENANT, $this->now()));
        self::assertFalse($this->allowance->isExhausted(self::TENANT, $this->now()));
    }

    public function testRemainingCountsAgainstTheCap(): void
    {
        $this->cap(10);
        $this->devices(activated: 4);

        self::assertSame(6, $this->allowance->remaining(self::TENANT, $this->now()));
    }

    /** Retired units free their place — the cap is what is in service, not what was ever sold. */
    public function testRetiredDevicesDoNotCountAgainstTheCap(): void
    {
        $this->cap(3);
        $this->devices(activated: 3);
        $this->pdo->exec("UPDATE licensed_devices SET retired_at = '2026-09-01 00:00:00' WHERE id = 1");

        self::assertSame(1, $this->allowance->remaining(self::TENANT, $this->now()));
    }

    /**
     * THE CAP COUNTS WHAT THE BILL COUNTS. On a provisioned basis an unactivated
     * unit already fills a place, because the customer is already paying for it
     * — capping them on activations instead would charge for ten while telling
     * them they had eight left.
     */
    public function testTheProvisionedBasisCountsUnactivatedUnits(): void
    {
        $this->cap(5);
        $this->devices(provisionedOnly: 5);

        self::assertSame(5, $this->allowance->remaining(self::TENANT, $this->now()));

        (new TenantSettingsRepository($this->pdo))
            ->set(self::TENANT, SettingsRegistry::LICENSING_BILLING_BASIS, 'provisioned');

        self::assertSame(0, $this->allowance->remaining(self::TENANT, $this->now()));
    }

    /**
     * A RETROSPECTIVE BASIS STILL ANSWERS. `active_in_period` has no forward
     * figure for BILLING, but a cap must say yes or no to somebody activating a
     * device right now, so it falls back to counting units in service.
     */
    public function testARetrospectiveBasisStillProducesACap(): void
    {
        $this->cap(2);
        $this->devices(activated: 2);
        (new TenantSettingsRepository($this->pdo))->set(
            self::TENANT,
            SettingsRegistry::LICENSING_BILLING_BASIS,
            LicensedDeviceCount::RETROSPECTIVE_BASIS
        );

        self::assertSame(0, $this->allowance->remaining(self::TENANT, $this->now()));
        self::assertTrue($this->allowance->isExhausted(self::TENANT, $this->now()));
    }

    // ── Activation ──────────────────────────────────────────────────────────

    public function testActivationSucceedsWhileThereIsRoom(): void
    {
        $this->cap(2);
        $deviceId = $this->device('SN-1');
        $code = $this->issueCode($deviceId);

        $result = $this->activations->redeem($code, null, null, null);

        self::assertSame($deviceId, $result['licensed_device_id']);
    }

    public function testActivationIsRefusedAtTheCap(): void
    {
        $this->cap(1);
        $this->devices(activated: 1);
        $code = $this->issueCode($this->device('SN-NEW'));

        try {
            $this->activations->redeem($code, null, null, null);
            self::fail('Activation beyond the cap must be refused.');
        } catch (LicensingException $e) {
            self::assertSame(LicensingException::REASON_LIMIT_REACHED, $e->reason);
        }
    }

    /**
     * THE TEST THIS FILE EXISTS FOR. Refusing must give the code back: it was
     * claimed before the tenant was known, and a customer who raises their cap
     * has to be able to use the code they already have.
     */
    public function testARefusedActivationDoesNotBurnTheCode(): void
    {
        $this->cap(1);
        $this->devices(activated: 1);
        $newDevice = $this->device('SN-NEW');
        $code = $this->issueCode($newDevice);

        try {
            $this->activations->redeem($code, null, null, null);
        } catch (LicensingException) {
            // expected
        }

        self::assertSame(0, $this->redemptionCount($code), 'The refused claim must be released.');

        // And the code still works once there is room.
        $this->cap(5);
        $result = $this->activations->redeem($code, null, null, null);
        self::assertSame($newDevice, $result['licensed_device_id']);
    }

    /**
     * A UNIT ALREADY IN SERVICE IS NOT RE-COUNTED. Otherwise a customer sitting
     * exactly at their cap could never re-activate hardware they already own —
     * after a reinstall, say — which is the moment they most need to.
     */
    public function testReactivatingADeviceAlreadyInServiceIsAllowedAtTheCap(): void
    {
        $this->cap(1);
        $deviceId = $this->device('SN-1');
        $this->activations->redeem($this->issueCode($deviceId), null, null, null);
        self::assertTrue($this->allowance->isExhausted(self::TENANT, $this->now()));

        $again = $this->activations->redeem($this->issueCode($deviceId), null, null, null);

        self::assertSame($deviceId, $again['licensed_device_id']);
    }

    /** With no allowance wired at all, nothing is capped — a self-hosted install. */
    public function testADeploymentWithNoAllowanceCapsNobody(): void
    {
        $uncapped = new ActivationService(
            $this->pdo,
            fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-13 12:00:00'),
        );
        $this->cap(1);
        $this->devices(activated: 1);

        $result = $uncapped->redeem($this->issueCode($this->device('SN-NEW')), null, null, null);

        self::assertIsInt($result['licensed_device_id']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-13 12:00:00');
    }

    private function cap(int $devices): void
    {
        $this->entitlements->set(self::TENANT, EntitlementRegistry::DEVICES_MAX, (string) $devices);
    }

    private function devices(int $activated = 0, int $provisionedOnly = 0): void
    {
        for ($i = 0; $i < $activated; $i++) {
            $this->device('ACT-' . $i, activated: true);
        }
        for ($i = 0; $i < $provisionedOnly; $i++) {
            $this->device('PRV-' . $i);
        }
    }

    private function device(string $serial, bool $activated = false): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO licensed_devices (tenant_id, serial_number, status, provisioned_at, activated_at)
             VALUES (:t, :serial, :status, :provisioned, :activated)'
        );
        $statement->execute([
            ':t' => self::TENANT,
            ':serial' => $serial,
            ':status' => $activated ? 'active' : 'provisioned',
            ':provisioned' => '2026-01-01 00:00:00',
            ':activated' => $activated ? '2026-02-01 00:00:00' : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** The formatted code a person would type, as issue() hands it back. */
    private function issueCode(int $deviceId): string
    {
        return (string) $this->activations->issue(self::TENANT, $deviceId)['code'];
    }

    /**
     * How many times the MOST RECENTLY ISSUED code has been claimed.
     *
     * Read from the row rather than from a service, because the property under
     * test is precisely that the claim was reversed in the database — a service
     * that reported it from memory could agree with itself while the row said
     * otherwise.
     */
    private function redemptionCount(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT redemption_count FROM device_activation_codes
              WHERE tenant_id = :t ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([':t' => self::TENANT]);

        return (int) $statement->fetchColumn();
    }
}
