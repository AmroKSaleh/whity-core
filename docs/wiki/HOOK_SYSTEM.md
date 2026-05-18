# Hook System

Hooks allow plugins to listen for and react to system events. They provide a mediator/observer pattern for extending Whity Core without modifying the framework itself.

## Overview

The Hook System enables plugins to:

- Listen for events at key points in the request lifecycle (before/after creating a user, deleting a role, etc.)
- Modify data before it's persisted (validation filters, encryption, audit logging)
- Queue background work when events occur (async event handlers)
- React to system events across multiple plugins with predictable execution order

Hooks are executed synchronously or asynchronously, in priority order, with automatic tenant context injection for security.

## How Hooks Work

### Synchronous vs Asynchronous

**Synchronous Hooks** (Filters)

Executed immediately during request processing. Data passed to each listener is returned and becomes input for the next listener. Used for:
- Pre-save validation
- Data transformation (encryption, normalization)
- Audit logging during the request
- Access control checks

```php
$data = HookManager::dispatch('user.creating', [
    'email' => 'john@example.com',
    'name' => 'John Doe'
]);
// Listeners can modify $data before user is created
```

**Asynchronous Hooks** (Actions)

Queued for background processing, returns immediately. Used for:
- Sending emails (user signup confirmations, notifications)
- Integration with external services (webhooks, analytics)
- Expensive operations (image processing, PDF generation)
- Non-critical side effects

```php
HookManager::dispatchAsync('user.created.async', [
    'user_id' => 42,
    'email' => 'john@example.com'
]);
// Returns immediately; queued for later processing
```

## Lifecycle: When Hooks Fire

Common hook events in Whity Core:

### User Lifecycle

- `user.creating` - Before user inserted (sync) - Validate email, hash passwords
- `user.created` - After user created (sync) - Log creation, update indexes
- `user.created.async` - Queued async after user created - Send welcome email, webhook
- `user.updating` - Before user updated (sync) - Validate new data
- `user.updated` - After user updated (sync) - Update search indexes
- `user.deleting` - Before user deleted (sync) - Check permissions, cascade deletes
- `user.deleted` - After user deleted (sync) - Clean up related data
- `user.deleted.async` - Queued async - Notify integrations of deletion

### Role Lifecycle

- `role.creating` - Before role inserted (sync)
- `role.created` - After role created (sync)
- `role.updating` - Before role updated (sync)
- `role.updated` - After role updated (sync)
- `role.deleting` - Before role deleted (sync) - Check for assigned users
- `role.deleted` - After role deleted (sync)

### Tenant Lifecycle

- `tenant.creating` - Before tenant created (sync)
- `tenant.created` - After tenant created (sync)
- `tenant.updating` - Before tenant updated (sync)
- `tenant.updated` - After tenant updated (sync)
- `tenant.deleting` - Before tenant deleted (sync) - Check for users
- `tenant.deleted` - After tenant deleted (sync)
- `tenant.deleted.async` - Queued async - Archive tenant data, notify admins

### Permission Lifecycle

- `permission.registered` - When plugin registers permissions (sync) - Logging hook

## Priority-Based Execution

Listeners execute in priority order. Lower numbers execute first.

```php
// Priority 5 (runs first)
HookManager::listen('user.creating', function($data, $context) {
    // Validate email format
    return $data;
}, 5);

// Priority 10 (default, runs second)
HookManager::listen('user.creating', function($data, $context) {
    // Hash password
    return $data;
}, 10);

// Priority 20 (runs last)
HookManager::listen('user.creating', function($data, $context) {
    // Audit log
    return $data;
}, 20);
```

Use priority to control execution sequence:
- Priority 0-5: Core framework validators
- Priority 10: Default (use this if no preference)
- Priority 20+: Side effects (logging, analytics)

## Hook Payloads: What Data Is Passed

Hooks automatically inject context data alongside your payload:

```php
// Callback signature
function ($data, $context) {
    // $data: Your event payload
    // $context: Automatic context injected by framework
    return $data; // Modified or original data
}

// $context always contains:
$context = [
    'tenant_id' => 42,           // Current tenant (extracted from JWT)
    'timestamp' => 1684334800,   // Unix timestamp when hook fired
];
```

### Important: Hook Payloads Are Scalar-Only

Hook payloads must contain **only primitive values** (strings, integers, booleans, arrays):

```php
// GOOD: Scalar data only
HookManager::dispatch('user.created', [
    'user_id' => 42,
    'email' => 'john@example.com',
    'name' => 'John Doe'
]);

// BAD: Never pass model instances
$user = User::find(42);
HookManager::dispatch('user.created', [
    'user' => $user  // WRONG! Objects can be mutated by plugins
]);
```

