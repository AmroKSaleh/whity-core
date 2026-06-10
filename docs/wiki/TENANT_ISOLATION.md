# Tenant Isolation

Whity Core is multi-tenant: many tenants share one PostgreSQL database, and isolation is enforced **logically** by a `tenant_id` column on tenant-scoped tables plus a request-scoped tenant context — there is **no** per-tenant database. Isolation is enforced at three cooperating layers so a single missed check cannot leak data. This page is grounded in the current source.

Related: [Architecture](Architecture.md) · [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [HOOK_SYSTEM](HOOK_SYSTEM.md).

## The three layers

| Layer | Where | What it does | File |
| --- | --- | --- | --- |
| HTTP | Middleware (runs first) | Resolves tenant from JWT; refuses cross-tenant requests before routing/DB. | `src/Http/Middleware/EnforceTenantIsolation.php` |
| Context | Request-scoped static | Holds + locks the current tenant id for the request's lifetime. | `src/Core/Tenant/TenantContext.php` |
| Query | Explicit predicates in handlers/repositories | Every statement on a tenant-owned table binds a `tenant_id` predicate from `TenantContext`; proven per table by a real-engine test suite. | `src/Api/*`, `src/Core/*/…Repository.php`, `tests/Integration/CrossTenantRejectionRealEngineTest.php` |

## TenantContext — the request-scoped holder

`TenantContext` (`src/Core/Tenant/TenantContext.php`) holds the current request's tenant id in static state. Because FrankenPHP workers persist across requests, this static state is the framework's sanctioned exception to the "no request state in statics" rule — and it **must** be reset between requests (it is, by both the kernel and the worker loop).

Tenant ids are **integers**. The special tenant id **0 is the system tenant** — a fully valid, settable value, distinct from the "unset" `null` state. Downstream code (e.g. cross-tenant authority checks) relies on `getTenantId() === 0`.

### Resolving the tenant

```php
public static function resolve(Request $request, JwtParser $jwtParser): int
```

`resolve()` extracts the JWT (`Authorization: Bearer <token>`, falling back to the `access_token` cookie), validates it via `JwtParser`, reads the `tenant_id` claim, coerces a numeric claim to int, and **locks** the context. There is **no silent fallback** — every failure throws `TenantResolutionException`:

- missing token,
- invalid/expired token,
- missing `tenant_id` claim,
- a `tenant_id` claim that is not a valid integer.

### Locking

Once set (via `resolve()` or `setTenantId()`), the context is **locked**. A second `setTenantId()` throws `RuntimeException('TenantContext is locked and cannot be mutated')` until `reset()` is called. This prevents a handler or plugin from changing tenants mid-request and escaping the boundary.

```php
TenantContext::setTenantId(42);
TenantContext::getTenantId();   // 42 (read is always allowed)
TenantContext::setTenantId(99); // RuntimeException: locked
```

### System mode (the trusted bypass)

`setSystemMode(bool $enabled, string $actor, array $context = [])` toggles a tenant-scoping bypass for **trusted, non-request** contexts (migrations, admin CLI). It is **never derived from request input**, and every transition is audit-logged with the actor. `isSystemMode()` reports it. This is separate from "tenant id 0": both grant cross-tenant authority, but system mode is an explicit out-of-band switch.

### Reset between requests

`reset()` clears the tenant id, the lock, and system mode. It is called in the `finally` block of `HttpKernel::handle()` (`resetRequestState()`) and again in the worker loop's `finally` in `public/index.php`, so no tenant or privilege state leaks into the next request on the same worker. (The injected audit logger is intentionally preserved across resets — it is process-scoped infrastructure, not request state.)

## EnforceTenantIsolation — the HTTP layer

`EnforceTenantIsolation` (`src/Http/Middleware/EnforceTenantIsolation.php`) is the first middleware in the pipeline (registered via `$kernel->use(...)` in `public/index.php`). It runs **before** routing, RBAC, and any database access.

`handle(Request $request, callable $next)`:

1. **Public routes** carry no tenant context and pass straight through: `/api/login`, `/api/login/2fa`, `/api/me`, `/api/auth/refresh`, `/api/auth/logout`, `/api/navigation`.
2. Otherwise it delegates token → tenant extraction to `TenantContext::resolve()`. Any `TenantResolutionException` collapses to a generic `401 Authentication required` (internals never leak to the client).
3. It re-parses the (now validated) token to expose the decoded payload as `Request::$user` for downstream handlers.
4. It determines the tenant the request *addresses*, if any (`resolveResourceTenantId()`), in priority order:
   - a `/api/tenants/{id}` path segment,
   - a `tenant_id` query-string parameter,
   - an `X-Tenant-Id` header.
5. Decision:
   - no addressed tenant → defer to the handler and the query-level layer (`next`),
   - addressed tenant **equals** the caller's tenant → allow,
   - addressed tenant **differs** → allow only if the caller has **cross-tenant authority** (tenant id 0 **or** `isSystemMode()`), in which case the bypass is **audit-logged**; otherwise refuse with `403 Access to the requested tenant is forbidden` *before any handler/DB work runs*.

```mermaid
flowchart TD
    A["Request"] --> B{Public route?}
    B -- yes --> Z["next() — no tenant context"]
    B -- no --> C["TenantContext::resolve()"]
    C -- throws --> E["401 Authentication required"]
    C -- tenantId --> D["attach Request::user"]
    D --> F{"Resource addresses a tenant?"}
    F -- no --> Z2["next()"]
    F -- yes --> G{"resourceTenant == callerTenant?"}
    G -- yes --> Z3["next()"]
    G -- no --> H{"Cross-tenant authority?<br/>(tenant 0 or system mode)"}
    H -- yes --> I["audit log → next()"]
    H -- no --> J["403 forbidden (before handler/DB)"]
```

## The query layer — explicit predicates, proven by tests

Every handler/repository statement that touches a tenant-owned table carries an explicit, parameterised `tenant_id` predicate bound from `TenantContext`. **This hand-written predicate IS the query-level isolation mechanism** — there is no automatic query-rewriting layer. (The `ScopesToTenant` trait that previously advertised one was removed by WC-161: a full audit found zero production call sites, its rewriter refused the JOINs most list endpoints need, and it had no concept of the system tenant's cross-tenant visibility. An advertised guarantee that does not run is worse than none.)

The conventions every query follows:

```php
// Regular tenant: scoped read/write
$sql = 'SELECT ... FROM users u JOIN roles r ON u.role_id = r.id WHERE u.tenant_id = ?';

// System tenant (id 0): sees across tenants — the platform-wide convention
if ($tenantId === 0) { /* unscoped variant */ } else { /* scoped variant */ }

// Roles: a tenant sees its OWN roles plus GLOBAL (NULL-tenant) roles
'... WHERE r.id = ? AND (r.tenant_id = ? OR r.tenant_id IS NULL)'
```

- The tenant id is **always bound**, never string-interpolated.
- JOINed statements qualify the predicate with the owning table's alias (`u.tenant_id = ?`), so joined rows cannot under-scope it.
- `INSERT`s set `tenant_id` explicitly from the context; cross-tenant `UPDATE`/`DELETE` attempts match zero rows and surface as 404.

**Because the predicates are hand-written, they are enforced by tests rather than by structure:** `tests/Integration/CrossTenantRejectionRealEngineTest.php` drives the real handlers/repositories against a real SQL engine and proves, per tenant-owned table (users, roles, organizational units, audit log, delegations — persons/relations have the same proof in their own real-engine suites): list/read scoping, cross-tenant read rejection, cross-tenant **write** rejection with the row verified untouched, and system-tenant visibility. Dropping a single predicate makes the suite fail. **When you add a tenant-owned table, extend that suite.**

## The system tenant (id 0)

Migration `011_create_system_tenant.php` provisions tenant id **0** ("System") and a `system@whity.local` admin user. A caller resolved to tenant 0 holds cross-tenant authority: `EnforceTenantIsolation` lets it cross tenant boundaries (audited), and `RolesApiHandler` lets it see and manage every tenant's roles. This is the same mechanism trusted tooling uses via `TenantContext::isSystemMode()` — there is no separate super-admin flag.

## End-to-end flow

```mermaid
sequenceDiagram
    actor User
    participant UI as Next.js UI
    participant TI as EnforceTenantIsolation
    participant TC as TenantContext
    participant H as Handler
    participant DB as PostgreSQL

    User->>UI: login (email/password)
    UI->>H: POST /api/login (public route)
    H-->>UI: JWT with tenant_id claim (httpOnly cookie)
    UI->>TI: GET /api/users (cookie/Bearer)
    TI->>TC: resolve(request, jwtParser)
    TC-->>TI: tenantId (locks context) or 401
    TI->>TI: enforce resource tenant boundary (403 on mismatch)
    TI->>H: next(request) — Request::user attached
    H->>DB: SELECT ... WHERE tenant_id = :tenant
    DB-->>H: rows for this tenant only
    H-->>UI: Response
    Note over TC: HttpKernel + worker loop call TenantContext::reset() in finally
```

## Worker-level connection hygiene

Tenant safety also depends on the shared worker connection not carrying state between requests. `Database` (`src/Database/Database.php`) runs `resetSessionState()` between requests, which rolls back any dangling transaction and issues `DISCARD ALL` so temp tables, prepared plans, `SET` values, and advisory locks cannot bleed across tenants on the one connection a worker reuses. See [Architecture](Architecture.md#multi-tenancy) for connection pooling details.

## Summary

- One shared PostgreSQL DB; isolation is a `tenant_id` column + request-scoped context, **not** separate databases.
- `TenantContext` resolves the tenant from the JWT, locks it, and is reset between requests (no silent fallback; tenant 0 = system).
- `EnforceTenantIsolation` resolves + refuses cross-tenant requests at the HTTP layer before routing/DB; public routes bypass it.
- Query-level isolation is the explicit, bound `tenant_id` predicate every handler/repository statement carries (no rewriting layer); `CrossTenantRejectionRealEngineTest` proves read AND write rejection per table on a real engine and fails if a predicate is dropped.
- The system tenant (id 0) and `isSystemMode()` are the audited cross-tenant bypass; `DISCARD ALL` keeps the shared worker connection clean between requests.
</content>
