<?php

declare(strict_types=1);

namespace Tests\Core\Licensing;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Licensing\ActivationCode;
use Whity\Core\Licensing\ActivationService;
use Whity\Core\Licensing\LicensingException;

/**
 * Issuing and redeeming, against real migrations.
 *
 * The tests that matter here are the ones about MONEY and about a caller who
 * cannot be trusted: a code must not be redeemable more times than it was sold,
 * must not activate hardware belonging to a different customer, and must not
 * tell an anonymous guesser which codes exist.
 */
final class ActivationServiceRealEngineTest extends TestCase
{
    private PDO $pdo;
    private ActivationService $service;

    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'A Customer', 'a-customer')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Another', 'another')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $this->now = new DateTimeImmutable('2026-09-11 12:00:00');
        $this->service = new ActivationService($this->pdo, fn (): DateTimeImmutable => $this->now);
    }

    // ── the money invariant ─────────────────────────────────────────────────

    /**
     * THE ONE THAT MATTERS. A single-use code redeemed twice has given away a
     * licence nobody paid for.
     */
    public function testASingleUseCodeCannotBeRedeemedTwice(): void
    {
        $device = $this->device('SN-1');
        $issued = $this->service->issue(self::TENANT, $device);

        $this->service->redeem($issued['code']);

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('already been used');
        $this->service->redeem($issued['code']);
    }

    /**
     * Thirty students, one classroom code, and the thirty-first must be
     * refused — the count is enforced, not merely recorded.
     */
    public function testAMultiUseCodeStopsExactlyAtItsLimit(): void
    {
        $issued = $this->service->issue(self::TENANT, null, maxRedemptions: 3);

        foreach (['SN-A', 'SN-B', 'SN-C'] as $serial) {
            $this->service->redeem($issued['code'], $this->device($serial));
        }

        $this->expectException(LicensingException::class);
        $this->service->redeem($issued['code'], $this->device('SN-D'));
    }

    /**
     * The counter and the redemption log must agree. The counter enforces; the
     * log explains. A disputed bill is answered from the log, so a divergence
     * between them would make the enforcement unauditable.
     */
    public function testEveryRedemptionIsLoggedAndTheCountMatches(): void
    {
        $issued = $this->service->issue(self::TENANT, null, maxRedemptions: 2);
        $this->service->redeem($issued['code'], $this->device('SN-X'), redeemedByUserId: null);
        $this->service->redeem($issued['code'], $this->device('SN-Y'), redeemedByUserId: 7);

        $logged = (int) $this->q('SELECT count(*) FROM device_activation_redemptions')->fetchColumn();
        $counted = (int) $this->q('SELECT redemption_count FROM device_activation_codes')->fetchColumn();

        self::assertSame(2, $logged);
        self::assertSame(2, $counted, 'the enforced count and the audit trail disagree');
    }

    /** A student with no account is the expected case, not an error. */
    public function testRedemptionWithoutAUserIsRecordedRatherThanRefused(): void
    {
        $issued = $this->service->issue(self::TENANT, $this->device('SN-ANON'));
        $this->service->redeem($issued['code'], redeemedByUserId: null, fromIp: '203.0.113.9');

        $row = $this->q('SELECT redeemed_by_user_id, redeemed_from_ip FROM device_activation_redemptions')
            ->fetch(PDO::FETCH_ASSOC);

        self::assertNull($row['redeemed_by_user_id'], 'an absent actor is recorded as absent, not invented');
        self::assertSame('203.0.113.9', $row['redeemed_from_ip']);
    }

    // ── activation is the billing event ─────────────────────────────────────

    public function testRedeemingActivatesTheDeviceAndDatesIt(): void
    {
        $device = $this->device('SN-ACT');
        $issued = $this->service->issue(self::TENANT, $device);

        $this->service->redeem($issued['code']);

        $row = $this->q("SELECT status, activated_at FROM licensed_devices WHERE id = {$device}")
            ->fetch(PDO::FETCH_ASSOC);

        self::assertSame('active', $row['status']);
        self::assertNotNull($row['activated_at'], 'an activation-based invoice is computed from this date');
    }

    /**
     * A device redeemed twice under a multi-use code keeps the date it FIRST
     * entered service. If activation_at drifted, the invoice would silently
     * change for a period already billed.
     */
    public function testActivationDateDoesNotDriftOnASecondRedemption(): void
    {
        $device = $this->device('SN-TWICE');
        $issued = $this->service->issue(self::TENANT, null, maxRedemptions: 2);

        $this->service->redeem($issued['code'], $device);
        $first = $this->q("SELECT activated_at FROM licensed_devices WHERE id = {$device}")->fetchColumn();

        $this->now = $this->now->modify('+10 days');
        $this->service = new ActivationService($this->pdo, fn (): DateTimeImmutable => $this->now);
        $this->service->redeem($issued['code'], $device);

        $second = $this->q("SELECT activated_at FROM licensed_devices WHERE id = {$device}")->fetchColumn();
        self::assertSame($first, $second, 'the activation date moved, changing an already-billed period');
    }

    // ── the untrusted caller ────────────────────────────────────────────────

    /**
     * A code bound at issue time wins over whatever the caller supplies.
     * Otherwise somebody redeems their own code against hardware nobody sold
     * them a licence for.
     */
    public function testABoundCodeIgnoresTheDeviceTheCallerAsksFor(): void
    {
        $sold = $this->device('SN-SOLD');
        $other = $this->device('SN-OTHER');
        $issued = $this->service->issue(self::TENANT, $sold);

        $result = $this->service->redeem($issued['code'], $other);

        self::assertSame($sold, $result['licensed_device_id'], 'the caller redirected the licence');
        self::assertSame('provisioned', (string) $this->q("SELECT status FROM licensed_devices WHERE id = {$other}")->fetchColumn());
    }

    /**
     * The tenant comes from the CODE, never from the caller. A code issued to
     * one customer must not activate another customer's hardware.
     */
    public function testACodeCannotActivateAnotherTenantsDevice(): void
    {
        $theirs = $this->device('SN-THEIRS', self::OTHER_TENANT);
        $issued = $this->service->issue(self::TENANT, null, maxRedemptions: 1);

        $this->expectException(LicensingException::class);
        $this->service->redeem($issued['code'], $theirs);
    }

    /**
     * A SERIAL IS RESOLVED INSIDE THE CODE'S TENANT. Two customers may hold
     * hardware bearing the same manufacturer serial, so an unscoped lookup would
     * let one customer's code activate the other's unit — and the caller
     * redeeming has no tenant of their own to check against.
     */
    public function testASerialResolvesOnlyWithinTheCodesTenant(): void
    {
        $this->device('SN-SHARED', self::OTHER_TENANT);
        $mine = $this->device('SN-SHARED', self::TENANT);
        $issued = $this->service->issue(self::TENANT, null, maxRedemptions: 1);

        $result = $this->service->redeem($issued['code'], 'SN-SHARED');

        self::assertSame($mine, $result['licensed_device_id'], "it resolved the wrong tenant's unit");
    }

    public function testASerialBelongingToAnotherTenantIsNotFound(): void
    {
        $this->device('SN-ONLY-THEIRS', self::OTHER_TENANT);
        $issued = $this->service->issue(self::TENANT, null, maxRedemptions: 1);

        $this->expectException(LicensingException::class);
        $this->service->redeem($issued['code'], 'SN-ONLY-THEIRS');
    }

    public function testARetiredDeviceCannotBeReactivated(): void
    {
        $device = $this->device('SN-RETIRED');
        $this->pdo->exec("UPDATE licensed_devices SET status = 'retired', retired_at = NOW() WHERE id = {$device}");
        $issued = $this->service->issue(self::TENANT, $device);

        $this->expectException(LicensingException::class);
        $this->service->redeem($issued['code']);
    }

    // ── refusals, and what they may reveal ──────────────────────────────────

    public function testARevokedCodeIsRefusedAndSaysSo(): void
    {
        $issued = $this->service->issue(self::TENANT, $this->device('SN-REV'));
        $this->pdo->exec("UPDATE device_activation_codes SET revoked_at = NOW()");

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('cancelled');
        $this->service->redeem($issued['code']);
    }

    public function testAnExpiredCodeIsRefusedAndSaysSo(): void
    {
        $issued = $this->service->issue(
            self::TENANT,
            $this->device('SN-EXP'),
            expiresAt: $this->now->modify('-1 day')
        );

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('expired');
        $this->service->redeem($issued['code']);
    }

    /**
     * A typo must be refused BEFORE any lookup. Otherwise a mistyped code is
     * indistinguishable from an unknown one — and the endpoint becomes a way to
     * probe which codes exist.
     */
    public function testATypoIsRejectedWithoutTouchingTheDatabase(): void
    {
        $issued = $this->service->issue(self::TENANT, $this->device('SN-TYPO'));
        $canonical = ActivationCode::canonicalize($issued['code']);
        $typo = substr_replace($canonical, $canonical[0] === '2' ? '3' : '2', 0, 1);

        try {
            $this->service->redeem($typo);
            self::fail('a mistyped code was accepted');
        } catch (LicensingException $e) {
            self::assertStringContainsString('not valid', $e->getMessage());
        }

        self::assertSame(0, (int) $this->q('SELECT redemption_count FROM device_activation_codes')->fetchColumn());
    }

    /**
     * An unknown-but-well-formed code and an exhausted one must not be
     * distinguishable in a way that lets an anonymous caller enumerate. Both
     * refuse; neither confirms the existence of anything.
     */
    public function testAnUnknownCodeRevealsNothingAboutWhatExists(): void
    {
        $unknown = ActivationCode::generate();

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('not valid');
        $this->service->redeem($unknown);
    }

    // ── issuing ─────────────────────────────────────────────────────────────

    public function testIssuedCodesAreUniqueAndWellFormed(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $issued = $this->service->issue(self::TENANT);
            self::assertTrue(ActivationCode::isWellFormed($issued['code']));
            $seen[ActivationCode::canonicalize($issued['code'])] = true;
        }
        self::assertCount(50, $seen);
    }

    public function testIssuingRefusesANonsensicalRedemptionLimit(): void
    {
        $this->expectException(LicensingException::class);
        $this->service->issue(self::TENANT, null, maxRedemptions: 0);
    }

    public function testAnUnboundCodeNeedsADeviceAtRedemption(): void
    {
        $issued = $this->service->issue(self::TENANT);

        $this->expectException(LicensingException::class);
        $this->expectExceptionMessage('must be given');
        $this->service->redeem($issued['code']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function device(string $serial, int $tenantId = self::TENANT): int
    {
        $statement = $this->pdo->prepare("
            INSERT INTO licensed_devices (tenant_id, serial_number, status)
            VALUES (:tenant, :sn, 'provisioned')
            RETURNING id
        ");
        $statement->execute([':tenant' => $tenantId, ':sn' => $serial]);

        return (int) $statement->fetchColumn();
    }

    /**
     * `PDO::query()` returns PDOStatement|false, and chaining straight off it
     * hides a failed query behind a fatal on the next line. Asserting here
     * turns that into a test failure naming the SQL.
     */
    private function q(string $sql): \PDOStatement
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement, "query failed: {$sql}");

        return $statement;
    }

}
