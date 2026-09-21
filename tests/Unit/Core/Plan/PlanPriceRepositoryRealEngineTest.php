<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Plan;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanValidationException;

/**
 * What a plan costs.
 *
 * Plans have existed since migration 055 and never had a price — an operator
 * could describe a tier, attach entitlements, assign a tenant and drive the
 * payment wall from it, without the system ever knowing what any of it was
 * worth.
 *
 * TWO THINGS HERE ARE WORTH MORE THAN THE CRUD.
 *
 * Money is an INTEGER OF MINOR UNITS, and the tests treat it as one. A float
 * cannot hold 0.10 exactly, and the error compounds through the exact operations
 * billing performs — multiply by a seat count, take a percentage off, sum a
 * total.
 *
 * And at most ONE ACTIVE PRICE may exist per plan, currency, period and
 * per-seat-ness. Without that a plan could carry two live monthly SAR prices and
 * every caller would have to choose between them — differently in the checkout,
 * the invoice and the price list, which is how a customer is charged an amount
 * no screen ever displayed.
 */
final class PlanPriceRepositoryRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PlanPriceRepository $prices;
    private int $planId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $plans = new PlanRepository($this->pdo);
        $this->planId = $plans->createPlan('pro', 'Pro', null, true, 0);
        $this->prices = new PlanPriceRepository($this->pdo);
    }

    /**
     * Fetch a row and prove it is there before indexing it.
     *
     * `findById` is nullable, and a test that indexes the null would fail with
     * "offset on null" rather than "the price is missing" — a message about the
     * test rather than about the thing under test.
     *
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->prices->findById($id);
        self::assertNotNull($row, "price {$id} should exist");

        return $row;
    }

    // ── storing money ────────────────────────────────────────────────────────

    public function testAPriceRoundTripsAsAnInteger(): void
    {
        $id = $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        $row = $this->row($id);

        self::assertSame(4900, $row['unit_amount']);
        // An int, not the string PDO hands back: a string amount compares and
        // arithmetics wrongly in ways that look right for small numbers.
        self::assertIsInt($row['unit_amount']);
    }

    public function testLargeAmountsSurviveIntact(): void
    {
        // Well past a 32-bit int. A currency with three decimal places and a
        // yearly enterprise price reaches this range in earnest.
        $id = $this->prices->create($this->planId, 'KWD', 9_999_999_999, PlanPriceRepository::PERIOD_YEAR);

        self::assertSame(9_999_999_999, $this->row($id)['unit_amount']);
    }

    /**
     * A free tier is a real commercial object. Expressed as a price of nothing
     * rather than the absence of a price, which is indistinguishable from a plan
     * nobody has got round to pricing.
     */
    public function testZeroIsAValidPrice(): void
    {
        $id = $this->prices->create($this->planId, 'SAR', 0, PlanPriceRepository::PERIOD_MONTH);
        self::assertSame(0, $this->row($id)['unit_amount']);
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(PlanValidationException::class);
        $this->prices->create($this->planId, 'SAR', -1, PlanPriceRepository::PERIOD_MONTH);
    }

    // ── currency ─────────────────────────────────────────────────────────────

    public function testCurrencyIsStoredUpperCase(): void
    {
        $id = $this->prices->create($this->planId, 'sar', 100, PlanPriceRepository::PERIOD_MONTH);

        // Otherwise `SAR` and `sar` are two currencies that never match.
        self::assertSame('SAR', $this->row($id)['currency']);
    }

    public function testANonIsoCurrencyIsRefused(): void
    {
        foreach (['S', 'SARS', 'S4R', ''] as $bad) {
            try {
                $this->prices->create($this->planId, $bad, 100, PlanPriceRepository::PERIOD_MONTH);
                self::fail("currency '{$bad}' should have been refused");
            } catch (PlanValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAnUnknownBillingPeriodIsRefused(): void
    {
        $this->expectException(PlanValidationException::class);
        $this->prices->create($this->planId, 'SAR', 100, 'fortnight');
    }

    // ── one live price per set of terms ──────────────────────────────────────

    public function testTwoLivePricesOnTheSameTermsAreRefused(): void
    {
        $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);

        $this->expectException(\PDOException::class);
        $this->prices->create($this->planId, 'SAR', 5900, PlanPriceRepository::PERIOD_MONTH);
    }

    public function testTheSamePlanMayBePricedInSeveralCurrencies(): void
    {
        $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        $this->prices->create($this->planId, 'USD', 1300, PlanPriceRepository::PERIOD_MONTH);
        $this->prices->create($this->planId, 'SAR', 49000, PlanPriceRepository::PERIOD_YEAR);

        self::assertCount(3, $this->prices->listForPlan($this->planId));
    }

    /** Flat and per-seat are different terms, so both may be live at once. */
    public function testFlatAndPerSeatCanCoexistOnTheSameTerms(): void
    {
        $this->prices->create($this->planId, 'SAR', 9900, PlanPriceRepository::PERIOD_MONTH, false);
        $this->prices->create($this->planId, 'SAR', 1900, PlanPriceRepository::PERIOD_MONTH, true);

        self::assertCount(2, $this->prices->listForPlan($this->planId));
    }

    /**
     * A flat and a per-device price are different terms too, since migration 148
     * widened the live-price index. Without that widening this pair collides,
     * and a plan could not be offered on both bases at once.
     */
    public function testFlatAndPerDeviceCanCoexistOnTheSameTerms(): void
    {
        $this->prices->create($this->planId, 'SAR', 9900, PlanPriceRepository::PERIOD_MONTH, false, false);
        $this->prices->create($this->planId, 'SAR', 1500, PlanPriceRepository::PERIOD_MONTH, false, true);

        self::assertCount(2, $this->prices->listForPlan($this->planId));
    }

    /**
     * A PRICE MULTIPLIES BY ONE THING, OR BY NOTHING. Both flags together is not
     * a price, it is an ambiguity — the billing run would have to choose a
     * multiplier, and whichever it chose would be wrong half the time on an
     * invoice somebody already paid.
     *
     * Refused HERE rather than left to the database CHECK, because a constraint
     * violation arrives as a PDOException that the API handler reports as a 409
     * about a duplicate price — the wrong answer entirely: nothing is
     * duplicated, the price is incoherent.
     */
    public function testAPriceCannotBePerSeatAndPerDeviceAtOnce(): void
    {
        $this->expectException(PlanValidationException::class);

        $this->prices->create($this->planId, 'SAR', 9900, PlanPriceRepository::PERIOD_MONTH, true, true);
    }

    /**
     * THE FLAG SURVIVES THE ROUND TRIP AS A BOOLEAN. PostgreSQL hands back 'f'
     * for false and `(bool) 'f'` is TRUE in PHP, so a raw cast would make every
     * flat price read as per-device on the real engine and as flat on SQLite —
     * every test passing while every production row lied.
     */
    public function testThePerDeviceFlagRoundTripsAsABoolean(): void
    {
        $flat = $this->prices->create($this->planId, 'SAR', 9900, PlanPriceRepository::PERIOD_MONTH, false, false);
        $device = $this->prices->create($this->planId, 'SAR', 1500, PlanPriceRepository::PERIOD_MONTH, false, true);

        self::assertFalse($this->row($flat)['is_per_device'], 'a flat price is not per-device');
        self::assertTrue($this->row($device)['is_per_device']);
    }

    /**
     * THE PRICE LIST MUST NOT DESCRIBE A DIFFERENT PRODUCT FROM THE INVOICE.
     * The billing run reads `is_per_device` from its own SELECT; if this
     * repository dropped the column while hydrating, every caller reading a
     * price through it — the price list, the quote, the admin screen — would
     * show a per-device price as flat.
     */
    public function testTheHydratedRowCarriesThePerDeviceFlagAtAll(): void
    {
        $id = $this->prices->create($this->planId, 'SAR', 1500, PlanPriceRepository::PERIOD_MONTH, false, true);

        self::assertArrayHasKey('is_per_device', $this->row($id));
    }

    /**
     * THE POINT OF RETIRING RATHER THAN DELETING. The slot is freed for the new
     * price while the old row survives as the record of what somebody was
     * charged.
     */
    public function testRetiringAPriceFreesItsSlotAndKeepsTheRow(): void
    {
        $old = $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        $this->prices->deactivate($old);

        $new = $this->prices->create($this->planId, 'SAR', 5900, PlanPriceRepository::PERIOD_MONTH);

        self::assertNotNull($this->prices->findById($old), 'the old price must still be readable');
        self::assertFalse($this->row($old)['is_active']);
        self::assertTrue($this->row($new)['is_active']);
        self::assertCount(2, $this->prices->listForPlan($this->planId));
    }

    // ── reading ──────────────────────────────────────────────────────────────

    public function testFindActiveReturnsTheLiveOne(): void
    {
        $old = $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        $this->prices->deactivate($old);
        $new = $this->prices->create($this->planId, 'SAR', 5900, PlanPriceRepository::PERIOD_MONTH);

        $found = $this->prices->findActive($this->planId, 'SAR', PlanPriceRepository::PERIOD_MONTH);
        self::assertNotNull($found);
        self::assertSame($new, $found['id']);
        self::assertSame(5900, $found['unit_amount']);
    }

    public function testFindActiveIsCaseInsensitiveAboutCurrency(): void
    {
        $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);

        self::assertNotNull($this->prices->findActive($this->planId, 'sar', PlanPriceRepository::PERIOD_MONTH));
    }

    public function testFindActiveDistinguishesPerSeatFromFlat(): void
    {
        $this->prices->create($this->planId, 'SAR', 9900, PlanPriceRepository::PERIOD_MONTH, false);

        self::assertNotNull($this->prices->findActive($this->planId, 'SAR', PlanPriceRepository::PERIOD_MONTH, false));
        self::assertNull(
            $this->prices->findActive($this->planId, 'SAR', PlanPriceRepository::PERIOD_MONTH, true),
            'a flat price must not answer a per-seat question'
        );
    }

    /**
     * "SINGLE BY CONSTRUCTION" HAS TO STAY TRUE. findActive() promises callers
     * it cannot pick between two rows — that is the sentence standing between a
     * customer and a charge no screen displayed. Migration 148 put that promise
     * at risk: a flat price and a per-device price share `is_per_seat = false`,
     * so matching on seat-ness alone makes BOTH satisfy the query and hands back
     * whichever the engine happened to return first.
     */
    public function testFindActiveDistinguishesPerDeviceFromFlat(): void
    {
        $flat = $this->prices->create($this->planId, 'SAR', 9900, PlanPriceRepository::PERIOD_MONTH, false, false);
        $device = $this->prices->create($this->planId, 'SAR', 1500, PlanPriceRepository::PERIOD_MONTH, false, true);

        $foundFlat = $this->prices->findActive($this->planId, 'SAR', PlanPriceRepository::PERIOD_MONTH, false, false);
        $foundDevice = $this->prices->findActive($this->planId, 'SAR', PlanPriceRepository::PERIOD_MONTH, false, true);

        self::assertNotNull($foundFlat);
        self::assertNotNull($foundDevice);
        self::assertSame($flat, $foundFlat['id'], 'the flat question must return the flat price');
        self::assertSame($device, $foundDevice['id'], 'the per-device question must return the per-device price');
        self::assertNotSame(
            $foundFlat['id'],
            $foundDevice['id'],
            'two different questions returning one row means the predicate is not discriminating'
        );
    }

    public function testListingCanBeRestrictedToLivePrices(): void
    {
        $retired = $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        $this->prices->deactivate($retired);
        $this->prices->create($this->planId, 'USD', 1300, PlanPriceRepository::PERIOD_MONTH);

        self::assertCount(2, $this->prices->listForPlan($this->planId));
        self::assertCount(1, $this->prices->listForPlan($this->planId, activeOnly: true));
    }

    /**
     * THE CROSS-ENGINE TRAP. PostgreSQL returns 'f' for false and `(bool) 'f'`
     * is TRUE in PHP, so a plain cast would report every retired price as live
     * on the real engine while passing on SQLite. `DbBool` is what makes the
     * two agree.
     */
    public function testRetiredIsFalseAsABooleanAndNotAStringOrInt(): void
    {
        $id = $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        $this->prices->deactivate($id);

        $row = $this->row($id);
        self::assertIsBool($row['is_active']);
        self::assertFalse($row['is_active']);
        self::assertIsBool($row['is_per_seat']);
    }

    /**
     * The same trap, caught on EITHER engine.
     *
     * The test above passes with a plain `(bool)` cast on SQLite, because SQLite
     * hands back 0 and `(bool) 0` is false — so it only fails on the PostgreSQL
     * shard, which is a slow and easily-missed way to learn. Writing the literal
     * PostgreSQL false ('f') into the column makes the cast wrong on both:
     * `(bool) 'f'` is TRUE, and a retired price would read as live.
     */
    public function testTheLiteralPostgresFalseIsNotReadAsTrue(): void
    {
        $id = $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        $this->pdo->exec("UPDATE plan_prices SET is_active = 'f' WHERE id = {$id}");

        self::assertFalse(
            $this->row($id)['is_active'],
            "'f' is how PostgreSQL says false; a (bool) cast would read it as true"
        );
    }

    // ── lifecycle ────────────────────────────────────────────────────────────

    /**
     * A price for a plan that no longer exists prices nothing, so the schema
     * declares ON DELETE CASCADE.
     *
     * The PRAGMA is set explicitly because SQLite does not enforce foreign keys
     * unless asked, and the shared test harness leaves it off. Without it this
     * test passes vacuously on the default engine while asserting a PostgreSQL
     * behaviour it never exercised — the same shape of hole as an assertion
     * about a key that does not exist.
     */
    public function testDeletingAPlanTakesItsPricesWithIt(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        $this->prices->create($this->planId, 'SAR', 4900, PlanPriceRepository::PERIOD_MONTH);
        (new PlanRepository($this->pdo))->deletePlan($this->planId);

        self::assertSame([], $this->prices->listForPlan($this->planId));
    }
}
