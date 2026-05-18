# Contributing to Whity Core

Thank you for contributing! See [README.md](README.md) for architecture overview.

## License

By contributing, you agree your code is licensed under AGPL v3.0 + Commons Clause.

## Key Architecture Principles

### 1. Stateless Controllers (CRITICAL)

FrankenPHP keeps workers alive in memory. **Never use static state:**

```php
// GOOD
class TaskController extends BaseController {
    public function store(Request $request) {
        return Task::create($request->validated());
    }
}

// WRONG - Leaks between requests!
class TaskController extends BaseController {
    private static $cache = [];  // FORBIDDEN
    
    public function store(Request $request) {
        self::$cache[] = $request->input();  // User A's data -> User B!
    }
}
```

### 2. RBAC Enforcement

Every operation must verify permissions:

```php
$this->authorize($user, 'tasks:update', $task);
```

### 3. Tenant Isolation

All queries must filter by tenant_id:

```php
// CORRECT
Task::where('tenant_id', $user->tenant_id)->get();

// WRONG
Task::all();  // User A sees User B's data!
```

## Development Setup

```bash
docker-compose up
./vendor/bin/phpunit
```

## Pull Request Checklist

- [ ] No static properties added
- [ ] RBAC checks included (if data operation)
- [ ] Tenant isolation preserved
- [ ] Tests written and passing
- [ ] No hardcoded values (use config)

See [SECURITY.md](SECURITY.md) for security requirements.

## Plugin Development Checklist

Use this checklist when developing a plugin:

### Core Requirements

- [ ] Plugin implements `PluginInterface`
- [ ] Plugin has unique ID (e.g., `my-plugin`)
- [ ] Plugin version follows semver (e.g., `1.0.0`)
- [ ] `onEnable()` registers all permissions
- [ ] `onDisable()` cleans up listeners and hooks

### Permissions

- [ ] All permissions registered in `onEnable()` with `PermissionRegistry`
- [ ] Permissions follow naming convention: `plugin-id.resource.action`
- [ ] Every protected operation checks `RoleChecker::hasPermission()`
- [ ] Permission checks occur at API handler entry point

### Safe Database Queries (Tenant Isolation)

CRITICAL: All queries must be scoped to the current tenant.

```php
// GOOD: Always include tenant_id filter
$sql = 'SELECT * FROM users WHERE email = ? AND tenant_id = ?';
$user = $db->query($sql, [$email, TenantContext::getTenantId()])->fetch();

// ALSO GOOD: Use ScopesToTenant trait
$user = new User();
$user->setTenantIdBeforePersist(); // Auto-sets tenant_id from context
$user->save();

// DANGEROUS: Missing tenant filter
$sql = 'SELECT * FROM users WHERE email = ?'; // User from any tenant!
$user = $db->query($sql, [$email])->fetch();
```

Rules:

- [ ] All SELECT queries include `AND tenant_id = ?`
- [ ] All INSERT statements call `setTenantIdBeforePersist()` before save
- [ ] All UPDATE/DELETE queries include `WHERE tenant_id = ?`
- [ ] No cross-tenant data can leak through queries
- [ ] Validate tenant boundary with `validateTenantBoundary()` before operations

### Registering Hooks and Permissions

```php
public function onEnable(HookManager $hookManager, PermissionRegistry $registry): void
{
    // Register permissions first
    $registry->registerPermissions($this->id(), [
        'my-plugin.use',
        'my-plugin.admin',
    ]);
    
    // Register hooks
    $hookManager->listen('user.creating', function($data, $context) {
        // Validate user data
        // Return modified $data
        return $data;
    }, 5); // Priority: lower = earlier
    
    $hookManager->listen('user.created', function($data, $context) {
        // Sync side effects (logging, indexing)
        return $data;
    }, 10);
}
```

- [ ] Permissions registered before hooks
- [ ] Hooks use correct event names
- [ ] Hooks assigned appropriate priorities
- [ ] Sync hooks return modified data
- [ ] Async hooks don't expect return values

### Hook Payload Best Practices

CRITICAL: Hook payloads must contain only scalar data, never objects.

