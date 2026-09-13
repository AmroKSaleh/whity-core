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

    /**
     * Delete a tier, but only one that nothing has ever used.
     *
     * THIS USED TO BE AN UNCONDITIONAL `DELETE FROM plans`, and the schema made
     * that look safe: `tenant_plan.plan_id` and `invoices.plan_id` are both
     * `ON DELETE SET NULL`, so tidying an old tier detached its live subscribers
     * and blanked it out of paid invoices — silently, with no cascade refusal
     * and nothing in a log. The evidence appeared months later as a report that
     * stopped adding up, by which time the tier's name was gone.
     *
     * @throws PlanValidationException When the tier is unknown, or something
     *         still points at it. The reason names the counts and the remedy.
     */
    public function deletePlan(int $id): bool
    {
        if ($this->plans->findById($id) === null) {
            return false;
        }

        $usage = $this->plans->usageFor($id);
        $reason = $usage->refusalReason();
        if ($reason !== null) {
            throw new PlanValidationException('plan_id', $reason);
        }

        return $this->plans->deletePlan($id) > 0;
    }

    /** What still points at a tier — for a confirmation, or to decide what to offer. */
    public function usageFor(int $planId): PlanUsage
    {
        return $this->plans->usageFor($planId);
    }

    /**
     * Move every workspace off one tier onto another.
     *
     * THE REMEDY THAT MAKES RETIRING A TIER POSSIBLE WITHOUT ABANDONING ANYONE.
     * A tier with subscribers cannot be deleted and should not simply be
     * switched off underneath them — deactivating it stops it being SOLD but
     * leaves those workspaces on a tier nobody maintains, quietly diverging from
     * every other customer as the catalogue moves on.
     *
     * REFUSES TO MOVE A TIER ONTO ITSELF, and refuses an unknown destination:
     * both would report a cheerful "moved 0" or silently strand everyone.
     *
     * THE DESTINATION'S ACTIVE FLAG IS NOT CHECKED, deliberately. Consolidating
     * two retired tiers into one retired tier is a legitimate tidy-up, and
     * refusing it would force an operator to reactivate a tier — putting it back
     * on sale — as a step in cleaning up.
     *
     * @return int How many workspaces moved.
     *
     * @throws PlanValidationException When either tier is unknown or they are
     *         the same tier.
     */
    public function moveSubscribers(int $fromPlanId, int $toPlanId, ?int $movedBy = null): int
    {
        if ($fromPlanId === $toPlanId) {
            throw new PlanValidationException('to_plan_id', 'Choose a different tier to move these workspaces to.');
        }
        if ($this->plans->findById($fromPlanId) === null) {
            throw new PlanValidationException('plan_id', "Plan {$fromPlanId} not found");
        }
        if ($this->plans->findById($toPlanId) === null) {
            throw new PlanValidationException('to_plan_id', "Plan {$toPlanId} not found");
        }

        return $this->plans->moveSubscribers($fromPlanId, $toPlanId, $movedBy);
    }

    /**
     * Take a tier off sale without deleting it.
     *
     * The honest end state for a tier that has customers or history: it stops
     * being offered, and every row pointing at it keeps pointing at something
     * that still has a name.
     */
    public function retirePlan(int $id): bool
    {
        return $this->updatePlan($id, ['is_active' => false]);
    }

    /**
     * The workspaces on a tier, for recording a move tenant by tenant.
     *
     * @return list<int>
     */
    public function subscriberTenantIds(int $planId): array
    {
        return $this->plans->subscriberTenantIds($planId);
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
    public function setPlanEntitlement(
        int $planId,
        string $key,
        string $value,
        bool $confirmReduction = false,
    ): void {
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

        $normalized = EntitlementRegistry::normalize($key, $value);
        $this->assertNotASilentReduction($planId, $key, $normalized, $confirmReduction);

        $this->plans->setEntitlement($planId, $key, $normalized);
    }

    /**
     * Refuse a change that takes something away from live customers, unless
     * somebody has said they mean it.
     *
     * ── Why the guard is here and not at resolution ────────────────────────
     *
     * A tier's bundle is read LIVE now, so an edit reaches every tenant on that
     * tier immediately. That is the entire point — a promotion should not need
     * anybody to re-apply plans one at a time — but it makes the edit box a
     * place where a typo silently restricts paying customers. Nothing about the
     * resolution can tell a deliberate repricing from a slip; only the person
     * typing knows, so this is where they are asked.
     *
     * It refuses rather than warns, and the message CARRIES THE COUNT. "This
     * affects 40 workspaces" is a sentence somebody reads; "are you sure?" is
     * one they click through.
     *
     * A TIER WITH NOBODY ON IT IS NOT GUARDED. Pricing a new tier means setting
     * every value from its default, which is a reduction more often than not —
     * and there is no customer to protect. Guarding it would train whoever
     * builds a price list to pass the confirmation flag by reflex, which is how
     * the guard stops working for the case it exists for.
     *
     * @throws PlanValidationException When the change reduces a live tier and
     *         the reduction was not confirmed.
     */
    private function assertNotASilentReduction(
        int $planId,
        string $key,
        string $normalized,
        bool $confirmed,
    ): void {
        if ($confirmed) {
            return;
        }

        $affected = $this->plans->countTenantsOnPlan($planId);
        if ($affected === 0) {
            return;
        }

        // What a tenant on this tier resolves to TODAY for this key. The
        // comparison is against the tier's own current answer including its
        // fallthrough to the baseline — not against "unset", which would read
        // every first-time grant as a reduction from nothing.
        $bundle = $this->plans->getEntitlements($planId);
        $current = $bundle[$key] ?? EntitlementRegistry::defaultFor($key);

        if (EntitlementRegistry::definition($key)->grantsAtLeast($normalized, $current)) {
            return;
        }

        throw new PlanValidationException(
            $key,
            sprintf(
                'This reduces %s from "%s" to "%s" for %d workspace(s) already on this plan, '
                . 'and takes effect for them immediately. Confirm the reduction to apply it.',
                $key,
                $current,
                $normalized,
                $affected
            )
        );
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
     * Put a tenant on a plan.
     *
     * IT NO LONGER MATERIALISES THE BUNDLE, and that is the change that makes a
     * tier editable. It used to copy every value from the plan into
     * `tenant_entitlements` inside a transaction, which looked tidy and had one
     * consequence nobody wanted: those copies are PER-TENANT OVERRIDES, the
     * most specific layer there is. Every subscriber therefore carried a frozen
     * snapshot of their tier taken on the day they joined, outranking the tier
     * itself — so marketing could change what "Pro" includes and not one
     * existing Pro customer would notice. Worse, an operator's genuine
     * per-tenant grant became indistinguishable from a copied plan value, so
     * re-applying a plan silently erased it.
     *
     * Now the assignment is just that: a row saying which plan this tenant is
     * on. {@see \Whity\Core\Entitlement\EntitlementService::effective()} reads
     * the bundle live, so the tier stays the source of truth for everyone on it
     * and an override means what it says again.
     *
     * ANY OVERRIDES LEFT BY THE OLD BEHAVIOUR ARE CLEARED as the tenant moves
     * plans — migration 151 clears the historical ones, and this stops new ones
     * appearing. Without that, tenants who subscribed under the old code would
     * keep their snapshot for good and the tier would never reach them.
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

        $this->db->beginTransaction();
        try {
            // Clear any per-tenant copies a previous plan left behind, so the
            // new tier is what this tenant resolves to. Deliberately a RESET of
            // the override layer rather than a write of the new bundle: the
            // bundle is read live, and writing it here is exactly the bug this
            // method used to have.
            foreach (EntitlementRegistry::keys() as $key) {
                $this->entitlements->set($tenantId, $key, null, $appliedBy);
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
