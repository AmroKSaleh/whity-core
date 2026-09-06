<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Promotion;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Promotion\PromotionEligibility;
use Whity\Core\Promotion\PromotionRepository;
use Whity\Core\Promotion\PromotionService;
use Whity\Core\Promotion\PromotionValidationException;

/**
 * Early birds, offers and promo codes — ONE object, judged.
 *
 * They differ only in how they are discovered: a code is typed, an early bird
 * applies to whoever arrives in time. So `code` is nullable, and most of what
 * follows tests rules that are identical for all three — which is the argument
 * for not having built three of them.
 *
 * THE REASONS ARE THE SUBJECT. "That code is not valid" is what every badly
 * built checkout says, and it is the same sentence for a code that never
 * existed, one that expired, one already used, one that ran out, and one that
 * does not apply to the chosen plan. Four of those are recoverable and need
 * different actions, so the reasons are a closed set and the tests pin which one
 * comes back.
 *
 * The clock is injected because the interesting cases are boundaries, and a
 * service reading the wall clock can only be tested by waiting.
 */
final class PromotionServiceRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** 2026-06-15 12:00:00 UTC — mid-window for every fixture below. */
    private const NOW = 1781524800;

    private PDO $pdo;
    private PromotionRepository $repo;
    private PromotionService $service;
    private int $planId;
    private int $otherPlanId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'one', 'one')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'two', 'two')");

        $plans = new PlanRepository($this->pdo);
        $this->planId = $plans->createPlan('pro', 'Pro', null, true, 0);
        $this->otherPlanId = $plans->createPlan('lite', 'Lite', null, true, 1);

        $this->repo = new PromotionRepository($this->pdo);
        $this->service = new PromotionService($this->repo, fn (): int => self::NOW);
    }

    // ── the same object, three discoveries ───────────────────────────────────

    public function testACodedPromotionIsFoundByItsCode(): void
    {
        $this->repo->createPercentOff('Summer', 20, code: 'SUMMER24');

        self::assertTrue($this->service->checkCode('SUMMER24', self::TENANT)->eligible);
    }

    /**
     * Codes are compared upper case, so a customer cannot fail to redeem one by
     * not matching the operator's shift key.
     */
    public function testACodeIsCaseAndSpaceInsensitive(): void
    {
        $this->repo->createPercentOff('Summer', 20, code: 'SUMMER24');

        self::assertTrue($this->service->checkCode('  summer24 ', self::TENANT)->eligible);
    }

    /** An early bird is the same row with no code — found automatically. */
    public function testAnEarlyBirdIsFoundWithoutACode(): void
    {
        $this->repo->createPercentOff('Early bird', 30, maxRedemptions: 100);

        $automatic = $this->service->automaticFor(self::TENANT);
        self::assertCount(1, $automatic);
        self::assertSame('Early bird', $automatic[0]['name']);
    }

    /** And a coded one is NOT automatic — it has to be typed. */
    public function testACodedPromotionIsNotAppliedAutomatically(): void
    {
        $this->repo->createPercentOff('Summer', 20, code: 'SUMMER24');

        self::assertSame([], $this->service->automaticFor(self::TENANT));
    }

    // ── the window ───────────────────────────────────────────────────────────

    public function testAPromotionBeforeItsWindowIsNotStarted(): void
    {
        $this->repo->createPercentOff('Later', 20, code: 'LATER', startsAt: '2026-07-01 00:00:00');

        $result = $this->service->checkCode('LATER', self::TENANT);
        self::assertFalse($result->eligible);
        self::assertSame(PromotionEligibility::NOT_STARTED, $result->reason);
    }

    public function testAPromotionAfterItsWindowIsExpired(): void
    {
        $this->repo->createPercentOff('Gone', 20, code: 'GONE', endsAt: '2026-06-01 00:00:00');

        self::assertSame(
            PromotionEligibility::EXPIRED,
            $this->service->checkCode('GONE', self::TENANT)->reason
        );
    }

    /**
     * THE BOUNDARY. Inclusive at the start, EXCLUSIVE at the end: a promotion
     * running 1 June to 1 July covers the whole of June and not 1 July. The
     * alternative makes the last day ambiguous and lets two adjacent campaigns
     * overlap for an instant.
     */
    public function testTheStartIsInclusiveAndTheEndIsExclusive(): void
    {
        $atStart = new PromotionService($this->repo, fn (): int => strtotime('2026-06-01 00:00:00'));
        $atEnd = new PromotionService($this->repo, fn (): int => strtotime('2026-07-01 00:00:00'));

        $this->repo->createPercentOff(
            'June',
            20,
            code: 'JUNE',
            startsAt: '2026-06-01 00:00:00',
            endsAt: '2026-07-01 00:00:00'
        );

        self::assertTrue($atStart->checkCode('JUNE', self::TENANT)->eligible, 'the first instant is included');
        self::assertSame(
            PromotionEligibility::EXPIRED,
            $atEnd->checkCode('JUNE', self::TENANT)->reason,
            'the closing instant is not'
        );
    }

    public function testAPromotionWithNoWindowIsAlwaysInSeason(): void
    {
        $this->repo->createPercentOff('Always', 10, code: 'ALWAYS');

        self::assertTrue($this->service->checkCode('ALWAYS', self::TENANT)->eligible);
    }

    // ── caps: the early bird's usual ending ──────────────────────────────────

    public function testAnAllocationRunsOut(): void
    {
        $id = $this->repo->createPercentOff('First fifty', 50, code: 'FIRST', maxRedemptions: 2);

        $this->repo->recordRedemption($id, self::TENANT);
        $this->repo->recordRedemption($id, self::OTHER_TENANT);

        self::assertSame(
            PromotionEligibility::FULLY_REDEEMED,
            $this->service->checkCode('FIRST', 3)->reason
        );
    }

    /**
     * ONE TENANT CANNOT TAKE THE WHOLE ALLOCATION. The per-tenant cap defaults
     * to 1 for exactly this reason.
     */
    public function testATenantCannotRedeemTwiceByDefault(): void
    {
        $id = $this->repo->createPercentOff('Once', 20, code: 'ONCE', maxRedemptions: 100);
        $this->repo->recordRedemption($id, self::TENANT);

        self::assertSame(
            PromotionEligibility::ALREADY_REDEEMED,
            $this->service->checkCode('ONCE', self::TENANT)->reason
        );
        // And another tenant is unaffected.
        self::assertTrue($this->service->checkCode('ONCE', self::OTHER_TENANT)->eligible);
    }

    public function testAPerTenantCapAboveOneIsHonoured(): void
    {
        $id = $this->repo->createPercentOff('Thrice', 20, code: 'THRICE', maxPerTenant: 3);
        $this->repo->recordRedemption($id, self::TENANT);
        $this->repo->recordRedemption($id, self::TENANT);

        self::assertTrue($this->service->checkCode('THRICE', self::TENANT)->eligible);

        $this->repo->recordRedemption($id, self::TENANT);
        self::assertSame(
            PromotionEligibility::ALREADY_REDEEMED,
            $this->service->checkCode('THRICE', self::TENANT)->reason
        );
    }

    // ── which plans ──────────────────────────────────────────────────────────

    /**
     * NO ROWS MEANS EVERY PLAN. A blanket promotion that stored one row per plan
     * would need re-synchronising whenever a tier was added, and the failure
     * would be silent — a new plan quietly outside the campaign meant to cover
     * everything.
     */
    public function testAPromotionWithNoPlanListCoversEveryPlan(): void
    {
        $this->repo->createPercentOff('Everything', 20, code: 'ALL');

        self::assertTrue($this->service->checkCode('ALL', self::TENANT, $this->planId)->eligible);
        self::assertTrue($this->service->checkCode('ALL', self::TENANT, $this->otherPlanId)->eligible);
    }

    public function testARestrictedPromotionRefusesOtherPlans(): void
    {
        $this->repo->createPercentOff('Pro only', 20, code: 'PRO', planIds: [$this->planId]);

        self::assertTrue($this->service->checkCode('PRO', self::TENANT, $this->planId)->eligible);
        self::assertSame(
            PromotionEligibility::PLAN_NOT_ELIGIBLE,
            $this->service->checkCode('PRO', self::TENANT, $this->otherPlanId)->reason
        );
    }

    // ── currency ─────────────────────────────────────────────────────────────

    /**
     * A fixed discount is an amount OF A CURRENCY. Refused rather than
     * converted: a conversion needs a rate nobody stored and that has since
     * moved, and a discount that quietly became a different amount of money is
     * worse than one that did not apply.
     */
    public function testAFixedDiscountRefusesAForeignCurrency(): void
    {
        $this->repo->createAmountOff('Fifty riyals', 5000, 'SAR', code: 'SAR50');

        self::assertTrue($this->service->checkCode('SAR50', self::TENANT, null, 'SAR')->eligible);
        self::assertSame(
            PromotionEligibility::CURRENCY_MISMATCH,
            $this->service->checkCode('SAR50', self::TENANT, null, 'USD')->reason
        );
    }

    /** A percentage has no currency, so it never mismatches. */
    public function testAPercentageAppliesInAnyCurrency(): void
    {
        $this->repo->createPercentOff('Fifth off', 20, code: 'FIFTH');

        self::assertTrue($this->service->checkCode('FIFTH', self::TENANT, null, 'SAR')->eligible);
        self::assertTrue($this->service->checkCode('FIFTH', self::TENANT, null, 'JPY')->eligible);
    }

    /** Checking a code before a plan is chosen must not fail on currency. */
    public function testCurrencyIsNotTestedWhenNothingIsBeingPricedYet(): void
    {
        $this->repo->createAmountOff('Fifty riyals', 5000, 'SAR', code: 'SAR50');

        self::assertTrue($this->service->checkCode('SAR50', self::TENANT)->eligible);
    }

    // ── what a stranger is told ──────────────────────────────────────────────

    public function testAnUnknownCodeIsNotFound(): void
    {
        self::assertSame(
            PromotionEligibility::NOT_FOUND,
            $this->service->checkCode('NOPE', self::TENANT)->reason
        );
    }

    public function testARetiredPromotionIsInactiveRatherThanMissing(): void
    {
        $id = $this->repo->createPercentOff('Old', 20, code: 'OLD');
        $this->repo->deactivate($id);

        // The operator needs these apart...
        self::assertSame(
            PromotionEligibility::INACTIVE,
            $this->service->checkCode('OLD', self::TENANT)->reason
        );
    }

    /**
     * ...and the customer must not have them. Telling a stranger that a code
     * exists but is switched off is an invitation to guess at more of them, so
     * both reasons are withheld — and the distinction is flattened at the
     * boundary rather than thrown away here, where it cannot be recovered.
     */
    public function testNeitherMissingNorRetiredIsDisclosedToACustomer(): void
    {
        $id = $this->repo->createPercentOff('Old', 20, code: 'OLD');
        $this->repo->deactivate($id);

        self::assertFalse($this->service->checkCode('OLD', self::TENANT)->isDisclosableToCustomer());
        self::assertFalse($this->service->checkCode('NOPE', self::TENANT)->isDisclosableToCustomer());
        // An expiry, by contrast, is worth saying out loud — it is recoverable
        // information about a real promotion.
        $this->repo->createPercentOff('Gone', 20, code: 'GONE', endsAt: '2026-06-01 00:00:00');
        self::assertTrue($this->service->checkCode('GONE', self::TENANT)->isDisclosableToCustomer());
    }

    // ── refusing nonsense at creation ────────────────────────────────────────

    public function testAWindowThatClosesBeforeItOpensIsRefused(): void
    {
        $this->expectException(PromotionValidationException::class);
        $this->repo->createPercentOff(
            'Impossible',
            20,
            startsAt: '2026-07-01 00:00:00',
            endsAt: '2026-06-01 00:00:00'
        );
    }

    public function testAPercentageOutsideOneToAHundredIsRefused(): void
    {
        foreach ([0, -5, 101] as $bad) {
            try {
                $this->repo->createPercentOff("Bad {$bad}", $bad);
                self::fail("percent_off {$bad} should have been refused");
            } catch (PromotionValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAFixedDiscountNeedsARealCurrency(): void
    {
        $this->expectException(PromotionValidationException::class);
        $this->repo->createAmountOff('Bad', 100, 'RIYAL');
    }

    public function testTwoLivePromotionsCannotShareACode(): void
    {
        $this->repo->createPercentOff('First', 20, code: 'DUP');

        $this->expectException(\PDOException::class);
        $this->repo->createPercentOff('Second', 30, code: 'DUP');
    }

    /**
     * A retired promotion keeps its code in the record without blocking next
     * year's campaign from reusing it — which operators do, with the same
     * seasonal name, every year.
     */
    public function testARetiredCodeCanBeReused(): void
    {
        $old = $this->repo->createPercentOff('Summer 2025', 20, code: 'SUMMER');
        $this->repo->deactivate($old);

        $new = $this->repo->createPercentOff('Summer 2026', 25, code: 'SUMMER');

        self::assertNotNull($this->repo->findById($old), 'the old campaign is still on record');

        $found = $this->service->checkCode('SUMMER', self::TENANT);
        self::assertNotNull($found->promotion);
        self::assertSame($new, $found->promotion['id'], 'the live one is the new campaign');
    }
}
