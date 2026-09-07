<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Billing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\QuoteService;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Money\MoneyException;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Promotion\PromotionEligibility;
use Whity\Core\Promotion\PromotionRepository;
use Whity\Core\Promotion\PromotionService;
use Whity\Core\Seat\SeatService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * What a tenant would pay: price × seats, less a promotion.
 *
 * THE ORDER OF OPERATIONS IS THE SUBJECT. Both of these are defensible and they
 * give different answers once rounding enters:
 *
 *   discount the SUBTOTAL:  33% of (5 × 10) = 33% of 50 = 17  → total 33
 *   discount each UNIT:     33% of 5 = 2, × 10              = 20 → total 30
 *
 * Only one can be the rule. This service discounts the subtotal, and the test
 * below pins it — otherwise the answer becomes whichever call site was written
 * first, and the two would disagree the moment a second one existed.
 *
 * The other thing worth more than the arithmetic is the CLAMP: a fixed discount
 * larger than the price discounts the price, not the offer. Recording the offer
 * would put a number in the ledger that never moved, and make a total that does
 * not add up.
 *
 * There are TWO clamps and only one is reachable. `discountFor()` limits the
 * discount to the subtotal, which makes `Money::clampedToZero()` on the total
 * dead code from here — mutation testing showed removing it fails nothing. It is
 * kept deliberately, and said so in the service, rather than tested by a case
 * that cannot be constructed through the public interface.
 */
