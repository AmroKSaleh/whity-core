# Permission System

Whity Core uses role-based access control (RBAC) with a two-part design: an in-memory **permission catalogue** (the source of truth for *which permissions exist*) and database tables that map **roles → permissions** and **users → roles** (the source of truth for *who has what*). This page is grounded in the current source; cited files are authoritative.

Related: [Architecture](Architecture.md) · [TENANT_ISOLATION](TENANT_ISOLATION.md) · [HOOK_SYSTEM](HOOK_SYSTEM.md) · [Plugin-Development](Plugin-Development.md).

## The pieces

| Component | Responsibility | File |
| --- | --- | --- |
| `PermissionRegistry` | In-memory catalogue of all known permissions, keyed by source (`core` or plugin name). | `src/Core/RBAC/PermissionRegistry.php` |
| `CorePermissions` | Canonical list of built-in permissions. | `src/Core/RBAC/CorePermissions.php` |
| `RoleChecker` | Resolves whether a user has a role/permission, including hierarchy inheritance + caching. | `src/Auth/RoleChecker.php` |
| `RbacMiddleware` | Enforces a route's required role/permission against the authoritative store. | `src/Http/RbacMiddleware.php` |
| `RolesApiHandler` | CRUD for roles + permission assignment, tenant-scoped. | `src/Api/RolesApiHandler.php` |
| `permissions`, `role_permissions`, `roles`, `user_roles` | Database catalogue + grants. | `database/migrations/` |

## Permission naming: `resource:action`

Permissions are strings in **colon notation**: `resource:action`. The registry validates the strict form with `^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$` (see `PermissionRegistry::isValidPermission()`). Examples: `users:read`, `roles:manage`, `tenants:delete`, `plugins:manage`.

