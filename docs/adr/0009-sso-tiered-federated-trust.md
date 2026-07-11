# ADR 0009: SSO tiered federated trust & identity namespacing

- **Status:** Accepted
- **Date:** 2026-07-11
- **Task / Issue:** WC-f3b17bd2
- **Deciders:** Amro Saleh

> **Implementation note:** Unlike ADR 0005 (which recorded a *target* state ahead
> of the work), this ADR documents an architecture that is **already built and
> tested**. It exists to lift the tiered-trust design out of code docblocks and
> into a durable, reviewable record. The behaviour described here is implemented
> in [`FederatedIdentityLinker`](../../src/Core/Identity/FederatedIdentityLinker.php),
> [`FederatedProviderContext`](../../src/Core/Identity/FederatedProviderContext.php),
> [`ExternalIdentityRepository`](../../src/Core/Identity/ExternalIdentityRepository.php),
> and migrations [047](../../database/migrations/047_create_external_identities.php) /
> [048](../../database/migrations/048_create_identity_providers.php).

## Context

ADR 0005 canonicalised identity on `profiles` (a person) + `memberships` (a
person's binding to a tenant), with `profile_emails.UNIQUE(email)` making email a
global identity key. Federated sign-in (OIDC "Sign in with Google", Microsoft,
generic OIDC) must map an external account onto that model without a password —
and it must do so across two structurally different classes of identity provider:

1. **Operator IdPs** — configured by the deployment operator at the system tenant
   (id 0). Example: the real `accounts.google.com`. Only the operator can
   configure one, so its `email_verified` assertion can be trusted about *any*
   person in the deployment.

2. **Tenant bring-your-own (BYO) IdPs** — a single tenant's own IdP (their Okta,
   their Azure AD). The tenant admin controls that IdP's issuer, JWKS, and which
   `sub`s it mints. Nothing stops a hostile or misconfigured tenant IdP from
   asserting `iss=accounts.google.com` or `email=ceo@another-tenant.com`.

Treating these two the same is a tenant-takeover vector: a tenant BYO IdP could
mint an assertion that resolves to the operator's global Google links, or to a
profile that belongs to a *different* tenant. The forces:

- **Tenant isolation is the #1 invariant.** A tenant IdP must never reach an
  account outside the tenant that configured it, nor the global namespace.
- **One person, one profile.** A real Google `sub` should map one human to one
  profile across every tenant they belong to (ADR 0005), so operator IdPs must be
  allowed to act on the *global* profile namespace.
- **FrankenPHP worker concurrency.** First-login provisioning races between
  persistent workers must not double-provision or 500.
- **`email_verified` is only as trustworthy as who vouches for it.**

## Decision

We will **fork federated-login policy on a trust tier derived from *who
configured the IdP***, and enforce the tier both structurally (DB unique indexes)
and in application code.

The tier is carried by [`FederatedProviderContext`](../../src/Core/Identity/FederatedProviderContext.php)
and derived from the configuring tenant: `isGlobalTrust() === (tenantId === 0)`.

**Identity namespacing** — the key that maps an external account to a profile in
`external_identities` depends on the tier (migration 047, two *partial* unique
indexes):

| Tier | `provider_id` | Identity key | Unique index |
|---|---|---|---|
| **Global-trust** (operator, tenant 0) | `NULL` | `(issuer, subject)` | `uq_external_identities_global … WHERE provider_id IS NULL` |
| **Tenant-trust** (BYO) | `identity_providers.id` | `(provider_id, subject)` | `uq_external_identities_tenant … WHERE provider_id IS NOT NULL` |

Namespacing tenant-trust links by `provider_id` (not by `issuer`) is what stops a
tenant IdP from spoofing `issuer=accounts.google.com` and colliding with the
operator's global Google links: the two live in disjoint index partitions.

**Resolution policy** (`FederatedIdentityLinker::resolveForLogin`), returning one
of `existing | linked | provisioned | refused_unverified | refused_conflict |
refused_no_account`:

- **Global-trust** — the operator IdP's `email_verified` is authoritative over the
  global namespace. Existing `(issuer, subject)` link → that profile. Unverified
  email → refuse. Verified email `E`: matches a *verified* `profile_email` → link;
  matches an *unverified* one → refuse (else the IdP could seize a half-registered
  account); matches nothing → provision a passwordless profile + verified primary
  email + global link.