**Why?** If you pass model instances, plugins could mutate them directly and escape the hook chain's control, breaking data integrity. Extracting scalars ensures plugins can't bypass the framework's safeguards.

## Hook Context: Tenant Isolation

Context is automatically injected and locked (read-only):

```php
HookManager::listen('user.creating', function($data, $context) {
    // $context['tenant_id'] is guaranteed to match the request's tenant
    // Cannot access other tenants, even if you try
    
    // Safe to use in queries:
    $count = $db->query(
        'SELECT COUNT(*) FROM users WHERE tenant_id = ?',
        [$context['tenant_id']]
    );
    
    return $data;
}, 10);
```

The context is read-only and set by the framework before any plugin code runs. Plugins cannot:
- Modify the tenant ID
- Access data from other tenants
- Escape the current tenant's isolation boundary

## Registering Hook Listeners

Plugins register listeners in their `onEnable()` method:

```php
<?php
namespace Plugins\MyPlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Hooks\HookManager;

class Plugin implements PluginInterface
{
    public function onEnable(HookManager $hookManager): void
    {
        // Register sync listener: before user creation
        $hookManager->listen('user.creating', function($data, $context) {
            // Validate email doesn't already exist in this tenant
            // Add audit log entry
            return $data;
        }, 5);
        
        // Register sync listener: after user creation
        $hookManager->listen('user.created', function($data, $context) {
            // Update search indexes
            return $data;
        }, 10);
        
        // Register async listener: async after user creation
        $hookManager->listen('user.created.async', function($data, $context) {
            // Send welcome email (no return value)
            // This won't actually execute async yet (Phase 2)
        }, 10);
    }
    
    public function onDisable(): void
    {
        // Listeners are automatically cleared when plugin is disabled
    }
}
```

## Code Examples

### Example 1: Audit Logging Plugin

```php
class AuditPlugin implements PluginInterface
{
    public function id(): string { return 'audit-logger'; }
    public function name(): string { return 'Audit Logging'; }
    public function version(): string { return '1.0.0'; }
    
    public function onEnable(HookManager $hookManager): void
    {
        // Log all user operations
        foreach (['user.created', 'user.updated', 'user.deleted'] as $event) {
            $hookManager->listen($event, function($data, $context) {
                $log = [
                    'tenant_id' => $context['tenant_id'],
                    'timestamp' => $context['timestamp'],
                    'event' => $event,
                    'data' => $data
                ];
                file_put_contents(
                    '/logs/audit.json',
                    json_encode($log) . "\n",
                    FILE_APPEND
                );
                return $data;
            }, 20); // Low priority (runs last)
        }
    }
}
```

### Example 2: Data Validation Plugin

```php
class ValidationPlugin implements PluginInterface
{
    public function id(): string { return 'validators'; }
    public function onEnable(HookManager $hookManager): void
    {
        // Validate user email before creation
        $hookManager->listen('user.creating', function($data, $context) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('Invalid email format');
            }
            
            // Check email isn't already in use in this tenant
            $existing = $db->query(
                'SELECT id FROM users WHERE email = ? AND tenant_id = ?',
                [$data['email'], $context['tenant_id']]
            );
            
            if ($existing->fetch() !== false) {
                throw new \InvalidArgumentException('Email already in use');
            }
            
            return $data;
        }, 5); // High priority (runs first)
    }
}
```

### Example 3: Password Hashing Plugin

```php
class PasswordHasherPlugin implements PluginInterface
{
    public function id(): string { return 'password-hasher'; }
    public function onEnable(HookManager $hookManager): void
    {
        $hookManager->listen('user.creating', function($data, $context) {
            if (isset($data['password'])) {
                // Hash password before storage
                $data['password'] = password_hash(
                    $data['password'],
                    PASSWORD_BCRYPT
                );
            }
            return $data;
        }, 8); // High priority
    }
}
```

## Best Practices

### 1. Use Correct Hook for the Job

- **Creating/Updating hooks**: For validation, transformation, pre-processing
- **Created/Updated hooks**: For logging, indexing, synchronous side effects
- **Async hooks**: For external integrations, emails, slow operations

```php
// RIGHT: Validation in .creating
$hookManager->listen('user.creating', function($data, $context) {
    if (strlen($data['password']) < 8) {
        throw new \InvalidArgumentException('Password too short');
    }
    return $data;
}, 5);

// WRONG: Validation in .created.async
// Async hooks don't block, user would be created with bad data
```

