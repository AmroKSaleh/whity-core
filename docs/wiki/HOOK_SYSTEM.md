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

**Asynchronous (`dispatchAsync`)** — PERSISTS the event to the durable event spine (WC-154/#162): it writes an immutable `domain_events` row plus a `pending` `event_outbox` row via `DomainEventStore::append(...)`, then returns immediately. This replaces the retired log-only `Queue::push` stub, which dropped every event. The current tenant (`TenantContext`) and actor (`AuditContext`) are promoted to first-class columns, and the aggregate is derived from the dotted event name (`user.created.async` → `user`) plus the payload's own `id`; the raw payload is stored as JSON (no `_context` wrapper). Use for slow or non-critical side effects — a failure to persist is logged server-side and never propagated to the caller. A separate **relay worker** (not `dispatchAsync` itself) drains the outbox and performs downstream work such as sending notifications or calling external endpoints. A `HookManager` constructed without a store (plugin-loader / CLI contexts that only register listeners) treats `dispatchAsync` as a safe no-op.

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

The synchronous `dispatch()` passes a context array (built from the current request) as the second argument to each listener. `dispatchAsync()` instead records that same context as first-class `domain_events` columns (`tenant_id`, `actor_user_id`, `occurred_at`) rather than wrapping it into the payload. The shape of the sync context:

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

`PluginLoader::registerCapabilities()` reads `getHooks()` and subscribes each callback through `HookManager::listen()` — but **wrapped in a per-plugin error boundary** (`wrapHookCallback()`). A throwing hook callback is caught and logged, the original `$data` is returned unchanged (so a bad listener can't corrupt the chain), and the failure is recorded against the plugin's lifecycle (after `MAX_CONSECUTIVE_ERRORS = 3` the plugin is taken out of service).

The loader records the exact wrapped callbacks it registered so it can cleanly `removeListener()` them when the plugin is disabled, removed, or hot-reloaded. See [Plugin-Development](Plugin-Development.md) and the plugin lifecycle in [Architecture](Architecture.md#plugin-system).

**One exception crosses that boundary: `Whity\Sdk\Hooks\HookVetoException` (SDK 1.15, WC-713).** The isolation above is what makes a broken plugin survivable, but it also meant a plugin could never say *"do not do this"* — its objection was logged and the host carried on regardless. A veto is therefore re-thrown to the dispatching handler, and does **not** count toward the failure threshold (a veto is a healthy plugin doing its job, not a fault). See the deletion contract below.

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
| `datatype.lifecycle.changing` | `DataTypeLifecycleService` | **Vetoable.** Fires before the write for `trash`/`restore`/`retire`/`delete` on a plugin-declared data type. See [Plugin-Data-Types](Plugin-Data-Types.md#refusing-a-transition-datatypelifecyclechanging). |
| `datatype.lifecycle.changed` | `DataTypeLifecycleService` | Observation only, after a transition that actually committed. Same payload. |

`UsersApiHandler`, `TenantsApiHandler`, and `OusApiHandler` are also constructed with the `HookManager`, so check those handlers for the exact `user.*` / `tenant.*` / `ou.*` events they emit; treat any event not in the table above as something to confirm in source rather than assume.

### Deletion is transactional (WC-713)

For `tenant.*`, `ou.*` and `role.*`, `TenantsApiHandler::delete()`, `OusApiHandler::delete()` and `RolesApiHandler::delete()` run

```
*.deleting  →  DELETE  →  *.deleted        [ all inside ONE transaction ]
                                            ↓ commit
                                        *.deleted.async
```

Three guarantees follow:

1. **`*.deleting` still sees the row.** It is the read point — look up whatever you need about the entity there, because by `*.deleted` it is gone (within the transaction).
2. **A throwing listener rolls the DELETE back.** Previously the DELETE committed on its own and `*.deleted` fired afterwards, so a plugin's cleanup was best-effort: a failure left the caller a 500 *and* a committed delete, with the plugin's own rows orphaned against a parent that no longer existed. Core tables survive that on their `ON DELETE CASCADE` constraints; plugin tables have no FK, so the hook was their only mechanism.
3. **`*.deleted.async` fires only after a successful commit**, so no durable/outbox event ever announces a deletion that was rolled back.

Throw `HookVetoException` from either hook to refuse the deletion deliberately; the API answers **409 Conflict** with your `reason()` under `details.reason`. Any other Throwable rolls the delete back too, but surfaces as a generic 500.

`user.deleted` is deliberately **not** part of this contract: it has no `user.deleting` counterpart, and its core listeners send mail — which must neither hold a transaction open nor be able to undo a membership removal.

> **Core subscriber — the audit trail (WC-34).** `AuditLogger` (`src/Core/Audit/AuditLogger.php`) subscribes (at priority 50) to the post-action `role.*`, `user.*`, `tenant.*` and `ou.*` lifecycle hooks and writes a row to `audit_log` for each — so security-relevant mutations are audited without per-handler code. To support this, `UsersApiHandler::update()`/`delete()` now also fire `user.updated` / `user.deleted` (carrying `id` + `tenant_id`). The listeners return `$data` unchanged. See [AUDIT_TRAIL](AUDIT_TRAIL.md).

## Best practices

1. **Always return data from sync hooks** — a missing `return` breaks the filter chain for downstream listeners.
2. **Scope by tenant** — use `$context['tenant_id']` in any query a listener runs.
3. **Keep payloads scalar** — pass ids/strings, not live model objects, so listeners can't mutate shared object state and escape the chain.
4. **No request state in statics** — workers persist; never accumulate per-request state in a static variable inside a listener.
5. **Fail loudly in validators** — throwing in a sync `*.creating`/`*.updating` listener is fine; the plugin error boundary will catch a plugin listener's throw, log it, and leave the data unchanged (and count it toward the plugin's failure threshold).
6. **Veto with `HookVetoException`, not a bare throw** — on the deletion paths and on `datatype.lifecycle.changing` it is the only Throwable that reaches the caller as a clean 409 with your reason; anything else is a generic 500 (deletion) or is swallowed while the transition proceeds (data-type lifecycle). Write `reason()` for a human administrator, never as raw internal error text — it is shown to the client. A veto never counts toward the plugin failure breaker.

## Summary

- `HookManager` is an instance-based Mediator/Observer; `dispatch()` is a synchronous filter chain, `dispatchAsync()` queues background work.
- Listeners run in ascending priority (default 10); every dispatch injects `{tenant_id, timestamp}` context.
- Plugins declare hooks via `PluginInterface::getHooks()`; the loader subscribes them through `HookManager::listen()` inside a per-plugin error boundary and unsubscribes them on disable/reload via `removeListener()`.
- Core fires worker lifecycle, navigation, permission-registration, and role lifecycle events; confirm `user.*`/`tenant.*`/`ou.*` payload shapes in their handlers.
- Entity deletion (`tenant`/`ou`/`role`) runs `*.deleting` → DELETE → `*.deleted` in one transaction; a throwing listener rolls it back, `HookVetoException` turns that into a 409, and `*.deleted.async` fires only after commit.
</content>
