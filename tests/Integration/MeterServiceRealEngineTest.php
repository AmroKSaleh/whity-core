<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * Limits that are spent and come back: "5 documents a day, 50 a month".
 *
 * Three properties carry the weight here, and each one is a bill somebody would
 * otherwise dispute:
 *
 *  1. A REFUSAL COSTS NOTHING. Being told no must not spend budget, or a
 *     customer at their monthly limit loses a day's allowance every time they
 *     try — and tomorrow starts in the hole.
 *  2. ONE ACTION SPENDS ALL ITS WINDOWS OR NONE. A render takes from the daily
 *     meter and the monthly one; if the month refuses, the day must be given
 *     back. Otherwise "5 a day" quietly becomes fewer.
 *  3. THE RESET IS ON THE CALENDAR, IN THE TENANT'S OWN ZONE. Somebody sold "5
 *     a day" reads that as a day, not as twenty-four hours from whenever they
 *     first rendered something — and a workspace in Amman means their midnight,
 *     not UTC's.
 */
final class MeterServiceRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const PER_DAY = EntitlementRegistry::DOCUMENTS_RENDER_PER_DAY;
    private const PER_MONTH = EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH;

    private PDO $pdo;
    private MeterService $meters;
    private EntitlementService $entitlements;
    private TenantSettingsRepository $tenantSettings;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");

        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->tenantSettings = new TenantSettingsRepository($this->pdo);
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), $this->tenantSettings);

        $this->meters = new MeterService(
            new DatabaseSharedStore($this->pdo),
            $this->entitlements,
            $settings,
        );
    }

    // ── Spending ────────────────────────────────────────────────────────────

    public function testSpendingWithinTheLimitIsAllowed(): void
    {
        $this->allow(self::PER_DAY, 3);

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 09:00'))->allowed);
        }
    }

    /** The refusal names WHICH limit and when it comes back — not just "no". */
    public function testExceedingTheLimitIsRefusedAndSaysWhichOneAndWhen(): void
    {
        $this->allow(self::PER_DAY, 2);
        $now = $this->at('2026-09-13 09:00');

        $this->meters->consume(self::TENANT, [self::PER_DAY], now: $now);
        $this->meters->consume(self::TENANT, [self::PER_DAY], now: $now);
        $decision = $this->meters->consume(self::TENANT, [self::PER_DAY], now: $now);

        self::assertFalse($decision->allowed);
        self::assertSame(self::PER_DAY, $decision->key);
        self::assertSame(2, $decision->limit);
        self::assertSame(0, $decision->remaining);
        self::assertStringStartsWith('2026-09-14T00:00', (string) $decision->resetsAt);
    }

    /**
     * A REFUSAL COSTS NOTHING. Ten refused attempts must not eat tomorrow's
     * allowance — the counter is given back every time it says no.
     */
    public function testBeingRefusedDoesNotSpendTomorrowsAllowance(): void
    {
        $this->allow(self::PER_DAY, 1);

        $this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 09:00'));
        for ($i = 0; $i < 10; $i++) {
            $this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 10:00'));
        }

        $tomorrow = $this->meters->peek(self::TENANT, [self::PER_DAY], $this->at('2026-09-14 09:00'));
        self::assertSame(0, $tomorrow[self::PER_DAY]['used']);
        self::assertSame(1, $tomorrow[self::PER_DAY]['remaining']);
    }

    /**
     * ALL WINDOWS OR NONE. The month refuses, so the day it had already taken
     * is handed back. Without this, a customer at their monthly limit loses a
     * daily unit on every attempt and "5 a day" becomes a lie.
     */
    public function testWhenTheMonthRefusesTheDayIsGivenBack(): void
    {
        $this->allow(self::PER_DAY, 5);
        $this->allow(self::PER_MONTH, 1);
        $keys = [self::PER_DAY, self::PER_MONTH];

        self::assertTrue($this->meters->consume(self::TENANT, $keys, now: $this->at('2026-09-13 09:00'))->allowed);

        $refused = $this->meters->consume(self::TENANT, $keys, now: $this->at('2026-09-13 10:00'));
        self::assertFalse($refused->allowed);
        self::assertSame(self::PER_MONTH, $refused->key);

        // One render happened today, not two: the refused attempt gave its day back.
        $peek = $this->meters->peek(self::TENANT, $keys, $this->at('2026-09-13 11:00'));
        self::assertSame(1, $peek[self::PER_DAY]['used']);
    }

    /** Sold none of it: refused without a counter and without spending. */
    public function testAZeroLimitRefusesImmediately(): void
    {
        $this->allow(self::PER_DAY, 0);

        $decision = $this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 09:00'));

        self::assertFalse($decision->allowed);
        self::assertSame(0, $decision->limit);
    }

    /**
     * Unlimited is never refused, reports no quantity, and — the part that
     * needed asserting directly — WRITES NOTHING.
     *
     * Found by mutation: peek() reports 0 used for an unlimited limit by
     * construction, so an implementation that incremented a counter on every
     * single action passed the earlier version of this test. On a deployment
     * that sells nothing, every limit is unlimited, so that is one wasted write
     * per rendered document forever. The store is inspected instead.
     */
    public function testUnlimitedIsNeverRefusedAndWritesNoCounter(): void
    {
        $now = $this->at('2026-09-13 09:00');
        for ($i = 0; $i < 50; $i++) {
            self::assertTrue($this->meters->consume(self::TENANT, [self::PER_DAY], now: $now)->allowed);
        }

        $peek = $this->meters->peek(self::TENANT, [self::PER_DAY], $now);
        self::assertNull($peek[self::PER_DAY]['remaining'], 'Unlimited is not a quantity.');
        self::assertSame(0, $this->counterRows(), 'An unlimited meter must not cost a write per action.');
    }

    /**
     * A zero limit refuses without opening a counter either — there is no
     * number to keep, and a key written once per refused attempt would
     * accumulate for a tenant who is simply not sold the feature.
     */
    public function testAZeroLimitRefusesWithoutWritingACounter(): void
    {
        $this->allow(self::PER_DAY, 0);

        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 09:00'))->allowed);
        }

        self::assertSame(0, $this->counterRows());
    }

    // ── Resetting ───────────────────────────────────────────────────────────

    /** THE DAILY LIMIT RESETS AT MIDNIGHT, not 24 hours after the first render. */
    public function testTheDailyWindowResetsOnTheCalendarDay(): void
    {
        $this->allow(self::PER_DAY, 1);

        // Spent late in the evening.
        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 23:30'))->allowed);
        // Refused a moment later, still the 13th.
        self::assertFalse($this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 23:45'))->allowed);
        // Allowed half an hour later, because it is the 14th — a rolling
        // 24-hour window would still be refusing here, until 23:30 tomorrow.
        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-14 00:15'))->allowed);
    }

    /**
     * A MONTH IS A MONTH, NOT A DAY. Two renders on different days of the same
     * month both count against it — found by mutation: keying the monthly
     * counter by day passed the first version of this test, because it only
     * ever compared 30 September with 1 October, which differ in both. A
     * customer sold 50 a month would have received 50 a DAY.
     */
    public function testSpendingOnDifferentDaysCountsAgainstTheSameMonth(): void
    {
        $this->allow(self::PER_MONTH, 2);

        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_MONTH], now: $this->at('2026-09-03 10:00'))->allowed);
        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_MONTH], now: $this->at('2026-09-17 10:00'))->allowed);
        self::assertFalse(
            $this->meters->consume(self::TENANT, [self::PER_MONTH], now: $this->at('2026-09-28 10:00'))->allowed,
            'Three renders in one month against a limit of two — the third is refused.'
        );
    }

    public function testTheMonthlyWindowResetsOnTheFirst(): void
    {
        $this->allow(self::PER_MONTH, 1);

        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_MONTH], now: $this->at('2026-09-30 12:00'))->allowed);
        self::assertFalse($this->meters->consume(self::TENANT, [self::PER_MONTH], now: $this->at('2026-09-30 23:00'))->allowed);
        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_MONTH], now: $this->at('2026-10-01 00:05'))->allowed);
    }

    /**
     * THE TENANT'S MIDNIGHT, NOT UTC'S. A workspace in Amman (UTC+3) rolls over
     * three hours before UTC does. Resetting on UTC would cut their day at 3am
     * and hand them a fresh allowance in the middle of a working evening.
     */
    public function testTheDayResetsInTheTenantsOwnTimezone(): void
    {
        $this->tenantSettings->set(self::TENANT, SettingsRegistry::TIMEZONE, 'Asia/Amman');
        $this->allow(self::PER_DAY, 1);

        // 2026-09-13 22:00 UTC is already 2026-09-14 01:00 in Amman, so this is
        // a NEW day locally even though UTC still calls it the 13th.
        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 20:00'))->allowed);
        self::assertTrue(
            $this->meters->consume(self::TENANT, [self::PER_DAY], now: $this->at('2026-09-13 22:00'))->allowed,
            'Local midnight has passed in Amman, so the daily allowance is fresh.'
        );
    }

    /**
     * NEW YEAR DOES NOT HAND OUT A SECOND WEEK. The ISO week year ('o') belongs
     * to the WEEK; using the calendar year ('Y') splits the week straddling 1
     * January into two counters and doubles that week's allowance.
     */
    public function testTheWeeklyWindowDoesNotResetMidWeekAtNewYear(): void
    {
        // 2026-12-31 and 2027-01-01 are Thursday and Friday of the SAME ISO week.
        $this->allowWeekly(1);
        self::assertTrue($this->meters->consume(self::TENANT, [self::weeklyKey()], now: $this->at('2026-12-31 10:00'))->allowed);
        self::assertFalse(
            $this->meters->consume(self::TENANT, [self::weeklyKey()], now: $this->at('2027-01-01 10:00'))->allowed,
            'Same ISO week, so the allowance is already spent.'
        );
    }

    // ── Reading without spending ────────────────────────────────────────────

    /** Rendering a progress bar must not cost the customer a unit. */
    public function testPeekDoesNotConsume(): void
    {
        $this->allow(self::PER_DAY, 5);
        $now = $this->at('2026-09-13 09:00');

        $this->meters->consume(self::TENANT, [self::PER_DAY], now: $now);
        for ($i = 0; $i < 5; $i++) {
            $this->meters->peek(self::TENANT, [self::PER_DAY], $now);
        }

        $peek = $this->meters->peek(self::TENANT, [self::PER_DAY], $now);
        self::assertSame(1, $peek[self::PER_DAY]['used']);
        self::assertSame(4, $peek[self::PER_DAY]['remaining']);
    }

    /** Meters are per tenant: one workspace's spending is not another's. */
    public function testOneTenantsSpendingDoesNotCountAgainstAnother(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");
        $this->allow(self::PER_DAY, 1);
        $this->allow(self::PER_DAY, 1, tenantId: 2);
        $now = $this->at('2026-09-13 09:00');

        self::assertTrue($this->meters->consume(self::TENANT, [self::PER_DAY], now: $now)->allowed);
        self::assertTrue($this->meters->consume(2, [self::PER_DAY], now: $now)->allowed);
    }

    /** A standing cap is not spendable — asking is a programming error, loudly. */
    public function testConsumingANonMeteredLimitIsRefusedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->meters->consume(self::TENANT, [EntitlementRegistry::MEMBERS_MAX]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * How many meter counters exist in the shared store.
     *
     * Asserted directly because two of the guarantees here are about a write
     * NOT happening, and no amount of reading the decision object can see that.
     */
    private function counterRows(): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM shared_store WHERE store_key LIKE 'meter:%'");

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc . ' UTC');
    }

    private function allow(string $key, int $limit, int $tenantId = self::TENANT): void
    {
        $this->entitlements->set($tenantId, $key, (string) $limit);
    }

    /**
     * A weekly meter, declared the way a plugin would declare one — core ships
     * no weekly limit today, and the ISO-week property still has to be proven.
     */
    private static function weeklyKey(): string
    {
        return 'weeklyfixture.renders';
    }

    private function allowWeekly(int $limit): void
    {
        \Whity\Core\Entitlement\PluginEntitlements::register(
            new \Whity\Core\Entitlement\EntitlementDefinition(
                self::weeklyKey(),
                \Whity\Core\Entitlement\EntitlementDefinition::TYPE_INT,
                '-1',
                'Renders per week, for proving the ISO-week boundary.',
                \Whity\Core\Entitlement\EntitlementDefinition::PERIOD_WEEK,
                'weeklyfixture',
            )
        );
        $this->entitlements->set(self::TENANT, self::weeklyKey(), (string) $limit);
    }

    protected function tearDown(): void
    {
        \Whity\Core\Entitlement\PluginEntitlements::reset();
    }
}