### 2. Return Data in Sync Hooks

Always return the modified (or unmodified) data in synchronous hooks:

```php
// GOOD
$hookManager->listen('user.creating', function($data, $context) {
    $data['email'] = strtolower($data['email']);
    return $data; // Chain to next listener
}, 10);

// BAD: Not returning data breaks the chain
$hookManager->listen('user.creating', function($data, $context) {
    $data['email'] = strtolower($data['email']);
    // Missing return statement
}, 10);
```

### 3. Use Context for Tenant Safety

Always use `$context['tenant_id']` when scoping queries:

```php
// GOOD: Using context tenant
$hookManager->listen('user.creating', function($data, $context) {
    $exists = $db->query(
        'SELECT id FROM users WHERE email = ? AND tenant_id = ?',
        [$data['email'], $context['tenant_id']] // Always include tenant
    );
    return $data;
}, 5);

// DANGEROUS: Not scoping by tenant
$hookManager->listen('user.creating', function($data, $context) {
    $exists = $db->query(
        'SELECT id FROM users WHERE email = ?', // Missing tenant filter!
        [$data['email']]
    );
    return $data;
}, 5);
```

### 4. Handle Exceptions Gracefully

If a validation fails, throw an exception. The framework catches it and returns an error:

```php
$hookManager->listen('user.creating', function($data, $context) {
    if (isset($data['age']) && $data['age'] < 18) {
        throw new \InvalidArgumentException(
            'Users must be 18 or older'
        );
    }
    return $data;
}, 5);
```

### 5. Keep Hooks Stateless

Never use static variables or object state in hooks:

```php
// GOOD: Stateless
$hookManager->listen('user.created', function($data, $context) {
    $count = $db->query('SELECT COUNT(*) FROM users')->fetch()['count'];
    return $data;
}, 10);

// BAD: Static state
private static $callCount = 0;
$hookManager->listen('user.created', function($data, $context) {
    self::$callCount++; // WRONG! Persists across requests in workers
    return $data;
}, 10);
```

## Hook Events Reference

### Core Framework Hooks

| Event | Type | When | Data |
|-------|------|------|------|
| `user.creating` | sync | Before user INSERT | [email, name, password, role_id, tenant_id] |
| `user.created` | sync | After user INSERT | [id, email, name, role_id, tenant_id] |
| `user.created.async` | async | Queued after INSERT | [user_id, email, name] |
| `user.updating` | sync | Before user UPDATE | [id, email, name, role_id] |
| `user.updated` | sync | After user UPDATE | [id, email, name, role_id] |
| `user.deleting` | sync | Before user DELETE | [id, email, name] |
| `user.deleted` | sync | After user DELETE | [id, email, name] |
| `user.deleted.async` | async | Queued after DELETE | [user_id, email] |
| `role.creating` | sync | Before role INSERT | [name, description] |
| `role.created` | sync | After role INSERT | [id, name, description] |
| `role.updating` | sync | Before role UPDATE | [id, name, description] |
| `role.updated` | sync | After role UPDATE | [id, name, description] |
| `role.deleting` | sync | Before role DELETE | [id, name] |
| `role.deleted` | sync | After role DELETE | [id, name] |
| `tenant.creating` | sync | Before tenant INSERT | [name, slug] |
| `tenant.created` | sync | After tenant INSERT | [id, name, slug] |
| `tenant.updating` | sync | Before tenant UPDATE | [id, name, slug] |
| `tenant.updated` | sync | After tenant UPDATE | [id, name, slug] |
| `tenant.deleting` | sync | Before tenant DELETE | [id, name, slug] |
| `tenant.deleted` | sync | After tenant DELETE | [id, name, slug] |
| `tenant.deleted.async` | async | Queued after DELETE | [tenant_id, name] |
| `permission.registered` | sync | When plugin registers | [plugin_id, permissions] |

## Summary

- **Hooks** enable plugins to listen and react to system events
- **Sync hooks** modify data; run before or after database operations
- **Async hooks** queue background work; return immediately
- **Priority** controls execution order (lower = earlier)
- **Context** is automatically injected with tenant_id and timestamp
- **Payloads** are scalar-only to prevent reference escape
- **Listeners** are registered in `onEnable()` and cleared on `onDisable()`

See [PERMISSION_SYSTEM.md](PERMISSION_SYSTEM.md) for how permissions work alongside hooks, and [CONTRIBUTING.md](../../CONTRIBUTING.md) for plugin development guidelines.
