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
plugins:manage
```

## PermissionRegistry — the in-memory catalogue

`PermissionRegistry` (`src/Core/RBAC/PermissionRegistry.php`) holds every permission the platform currently knows about, organized by **source**: the literal `core` for built-ins, or the plugin name for plugin permissions.

```php
// Strict registration (validates resource:action), used for first-class sources.
$registry->register('core', CorePermissions::all());

// Plugin registration entry point used by PluginLoader.
// (Does NOT enforce the pattern — preserves existing plugin/test behaviour.)
$registry->registerPermissions('my-plugin', ['my_plugin:use', 'my_plugin:admin']);

// Queries
$registry->exists('users:read');            // true  (alias: permissionExists())
$registry->getAll();                          // ['permission' => 'source', ...]
$registry->getBySource('core');               // ['users:read', ...]
$registry->getPluginPermissions('my-plugin'); // plugin-only (excludes core)
$registry->getAllActivePermissions();         // ['plugin' => [...]] (excludes core)
```

Key behaviours:

- **Lazy core registration** — core permissions register themselves on first read (`ensureCoreRegistered()`), so validation works even without explicit bootstrap wiring (issue #55).
- **Plugins are the single source of truth for their own permissions** — when a plugin is unloaded, its source entry disappears from the registry, so its permissions instantly stop existing. There are no orphaned permission rows to clean up.
- **Worker-level state** — the registry holds nothing request-specific, so it is safe to share across the requests a FrankenPHP worker serves.
- Registration dispatches a `permission.registered` hook via the optional `HookManager` (`plugin_id`, `source`, `permissions`).

### How a plugin declares permissions

Plugins declare permissions through the declarative `PluginInterface::getPermissions()` (`src/Core/PluginInterface.php`) — there is **no** `onEnable()` method. The `PluginLoader` reads the array and calls `PermissionRegistry::registerPermissions($plugin->getName(), $plugin->getPermissions())` (`PluginLoader::registerCapabilities()`).

```php
final class MyPlugin implements \Whity\Core\PluginInterface
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

1. **Registry check** — `if (!$this->registry->permissionExists($permission)) return false;`. An unregistered permission (e.g. one whose plugin was unloaded) can never be granted.
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

## Deleted/unloaded plugins: automatic denial

Because step 1 of `hasPermission()` consults the registry, removing a plugin instantly denies its permissions with no DB cleanup:

1. Before: the plugin's `getPermissions()` are in the registry; granted users pass.
2. The plugin is unloaded / hot-reloaded away → `PluginLoader::unregisterAll()` drops it, and its source entry leaves the registry.
3. After: `registry->permissionExists('my_plugin:use')` is `false`, so `hasPermission()` returns `false` immediately even though a `role_permissions` row may still exist.

## Summary

- Permissions are `resource:action` strings; `PermissionRegistry` (in-memory) decides which **exist**, `role_permissions` (DB) decides which are **granted**.
- `CorePermissions` is the canonical built-in set, registered under the `core` source.
- Plugins declare permissions via `PluginInterface::getPermissions()`; the `PluginLoader` registers them. Unloading a plugin removes them instantly.
- `RoleChecker` resolves access: registry existence → direct grant → hierarchy inheritance, with cycle/depth-safe traversal and a worker-level cache invalidated on writes.
- `RbacMiddleware` enforces route requirements against the authoritative store and never trusts JWT role/permission claims.
- `RolesApiHandler` is tenant-scoped via `roles.tenant_id` (NULL = global), and accepts permission ids or names.
</content>
