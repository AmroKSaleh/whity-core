<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;

/**
 * Orchestrates the subscription-plan catalog (WC-plans, ADR 0010): defining plans
 * and their entitlement bundles, and APPLYING a plan to a tenant.
 *
 * Applying a plan MATERIALISES its bundle into the tenant's entitlements: inside a
 * single transaction it resets `tenant_entitlements` to exactly the plan's bundle
 * (each registry key the plan sets → that value; keys it does not set → cleared to
 * the registry default) and records `tenant_plan`. The runtime gate
 * ({@see EntitlementService}) is unchanged — plans are a management + billing
 * anchor, not a second resolution path.
 *
 * All bundle values are validated against {@see EntitlementRegistry}, so every
 * entitlement is automatically plan-able with no plan-side change. Stateless
 * beyond its injected collaborators — safe for a FrankenPHP worker.
 */
final class PlanService
{
    /** System tenant (0) is implicitly unlimited; it is never assigned a plan. */
    private const SYSTEM_TENANT_ID = 0;

    /** The system tenant id — the operator authority tenant (for gate checks). */
    public static function systemTenantId(): int
    {
        return self::SYSTEM_TENANT_ID;
    }

    private PlanRepository $plans;
    private EntitlementService $entitlements;
    private PDO $db;

    public function __construct(PlanRepository $plans, EntitlementService $entitlements, PDO $db)
    {
        $this->plans = $plans;
        $this->entitlements = $entitlements;
        $this->db = $db;
    }

    /**
     * Create a plan. Returns the new plan id.
     *
     * @throws PlanValidationException On an invalid key/name or a duplicate key.
     */
    public function createPlan(
        string $key,
        string $name,
        ?string $description = null,
        bool $isActive = true,
        int $sortOrder = 0,
    ): int {
        $key = strtolower(trim($key));
        $this->assertValidKey($key);
        $name = trim($name);
        if ($name === '') {
            throw new PlanValidationException('name', 'name is required');
        }

        try {
            return $this->plans->createPlan($key, $name, $this->trimOrNull($description), $isActive, $sortOrder);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) {
                throw new PlanValidationException('plan_key', "A plan with key '{$key}' already exists");
            }
            throw $e;
        }
    }

    /**
     * Update mutable plan fields (name / description / is_active / sort_order).
     *
     * @param array{name?: string, description?: ?string, is_active?: bool, sort_order?: int} $fields
     * @return bool True when a row was updated.
     * @throws PlanValidationException When a supplied name is empty.
     */
    public function updatePlan(int $id, array $fields): bool
    {
        if (array_key_exists('name', $fields)) {
            $fields['name'] = trim($fields['name']);
            if ($fields['name'] === '') {
                throw new PlanValidationException('name', 'name cannot be empty');
            }
        }
        if (array_key_exists('description', $fields)) {
            $fields['description'] = $this->trimOrNull($fields['description']);
        }

        return $this->plans->updatePlan($id, $fields) > 0;
    }

    public function deletePlan(int $id): bool
    {
        return $this->plans->deletePlan($id) > 0;
    }

    /**
     * List plans in catalog order.
     *
     * @return list<array<string, mixed>>
     */
    public function listPlans(bool $activeOnly = false): array
    {
        return $this->plans->listPlans($activeOnly);
    }

    /**
     * Set one entitlement in a plan's bundle, validated against the registry.
     *
     * @throws PlanValidationException When the plan is unknown, or the entitlement
     *         key/value is invalid.
     */
    public function setPlanEntitlement(int $planId, string $key, string $value): void
    {
        if ($this->plans->findById($planId) === null) {
            throw new PlanValidationException('plan_id', "Plan {$planId} not found");
        }
        if (!EntitlementRegistry::isKnown($key)) {
            throw new PlanValidationException($key, "Unknown entitlement key: {$key}");
        }
        $reason = EntitlementRegistry::validate($key, $value);
        if ($reason !== null) {
            throw new PlanValidationException($key, $reason);
        }

        $this->plans->setEntitlement($planId, $key, EntitlementRegistry::normalize($key, $value));
    }

    public function removePlanEntitlement(int $planId, string $key): bool
    {
        return $this->plans->deleteEntitlement($planId, $key) > 0;
    }

    /**
     * A plan with its entitlement bundle, cast to typed values. Null when the plan
     * does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function getPlanWithEntitlements(int $planId): ?array
    {
        $plan = $this->plans->findById($planId);
        if ($plan === null) {
            return null;
        }

        $bundle = [];
        foreach ($this->plans->getEntitlements($planId) as $key => $raw) {
            // Skip a stored key that is no longer in the registry (defensive).
            if (EntitlementRegistry::isKnown($key)) {
                $bundle[$key] = EntitlementRegistry::cast($key, $raw);
            }
        }
        $plan['entitlements'] = $bundle;

        return $plan;
    }

    /**
     * Apply a plan to a tenant: MATERIALISE its bundle into the tenant's
     * entitlements (reset to exactly the plan) and record the assignment — all in
     * one transaction. The tenant's effective entitlements become the plan's
     * values, with every unset key falling back to the registry default.
     *
     * @throws PlanValidationException When the plan is unknown or the tenant is the
     *         system tenant (implicitly unlimited — never assigned a plan).
     */
    public function applyToTenant(int $planId, int $tenantId, ?int $appliedBy = null): void
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            throw new PlanValidationException('tenant_id', 'The system tenant is implicitly unlimited and has no plan');
        }
        if ($this->plans->findById($planId) === null) {
            throw new PlanValidationException('plan_id', "Plan {$planId} not found");
        }

        $bundle = $this->plans->getEntitlements($planId);

        $this->db->beginTransaction();
        try {
            // Deterministic reset: every registry key the plan sets → that value;
            // every key it does not set → cleared (null) to the registry default.
            foreach (EntitlementRegistry::keys() as $key) {
                $value = array_key_exists($key, $bundle) ? $bundle[$key] : null;
                $this->entitlements->set($tenantId, $key, $value, $appliedBy);
            }
            $this->plans->setTenantPlan($tenantId, $planId, $appliedBy);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * The tenant's current plan assignment enriched with the plan row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getTenantPlan(int $tenantId): ?array
    {
        $assignment = $this->plans->getTenantPlan($tenantId);
        if ($assignment === null) {
            return null;
        }
        $assignment['plan'] = $assignment['plan_id'] !== null
            ? $this->plans->findById($assignment['plan_id'])
            : null;

        return $assignment;
    }

    /**
     * @throws PlanValidationException When the key is empty or malformed.
     */
    private function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new PlanValidationException('plan_key', 'plan_key is required');
        }
        if (strlen($key) > 64) {
            throw new PlanValidationException('plan_key', 'plan_key must be 64 characters or fewer');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key) !== 1) {
            throw new PlanValidationException(
                'plan_key',
                'plan_key must be lowercase alphanumeric, starting with a letter or digit (hyphens/underscores allowed)'
            );
        }
    }

    private function trimOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
