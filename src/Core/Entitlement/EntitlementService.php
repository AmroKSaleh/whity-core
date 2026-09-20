<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * Resolves and mutates per-tenant entitlements (WC-ent) — the operator-granted
 * capabilities/limits that gate what a tenant may configure or consume.
 *
 * Resolution for {@see effective()}, per known registry key:
 *   tenant_entitlements[key] ?? plan_entitlements[key] ?? EntitlementRegistry::default(key)
 *
 * THE MIDDLE LAYER IS WHAT MAKES A TIER MEAN ANYTHING, and it is read live. It
 * used to be materialised into `tenant_entitlements` when a plan was applied,
 * which turned a tier's definition into a snapshot taken at subscribe time: a
 * change to what "Pro" includes reached nobody who was already on Pro. Reading
 * the bundle at resolution time means marketing edits a tier and every customer
 * on it is affected — which is the point of being able to edit it.
 *
 * That cuts both ways, so REDUCTIONS ARE STOPPED AT THE WRITE, not here. See
 * {@see \Whity\Core\Plan\PlanService::setEntitlement()}: taking something away
 * from a live tier needs an explicit confirmation naming how many tenants it
 * restricts. Guarding it at resolution instead would mean deciding, per request,
 * whether a customer is entitled to yesterday's deal — which is a second copy of
 * the pricing rules and wrong the first time they diverge.
 *
 * The registry DEFAULT remains the baseline (free-tier) grant, and an operator
 * can still raise or lower one tenant by hand without touching their tier. The SYSTEM tenant (id 0) is implicitly UNLIMITED: every bool is granted
 * and every int limit is UNLIMITED, and it has no stored override layer (writing
 * one is rejected, exactly like SettingsService::setTenant).
 *
 * All writes are registry-validated before touching the repository; an invalid
 * value or unknown key raises {@see EntitlementValidationException} and nothing
 * is persisted. All SQL goes through the repository, which binds an explicit
 * `tenant_id` predicate on every statement. Stateless beyond its injected
 * repository — safe for a FrankenPHP worker.
 */
final class EntitlementService
{
    /** The system tenant id; implicitly unlimited, no override layer. */
    public const SYSTEM_TENANT_ID = 0;

    private TenantEntitlementRepository $repo;

    public function __construct(TenantEntitlementRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * The effective, TYPED entitlement map for a tenant: one entry per known
     * key, resolved tenant-override → registry-default, cast to bool|int.
     *
     * The system tenant resolves to an all-unlimited map (bool → true, int →
     * UNLIMITED) without consulting the store.
     *
     * @return array<string, bool|int>
     */
    public function effective(int $tenantId): array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return $this->unlimitedMap();
        }

        $overrides = $this->repo->allForTenant($tenantId);
        $plan = $this->repo->planBundleFor($tenantId);

        $effective = [];
        foreach (EntitlementRegistry::keys() as $key) {
            // THREE LAYERS, MOST SPECIFIC FIRST.
            //
            //   tenant override -- what an operator granted this one customer,
            //                      by hand, for a reason nobody else shares.
            //   tier bundle     -- what they are paying for. Read LIVE, so a
            //                      change to the tier reaches every customer on
            //                      it rather than only the next person to
            //                      subscribe.
            //   registry default-- the baseline a deployment that sells nothing
            //                      still has to answer with.
            //
            // A key the tier does not mention falls through rather than
            // resolving to "nothing": a tier is a list of what it CHANGES, not
            // a complete world, so adding a limit to the registry does not
            // silently revoke it from every existing tier.
            $raw = $overrides[$key]
                ?? $plan[$key]
                ?? EntitlementRegistry::defaultFor($key);

            // A stored value can be invalid if a plugin changed a key's type
            // under data that was already written. Falling back to the default
            // beats throwing on the path of a customer doing their work.
            $effective[$key] = EntitlementRegistry::validate($key, $raw) === null
                ? EntitlementRegistry::cast($key, $raw)
                : EntitlementRegistry::cast($key, EntitlementRegistry::defaultFor($key));
        }

        return $effective;
    }

    /**
     * Whether a bool feature-flag entitlement is granted to the tenant.
     *
     * @throws \InvalidArgumentException When the key is unknown or not a bool.
     */
    public function isGranted(int $tenantId, string $key): bool
    {
        if (EntitlementRegistry::typeFor($key) !== 'bool') {
            throw new \InvalidArgumentException("{$key} is not a boolean entitlement; use limit().");
        }

        $value = $this->effective($tenantId)[$key];

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * The int limit for a quota entitlement (EntitlementRegistry::UNLIMITED when
     * uncapped). The caller compares its usage against this.
     *
     * @throws \InvalidArgumentException When the key is unknown or not an int.
     */
    public function limit(int $tenantId, string $key): int
    {
        if (EntitlementRegistry::typeFor($key) !== 'int') {
            throw new \InvalidArgumentException("{$key} is not an integer entitlement; use isGranted().");
        }

        $value = $this->effective($tenantId)[$key];

        return is_int($value) ? $value : (int) $value;
    }

    /**
     * The keys the tenant has an EXPLICIT operator override for (its effective
     * value comes from the store, not the registry default). Empty for the
     * system tenant. Useful for the admin API to distinguish "set" from "default".
     *
     * @return list<string>
     */
    public function overriddenKeys(int $tenantId): array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return [];
        }

        $overrides = $this->repo->allForTenant($tenantId);
        $out = [];
        foreach (EntitlementRegistry::keys() as $key) {
            if (array_key_exists($key, $overrides)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Set (or clear) a single per-tenant entitlement override, registry-validated.
     *
     * A null value clears the override (the entitlement falls back to the
     * baseline default); a non-null value is validated, normalised, and upserted.
     * The system tenant (0) is implicitly unlimited and has no override layer —
     * writing one is rejected so a meaningless row can never be created.
     *
     * @param int|null $updatedBy The operator profile id making the change (audit).
     * @throws EntitlementValidationException When the key is unknown, the value
     *         invalid, or the tenant is the system tenant.
     */
    public function set(int $tenantId, string $key, ?string $value, ?int $updatedBy = null): void
    {
        if (!EntitlementRegistry::isKnown($key)) {
            throw new EntitlementValidationException($key, "Unknown entitlement key: {$key}");
        }

        if ($tenantId === self::SYSTEM_TENANT_ID) {
            throw new EntitlementValidationException(
                $key,
                'The system tenant is implicitly unlimited and has no entitlement override layer.'
            );
        }

        if ($value === null) {
            $this->repo->delete($tenantId, $key);
            return;
        }

        $reason = EntitlementRegistry::validate($key, $value);
        if ($reason !== null) {
            throw new EntitlementValidationException($key, $reason);
        }

        $this->repo->set($tenantId, $key, EntitlementRegistry::normalize($key, $value), $updatedBy);
    }

    /**
     * The all-unlimited entitlement map for the system tenant.
     *
     * @return array<string, bool|int>
     */
    private function unlimitedMap(): array
    {
        $map = [];
        foreach (EntitlementRegistry::keys() as $key) {
            $map[$key] = EntitlementRegistry::typeFor($key) === 'bool'
                ? true
                : EntitlementRegistry::UNLIMITED;
        }

        return $map;
    }
}