final class QuoteServiceRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private QuoteService $quotes;
    private PromotionRepository $promotions;
    private PlanPriceRepository $prices;
    private int $planId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'one', 'one')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $seats = new SeatService($this->pdo, $entitlements, $settings);

        $this->promotions = new PromotionRepository($this->pdo);
        $this->prices = new PlanPriceRepository($this->pdo);
        $this->planId = (new PlanRepository($this->pdo))->createPlan('pro', 'Pro', null, true, 0);

        $this->quotes = new QuoteService(new PromotionService($this->promotions), $seats);
    }

    // ── a flat price ─────────────────────────────────────────────────────────

    public function testAFlatPriceWithNoPromotionIsJustThePrice(): void
    {
        $quote = $this->quotes->quote(self::TENANT, $this->price(4900));

        self::assertSame(4900, $quote->subtotal->amount);
        self::assertSame(0, $quote->discount->amount);
        self::assertSame(4900, $quote->total->amount);
        self::assertSame(1, $quote->quantity);
        self::assertFalse($quote->hasDiscount());
    }

    public function testAPercentageComesOffTheTotal(): void
    {
        $promotion = $this->promotionRow($this->promotions->createPercentOff('Fifth', 20, code: 'FIFTH'));

        $quote = $this->quotes->quote(self::TENANT, $this->price(4900), $promotion);

        self::assertSame(980, $quote->discount->amount);
        self::assertSame(3920, $quote->total->amount);
        self::assertSame('Fifth', $quote->promotionName);
    }

    public function testAFixedAmountComesOff(): void
    {
        $promotion = $this->promotionRow(
            $this->promotions->createAmountOff('Fifty riyals', 5000, 'SAR', code: 'SAR50')
        );

        $quote = $this->quotes->quote(self::TENANT, $this->price(19900), $promotion);

        self::assertSame(5000, $quote->discount->amount);
        self::assertSame(14900, $quote->total->amount);
    }

    // ── the clamp ────────────────────────────────────────────────────────────

    /**
     * A fixed 50 against a price of 30 discounts 30. You cannot take more off a
     * thing than it costs, and recording the OFFER instead would put a number in
     * the ledger that never moved and leave the totals not adding up.
     */
    public function testAFixedDiscountLargerThanThePriceDiscountsOnlyThePrice(): void
    {
        $promotion = $this->promotionRow(
            $this->promotions->createAmountOff('Big', 5000, 'SAR', code: 'BIG')
        );

        $quote = $this->quotes->quote(self::TENANT, $this->price(3000), $promotion);

        self::assertSame(3000, $quote->discount->amount, 'the discount is what was actually taken off');
        self::assertSame(0, $quote->total->amount, 'and nothing is owed');
    }

    /** A hundred per cent leaves nothing owed, and is not an error. */
    public function testAFullPercentageDiscountLeavesNothingOwed(): void
    {
        $promotion = $this->promotionRow($this->promotions->createPercentOff('All of it', 100, code: 'ALL'));

        $quote = $this->quotes->quote(self::TENANT, $this->price(4900), $promotion);

        self::assertSame(4900, $quote->discount->amount);
        self::assertSame(0, $quote->total->amount);
    }

    // ── seats ────────────────────────────────────────────────────────────────

    public function testAPerSeatPriceMultipliesByTheSeatsHeld(): void
    {
        $this->member(10);
        $this->member(11);
        $this->member(12);

        $quote = $this->quotes->quote(self::TENANT, $this->price(1900, perSeat: true));

        self::assertSame(3, $quote->quantity);
        self::assertSame(1900, $quote->unitPrice->amount);
        self::assertSame(5700, $quote->subtotal->amount);
    }

    /**
     * A tenant with nobody in it is still billed for one seat. Zero would make
     * the invoice free, which is not what "a subscription with nobody added
     * yet" means — and a tenant always has at least the person who created it.
     */
    public function testAnEmptyTenantIsBilledForOneSeat(): void
    {
        $quote = $this->quotes->quote(self::TENANT, $this->price(1900, perSeat: true));

        self::assertSame(1, $quote->quantity);
        self::assertSame(1900, $quote->subtotal->amount);
    }

    /**
     * THE ORDER OF OPERATIONS. 33% off a subtotal of 50 is 17 (16.5 rounded up);
     * 33% off each unit of 5 would be 2 each, 20 in total. The subtotal is the
     * rule.
     */
    public function testTheDiscountAppliesToTheSubtotalAndNotToEachUnit(): void
    {
        $this->member(10);
        $this->member(11);
        $this->member(12);
        $this->member(13);
        $this->member(14);
        $this->member(15);
        $this->member(16);
        $this->member(17);
        $this->member(18);
        $this->member(19);

        $promotion = $this->promotionRow($this->promotions->createPercentOff('Third', 33, code: 'THIRD'));
        $quote = $this->quotes->quote(self::TENANT, $this->price(5, perSeat: true), $promotion);

        self::assertSame(10, $quote->quantity);
        self::assertSame(50, $quote->subtotal->amount);
        self::assertSame(17, $quote->discount->amount, '33% of 50 is 16.5, rounded up — not 2 per unit');
        self::assertSame(33, $quote->total->amount);
    }

    // ── currency ─────────────────────────────────────────────────────────────

    /**
     * Structural rather than a validation message: PromotionService already
     * refuses this pairing WITH a reason, so reaching the arithmetic with it
     * means a caller skipped the check. Better to throw than to produce a
     * number.
     */
    public function testAMismatchedCurrencyThrowsRatherThanProducingANumber(): void
    {
        $promotion = $this->promotionRow(
            $this->promotions->createAmountOff('Dollars', 500, 'USD', code: 'USD5')
        );

        $this->expectException(MoneyException::class);
        $this->quotes->quote(self::TENANT, $this->price(4900), $promotion);
    }

    // ── quoting with a code ──────────────────────────────────────────────────

    public function testQuotingWithAGoodCodeAppliesIt(): void
    {
        $this->promotions->createPercentOff('Fifth', 20, code: 'FIFTH');

        $result = $this->quotes->quoteWithCode(self::TENANT, $this->price(4900), 'FIFTH');

        self::assertSame(3920, $result['quote']->total->amount);
        self::assertNotNull($result['eligibility']);
        self::assertTrue($result['eligibility']->eligible);
    }

    /**
     * A mistyped code still returns a QUOTE. Somebody who fat-fingered a code
     * should see what they would pay, with the reason beside it — not an error
     * page instead of a price.
     */
    public function testAnUnusableCodeStillReturnsThePriceAndSaysWhy(): void
    {
        $result = $this->quotes->quoteWithCode(self::TENANT, $this->price(4900), 'NOPE');

        self::assertSame(4900, $result['quote']->total->amount);
        self::assertFalse($result['quote']->hasDiscount());
        self::assertNotNull($result['eligibility']);
        self::assertSame(PromotionEligibility::NOT_FOUND, $result['eligibility']->reason);
    }

    public function testNoCodeIsNotAFailedCode(): void
    {
        $result = $this->quotes->quoteWithCode(self::TENANT, $this->price(4900), null);

        self::assertSame(4900, $result['quote']->total->amount);
        self::assertNull($result['eligibility'], 'not asking is not the same as asking and being refused');
    }

    public function testAnExpiredCodeIsReportedAsExpiredAndNotApplied(): void
    {
        $this->promotions->createPercentOff('Gone', 50, code: 'GONE', endsAt: '2000-01-01 00:00:00');

        $result = $this->quotes->quoteWithCode(self::TENANT, $this->price(4900), 'GONE');

        self::assertSame(4900, $result['quote']->total->amount);
        self::assertNotNull($result['eligibility']);
        self::assertSame(PromotionEligibility::EXPIRED, $result['eligibility']->reason);
    }

    // ── the breakdown ────────────────────────────────────────────────────────

    /**
     * A quote carries its WORKING. A total alone cannot be checked by the person
     * paying it, shown as a breakdown, or reconciled against a charge later.
     */
    public function testTheQuoteCarriesEveryNumberABreakdownNeeds(): void
    {
        $promotion = $this->promotionRow($this->promotions->createPercentOff('Fifth', 20, code: 'FIFTH'));
        $array = $this->quotes->quote(self::TENANT, $this->price(4900), $promotion)->toArray();

        self::assertSame(
            ['currency', 'unit_price', 'quantity', 'subtotal', 'discount', 'total', 'promotion_id', 'promotion_name'],
            array_keys($array)
        );
        self::assertSame('SAR', $array['currency']);
        self::assertSame(4900, $array['subtotal']);
        self::assertSame(980, $array['discount']);
        self::assertSame(3920, $array['total']);
        // Amounts stay minor units — formatting needs a locale this layer does
        // not know, and a formatted string is one a caller has to parse back.
        self::assertIsInt($array['total']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function price(int $amount, bool $perSeat = false): array
    {
        $id = $this->prices->create(
            $this->planId,
            'SAR',
            $amount,
            PlanPriceRepository::PERIOD_MONTH,
            $perSeat
        );
        $row = $this->prices->findById($id);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function promotionRow(int $id): array
    {
        $row = $this->promotions->findById($id);
        self::assertNotNull($row);

        return $row;
    }

    private function member(int $profileId): void
    {
        $this->pdo->exec(
            "INSERT OR IGNORE INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ({$profileId}, 'p{$profileId}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES ({$profileId}, " . self::TENANT . ", 1, NULL, '" . MembershipRepository::STATUS_ACTIVE . "', CURRENT_TIMESTAMP)"
        );
    }
}