- **Tenant-trust** — trusted **only within the configuring tenant**. Existing
  `(provider_id, subject)` link → that profile. Unverified email → refuse. A
  verified `E` owned by an **active or invited member** of the tenant links in the
  tenant namespace (a pending invite is JIT-accepted, WC-635ee381); an *unverified*
  local email → refuse. `E` owned by a **non-member** (or no local account) is
  onboarded via **domain-claim JIT** (WC-ab821c60) **only** when the tenant holds a
  **DNS-verified, auto-provisioning** claim on `E`'s domain — otherwise refused. A
  **suspended** member is always refused. Every path touches only `ctx.tenantId`.

**Session confinement** — a tenant-trust login yields a session confined to the
configuring tenant (`SsoAuthHandler::callback` →
`completeFederatedLogin(..., restrictToTenantId)`). In-tenant impersonation by a
tenant IdP is *by design* (the tenant admin already holds identity authority over
their members); the guaranteed invariant is that a tenant IdP can **never** reach
a non-member.

We **reject** an explicit `tier`/`trust` column on `identity_providers`: the tier
is a total function of `tenant_id === 0`, so a stored column would be derivable,
redundant state that could drift from the row it describes.

## Alternatives Considered

- **One trust level for all IdPs (no tiering).** Simplest, but a tenant BYO IdP
  could assert any issuer/email and take over global or cross-tenant accounts.
  Rejected — breaks the #1 tenant-isolation invariant.
- **Namespace tenant-trust links by `(issuer, subject)` like global.** A tenant
  IdP configured with `issuer=accounts.google.com` would then collide with the
  operator's global Google links. Rejected in favour of `(provider_id, subject)`.
- **Enforce uniqueness only in application code.** Loses the structural guarantee
  under concurrent first-logins. Rejected — the partial unique indexes make a
  losing racer's insert fail closed and resolve to the winner's link.
- **An explicit `tier` column.** Redundant with `tenant_id === 0`; risks drift.
  Rejected.

## Consequences

### Positive

- A tenant BYO IdP is structurally and procedurally incapable of reaching another
  tenant's accounts or the global namespace.
- One real Google `sub` = one profile across all a person's tenants (ADR 0005).
- Concurrent first-logins are race-safe via the partial unique indexes.
- New OIDC providers need no schema change — only an `identity_providers` row.

### Negative / Trade-offs

- `external_identities.provider_id` is an **unconstrained** BIGINT, not a FK
  (migration 047 predates `identity_providers` in 048, and the SQLite test shim
  cannot add a cross-table FK after the fact). Deleting a tenant provider orphans
  its tenant-trust links; the provider-delete path owns any cleanup.
- The tier being implicit (`tenant_id === 0`) means every call site that decides
  trust must go through `FederatedProviderContext`, never re-derive it ad hoc.
- Partial unique indexes are a PostgreSQL feature; the SQLite test shim models the
  equivalent behaviour but the two are not byte-identical.

### Impact on existing conventions

- Reinforces ADR 0005 (profile-centric identity, membership gate) and the
  tenant-predicate pattern — tenant-trust reads/writes bind `ctx.tenantId`.
- `external_identities` is a **sanctioned GLOBAL table** (like `profiles` /
  `profile_emails`), enumerated in `SanctionedGlobalTables`; `identity_providers`
  is **tenant-owned** (`TenantOwnedTables`). Both registries must stay in sync
  with these tables.
- The SSO/IdP HTTP routes are documented separately (OpenAPI follow-up); this ADR
  covers only the trust model.

## References

- ADR 0005 — Identity & tenant-membership model
- [`FederatedIdentityLinker`](../../src/Core/Identity/FederatedIdentityLinker.php),
  [`FederatedProviderContext`](../../src/Core/Identity/FederatedProviderContext.php),
  [`ExternalIdentityRepository`](../../src/Core/Identity/ExternalIdentityRepository.php)
- Migrations [047 (external_identities)](../../database/migrations/047_create_external_identities.php),
  [048 (identity_providers)](../../database/migrations/048_create_identity_providers.php),
  [050 (domain ownership)](../../database/migrations/050_add_ownership_to_tenant_email_domains.php)
- Tests: `tests/Integration/FederatedIdentityLinkerRealEngineTest.php`,
  `tests/Integration/SsoAuthHandlerRealEngineTest.php`,
  `tests/Integration/ExternalIdentityRepositoryRealEngineTest.php`
- `docs/wiki/SSO-Google-Setup.md`
