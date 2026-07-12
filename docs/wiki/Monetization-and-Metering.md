# Monetization & Metering

How whity decides **what a tenant may use**, **how much**, and **whether their
subscription still entitles them to it**. This guide ties together four layers that
were built to stack cleanly on top of one another:

```
ENTITLEMENTS  →  PLANS  →  SUBSCRIPTIONS  →  PAYMENT WALL       (feature/quota gate + billing state)
                    ↘                          RATE LIMITS       (per-request throughput, plan-driven)
```

The design goals throughout: **one runtime access gate** (no second resolution
path), **billing-correct auditability** (what a customer has is a stable fact),
**provider-agnostic** (no PSP is assumed), and **each client is different** (nothing
load-bearing is hardcoded — it's a setting/entitlement with a smart default).

See also: [ADR 0010](../adr/0010-subscription-plans-and-billing-extensibility.md)
(plans/billing model) and
[ADR 0011](../adr/0011-payment-wall-and-per-tenant-enforcement.md) (enforcement).

---

## 1. Entitlements — the runtime gate

**What:** per-tenant feature flags and quotas. A boolean grants a capability; an
integer sets a limit (`-1` = unlimited).

- `EntitlementRegistry` — the code catalogue of entitlement keys, their type
  (bool/int), and their **free-tier baseline default** (an unregistered key is
  never granted).
- `tenant_entitlements` — tenant-owned rows holding per-tenant overrides.
- `EntitlementService` — the **single access-control choke point**:
  - `isGranted(key)` — boolean capability check.
  - `limit(key)` — integer quota (`-1` = unlimited).
  - `effective(key)` — resolved value: **tenant override ?? registry default**.
- **The system tenant (id 0) is implicitly unlimited** — it is the operator.

Every feature that needs gating asks `EntitlementService`, and only that. Plans and
subscriptions layer on top **without** introducing a second thing consumers must
check.

## 2. Plans — a catalog that materialises into entitlements

**What:** named bundles ("Free", "Pro", "Enterprise") an operator applies in one
step. See [ADR 0010](../adr/0010-subscription-plans-and-billing-extensibility.md).

- `plans` — global catalog (`plan_key`, name, `is_active`, `sort_order`).
  **Deliberately carries no price columns** — it is the anchor future billing tables
  reference.
- `plan_entitlements` — a plan's bundle: `(plan_id, entitlement_key, value)`,
  validated against the `EntitlementRegistry`. New entitlements are automatically
  plan-able.
- `tenant_plan` — which plan a tenant is on (tenant-owned).
- `PlanService::applyToTenant(planId, tenantId)` — **materialises** the plan:
  in one transaction it resets `tenant_entitlements` to exactly the plan's bundle
  (keys the plan sets → that value; keys it doesn't → registry default) and records
  `tenant_plan`.

**Materialise, not live-link.** Applying a plan snapshots its values onto the
tenant. Re-tuning a plan later does **not** silently change existing tenants — they
get the new bundle only on re-apply (or, in future, on renewal). This is the
billing-correct default and keeps the runtime path single-source. A bespoke
per-tenant override ("+10 seats for this one customer") is applied *after* via the
entitlements API and is intentionally cleared by a subsequent plan re-apply.

## 3. Subscriptions — lifecycle state on the tenant↔plan anchor

**What:** the lifecycle of a tenant's paid access. See
[ADR 0011](../adr/0011-payment-wall-and-per-tenant-enforcement.md).

Migration `057` adds subscription state to the existing `tenant_plan` row (not a new
table): `status`, `current_period_end`, `grace_until`, `enforcement_mode`,
`external_ref`.

- `status` — `active` / `trialing` / `past_due` / `canceled` (etc.).
- `grace_until` — a `past_due` tenant keeps access until this deadline (driven by the
  `billing.grace_days` setting).
- `external_ref` — **the webhook seam.** An opaque provider id a future PSP webhook
  writes; **nothing in core reads it today** — billing collection is provider-agnostic
  and out of band.
- `SubscriptionService` — stores/reads this state and answers the wall's question
  via `decide(tenantId, isWrite)`.

**Payment is external state, not a payment we process.** whity is told the status
(an admin sets it, or a future webhook writes it) and enforces on it. There is no
Stripe integration; billing *collection* (`plan_prices`, `promo_codes`/early-bird,
the PSP webhook) is a deferred additive layer that hangs off `plans`/`tenant_plan`
(ADR 0010's extension points) and never touches the entitlement engine.

## 4. Payment wall — enforcement at the boundary

`PaymentWall` middleware (in the `HttpKernel` pipeline) calls
`SubscriptionService::decide()` and short-circuits a lapsed tenant with **402 Payment
Required** + an `X-Subscription-Status` header (and an optional `Link` to the billing
URL). See [ADR 0011](../adr/0011-payment-wall-and-per-tenant-enforcement.md).

**Enforcement mode is a per-tenant operator setting**, resolved as:

> per-tenant `tenant_plan.enforcement_mode` **??** global `billing.enforcement_default`
> setting **??** `warn`

| Mode | Behaviour when a tenant is lapsed |
|------|-----------------------------------|
| `off` | Never wall (single-customer / internal deployments). |
| `warn` | Allow everything; stamp `X-Subscription-Status` so the UI can nudge. **Default.** |
| `block_writes` | Reads allowed; mutations get 402. |
| `block_all` | Fully walled (402). |

An `active`/`trialing` tenant, or a `past_due` tenant still within `grace_until`, is
always allowed regardless of mode.

**Hard exemptions — lockout is structurally impossible:**

1. **Public / unauthenticated** requests (no resolved tenant) → pass.
2. **The system tenant (id 0)** → always pass. *The operator is never walled* — an
   admin can always upgrade a tenant even if billing lapsed externally.
3. **Billing / subscription-management routes** (e.g. `/api/v1/subscription`) →
   always reachable, so an admin can inspect and fix billing state while walled.

The whole layer is a no-op unless `BILLING_WALL_ENABLED` is set — a deployment opts
in. `BILLING_URL` supplies the 402 `Link` target.

## 5. Rate limits — throughput, plan-driven

`RateLimitMiddleware` + `RateLimitRule` meter requests across several scopes, backed
by a Postgres `shared_store` so limits hold across FrankenPHP workers:

- **`ip`** / **`tenant`** / **`principal`** — fixed-window counters per scope.
- **`tenantEntitled`** — the per-tenant request-per-minute cap reads the tenant's
  **`ratelimit.rpm` entitlement** (via `RateLimitRule::limitFor()`), so throughput
  **scales with the plan** — a "Pro" tenant simply has a higher `ratelimit.rpm`
  entitlement. Nothing about the number lives in code.
- **`platform`** — a global safety cap protecting the deployment as a whole.

This is the same principle as the wall: the *number* is an entitlement/setting, the
*mechanism* is generic. A single-customer sovereign deployment and a busy multi-tenant
SaaS get the right behaviour from the same code with different data.

---

## Operator quick reference

| I want to… | Do this |
|---|---|
| Give a tenant a tier | `PlanService::applyToTenant(plan, tenant)` (materialises entitlements). |
| Bump one tenant's quota without a plan | Set the entitlement directly on that tenant (cleared on next plan re-apply). |
| Turn on billing enforcement | Set `BILLING_WALL_ENABLED`; set `billing.enforcement_default` (global) and/or per-tenant `enforcement_mode`. |
| Give a lapsed tenant a grace period | `billing.grace_days` (global) + set the tenant `past_due` with `grace_until`. |
| Raise a tenant's request rate | Raise its `ratelimit.rpm` entitlement (or put it on a higher plan). |
| Never wall the operator | Nothing — the system tenant (id 0) and billing routes are exempt by construction. |

## Invariants (do not break)

- `EntitlementService` is the **only** runtime access gate. Plans/subscriptions feed
  it; they are not a second path.
- The **system tenant (id 0) is never walled and is implicitly unlimited.**
- Enforcement runs on **stored state**, not a payment whity processes — keep it
  provider-agnostic; the PSP webhook writes `status`/`external_ref` only.
- **No hardcoded tunables** — quotas, caps, grace, enforcement strength are
  entitlements/settings with smart defaults (per-tenant ?? global ?? registry).
- Every tenant-owned query (`tenant_entitlements`, `tenant_plan`) carries an explicit
  `tenant_id` predicate; the tables are registered in `TenantOwnedTables`.
