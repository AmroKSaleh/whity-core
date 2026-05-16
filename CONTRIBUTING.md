# Contributing to Whity Core

Thank you for contributing! See [README.md](README.md) for architecture overview.

## License

By contributing, you agree your code is licensed under AGPL v3.0 + Commons Clause.

## Key Architecture Principles

### 1. Stateless Controllers (CRITICAL)

FrankenPHP keeps workers alive in memory. **Never use static state:**

```php
// ✅ GOOD
class TaskController extends BaseController {
    public function store(Request $request) {
        return Task::create($request->validated());
    }
}

// ❌ WRONG - Leaks between requests!
class TaskController extends BaseController {
    private static $cache = [];  // FORBIDDEN
    
    public function store(Request $request) {
        self::$cache[] = $request->input();  // User A's data → User B!
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
// ✅ CORRECT
Task::where('tenant_id', $user->tenant_id)->get();

// ❌ WRONG
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
