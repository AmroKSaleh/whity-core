# Audit Trail

Whity Core records a tenant-scoped, append-only **security audit trail** (WC-34): who did what, in which tenant, and when. It captures authentication and authorization-relevant actions so administrators have a queryable history for incident response and compliance. This page is grounded in the current source.

Related: [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [TENANT_ISOLATION](TENANT_ISOLATION.md) · [HOOK_SYSTEM](HOOK_SYSTEM.md) · [Architecture](Architecture.md).

## The pieces

| Component | Responsibility | File |
| --- | --- | --- |
| `audit_log` table | Append-only storage for audit entries. | `database/migrations/014_create_audit_log.php` |
| `AuditLogger` | The single writer. Subscribes to CRUD hooks and exposes `record()`. | `src/Core/Audit/AuditLogger.php` |
| `AuditContext` | Request-scoped holder for the acting user id + client IP. | `src/Core/Audit/AuditContext.php` |
| `AuditLogApiHandler` | Queryable, RBAC-protected read API. | `src/Api/AuditLogApiHandler.php` |
| `audit:read` permission | Gates the read API. | `src/Core/RBAC/CorePermissions.php` |

## Schema

```sql
CREATE TABLE audit_log (
    id            SERIAL PRIMARY KEY,
    tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    actor_user_id INTEGER NULL,            -- nullable: failed logins / system actions
    action        VARCHAR(100) NOT NULL,   -- stable key, e.g. auth.login.success
    target_type   VARCHAR(100) NULL,       -- affected entity type (role/user/tenant/ou)
    target_id     INTEGER NULL,            -- affected entity id (null for logins)
    metadata      JSONB NOT NULL DEFAULT '{}'::jsonb,
    ip_address    VARCHAR(45) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_audit_log_tenant_created ON audit_log (tenant_id, created_at DESC, id DESC);
CREATE INDEX idx_audit_log_tenant_action  ON audit_log (tenant_id, action);
```

Notes:

- `tenant_id` cascades with the tenant, mirroring the other scoped tables. The **system tenant (id 0)** owns cross-tenant/system records (e.g. a failed login with no resolved tenant).
- `actor_user_id` is **nullable and intentionally not a foreign key** — a failed login has no authenticated user, and an audit record must survive deletion of the user it refers to (deleting the evidence with the subject would defeat the trail).
- `metadata` carries action-specific context and **never** stores secrets or PII: the writer drops any key whose name suggests a password/hash/secret/token/code before persisting.
- The composite `(tenant_id, created_at DESC, id DESC)` index backs the primary access pattern — a tenant's newest-first listing.

## AuditLogger — the single writer

`AuditLogger` (`src/Core/Audit/AuditLogger.php`) is **process-scoped infrastructure** (one instance shared across the requests a FrankenPHP worker serves). It is the only writer to `audit_log`; handlers do not insert audit rows directly. Two paths feed it:

1. **Hook subscription** — `AuditLogger::subscribe($hookManager)` listens (at priority 50, after the default listeners) to the post-action lifecycle hooks the CRUD handlers already fire and turns each into a row:

   | Hook | Audit action | Target |
   | --- | --- | --- |
   | `role.created` / `role.updated` / `role.deleted` | same | `role` |
   | `user.created` / `user.updated` / `user.deleted` | same | `user` |
   | `tenant.created` / `tenant.updated` / `tenant.deleted` | same | `tenant` |
   | `ou.created` / `ou.updated` / `ou.deleted` | same | `ou` |
   | `ou.role_assigned` / `ou.role_removed` | same | `ou` (role id in metadata) |

   The listeners always return the data unchanged, so the hook filter chain is never disturbed.

2. **Explicit `record()` calls** — the auth/2FA endpoints do not fire hooks, so `AuthHandler` and `TwoFactorHandler` call `record()` directly for: `auth.login.success`, `auth.login.failure`, `auth.login.2fa_required`, `auth.2fa.verify_success`, `auth.2fa.verify_failure`, `auth.2fa.enabled`, `auth.2fa.disabled`.

`record()` is **fail-soft**: a write error is logged via PSR-3 and swallowed so auditing can never break the action it is recording.

### Actor & IP resolution

The actor id and client IP are request-specific, but the logger subscribes to hooks deep inside handlers and has no access to the `Request`. `AuditContext` (`src/Core/Audit/AuditContext.php`) bridges this: `EnforceTenantIsolation` (which already decodes the JWT first) sets the actor/IP once per request, and the logger reads them when it writes. Like `TenantContext`, this is the sanctioned exception to the "no request state in statics" rule on persistent workers and is **reset between requests** (by the HTTP kernel's reflective reset and explicitly in the worker loop's `finally`). The auth path passes the actor/IP explicitly to `record()` because it knows the user before the request context is populated (login is a public route).

## Query API

`GET /api/audit-logs` (`src/Api/AuditLogApiHandler.php`) is the read endpoint:

- **RBAC**: gated on `audit:read` at the route boundary (`RbacMiddleware`) and re-checked in the handler as defence in depth.
- **Tenant scoping**: every query is scoped to the caller's tenant. The **system tenant (id 0)** sees entries across all tenants; every other tenant sees only its own. An unresolved tenant context fails closed (403).
- **Filters** (query params): `action`, `actor` (actor_user_id), `target_type`, `from`/`to` (inclusive `created_at` bounds), `page`, `per_page` (default 25, max 100).
- **Ordering**: newest first (`created_at DESC, id DESC`).
- **Response**: `{ data: [...], pagination: { page, perPage, total, totalPages } }`.

See the OpenAPI spec (`public/openapi.json`) for the full request/response contract.

## Admin UI

`web/app/(protected)/admin/audit-logs` lists entries with the filters and pagination, matching the other admin pages' loading/empty/error states and design tokens. A sidebar entry (`Audit Logs`, registered via the `navigation.register` hook in `public/index.php`) links to it.

## Summary

- `audit_log` is an append-only, tenant-scoped trail; `tenant_id` cascades, `actor_user_id` is nullable, `metadata` is secret/PII-free.
- `AuditLogger` is the single writer: it subscribes to the core CRUD hooks and is called explicitly by the auth/2FA endpoints; it is fail-soft and worker-safe.
- `AuditContext` carries the per-request actor/IP and is reset between requests.
- `GET /api/audit-logs` is gated on `audit:read`, tenant-scoped (system tenant 0 sees all), filterable and paginated.
