<?php

declare(strict_types=1);

namespace Tests\Core\Licensing;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * The per-device licensing schema, against real migrations.
 *
 * WHY THE CONSTRAINTS ARE TESTED AND NOT JUST THE COLUMNS. Everything asserted
 * below is a rule the DATABASE enforces, deliberately, rather than the
 * application. Each one guards something that costs money when it goes wrong:
 * a code redeemed more times than it was sold, a device billed as active with
 * no activation date to justify it, two units sharing a serial within one
 * customer. Application-level checks lose those races; a CHECK constraint does
 * not, and this file is how we know the constraint is really there rather than
 * merely written down.
 */
final class LicensedDeviceSchemaRealEngineTest extends TestCase
{
    private PDO $pdo;
    private const TENANT = 1;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'A Customer', 'a-customer')");
        SchemaFromMigrations::syncSequences($this->pdo);
    }

    // ── the money invariants ────────────────────────────────────────────────

    /**
     * THE ONE THAT MATTERS MOST. A code sold as single-use that is redeemed
     * twice has given away a licence nobody paid for — and two students typing
     * the last code of a batch at the same moment is an ordinary Tuesday, which
     * application-level counting loses.
     */
    public function testACodeCannotBeRedeemedMoreTimesThanItAllows(): void
    {
        $this->insertCode('SINGLEUSE01', maxRedemptions: 1, redemptionCount: 1);

        $this->expectException(PDOException::class);
        $this->pdo->exec("
            UPDATE device_activation_codes SET redemption_count = 2 WHERE code = 'SINGLEUSE01'
        ");
    }

    public function testAMultiUseCodeIsAllowedUpToItsLimit(): void
    {
        $this->insertCode('CLASSROOM30', maxRedemptions: 30, redemptionCount: 0);

        // Thirty is fine — 'single use' is a default, not a law. A classroom
        // set is an ordinary requirement a boolean could not have expressed.
        $this->pdo->exec("
            UPDATE device_activation_codes SET redemption_count = 30 WHERE code = 'CLASSROOM30'
        ");

        self::assertSame(30, (int) $this->pdo
            ->query("SELECT redemption_count FROM device_activation_codes WHERE code = 'CLASSROOM30'")
            ->fetchColumn());
    }

    public function testACodeMustAllowAtLeastOneRedemption(): void
    {
        $this->expectException(PDOException::class);
        $this->insertCode('ZEROUSES001', maxRedemptions: 0, redemptionCount: 0);
    }

    /**
     * An activation-based invoice is computed from `activated_at`. A device
     * marked active without one would appear on a bill that cannot be
     * explained when the customer asks which day it started.
     */
    public function testAnActiveDeviceMustCarryItsActivationDate(): void
    {
        $this->insertDevice('SN-NO-DATE');

        $this->expectException(PDOException::class);
        $this->pdo->exec("
            UPDATE licensed_devices SET status = 'active' WHERE serial_number = 'SN-NO-DATE'
        ");
    }

    public function testAnActiveDeviceWithAnActivationDateIsFine(): void
    {
        $this->insertDevice('SN-ACTIVATED');

        $this->pdo->exec("
            UPDATE licensed_devices
               SET status = 'active', activated_at = NOW()
             WHERE serial_number = 'SN-ACTIVATED'
        ");

        self::assertSame('active', (string) $this->pdo
            ->query("SELECT status FROM licensed_devices WHERE serial_number = 'SN-ACTIVATED'")
            ->fetchColumn());
    }

    public function testAnUnknownStatusIsRefused(): void
    {
        $this->insertDevice('SN-STATUS');

        $this->expectException(PDOException::class);
        $this->pdo->exec("
            UPDATE licensed_devices SET status = 'lost-in-post' WHERE serial_number = 'SN-STATUS'
        ");
    }

    // ── identity ────────────────────────────────────────────────────────────

    public function testASerialIsUniqueWithinATenant(): void
    {
        $this->insertDevice('SN-DUPLICATE');

        $this->expectException(PDOException::class);
        $this->insertDevice('SN-DUPLICATE');
    }

    /**
     * But NOT globally. Two customers may legitimately hold hardware bearing
     * the same manufacturer serial, and refusing the second one would make
     * whichever customer onboarded later unable to register their own stock.
     */
    public function testTwoTenantsMayHoldTheSameManufacturerSerial(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Another', 'another')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->insertDevice('SN-SHARED', tenantId: 1);
        $this->insertDevice('SN-SHARED', tenantId: 2);

        self::assertSame(2, (int) $this->pdo
            ->query("SELECT count(*) FROM licensed_devices WHERE serial_number = 'SN-SHARED'")
            ->fetchColumn());
    }

    /**
     * A code, by contrast, IS globally unique — because whoever redeems it may
     * be a student with no tenant relationship, so the code alone has to
     * resolve the tenant. Two tenants sharing a code would mean the redemption
     * endpoint could not tell whose licence was being consumed.
     */
    public function testACodeIsUniqueAcrossEveryTenant(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Another', 'another')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->insertCode('GLOBALCODE1', tenantId: 1);

        $this->expectException(PDOException::class);
        $this->insertCode('GLOBALCODE1', tenantId: 2);
    }

    // ── retention ───────────────────────────────────────────────────────────

    /**
     * A licensed device is a commercial record: something was sold. Deleting
     * the tenant must not silently take the evidence with it — the same guard
     * invoices already carry.
     */
    public function testATenantWithLicensedDevicesCannotSimplyBeDeleted(): void
    {
        $this->insertDevice('SN-RETAINED');

        $this->expectException(PDOException::class);
        $this->pdo->exec('DELETE FROM tenants WHERE id = 1');
    }

    // ── the billing-policy facts ────────────────────────────────────────────

    /**
     * The schema must keep all three timestamps SEPARATELY, because the billing
     * basis — provisioned, activated, or active-in-period — is a policy this
     * table deliberately does not choose. Collapsing them into one column would
     * make that decision permanent and unrecoverable after the fact.
     */
    public function testAllThreeBillingFactsAreRecordedIndependently(): void
    {
        $columns = [];
        $statement = $this->pdo->query('SELECT * FROM licensed_devices LIMIT 0');
        self::assertNotFalse($statement);
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $columns[] = $statement->getColumnMeta($i)['name'] ?? '';
        }

        foreach (['provisioned_at', 'activated_at', 'last_seen_at'] as $fact) {
            self::assertContains(
                $fact,
                $columns,
                "{$fact} is missing — the billing basis would become a schema decision"
            );
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function insertDevice(string $serial, int $tenantId = self::TENANT): void
    {
        $statement = $this->pdo->prepare("
            INSERT INTO licensed_devices (tenant_id, serial_number, status)
            VALUES (:tenant, :serial, 'provisioned')
        ");
        $statement->execute([':tenant' => $tenantId, ':serial' => $serial]);
    }

    private function insertCode(
        string $code,
        int $tenantId = self::TENANT,
        int $maxRedemptions = 1,
        int $redemptionCount = 0
    ): void {
        $statement = $this->pdo->prepare('
            INSERT INTO device_activation_codes (tenant_id, code, max_redemptions, redemption_count)
            VALUES (:tenant, :code, :max, :count)
        ');
        $statement->execute([
            ':tenant' => $tenantId,
            ':code' => $code,
            ':max' => $maxRedemptions,
            ':count' => $redemptionCount,
        ]);
    }
}
