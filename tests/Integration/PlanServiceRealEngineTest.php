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
 * Real-engine tests for {@see PlanService} (WC-plans, ADR 0010): plan creation +
 * validation, bundle validation against the entitlement registry, and — the core
 * behaviour — applying a plan MATERIALISES its bundle into the tenant's
 * entitlements (a deterministic reset) and records tenant_plan, all tenant-scoped.
 */
final class PlanServiceRealEngineTest extends TestCase
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

    private function proPlan(): int
    {
        $id = $this->service->createPlan('pro', 'Pro', 'Everything', true, 10);
        $this->service->setPlanEntitlement($id, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, 'true');
        $this->service->setPlanEntitlement($id, EntitlementRegistry::MEMBERS_MAX, '50');
        return $id;
    }

    public function testCreatePlanRejectsDuplicateKey(): void
    {
        $this->service->createPlan('free', 'Free');
        $this->expectException(PlanValidationException::class);
        $this->service->createPlan('free', 'Free again');
    }

    public function testCreatePlanRejectsMalformedKey(): void
    {
        $this->expectException(PlanValidationException::class);
        $this->service->createPlan('Pro Plan!', 'Bad');
    }

    public function testSetPlanEntitlementRejectsUnknownKey(): void
    {
        $id = $this->service->createPlan('pro', 'Pro');
        $this->expectException(PlanValidationException::class);
        $this->service->setPlanEntitlement($id, 'made.up.key', 'true');
    }

    public function testSetPlanEntitlementRejectsInvalidValue(): void
    {
        $id = $this->service->createPlan('pro', 'Pro');
        $this->expectException(PlanValidationException::class);
        $this->service->setPlanEntitlement($id, EntitlementRegistry::MEMBERS_MAX, 'lots');
    }

    public function testGetPlanWithEntitlementsReturnsTypedBundle(): void
    {
        $id = $this->proPlan();
        $plan = $this->service->getPlanWithEntitlements($id);

        self::assertNotNull($plan);
        self::assertSame('pro', $plan['plan_key']);
        self::assertTrue($plan['entitlements'][EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertSame(50, $plan['entitlements'][EntitlementRegistry::MEMBERS_MAX]);
    }

    public function testApplyToTenantMaterialisesBundle(): void
    {
        $id = $this->proPlan();
        $this->service->applyToTenant($id, self::TENANT_A, 999);

        // The runtime gate now reflects the plan.
        self::assertTrue($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
        self::assertSame(50, $this->entitlements->limit(self::TENANT_A, EntitlementRegistry::MEMBERS_MAX));

        // And the assignment is recorded.
        $assignment = $this->service->getTenantPlan(self::TENANT_A);
        self::assertNotNull($assignment);
        self::assertSame($id, $assignment['plan_id']);
        self::assertSame('pro', $assignment['plan']['plan_key']);
    }

    public function testApplyIsADeterministicResetClearingBespokeOverrides(): void
    {
        // A bespoke override the operator set by hand, NOT part of the plan.
        $this->entitlements->set(self::TENANT_A, EntitlementRegistry::SSO_TENANT_IDP, 'true', 1);
        self::assertTrue($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::SSO_TENANT_IDP));

        // 'pro' sets storage + members but NOT sso.tenant_idp → applying it resets
        // sso.tenant_idp back to the registry default.
        $this->service->applyToTenant($this->proPlan(), self::TENANT_A, 1);

        self::assertFalse($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::SSO_TENANT_IDP));
        self::assertTrue($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
    }

    public function testReapplyingADifferentPlanSwitchesEntitlements(): void
    {
        $pro = $this->proPlan();
        $free = $this->service->createPlan('free', 'Free'); // empty bundle → all defaults

        $this->service->applyToTenant($pro, self::TENANT_A, 1);
        self::assertTrue($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));

        $this->service->applyToTenant($free, self::TENANT_A, 1);
        self::assertFalse($this->entitlements->isGranted(self::TENANT_A, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
        $assignment = $this->service->getTenantPlan(self::TENANT_A);
        self::assertNotNull($assignment);
        self::assertSame($free, $assignment['plan_id']);
    }

    public function testApplyToSystemTenantIsRejected(): void
    {
        $this->expectException(PlanValidationException::class);
        $this->service->applyToTenant($this->proPlan(), 0, 1);
    }

    public function testApplyUnknownPlanIsRejected(): void
    {
        $this->expectException(PlanValidationException::class);
        $this->service->applyToTenant(99999, self::TENANT_A, 1);
    }

    public function testTenantPlanIsIsolated(): void
    {
        $this->service->applyToTenant($this->proPlan(), self::TENANT_A, 1);

        self::assertNotNull($this->service->getTenantPlan(self::TENANT_A));
        self::assertNull($this->service->getTenantPlan(self::TENANT_B), "Tenant B must not see tenant A's plan");
        self::assertFalse($this->entitlements->isGranted(self::TENANT_B, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
    }
}