> History: the original seeds (migrations 002 and 007) used dot notation (`users.read`). Migration `016_normalize_permission_notation.php` rewrote them to colon notation so the stored data matches what the RBAC layer validates (issue #55). Fresh databases already seed colon notation.

The built-in set is defined as constants on `CorePermissions` and registered under the `core` source:

```
users:read   users:write   users:delete
roles:read   roles:write   roles:delete   roles:manage
tenants:read tenants:write tenants:delete
ous:read     ous:write     ous:delete     ous:assign
permissions:read
audit:read
plugins:manage
delegation:manage
relations:read   relations:manage
```

> `audit:read` (WC-34) gates the read-only security audit trail (`GET /api/audit-logs`). Migration `016_create_audit_log` seeds it into the `permissions` catalogue and grants it to the seeded `admin` role, so administrators can read the trail out of the box. See [AUDIT_TRAIL](AUDIT_TRAIL.md).

> `relations:read` / `relations:manage` (WC-65) gate the family relations feature: `relations:read` covers the read surface (relationship-type vocabulary, persons, a node's relations) and `relations:manage` covers every write (create/edit/delete a person, add/remove a relation edge). Migration `020_create_relations` seeds both into the `permissions` catalogue and grants them to the seeded `admin` role. See [RELATIONS](RELATIONS.md).

## PermissionRegistry — the in-memory catalogue

`PermissionRegistry` (`src/Core/RBAC/PermissionRegistry.php`) holds every permission the platform currently knows about, organized by **source**: the literal `core` for built-ins, or the plugin name for plugin permissions.

```php
// The single registration entry point for core AND plugin sources. Every
// permission is validated against the resource:action pattern; an invalid
// permission throws InvalidPermissionException.
$registry->register('core', CorePermissions::all());
$registry->register('my-plugin', ['my_plugin:use', 'my_plugin:admin']);

// Queries
$registry->exists('users:read');   // true
$registry->getAll();               // ['permission' => 'source', ...]
$registry->getBySource('core');    // ['users:read', ...] (per-source list)
```

Key behaviours:

- **Lazy core registration** — core permissions register themselves on first read (`ensureCoreRegistered()`), so validation works even without explicit bootstrap wiring (issue #55).
- **Plugins are the single source of truth for their own permissions** — when a plugin is unloaded, its source entry disappears from the registry, so its permissions instantly stop existing. There are no orphaned permission rows to clean up.
- **Worker-level state** — the registry holds nothing request-specific, so it is safe to share across the requests a FrankenPHP worker serves.
- Registration dispatches a `permission.registered` hook via the optional `HookManager` (`plugin_id`, `source`, `permissions`).

### How a plugin declares permissions

Plugins declare permissions through the declarative `PluginInterface::getPermissions()` (`sdk/src/PluginInterface.php`) — there is **no** `onEnable()` method. The `PluginLoader` reads the array and calls `PermissionRegistry::register($plugin->getName(), $plugin->getPermissions())` (`PluginLoader::registerCapabilities()`). A plugin that declares a permission outside the `resource:action` pattern is rejected with a logged warning rather than crashing the host.

```php
final class MyPlugin implements \Whity\Sdk\PluginInterface
{
    public function getName(): string { return 'my-plugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }

    public function getPermissions(): array
    {
        return ['my_plugin:use', 'my_plugin:admin'];
    }

    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
```

See [Plugin-Development](Plugin-Development.md) for the full plugin contract.

## Database tables

Permission grants live in the database; the registry only governs *existence*.

```sql
-- permissions (migration 002): the catalogue rows the registry validates against
CREATE TABLE permissions (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL UNIQUE,  -- resource:action
    description TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);

-- role_permissions (migration 002): role -> permission grants, by permission_id
CREATE TABLE role_permissions (
    id            SERIAL PRIMARY KEY,
    role_id       INTEGER NOT NULL REFERENCES roles(id),
    permission_id INTEGER NOT NULL REFERENCES permissions(id),
    created_at    TIMESTAMP NOT NULL DEFAULT NOW(),
    UNIQUE(role_id, permission_id)
);
```

> Note: `role_permissions` references permissions by **`permission_id`** (a foreign key to `permissions.id`), not by a permission string. Roles relate to permissions many-to-many through this junction table.

Users get roles two ways: the primary `users.role_id` column (migration 001), and the many-to-many `user_roles` junction (migration 015). Roles can inherit from a parent via `roles.parent_id` (migration 017), and roles are tenant-scoped via the nullable `roles.tenant_id` (migration 018).

## RoleChecker — resolving access

`RoleChecker` (`src/Auth/RoleChecker.php`) is the authoritative resolver. It is constructed with the `Database` and the `PermissionRegistry`.

### hasPermission

```php
public function hasPermission(int $userId, string $permission, int $tenantId): bool
```

Every check is **tenant scoped** (WC-54): the user's effective grants include roles reached through their organizational unit, which are tenant-bound, so the resolved tenant id (from `TenantContext`) is required.

Resolution order:

1. **Registry check** — `if (!$this->registry->exists($permission)) return false;`. An unregistered permission (e.g. one whose plugin was unloaded) can never be granted.
2. **Effective permission set** — otherwise resolve the user's full effective permission set and test membership. The set is the union, over every **effective role** (see below), of that role's hierarchy-resolved permissions. Every grant — direct, role-hierarchy-inherited, or OU-inherited — is read through the SAME real-schema join (`role_permissions.permission_id → permissions.name`); there is no `role_permissions.permission_string` column.

### Effective roles (direct + OU inheritance)

A user's **effective role set** is the UNION of:

1. Their **direct role** (`users.role_id`).
2. Every role assigned to their **organizational unit AND each ancestor OU** — the `organizational_units.parent_id` chain walked up to the root — via `ou_role_assignments`, filtered to the current tenant.

So a user in a child OU inherits the roles granted to every OU above it, and OU role assignments are **additive** (they never restrict). The OU parent-chain walk has the same visited-set cycle detection and `MAX_HIERARCHY_DEPTH` bound as the role hierarchy. Because the lookups are tenant scoped, an OU role assigned in tenant A can never grant anything in tenant B.

### Role hierarchy + worker cache

`getEffectivePermissionsForRole($roleId)` walks **up** the `roles.parent_id` chain from the given role, unioning each role's directly-granted permissions (resolved by joining `role_permissions` → `permissions` and reading `permissions.name`). A higher role inherits everything its ancestors grant (`super_admin → admin → editor → viewer`).

Safety:

- **Cycle detection** via a visited-set — a malformed loop (`A → B → A`) is logged and traversal stops with the permissions collected so far.
- **Depth bound** `MAX_HIERARCHY_DEPTH = 64` — even a non-repeating-but-pathological chain cannot loop forever.

Resolved sets are memoized in two **worker-level static caches**: `$effectivePermissionCache` (per role id; a role's resolved permissions are tenant-independent) and `$effectiveUserPermissionCache` (per `userId:tenantId`, because OU membership and OU role assignments are tenant scoped). Both hold only derived, non-request data, so they are safe across requests on a persistent worker. They **must** be invalidated when any authorization input changes — `RoleChecker::clearCache()` is called by `RolesApiHandler` (role/permission writes), `UsersApiHandler` (role re-assignment or OU-membership change) and `OusApiHandler` (OU role assign/remove).

### Other helpers

- `hasRole($userId, $role, $tenantId)` — true when `$role` is in the user's effective role set (direct role + OU/ancestor-OU roles, tenant scoped).
- `getRoleForUser($userId)` — the user's primary (direct) role name only.
- `getPermissionsForUser($userId)` — directly-granted permissions for the user's primary role (no inherited set).
- `getEffectiveRolesForUser($userId, $tenantId)` — the effective role-name set (direct + OU/ancestor-OU inherited).
- `getEffectivePermissionsForUser($userId, $tenantId)` — the full effective permission set `hasPermission()` tests against.

## Enforcement at the route boundary

`RbacMiddleware` (`src/Http/RbacMiddleware.php`) enforces a route's `requiredRole` and/or `requiredPermission`. It runs inside the kernel's core pipeline **only for routes that declare a requirement** (see [Architecture](Architecture.md) for the full middleware order).

Flow:

1. If the route requires neither a role nor a permission, pass through (fail-open).
2. Extract the bearer token from the `Authorization` header (or `access_token` cookie); missing → `401`.
3. Validate the JWT via `JwtParser`; invalid/expired → `401`. The `user_id` claim must be an integer → else `401`.
4. Read the resolved tenant id from `TenantContext` (set earlier by `EnforceTenantIsolation`); an unresolved tenant → `401` (fail closed, since OU-inherited grants cannot be evaluated without it).
5. If `requiredRole` is set, enforce it via `RoleChecker::hasRole($userId, $role, $tenantId)`.
6. If `requiredPermission` is set, enforce it via `RoleChecker::hasPermission($userId, $permission, $tenantId)`; the `403` body echoes the missing permission under `required`.
7. Attach the decoded payload as `Request::$user` and call the next handler.

> Security invariant: authorization is **always** decided against the server-side store via `RoleChecker`. Role/permission claims that may appear in the JWT are never trusted for access decisions (issue #54). The core routes in `public/index.php` are protected with the legacy role string `'admin'`.

Inside a handler you can still check a permission explicitly (pass the resolved tenant id):

```php
$tenantId = TenantContext::getTenantId();
if (!$roleChecker->hasPermission($request->user->user_id, 'reports:read', $tenantId)) {
    return Response::error('Permission denied', 403);
}
```

## Roles API + tenant scoping

`RolesApiHandler` (`src/Api/RolesApiHandler.php`) provides full role CRUD, scoped by the nullable `roles.tenant_id` column (migration 018):

- `tenant_id IS NULL` → **global/system role**, visible to all tenants (the seeded `admin` id 1 and `user` id 2 are global).
- non-NULL `tenant_id` → **tenant-owned** custom role, isolated to its owner.

Visibility rules:

- **Read** (`GET /api/roles`, `/api/roles/{id}`, `/api/roles/{id}/permissions`): a tenant sees `WHERE (r.tenant_id = ? OR r.tenant_id IS NULL)`; the **system tenant (id 0)** sees every role.
- **Write** (`PATCH`/`DELETE /api/roles/{id}`): a tenant may modify only its *own* roles; a global (NULL) base role returns `404` for a tenant and is manageable only by the system tenant.
- **Create** (`POST /api/roles`): stamps the new role with the current tenant id. A role with active user assignments cannot be deleted (`409`).

### Assigning permissions (ids OR names)

Create and update accept the assigned permissions under the canonical `permissions` key. Each entry may be **either** a numeric `permissions.id` (the form the web UI sends — its checkboxes come from `GET /api/permissions`, which returns `{id, name, ...}`) **or** a `resource:action` name string; mixed arrays are accepted (`RolesApiHandler::resolvePermissionIds()`):

```json
POST /api/roles
{
  "name": "editor",
  "description": "Content editors",
  "permissions": [3, "posts:read", "posts:write"]
}
```

Ids are validated against the catalogue; names are resolved to ids via `permissions.name`. **Unknown ids/names are dropped, never fabricated**, before being linked through `role_permissions` (which references permissions by id). Every mutating write calls `RoleChecker::clearCache()` so RBAC checks never go stale.

## Permission delegation (WC-34)

Delegation lets a **role-holder grant a SUBSET of their OWN effective permissions** to a role or a user, tenant- and optionally OU-scoped, with a revocable lifecycle. It layers on top of the RBAC resolution above without replacing it.

| Component | Responsibility | File |
| --- | --- | --- |
| `permission_delegations` | Stores each delegated permission (one row per permission). | `database/migrations/014_create_permission_delegations.php` |
| `DelegationRepository` | All tenant-scoped SQL for delegations (insert/list/find/revoke + resolution lookup). | `src/Core/Delegation/DelegationRepository.php` |
| `DelegationService` | Enforces the subset invariant on create; resolves live delegated permissions for `RoleChecker`. | `src/Core/Delegation/DelegationService.php` |
| `DelegationsApiHandler` | RBAC-protected API (create/list/revoke), gated on `delegation:manage`. | `src/Api/DelegationsApiHandler.php` |
| `PermissionNotDelegableException` | Typed domain error for a subset-invariant violation → `422`. | `src/Api/Exception/PermissionNotDelegableException.php` |

### Storage model

```sql
CREATE TABLE permission_delegations (
    id              SERIAL PRIMARY KEY,
    tenant_id       INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    grantor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    grantee_type    VARCHAR(16) NOT NULL,   -- 'role' | 'user' (CHECK-constrained)
    grantee_id      INTEGER NOT NULL,
    permission      VARCHAR(255) NOT NULL,  -- a resource:action string
    ou_id           INTEGER NULL REFERENCES organizational_units(id) ON DELETE CASCADE,
    granted_at      TIMESTAMP NOT NULL DEFAULT NOW(),
    revoked_at      TIMESTAMP NULL,         -- NULL = LIVE; non-destructive revoke
    CONSTRAINT chk_permission_delegations_grantee_type CHECK (grantee_type IN ('role','user'))
);
```

- **Polymorphic grantee** — modelled as a discriminator + id pair (`grantee_type` + `grantee_id`) with a CHECK pinning the type to `role`|`user`. This keeps resolution a single equality match (no "exactly one of two FK columns" gymnastics).
- **One row per permission** — each delegated permission is an independent, individually-revocable grant.
- **Lifecycle** — a delegation is LIVE while `revoked_at IS NULL`; revocation is non-destructive (it stamps `revoked_at`) so the historical grant survives for the audit trail.
- **Indexing** — `idx_pd_resolution(tenant_id, grantee_type, grantee_id, revoked_at)` covers the hot resolution lookup; secondary indexes cover listing by grantor and by OU.

### The HARD subset invariant

> **A grantor can NEVER delegate a permission they do not themselves currently hold.**

Enforced server-side, always, in `DelegationService::delegate()`: it computes the grantor's effective permission set via `RoleChecker::getEffectivePermissionsForUser()` (direct role + role hierarchy + OU inheritance) and rejects any requested permission outside that set — or any permission not registered in the `PermissionRegistry` — by throwing `PermissionNotDelegableException`. The handler translates that into a safe `422` and writes **no row**; the internal reason (which permissions were denied) is logged, never leaked.

The `delegation:manage` permission gates **who may manage delegations** (the API route); it never widens **what** a grantor may delegate. To avoid transitive re-delegation escalation, the grantor's delegable set is their BASE RBAC effective set — the `RoleChecker` the service uses to bound a grantor is deliberately *not* delegation-aware, so "you can only delegate what RBAC grants you, never what was delegated TO you".

### How delegated permissions enter resolution

`RoleChecker` takes an optional `DelegatedPermissionResolver` (implemented by `DelegationService`). When wired (as it is in `public/index.php`), `getEffectivePermissionsForUser()` unions the user's base effective permissions with the **live** delegated permissions resolved for them, so a non-revoked delegation actually makes `hasPermission()` return `true`:

```
effective = (direct role + hierarchy + OU roles)  ∪  (live delegations to the user)  ∪  (live delegations to any of the user's effective roles)
```

Delegated grants are resolved tenant-scoped and OU-scoped:

- A **user-targeted** delegation reaches that user; a **role-targeted** delegation reaches every user whose effective role set contains that role.
- **OU scope**: a delegation with `ou_id IS NULL` applies tenant-wide; a delegation scoped to OU *X* applies only to grantees whose OU is *X* or a descendant of *X* (resolved from the user's OU + ancestor chain, mirroring OU role inheritance).
- **Cache**: delegated grants flow through the same per-user worker cache as RBAC, so create/revoke call `RoleChecker::clearCache()` to avoid serving a stale resolved set.

### API

All routes are gated on `delegation:manage` and are tenant-scoped:

- `POST /api/delegations` — `{granteeType: 'role'|'user', granteeId, permissions: string[], ouId?: int|null}`. Returns `201` (one row per permission) or `422` when the subset invariant is violated; `404` when the grantee/OU is not visible to the tenant.
- `GET /api/delegations` — list with optional `granteeType`/`granteeId`/`grantorUserId`/`includeRevoked` filters.
- `DELETE /api/delegations/{id}` — non-destructive revoke; `404` when not found / not visible / already revoked.

## Deleted/unloaded plugins: automatic denial

Because step 1 of `hasPermission()` consults the registry, removing a plugin instantly denies its permissions with no DB cleanup:

1. Before: the plugin's `getPermissions()` are in the registry; granted users pass.
2. The plugin is unloaded / hot-reloaded away → `PluginLoader::unregisterAll()` drops it, and its source entry leaves the registry.
3. After: `registry->exists('my_plugin:use')` is `false`, so `hasPermission()` returns `false` immediately even though a `role_permissions` row may still exist.

## Summary

- Permissions are `resource:action` strings; `PermissionRegistry` (in-memory) decides which **exist**, `role_permissions` (DB) decides which are **granted**.
- `CorePermissions` is the canonical built-in set, registered under the `core` source.
- Plugins declare permissions via `PluginInterface::getPermissions()`; the `PluginLoader` registers them. Unloading a plugin removes them instantly.
- `RoleChecker` resolves access: registry existence → direct grant → hierarchy inheritance, with cycle/depth-safe traversal and a worker-level cache invalidated on writes.
- `RbacMiddleware` enforces route requirements against the authoritative store and never trusts JWT role/permission claims.
- `RolesApiHandler` is tenant-scoped via `roles.tenant_id` (NULL = global), and accepts permission ids or names.
- **Delegation** (WC-34) lets a role-holder grant a SUBSET of their own effective permissions to a role or user, tenant/OU-scoped and revocable. The HARD invariant — you can never delegate a permission you do not hold — is enforced server-side in `DelegationService`, and live delegations enter `hasPermission()` resolution through the `DelegatedPermissionResolver` wired into `RoleChecker`.
</content>
