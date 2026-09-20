<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\Plan\PlanValidationException;

/**
 * A tier that can be edited, and edits that reach the people paying for it.
 *
 * ── The bug these close ────────────────────────────────────────────────────
 *
 * Three tiers at 5, 15 and 100 JOD granted identical access, because nothing
 * ever read a plan's bundle at the moment access was decided. Applying a plan
 * COPIED its values into the tenant's own overrides — the most specific layer
 * there is — so every subscriber carried a snapshot of their tier taken on the
 * day they joined. Marketing could change what "Pro" includes and not one
 * existing Pro customer would be affected.
 *
 * The first test here fails against that old behaviour, which is the point of
 * it: it is the difference between a price list and a product.
 *
 * ── And the risk the fix introduces ────────────────────────────────────────
 *
 * Live means live in both directions. A typo in the edit box would restrict
 * every paying customer on the tier instantly, so a REDUCTION is refused unless
 * somebody confirms it, and the refusal says how many workspaces it affects.
 * Those tests matter as much as the propagation one — they are what makes
 * handing this screen to a marketing department a reasonable thing to do.
 */
final class PlanTierEntitlementsRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private EntitlementService $entitlements;
    private PlanService $service;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");

        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->service = new PlanService(new PlanRepository($this->pdo), $this->entitlements, $this->pdo);
    }

    // ── The feature ─────────────────────────────────────────────────────────

    /**
     * THE ONE THIS EXISTS FOR. Marketing raises what Pro includes; the customer
     * who subscribed last month gets it, without anybody re-applying anything.
     */
    public function testEditingATierReachesTenantsAlreadyOnIt(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->service->applyToTenant($pro, self::TENANT_A);
        self::assertSame(10, $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX));

        $this->service->setPlanEntitlement($pro, EntitlementRegistry::MEMBERS_MAX, '25');

        self::assertSame(
            25,
            $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX),
            'A tenant already on the tier must see the new figure — that is what makes it editable.'
        );
    }

    /** A promotion switching a feature on reaches existing subscribers too. */
    public function testTurningAFeatureOnForATierGrantsItToExistingSubscribers(): void
    {
        $pro = $this->tier('pro', []);
        $this->service->applyToTenant($pro, self::TENANT_A);
        self::assertFalse($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::SSO_TENANT_IDP));

        $this->service->setPlanEntitlement($pro, EntitlementRegistry::SSO_TENANT_IDP, 'true');

        self::assertTrue($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::SSO_TENANT_IDP));
    }

    /**
     * TIERS DO NOT LEAK INTO EACH OTHER. Editing Pro must not move anybody on
     * Plus — obvious, and precisely the thing a join written slightly wrong
     * would get wrong while every single-tenant test stayed green.
     */
    public function testEditingOneTierDoesNotTouchAnother(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $plus = $this->tier('plus', [EntitlementRegistry::MEMBERS_MAX => '3']);
        $this->service->applyToTenant($pro, self::TENANT_A);
        $this->service->applyToTenant($plus, self::TENANT_B);

        $this->service->setPlanEntitlement($pro, EntitlementRegistry::MEMBERS_MAX, '25');

        self::assertSame(25, $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX));
        self::assertSame(3, $this->entitlements->limit(self::TENANT_B, EntitlementRegistry::MEMBERS_MAX));
    }

    // ── Precedence ──────────────────────────────────────────────────────────

    /**
     * AN OPERATOR'S OWN GRANT OUTRANKS THE TIER. A customer given something by
     * hand — while a deal was negotiated, or to unblock them — must not have it
     * taken back by an unrelated edit to their tier.
     */
    public function testATenantOverrideBeatsTheTier(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->service->applyToTenant($pro, self::TENANT_A);
        $this->entitlements->set(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX, '99');

        $this->service->setPlanEntitlement($pro, EntitlementRegistry::MEMBERS_MAX, '25');

        self::assertSame(99, $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX));
    }

    /**
     * A TIER IS A LIST OF WHAT IT CHANGES, not a complete world. A key it says
     * nothing about falls through to the baseline — so adding a new limit to
     * the registry does not silently revoke it from every tier that predates it.
     */
    public function testAKeyTheTierDoesNotMentionFallsThroughToTheBaseline(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->service->applyToTenant($pro, self::TENANT_A);

        self::assertSame(
            EntitlementRegistry::UNLIMITED,
            $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::DEVICES_MAX)
        );
    }

    /** A tenant on no tier at all is unchanged — the free-tier baseline. */
    public function testATenantWithNoTierGetsTheBaseline(): void
    {
        self::assertSame(
            EntitlementRegistry::UNLIMITED,
            $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX)
        );
        self::assertFalse($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::SSO_TENANT_IDP));
    }

    // ── The downgrade guard ─────────────────────────────────────────────────

    /**
     * A REDUCTION IS REFUSED, AND THE REFUSAL CARRIES THE COUNT. Live
     * propagation makes the edit box a place where a typo restricts paying
     * customers instantly; this is what stands between the two.
     */
    public function testReducingALiveTierIsRefusedAndNamesWhoItAffects(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '25']);
        $this->service->applyToTenant($pro, self::TENANT_A);
        $this->service->applyToTenant($pro, self::TENANT_B);

        try {
            $this->service->setPlanEntitlement($pro, EntitlementRegistry::MEMBERS_MAX, '5');
            self::fail('Reducing a live tier must be refused.');
        } catch (PlanValidationException $e) {
            self::assertStringContainsString('2 workspace', $e->getMessage());
        }

        self::assertSame(25, $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX));
    }

    /** Confirmed, it applies — and reaches the live tenants immediately. */
    public function testAConfirmedReductionApplies(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '25']);
        $this->service->applyToTenant($pro, self::TENANT_A);

        $this->service->setPlanEntitlement($pro, EntitlementRegistry::MEMBERS_MAX, '5', confirmReduction: true);

        self::assertSame(5, $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX));
    }

    /**
     * UNLIMITED IS THE CEILING, NOT THE FLOOR. -1 means no cap, so moving from
     * it to any finite number is a reduction — and a guard comparing the numbers
     * naively would read it as a generous increase and let somebody cap every
     * tenant on the tier.
     */
    public function testMovingFromUnlimitedToAFiniteCapIsAReduction(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '-1']);
        $this->service->applyToTenant($pro, self::TENANT_A);

        $this->expectException(PlanValidationException::class);
        $this->service->setPlanEntitlement($pro, EntitlementRegistry::MEMBERS_MAX, '1000');
    }

    /** And the other way is a grant, so it needs no confirmation. */
    public function testMovingToUnlimitedIsNeverAReduction(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '10']);
        $this->service->applyToTenant($pro, self::TENANT_A);

        $this->service->setPlanEntitlement($pro, EntitlementRegistry::MEMBERS_MAX, '-1');

        self::assertSame(
            EntitlementRegistry::UNLIMITED,
            $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX)
        );
    }

    /** Taking a feature away is a reduction just as much as lowering a number. */
    public function testRevokingAFeatureFromALiveTierIsRefused(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::SSO_TENANT_IDP => 'true']);
        $this->service->applyToTenant($pro, self::TENANT_A);

        $this->expectException(PlanValidationException::class);
        $this->service->setPlanEntitlement($pro, EntitlementRegistry::SSO_TENANT_IDP, 'false');
    }

    /**
     * A TIER NOBODY IS ON IS NOT GUARDED. Pricing a new tier means setting every
     * value down from an unlimited baseline, and there is no customer to
     * protect. Guarding it would teach whoever builds a price list to pass the
     * confirmation flag by reflex — which is how a guard stops working for the
     * case it exists for.
     */
    public function testPricingAnEmptyTierNeedsNoConfirmation(): void
    {
        $new = $this->service->createPlan('starter', 'Starter');

        $this->service->setPlanEntitlement($new, EntitlementRegistry::MEMBERS_MAX, '3');
        $this->service->setPlanEntitlement($new, EntitlementRegistry::DOCUMENTS_RENDER_PER_DAY, '5');

        self::assertSame(
            ['members.max' => '3', 'documents.render.per_day' => '5'],
            (new PlanRepository($this->pdo))->getEntitlements($new)
        );
    }

    /**
     * SWITCHING TIERS CLEARS THE PREVIOUS TIER'S COPIES. The old code left
     * per-tenant rows behind on every apply; if any survived a move they would
     * outrank the new tier and the customer would keep paying for one thing and
     * receiving another.
     */
    public function testMovingBetweenTiersLeavesNothingBehind(): void
    {
        $pro = $this->tier('pro', [EntitlementRegistry::MEMBERS_MAX => '25']);
        $plus = $this->tier('plus', [EntitlementRegistry::MEMBERS_MAX => '3']);

        $this->service->applyToTenant($pro, self::TENANT_A);
        $this->service->applyToTenant($plus, self::TENANT_A);

        self::assertSame(3, $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX));

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tenant_entitlements WHERE tenant_id = :t');
        $statement->execute([':t' => self::TENANT_A]);
        self::assertSame(0, (int) $statement->fetchColumn(), 'A tier assignment must not write per-tenant overrides.');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $bundle
     */
    private function tier(string $key, array $bundle): int
    {
        $id = $this->service->createPlan($key, ucfirst($key));
        foreach ($bundle as $entitlement => $value) {
            $this->service->setPlanEntitlement($id, $entitlement, $value);
        }

        return $id;
    }
}
