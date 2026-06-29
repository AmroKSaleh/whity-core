# Hook System

Hooks let plugins (and the core) react to and modify events at key points, without modifying the framework. The implementation is a Mediator/Observer pattern in `HookManager` (`src/Core/Hooks/HookManager.php`). This page is grounded in the current source.

Related: [Architecture](Architecture.md) · [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [TENANT_ISOLATION](TENANT_ISOLATION.md) · [Plugin-Development](Plugin-Development.md).

## HookManager API

`HookManager` is an **instance** (not static). In `public/index.php` a single `HookManager` is created at worker boot and registered in the service container via `Whity\register_service(HookManager::class, $hookManager)`.

```php
// Register a listener (lower priority number = runs earlier; default 10)
public function listen(string $eventName, callable $callback, int $priority = 10): void

// Synchronous filter chain: runs all listeners in priority order, threading the
// returned array through each; returns the final data.
public function dispatch(string $eventName, array $data): array

// Asynchronous action: injects context and queues the payload for background work.
public function dispatchAsync(string $eventName, array $payload): void

// Remove a previously-registered listener (used by plugin hot-reload).
public function removeListener(string $eventName, callable $callback): bool

// Inspect registered listeners.
public function getListeners(?string $eventName = null): array
```

## Synchronous vs asynchronous

**Synchronous (`dispatch`)** — runs immediately in the request. Each listener receives `($data, $context)` and returns the (possibly modified) `$data`, which becomes the input to the next listener. If a listener returns a non-array, the data is left unchanged for that step. Use for validation, transformation, and synchronous side effects.

```php
$data = $hookManager->dispatch('role.creating', [
    'name' => 'editor',
    'description' => 'Content editors',
    'tenant_id' => 7,
]);
// Listeners may adjust $data before the role is written.
```

**Asynchronous (`dispatchAsync`)** — injects context under `_context` and pushes the payload onto the `whity-core-async-hooks` queue (`Whity\Core\Queue\Queue::push(...)`), returning immediately. Use for slow or non-critical side effects; a queue worker (not `dispatchAsync` itself) is responsible for consuming the queue and performing any downstream work such as sending notifications or calling external endpoints.

```php
$hookManager->dispatchAsync('role.created.async', ['id' => 12, 'tenant_id' => 7]);
```

## Priority-based execution

Listeners run in ascending priority order (lower runs first); the default priority is `10`. Internally listeners are bucketed by priority and the buckets are `ksort`ed at dispatch time.

```php
$hookManager->listen('role.creating', $validate, 5);   // runs first
$hookManager->listen('role.creating', $transform, 10);  // default
$hookManager->listen('role.creating', $audit, 20);      // runs last
```

Suggested convention: `0–5` core validators, `10` default, `20+` side effects (logging, analytics).

## Context injection

Every `dispatch`/`dispatchAsync` injects a context array built from the current request:

```php
$context = [
    'tenant_id' => TenantContext::getTenantId(), // current tenant (0 = system, null if unresolved)
    'timestamp' => time(),
];
```

For sync hooks the context is the **second argument** to each listener; for async hooks it is merged into the payload under the `_context` key. Use `$context['tenant_id']` whenever a listener queries the database so its work stays within the current tenant (see [TENANT_ISOLATION](TENANT_ISOLATION.md)).

```php
$hookManager->listen('user.creating', function (array $data, array $context): array {
    // scope any lookups by the request's tenant
    // ... $context['tenant_id'] ...
    return $data;
}, 5);
```

## How plugins register hooks

Plugins declare hooks **declaratively** via `PluginInterface::getHooks()` (`sdk/src/PluginInterface.php`) — there is **no** `onEnable(HookManager)` method. `getHooks()` returns a map of event name → subscription, where a subscription is:

- a `callable` with signature `function (array $data, array $context): array`, or
- an array `['callback' => callable, 'priority' => int]`, or
- a list of either of the above.

```php
final class AuditPlugin implements \Whity\Sdk\PluginInterface
{
    public function getName(): string { return 'audit-logger'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getMigrations(): array { return []; }

    public function getHooks(): array
    {
        return [
            'role.created' => [
                'callback' => function (array $data, array $context): array {
                    // record an audit entry; always return the (un)modified data
                    return $data;
                },
                'priority' => 20,
            ],
        ];
    }
}
```

`PluginLoader::registerCapabilities()` reads `getHooks()` and subscribes each callback through `HookManager::listen()` — but **wrapped in a per-plugin error boundary** (`wrapHookCallback()`). A throwing hook callback is caught and logged, the original `$data` is returned unchanged (so a bad listener can't corrupt the chain), and the failure is recorded against the plugin's lifecycle (after `MAX_CONSECUTIVE_ERRORS = 3` the plugin is taken out of service). The loader records the exact wrapped callbacks it registered so it can cleanly `removeListener()` them when the plugin is disabled, removed, or hot-reloaded. See [Plugin-Development](Plugin-Development.md) and the plugin lifecycle in [Architecture](Architecture.md#plugin-system).

## Events fired by the core

These events are dispatched by the current core code (verify in source before relying on payload shapes):

| Event | Where | Notes |
| --- | --- | --- |
| `worker.boot` | `public/index.php` | Once per worker, at boot (worker mode). |
| `worker.request.start` | `public/index.php` | At the start of each request. |
| `worker.request.end` | `public/index.php` | In the request `finally` block. |
| `navigation.register` | `public/index.php` (core listener) + `NavigationApiHandler` | Filter chain that assembles navigation items; core registers Dashboard/Users/Roles/OUs/Tenants/Settings. |
| `permission.registered` | `PermissionRegistry::storeAndDispatch()` | Fires on registration with `plugin_id`, `source`, `permissions`. |
| `role.creating` / `role.created` | `RolesApiHandler::create()` | Filter before insert; sync notify after. |
| `role.created.async` | `RolesApiHandler::create()` | Queued async after create. |
| `role.updating` / `role.updated` | `RolesApiHandler::update()` | Filter before / notify after update. |
| `role.deleting` / `role.deleted` | `RolesApiHandler::delete()` | Filter before / notify after delete. |
| `role.deleted.async` | `RolesApiHandler::delete()` | Queued async after delete. |

`UsersApiHandler`, `TenantsApiHandler`, and `OusApiHandler` are also constructed with the `HookManager`, so check those handlers for the exact `user.*` / `tenant.*` / `ou.*` events they emit; treat any event not in the table above as something to confirm in source rather than assume.

> **Core subscriber — the audit trail (WC-34).** `AuditLogger` (`src/Core/Audit/AuditLogger.php`) subscribes (at priority 50) to the post-action `role.*`, `user.*`, `tenant.*` and `ou.*` lifecycle hooks and writes a row to `audit_log` for each — so security-relevant mutations are audited without per-handler code. To support this, `UsersApiHandler::update()`/`delete()` now also fire `user.updated` / `user.deleted` (carrying `id` + `tenant_id`). The listeners return `$data` unchanged. See [AUDIT_TRAIL](AUDIT_TRAIL.md).

## Best practices

1. **Always return data from sync hooks** — a missing `return` breaks the filter chain for downstream listeners.
2. **Scope by tenant** — use `$context['tenant_id']` in any query a listener runs.
3. **Keep payloads scalar** — pass ids/strings, not live model objects, so listeners can't mutate shared object state and escape the chain.
4. **No request state in statics** — workers persist; never accumulate per-request state in a static variable inside a listener.
5. **Fail loudly in validators** — throwing in a sync `*.creating`/`*.updating` listener is fine; the plugin error boundary will catch a plugin listener's throw, log it, and leave the data unchanged (and count it toward the plugin's failure threshold).

## Summary

- `HookManager` is an instance-based Mediator/Observer; `dispatch()` is a synchronous filter chain, `dispatchAsync()` queues background work.
- Listeners run in ascending priority (default 10); every dispatch injects `{tenant_id, timestamp}` context.
- Plugins declare hooks via `PluginInterface::getHooks()`; the loader subscribes them through `HookManager::listen()` inside a per-plugin error boundary and unsubscribes them on disable/reload via `removeListener()`.
- Core fires worker lifecycle, navigation, permission-registration, and role lifecycle events; confirm `user.*`/`tenant.*`/`ou.*` payload shapes in their handlers.
</content>
