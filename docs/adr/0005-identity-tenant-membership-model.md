# ADR 0005: Identity & tenant-membership model

- **Status:** Accepted
- **Date:** 2026-06-23
- **Task / Issue:** WC-89 (#89)
- **Deciders:** Amro Saleh

> **Implementation note:** This ADR records the *target* architecture for Phase B.  The
> current codebase still uses the old `{user_id, tenant_id}` JWT model and the `users`
> table as the identity anchor.  Each section below describes the intended end-state;
> the corresponding Phase B tasks (#96, #93, #103, #110, …) implement it incrementally.

## Context

### The #181 login-ambiguity bug

`users` carries a `UNIQUE(tenant_id, email)` constraint, meaning the same email address is allowed across different tenants.  The login handler (`AuthHandler::handle`) resolves the user by email *before* any tenant context exists:

```php
// @tenant-guard-ignore: login resolves a user by globally-unique email…
SELECT id, email, password, tenant_id, … FROM users WHERE email = ?
```

This comment claims global uniqueness, but the schema guarantees nothing of the sort.  If Alice registers as `alice@corp.com` in both Tenant A and Tenant B, the `WHERE email = ?` query returns whichever row the engine surfaces first — implementation-defined, nondeterministic from Alice's perspective, and guaranteed to produce a wrong-tenant login 50 % of the time.  This is issue **#181** (tenant-ambiguity login bug).

### Structural root cause

The current model conflates *identity* with *membership*:

| Concept | Where it lives today | Problem |
|---|---|---|
| Who you are (credentials, 2FA) | `users` row — one per tenant | Credentials duplicated for every org a person belongs to |
| Which org you belong to | `users.tenant_id` FK | One user = one org; cross-org access requires separate accounts |
| Email as identity key | `users.email` with per-tenant uniqueness | Same email in two tenants → login ambiguity (#181) |
| JWT identity | `{user_id, tenant_id}` in claims | Static; switching active tenant requires re-login |

This makes cross-tenant membership (a consultant at two companies), SSO federation (one OIDC subject → which `users` row?), and tenant switching impossible to implement cleanly.

### Forces

- FrankenPHP persistent worker safety: no request-scoped statics beyond `TenantContext`.
- Tenant isolation invariant: `TenantContext` must remain the sole resolver of the active tenant; no handler scopes by an untrusted request field.
- Token-epoch invalidation (WC-185): `token_epoch` on the current `users` row must migrate to `profiles`.
- Existing `CrossTenantRejectionRealEngineTest` must be extended for every new tenant-owned table.
- Migration 027 is the current head; identity migrations begin at 028.

---

## Decision

We introduce three new global or tenant-scoped tables that separate *identity* from *membership*, replace the current `users`-centric auth model, and change the JWT claim shape.

### 1. `profiles` — global identity anchor (migration 028)

```sql
CREATE TABLE profiles (
    id            SERIAL PRIMARY KEY,
    display_name  VARCHAR(255) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    two_factor_enabled          BOOLEAN NOT NULL DEFAULT FALSE,
    two_factor_secret           VARCHAR(512),
    two_factor_backup_codes_version INTEGER NOT NULL DEFAULT 0,
    token_epoch   INTEGER NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMP NOT NULL DEFAULT NOW()
);
```

`profiles` is **not** tenant-scoped.  It is the single source of credentials and 2FA configuration for a person, regardless of how many tenants they belong to.  It carries no `tenant_id` column and is therefore excluded from the tenant-predicate guard by addition to `SanctionedGlobalTables`.

### 2. `profile_emails` — globally-unique verified emails (migration 028)

```sql
CREATE TABLE profile_emails (
    id         SERIAL PRIMARY KEY,
    profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
    email      VARCHAR(255) NOT NULL,
    verified   BOOLEAN NOT NULL DEFAULT FALSE,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (email)   -- globally unique across ALL tenants; fixes #181
);
CREATE INDEX idx_profile_emails_profile_id ON profile_emails(profile_id);
CREATE INDEX idx_profile_emails_email ON profile_emails(email);
```

`UNIQUE(email)` is the structural fix for #181: the same email address cannot appear in two profiles.  Login resolves `profile_id` by looking up `profile_emails.email` (globally unique by construction), then queries `memberships` to determine which tenant(s) the profile belongs to.

`profile_emails` is not tenant-scoped.  It joins only to `profiles`, so it is also added to `SanctionedGlobalTables`.

### 3. `memberships` — profile-to-tenant binding (migration 029)

```sql
CREATE TABLE memberships (
    id         SERIAL PRIMARY KEY,
    profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
    tenant_id  INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    role_id    INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    ou_id      INTEGER REFERENCES organizational_units(id) ON DELETE SET NULL,
    status     VARCHAR(32) NOT NULL DEFAULT 'active',  -- active | invited | suspended
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (profile_id, tenant_id)
);
CREATE INDEX idx_memberships_profile_id ON memberships(profile_id);
CREATE INDEX idx_memberships_tenant_id  ON memberships(tenant_id);
```

`memberships` replaces `users.tenant_id` and `users.role_id` as the mechanism linking a person to an organisation.  It is **tenant-scoped** (`tenant_id` is present) and must be added to `TenantOwnedTables` and covered by `CrossTenantRejectionRealEngineTest`.

A profile with no active membership in a given tenant cannot log into that tenant.

### 4. `tenant_email_domains` — domain policy (migration 030)

```sql
CREATE TABLE tenant_email_domains (
    id        SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    domain    VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, domain)
);
CREATE INDEX idx_tenant_email_domains_tenant_id ON tenant_email_domains(tenant_id);
```

When a profile verifies an email whose domain matches a row in this table, the system JIT-creates an `active` membership for that profile in the owning tenant (with the default role).  This enables "everyone at @corp.com joins Tenant A automatically" without manual invites.  The table is tenant-scoped.

### 5. JWT claim model change

| Field | Old JWT | New JWT |
|---|---|---|
| Identity | `user_id` | `profile_id` |
| Active org | `tenant_id` | `active_tenant_id` |
| Role | `role_id` (static) | *(removed — resolved from memberships at request time)* |

`TenantContext::resolve()` reads `active_tenant_id` from the JWT, then validates that a live `memberships` row exists for `(profile_id, active_tenant_id)` with `status = 'active'`.  A valid token for a revoked/suspended membership is refused at the HTTP layer (403), not at query time.  This membership look-up is the mechanism that enforces per-membership suspension without waiting for token expiry; it is implemented as part of the JWT claim change task (#110) and is the required gate before that task can be considered complete.

Token-epoch invalidation remains on `profiles.token_epoch`; changing the epoch on a profile revokes all tokens for all of that profile's memberships simultaneously (desirable: credential compromise affects one person, not one org-scoped account).

### 6. Login flow (#181 fix)

```
POST /api/login  {email, password}
  1. SELECT profile_id FROM profile_emails WHERE email = ?            -- globally unique
  2. Verify password_hash on profiles row
  3. (If 2FA enabled) run TOTP challenge
  4. SELECT tenant_id, status FROM memberships WHERE profile_id = ?  -- list all orgs
     Filter to status IN ('active', 'invited') only; 'suspended' rows are hidden.
     - Zero rows (or all suspended)          → 403 "no active membership"
     - One or more 'invited' rows only       → 403 "account pending — check your email"
                                               (client must accept invitation first)
     - Exactly one 'active' row              → auto-select that tenant_id
     - Multiple 'active' rows                → 200 { requires_tenant_selection: true,
                                               memberships: [{ tenant_id, name, … }] }
       (client calls POST /api/auth/switch-tenant to complete login)
  5. Issue JWT { profile_id, active_tenant_id, exp, iat, token_epoch }
```

### 7. Tenant-switcher endpoint

`POST /api/auth/switch-tenant { tenant_id }` validates the caller's `profile_id` has an `active` membership in the requested `tenant_id` and re-issues a JWT with the new `active_tenant_id`.  The old token is not revoked (it expires normally); the new token supersedes it for the client session.

### 8. Data migration — `users` → new tables (migration 031)

A reversible data migration collapses the existing `users` rows into the new model:

1. For each distinct `users.email` (across all tenants), INSERT a `profiles` row (password_hash, 2FA fields, token_epoch from the first matching row; display_name from `users.email` local-part as fallback).
2. INSERT a `profile_emails` row for that email (verified=true, is_primary=true).
3. For every `users` row for that profile, INSERT a `memberships` row (profile_id, tenant_id, role_id, ou_id, status='active').
4. The system tenant's `system@whity.local` admin user becomes a profile + a membership in tenant 0.

Collision rule: if the same email appears in multiple tenants, all rows collapse to a **single profile** (the credentials from the `users` row with the lowest `id`, i.e. `ORDER BY users.id ASC`).  Each tenant gets its own `memberships` row.  This is the correct outcome — one person, multiple orgs.

**Credential collision audit (mandatory):** Before merging any credential rows, the migration must INSERT a record into an ephemeral `migration_031_collision_log` table (created and populated within the same transaction, dropped on `down()`):

```sql
CREATE TABLE migration_031_collision_log (
    email          VARCHAR(255),
    kept_user_id   INTEGER,   -- users.id whose password_hash was retained
    dropped_ids    INTEGER[]  -- users.id values whose password_hash was discarded
);
```

The migrated `up()` must print a summary of collision count and all affected emails to STDOUT (no hashes, no other PII).  Operators must review this output and notify affected users before deploying.  The `down()` reversal recreates the original `users` rows from `profiles + memberships + profile_emails`; password hashes for dropped rows cannot be recovered from this migration alone — operators should snapshot the `users` table before running `up()`.

The `users` table is kept during the transition period (Phase B) and removed only after all handlers, repositories, and the frontend are migrated (#96 et seq.).

### 9. `users` table deprecation path

`users` becomes an alias/view or a frozen table during Phase B.  Handlers and repositories are migrated task-by-task (#120 — profile-global vs tenant-local split, ~25 handlers) and the table is dropped once all references are gone.

---

## Alternatives Considered

- **Global `UNIQUE(email)` on `users`** — adds a constraint to the existing table; cheapest fix for #181.  Rejected: blocks legitimate cross-tenant membership (the same person at two organisations must have one row per org today) and does nothing for the credential-duplication or JWT rigidity problems.

- **Per-tenant login endpoints (`POST /api/tenants/{id}/login`)** — the client provides the target tenant before authenticating, eliminating ambiguity.  Rejected: worse UX (user must know their tenant slug before login), requires a tenant-discovery endpoint (a privacy concern), and still does not solve credential duplication or SSO identity mapping.

- **Add a `preferred_tenant_id` to `users`** — the login handler uses that as a tiebreaker when multiple rows share an email.  Rejected: band-aid; does not model cross-tenant membership, still duplicates credentials, and makes SSO federation impossible.

- **Do nothing** — the comment `@tenant-guard-ignore: login resolves a user by globally-unique email` becomes a documented lie; #181 remains open.  Rejected: the bug is already present in production scenarios and the current architecture cannot support SSO or cross-org access.

---

## Consequences

### Positive

- **#181 closed structurally**: `UNIQUE(email)` on `profile_emails` makes login ambiguity impossible.
- **Cross-tenant membership**: one person, multiple orgs, single credential set.
- **SSO-ready**: an OIDC subject maps to one `profile` regardless of how many tenants they belong to (via `external_identities`, Phase B).
- **Tenant switching without re-login**: `POST /api/auth/switch-tenant` re-issues a JWT without new credentials; `active_tenant_id` is mutable within the token lifetime.
- **Credential revocation scoped to the person**: rotating `profiles.token_epoch` invalidates all sessions for that person across all tenants at once.

### Negative / Trade-offs

- **Large handler surface**: approximately 25 handlers and repositories currently reference `users.tenant_id`, `users.email`, or `users.role_id` directly and must be updated (#120).
- **JWT format change**: existing tokens embed `user_id` and `tenant_id`; the new format embeds `profile_id` and `active_tenant_id`.  All existing sessions are invalidated on deploy (force re-login).  Mitigated by coordinating the cutover with a maintenance window.
- **`TenantContext` gains a DB read**: validating `active_tenant_id` against `memberships` adds one query to the hot path for every authenticated request.  Mitigate with a per-worker in-process cache keyed on `(profile_id, active_tenant_id, token_epoch)` with a short TTL (cleared on `TenantContext::reset()`).
- **Multi-membership login UX**: clients with multiple memberships must handle the `requires_tenant_selection` response.  The frontend requires a tenant-selection step before landing (#209 — Auth UI rewrite).
- **Seeder rewrite**: `010_create_system_tenant.php` and the `Seeder` class seed a `users` row for the system admin; they must be rewritten to seed a `profile` + `membership` in tenant 0 (#158).
- **Data migration risk**: collapsing cross-tenant duplicate emails into one profile merges two historically-separate credential sets.  The migration must be reversible and should be dry-run on a production snapshot before live deployment.

### Impact on existing conventions

- **`TenantOwnedTables`**: add `memberships`, `tenant_email_domains`.
- **`SanctionedGlobalTables`**: add `profiles`, `profile_emails`.
- **`CrossTenantRejectionRealEngineTest`**: extend with `memberships` and `tenant_email_domains` (read/write rejection per table).
- **JWT parsing (`JwtParser` / `TokenValidator`)**: add `profile_id` and `active_tenant_id` claim support; `TenantContext::resolve()` reads `active_tenant_id` and validates it against `memberships`.
- **OpenAPI** (#388): new `POST /api/auth/switch-tenant`, `GET /api/me` (now returns `profile_id` + active membership), updated auth response shapes.
- **Plugin SDK**: plugins that query `users` by `tenant_id` must migrate to `memberships`; the SDK `TenantOwnedTables` registry update propagates to the conformance kit automatically.
- **`backup_codes` exclusion comment**: `TenantOwnedTables.php` currently documents that `backup_codes` is excluded because it scopes transitively "via `users.user_id`".  After Phase B that FK points to `profiles.id`; the exclusion rationale comment must be updated in the same PR that drops `users`.

---

## References

- Issue #181 (login tenant-ambiguity bug)
- [TENANT_ISOLATION.md](../wiki/TENANT_ISOLATION.md) — current isolation model (to be updated by #93 once Phase B lands)
- [PERMISSION_SYSTEM.md](../wiki/PERMISSION_SYSTEM.md) — RBAC model (memberships replace users.role_id as the per-tenant role anchor)
- [Architecture.md](../wiki/Architecture.md)
- ADR 0003 ([0003-option-c-feature-portability-and-crypto-hardening.md](0003-option-c-feature-portability-and-crypto-hardening.md)) — prior crypto/token hardening context
- Phase B tasks: #96 (profiles migration), #89b (profile_emails + repository), #93 (memberships table), #120 (handler split), #103 (auth/login rewrite), #110 (JWT claim change)
