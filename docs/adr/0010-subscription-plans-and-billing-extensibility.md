# ADR 0010: Subscription plans & billing extensibility

- **Status:** Accepted
- **Date:** 2026-07-11
- **Task / Issue:** WC-plans
- **Deciders:** Amro Saleh

## Context

ADR 0009-adjacent work delivered per-tenant **entitlements** (`EntitlementRegistry`
+ `tenant_entitlements` + `EntitlementService`): operator-granted, per-tenant
feature flags and quotas that gate what a tenant may use. Today an operator sets
each entitlement on each tenant individually.

We want **subscription plans** — named bundles like "Free", "Pro", "Enterprise" —
that an operator applies to a tenant in one step. And we want the model to grow,
without re-architecture, into a full payments system: per-plan **pricing**
(monthly/yearly, multi-currency), **promo codes** and **early-bird** discounts,
free **trials**, and **subscriptions** with lifecycle (trialing / active /
past-due / canceled). The forces:

- **Don't disturb the runtime gate.** `EntitlementService::isGranted()/limit()` is
  already the single access-control choke point across the app. Plans must not
  introduce a second resolution path that every consumer has to learn.
- **Billing correctness / auditability.** What a customer is entitled to must be a
  stable fact, not something that silently changes when we re-tune a plan's
  contents later.
- **Separation of concerns.** "What a plan grants" (entitlements) and "what a plan
  costs" (pricing/discounts) are different lifecycles that evolve independently.
- **Tenant isolation** (#1 invariant) and the migration-pinned table registries.

## Decision

We will model plans as a **catalog layer that materialises into the existing
entitlement layer**, with pricing/promo/subscription as **future tables that
reference the plan catalog and never touch the entitlement engine**.

**Three layers, cleanly separated:**

```
ENTITLEMENTS (built)        PLANS (this ADR)               BILLING (future — attaches here)
EntitlementRegistry   ◄──   plan_entitlements              plan_prices  (plan_id, currency,
tenant_entitlements         (plan_id, ent_key, value)        amount, interval)
EntitlementService          plans (key,name,active,order)   promo_codes  (code, kind, amount,
  isGranted()/limit()       tenant_plan (tenant→plan)         valid_from/to, max_uses, plan_ids)
  ← the runtime gate        PlanService.applyToTenant()     subscriptions (tenant, plan, status,
                              = materialise entitlements       period, promo_code, trial_end)
```

- **`plans`** — a global platform catalog row per plan (`plan_key`, name,
  description, `is_active`, `sort_order`). Deliberately carries **no price
  columns**; it is the anchor that every future billing table references. Global
  catalog (no `tenant_id`), like `permissions` — not registered in
  SanctionedGlobalTables (the predicate guard only polices tenant-owned tables).
- **`plan_entitlements`** — a plan's bundle: `(plan_id, entitlement_key, value)`,
  UNIQUE per pair. Keys/values are validated against `EntitlementRegistry`, so
  every entitlement is automatically plan-able with no plan-side change.
- **`tenant_plan`** — which plan a tenant is currently on (`tenant_id` PK →
  `plan_id`, `assigned_at`, `assigned_by`). Tenant-owned; the anchor a future
  `subscriptions` row supersedes.
- **`PlanService::applyToTenant(planId, tenantId)`** — **materialises**: inside one
  transaction it resets the tenant's `tenant_entitlements` to exactly the plan's
  bundle (each registry key the plan sets → that value; keys the plan does not set
  → cleared to the registry default) and records `tenant_plan`. The runtime gate
  is unchanged — it still reads `tenant_entitlements`.

**Materialise, not live-link.** Applying a plan snapshots its entitlement values
onto the tenant. Re-tuning a plan's contents later does NOT silently change what
existing tenants have; they get the new bundle only when the plan is re-applied
(or their subscription renews, in the future billing layer). This is the
billing-correct default and keeps the runtime path single-source.

**Apply is a deterministic reset** to the plan's bundle. A bespoke per-tenant
override (e.g. "+10 seats for this one customer") is applied *after* via the
entitlements API; it is intentionally cleared by a subsequent plan (re-)apply,
because "this tenant is now exactly on Pro" must be unambiguous.

## Alternatives Considered

- **Resolve-through-plan** (effective = tenant override ?? plan value ?? default).
  Plan edits propagate live, but adds a second resolution layer every consumer
  must honour and makes "what did this customer buy" time-dependent. Rejected —
  breaks billing auditability and the single-choke-point gate.
- **Price/entitlements on one `plans` row.** Conflates two lifecycles; a pricing
  change would rev the entitlement bundle and vice-versa. Rejected in favour of
  separate `plan_prices` (future).
- **Plans as reserved registry keys** (like GLOBAL_ONLY settings). No room for
  pricing/promo/subscription to attach. Rejected.
- **Do nothing** — operators keep setting entitlements per-tenant by hand; no path
  to tiers or billing.

## Consequences

### Positive

- Plans are pure convenience + a billing anchor; the entitlement runtime gate is
  untouched and remains the single access-control path.
- Pricing, promo codes (incl. early-bird via `valid_from/valid_to` + `max_uses`),
  trials, and subscriptions are **additive future tables** hanging off `plans` /
  `tenant_plan` — no refactor of entitlements or plans required.
- New entitlements are automatically plan-able (validated via the registry).
- Customer entitlements are a stable, auditable snapshot.

### Negative / Trade-offs

- Re-tuning a plan requires re-applying it (or a renewal) to affect existing
  tenants — by design, but an operator must know it.
- `applyToTenant` clears bespoke per-tenant overrides; they must be re-applied
  after a plan change. Documented, and surfaced by the (future) admin UI.

### Impact on existing conventions

- New tenant-owned table `tenant_plan` → registered in `TenantOwnedTables`; the
  predicate guard polices it. `plans` / `plan_entitlements` are global catalogs
  (no `tenant_id`), unregistered like `permissions`.
- Plan management is an operator (system-tenant) capability — the admin API
  (follow-up PR) reuses the `permission + tenantId === 0` gate.
- Reinforces the entitlements layer (ADR-adjacent, [entitlements & storage]) as
  the sole runtime gate.

## References

- Entitlements & per-tenant storage (`EntitlementRegistry`, `EntitlementService`,
  `tenant_entitlements`).
- Future: `plan_prices`, `promo_codes`, `subscriptions` (this ADR's extension
  points); GTM notes (billing deferred — this is the layer billing will drive).
