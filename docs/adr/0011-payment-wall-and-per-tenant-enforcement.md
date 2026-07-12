# ADR 0011: Payment wall & per-tenant subscription enforcement

- **Status:** Accepted
- **Date:** 2026-07-12
- **Task / Issue:** WC-payment-wall
- **Deciders:** Amro Saleh

## Context

[ADR 0010](0010-subscription-plans-and-billing-extensibility.md) delivered the
plan catalog and framed pricing/promo/subscription as future tables that hang off
`plans` / `tenant_plan` and "never touch the entitlement engine". This ADR records
the **runtime enforcement** decision that sits on top of that model: given that a
tenant's paid access can lapse, how does a running deployment behave — and who
controls that behaviour?

Forces at play:

- **Payment is collected out-of-band.** whity has no Stripe/PSP integration and
  (per GTM) billing collection is deferred. The platform must enforce on
  subscription *state* it is *told about* (an admin toggles it, or a future webhook
  writes it), not on a payment it processes itself. The enforcement layer must not
  assume a provider exists.
- **One deployment ≠ one business model.** A full sovereign whity deployment can be
  a single customer (all tenants theirs), or a multi-tenant SaaS where each tenant
  is a paying account. "Lapsed = hard block" is right for the latter and hostile for
  the former. The policy must be tunable per deployment **and per tenant**.
- **The operator must never lock themselves out.** The system tenant (id 0) is the
  operator's own control plane. An upstream billing hiccup must never wall the
  admin who would fix it — especially since payment happens externally and an admin
  upgrades a tenant by hand.
- **Tenant isolation** (#1 invariant) and the existing middleware pipeline
  (`HttpKernel::use()`, order == execution) constrain where enforcement lives.
- **No raw exceptions to clients** (WC-186); typed, generic responses only.

## Decision

We will enforce subscription state at the **middleware boundary** via a
`PaymentWall` middleware driven by `SubscriptionService::decide()`, with the
enforcement **strength chosen per tenant by the operator** (an admin setting), and
a set of hard exemptions that make lockout structurally impossible.

**State model (additive to ADR 0010).** Migration `057` adds subscription state
columns to the existing tenant-owned `tenant_plan` row — `status`,
`current_period_end`, `grace_until`, `enforcement_mode`, `external_ref` — rather
than a new table. `tenant_plan` is already the tenant↔plan anchor; a subscription
is that anchor plus lifecycle. `external_ref` is the seam a future PSP webhook
writes (opaque provider id); nothing in core reads it today.

**Enforcement modes** (`off` | `warn` | `block_writes` | `block_all`), resolved as
**per-tenant `tenant_plan.enforcement_mode` ?? global `billing.enforcement_default`
setting ?? `warn`**:

- `off` — never wall (single-customer / internal deployments).
- `warn` — allow every request, stamp an `X-Subscription-Status` header so the UI
  can nudge. **The safe default.**
- `block_writes` — lapsed tenant may read but not mutate (402 on writes).
- `block_all` — lapsed tenant is fully walled (402).

**`SubscriptionService::decide(tenantId, isWrite)` → `SubscriptionDecision`**
(allow / warn / block). A tenant in `active`/`trialing`, or `past_due` still within
`grace_until` (driven by the `billing.grace_days` setting), is allowed regardless
of mode. Only a genuinely lapsed tenant is subject to its effective mode.

**Hard exemptions (lockout is structurally impossible):**

1. **Public / unauthenticated** requests — `TenantContext` unresolved (null) → pass.
2. **The system tenant (id 0)** — always pass. The operator is never walled.
3. **Billing / subscription-management routes** (`$exemptPrefixes`, e.g.
   `/api/v1/subscription`) — always reachable, so an admin can inspect and fix
   billing state even while walled.

A master env flag (`BILLING_WALL_ENABLED`) makes the entire layer a no-op when
unset, so a deployment opts in. `BILLING_URL` supplies the optional `Link` header
target on a 402.

## Alternatives Considered

- **Global on/off only (no per-tenant mode).** Simple, but forces one policy on
  every tenant in a deployment — wrong for mixed internal+paying tenants. Rejected;
  the per-tenant override is the whole point of "each client is different".
- **Enforce inside each handler / repository.** Scattered, easy to forget on a new
  route, and can't express "block writes but allow reads" uniformly. Rejected in
  favour of one middleware choke point.
- **Model subscription as a new `subscriptions` table now.** More rows to isolate
  and join for no present benefit; `tenant_plan` already carries the anchor.
  Deferred — a dedicated table can supersede the columns if lifecycle grows (ADR
  0010 anticipated this).
- **Hard-block lapsed tenants by default.** Hostile to the single-customer sovereign
  case and risks operator lockout. Rejected; default is `warn`.

## Consequences

### Positive

- One boundary enforces billing state for every route; handlers stay billing-unaware.
- Operators tune enforcement per tenant (an admin setting) without a code change —
  no hardcoded policy ([no-hardcoded-values rule]).
- The operator (system tenant) and the billing pages themselves can never be walled.
- Provider-agnostic: enforcement runs on stored state; a PSP webhook later just
  writes `status`/`external_ref`. No refactor to add real billing.

### Negative / Trade-offs

- Enforcement is only as correct as the state it's told; with no PSP wired, an admin
  (or a future webhook) must keep `status` current. Documented as expected.
- `enforcement_mode` on `tenant_plan` is one more tenant-owned column the predicate
  guard and cross-tenant tests must cover.

### Impact on existing conventions

- New settings `billing.enforcement_default` (default `warn`) and
  `billing.grace_days` — registered in `SettingsRegistry` (the `SettingsRegistryTest`
  exact-keys + count gate must be updated with them).
- `PaymentWall` is registered in the `HttpKernel` pipeline **after** `SettingsService`
  is constructed (a use-before-def there nulls the dependency and 500s every request
  at worker boot while unit/PHPStan/lint stay green — always boot-test wiring on the
  live stack). See [run-the-app-not-just-tests lesson].
- Returns generic 402s with a typed status only — never `$e->getMessage()` (WC-186).

## References

- [ADR 0010 — Subscription plans & billing extensibility](0010-subscription-plans-and-billing-extensibility.md)
- [docs/wiki/Monetization-and-Metering.md](../wiki/Monetization-and-Metering.md) —
  operator + developer guide tying entitlements → plans → subscriptions → wall →
  rate limits together.
- `src/Http/Middleware/PaymentWall.php`, `src/Core/Subscription/SubscriptionService.php`,
  migrations `055`–`058`.
