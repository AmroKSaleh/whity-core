# Architecture

## Core Principles

### 1. Stateless Controllers
No persistent state in worker processes. Every request = fresh instance.

### 2. RBAC at the Boundary
All permission checks in one layer. Impossible to bypass.

### 3. Plugins, Not Forks
Extend via plugins implementing PluginInterface.

### 4. Per-Tenant Isolation
Each tenant has own SQLite. Queries auto-scoped by `tenant_id`.

### 5. Type Safety
PHP 8.4 strict types + PHPStan static analysis.

## Runtime Flow

```
Request
  ↓ [Auth] Verify JWT, extract user/tenant
  ↓ [RBAC] Check permissions
  ↓ [Router] Route to plugin
  ↓ [Logic] Business logic
  ↓ [QueryGuard] Inject WHERE tenant_id = ?
  ↓ [Database] Execute
  ↓
Response
```

## Concurrency

FrankenPHP workers handle 10k+ concurrent users:

```
Worker Process (1000+ requests)
├─ Request 1 → Fresh controller
├─ Request 2 → Fresh controller
└─ Request 3 → Fresh controller
```

Stateless = no memory leaks.

## Multi-Tenancy

```
tenant1.db ← Tenant A (isolated)
tenant2.db ← Tenant B (isolated)

All queries: WHERE tenant_id = ?
```

Enforced at QueryGuard layer.

## Plugins

Plugins load at startup from `/plugins/` directory:

```
whity-core (Framework)
    ↓
[Plugins Load]
    ↓
plugins/admin/Plugin.php
plugins/custom/Plugin.php
```

- Implement PluginInterface
- Can't access internals
- Exceptions don't crash
- Can register permissions
- Can extend schema

See [Installation](Installation.md) and [Plugin Development](Plugin-Development.md) for details.