```php
// GOOD: Scalars only
HookManager::dispatch('user.created', [
    'user_id' => 42,
    'email' => 'john@example.com',
    'name' => 'John Doe'
]);

// BAD: Never pass model instances
$user = User::find(42);
HookManager::dispatch('user.created', [
    'user' => $user  // WRONG! Breaks encapsulation
]);
```

Rules:

- [ ] Hook payloads contain only strings, integers, booleans, arrays
- [ ] No model instances passed to hooks
- [ ] No database connections in payloads
- [ ] No object state that could be mutated
- [ ] Extract data: `['id' => $user->id, 'email' => $user->email]`

### State Management

- [ ] No static properties in controllers/handlers
- [ ] No request-scoped state persisted between requests
- [ ] Fresh instance created for every request
- [ ] FrankenPHP worker processes are stateless

### Testing

- [ ] Tests verify permission checks work
- [ ] Tests verify tenant isolation (one tenant can't see another's data)
- [ ] Tests verify hooks are called with correct payloads
- [ ] Minimum 80% code coverage
- [ ] Tests pass with `./vendor/bin/phpunit`

### Documentation

- [ ] README documents what the plugin does
- [ ] README lists all permissions it registers
- [ ] README includes setup/configuration instructions
- [ ] Code comments explain complex logic
- [ ] Example API calls provided for endpoints

## core_schema_migrations Table

The `core_schema_migrations` table tracks core system migrations and must never be used by plugins.

Rules:

- [ ] Plugin migrations must use standard `schema_migrations` table
- [ ] Never query or write to `core_schema_migrations`
- [ ] Core updates are isolated from plugin updates
- [ ] Plugin can safely manage own migration state independently

```php
// GOOD: Plugin migration (uses schema_migrations table)
public function up(Database $db): void
{
    $db->exec('INSERT INTO schema_migrations ...');
}

// WRONG: Plugin migration (touches core table)
public function up(Database $db): void
{
    $db->exec('INSERT INTO core_schema_migrations ...'); // FORBIDDEN
}
```

## TenantContext and Request Lifecycle

Availability: TenantContext is available in all request handlers after authentication middleware runs.

Guaranteed Properties:

- `TenantContext::getTenantId()` returns the current request's tenant ID
- `TenantContext::hasTenant()` returns true if set
- Context is locked (cannot be changed by plugins)

Cleanup:

- `TenantContext::reset()` is called by framework after response is sent
- Required for FrankenPHP worker process cleanup
- Do not call reset() in plugin code

```php
// SAFE: Read-only access
$tenantId = TenantContext::getTenantId(); // 42

// UNSAFE: Attempting to modify (throws exception)
TenantContext::setTenantId(99); // RuntimeException!

// Plugin cleanup (if needed) should be in onDisable()
// Framework handles TenantContext::reset()
public function onDisable(): void
{
    // Plugin cleanup here
    // Don't call TenantContext::reset()
}
```

## Migration Safety for Plugins

When creating migrations in plugins:

```php
// plugins/my-plugin/migrations/001_create_my_table.php
class CreateMyTable
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS my_table (
                id SERIAL PRIMARY KEY,
                plugin_data VARCHAR(255),
                tenant_id INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT NOW()
            )
        ');
        
        // IMPORTANT: Include tenant_id in schema
        $db->exec('CREATE INDEX idx_my_table_tenant_id ON my_table(tenant_id)');
    }
    
    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS my_table');
    }
}
```

Rules:

- [ ] Always include `tenant_id` column in plugin tables
- [ ] Create index on `tenant_id` for query performance
- [ ] Never use `core_schema_migrations` table
- [ ] Use standard `schema_migrations` for plugin tracking
- [ ] Test migration up/down cycle
- [ ] Document migration intent in code

## Reading Documentation

Before implementing, read the Phase 2 documentation:

- [HOOK_SYSTEM.md](docs/wiki/HOOK_SYSTEM.md) - How to register and use hooks
- [PERMISSION_SYSTEM.md](docs/wiki/PERMISSION_SYSTEM.md) - How permissions work
- [TENANT_ISOLATION.md](docs/wiki/TENANT_ISOLATION.md) - Tenant scoping and isolation
