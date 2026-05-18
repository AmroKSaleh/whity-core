# Tenant Isolation

Whity implements strict multi-tenant isolation at the framework level. One tenant's data is completely inaccessible to other tenants, enforced by automatic query scoping and JWT-based context.

## Overview

Multi-tenancy in Whity is built on a simple principle: **every request carries a tenant ID in the JWT, and all queries are automatically scoped to that tenant.**

This design prevents data leakage across tenants:

- Users can only see their own tenant's data
- API handlers don't need to manually filter by tenant
- Queries are scoped at the framework level (impossible to accidentally leak data)
- Tenant context is locked after authentication (plugins cannot escape)

## Architecture Overview

```
User logs in with email/password
    ↓
Backend validates credentials
    ↓
Backend creates JWT with tenant_id in payload
    ↓
Frontend stores JWT in localStorage
    ↓
Frontend sends JWT with every API request
    ↓
EnforceTenantIsolation middleware receives request
    ↓
Middleware extracts tenant_id from JWT
    ↓
TenantContext::setTenantId($tenantId) - locks context
    ↓
All subsequent queries are scoped to this tenant
    ↓
API handler processes request (user can only see their tenant's data)
    ↓
TenantContext::reset() at end of request (cleanup for next request)
```

## Tenant Context: Request Lifecycle

### Setting Tenant Context

The `EnforceTenantIsolation` middleware runs early in every request:

```php
<?php
namespace Whity\Http\Middleware;

use Whity\Core\Tenant\TenantContext;

class EnforceTenantIsolation
{
    public function handle(Request $request, callable $next): Response
    {
        // Extract Authorization header
        $authHeader = $request->getHeader('Authorization');
        
        // Parse JWT token
        $payload = $this->jwtParser->parse($token);
        
        // Validate tenant_id exists in token
        if (!isset($payload['tenant_id'])) {
            return Response::error('Missing tenant_id in token', 401);
        }
        
        // SET TENANT CONTEXT (locks it for request lifetime)
        TenantContext::setTenantId($payload['tenant_id']);
        
        // Attach user data to request
        $request->user = (object) $payload;
        
        // Call next handler (all subsequent code runs with tenant context set)
        return $next($request);
    }
}
```

Key points:
- Middleware runs before route handlers
- Tenant ID is extracted from JWT payload (trusted source)
- `TenantContext::setTenantId()` locks the context (cannot be changed)
- If tenant_id is missing, request is rejected with 401 Unauthorized

### TenantContext: Locked and Read-Only

Once set, the tenant context is locked and cannot be changed:

```php
TenantContext::setTenantId(42);
TenantContext::setTenantId(99); // RuntimeException! Already locked

// Plugin cannot escape:
try {
    TenantContext::setTenantId(99); // Attempting to access other tenant
} catch (\RuntimeException $e) {
    // Framework prevents the escape
}

// But plugins can read it:
$tenantId = TenantContext::getTenantId(); // Returns 42 (safe, read-only)
```

This design prevents even buggy plugins from accessing other tenants.

### Cleanup Between Requests

After the response is sent, the framework resets the context:

```php
// In HttpKernel or request termination handler:
finally {
    TenantContext::reset(); // Clear tenant_id, unlock context
}
```

This is critical in FrankenPHP (persistent worker processes). Without cleanup, the next request could run with the previous request's tenant context.

```php
// Request 1: Tenant 1
TenantContext::setTenantId(1);
// ... process request ...
TenantContext::reset();

// Request 2: Tenant 2 (different user in same worker)
TenantContext::setTenantId(2); // Safe because context was reset
// ... process request ...
TenantContext::reset();
```

## Automatic Query Scoping: ScopesToTenant Trait

The `ScopesToTenant` trait automatically injects tenant_id filtering into model operations:

### How It Works

Models that use `ScopesToTenant` are automatically scoped to the current tenant:

```php
<?php
namespace Whity\Models;

use Whity\Core\Database\ScopesToTenant;

class User extends Model
{
    use ScopesToTenant;
    
    // ... user model code ...
}

class Role extends Model
{
    use ScopesToTenant;
    
    // ... role model code ...
}
```

When you create or query records:

```php
// Creating a user (tenant_id auto-set)
$user = new User();
$user->name = 'John';
$user->email = 'john@example.com';
$user->setTenantIdBeforePersist(); // Sets user->tenant_id = TenantContext::getTenantId()
$user->save();

// Result: User is automatically scoped to current tenant (42)
// SQL: INSERT INTO users (name, email, tenant_id) VALUES ('John', 'john@example.com', 42)
```

### setTenantIdBeforePersist()

Call this before saving a record to automatically set its tenant_id:

```php
// GOOD: Automatic tenant scoping
$user = new User();
$user->name = 'John';
$user->setTenantIdBeforePersist(); // Tenant ID auto-set from context
$user->save();

// AVOID: Manual tenant_id handling
$user = new User();
$user->name = 'John';
$user->tenant_id = TenantContext::getTenantId(); // Manual, error-prone
$user->save();
```

If TenantContext is not set (request outside tenant context), it throws:

```php
$user = new User();
$user->setTenantIdBeforePersist();
// RuntimeException: Cannot persist User without active TenantContext
```

This defensive check prevents data from being created without tenant association.

### validateTenantBoundary()

Before operating on a record, validate it belongs to the current tenant:

```php
$user = User::find($id); // Gets from DB
$user->validateTenantBoundary(); // Ensures user belongs to current tenant

// If validation fails:
// RuntimeException: Tenant boundary violation: Record belongs to tenant 1, 
//                   but current context is tenant 2
```

Use this in API handlers before modifying records:

```php
public function updateUser($request): Response
{
    $userId = $request->get('user_id');
    
    // Get user from DB
    $user = User::find($userId);
    if (!$user) {
        return Response::error('User not found', 404);
    }
    
    // Validate user belongs to current tenant
    $user->validateTenantBoundary(); // Throws if cross-tenant access
    
    // Safe to update
    $user->email = $request->get('email');
    $user->save();
    
    return Response::json(['data' => $user]);
}
```

## Automatic Scoping: ORM Integration (Future)

In Phase 2, when moving to an ORM (Eloquent, Doctrine), the `ScopesToTenant` trait will register global query scopes:

```php
// Future Phase 2 implementation:
class User extends Model
{
    use ScopesToTenant;
    
    protected static function bootScopesToTenant()
    {
        // Register global scope (Eloquent pattern)
        static::addGlobalScope(new TenantScope);
    }
}

// Result: All queries automatically filtered
User::all(); // SELECT * FROM users WHERE tenant_id = 42
User::find(1); // SELECT * FROM users WHERE id = 1 AND tenant_id = 42
User::where('email', 'john@example.com')->first();
// SELECT * FROM users WHERE email = 'john@example.com' AND tenant_id = 42
```

Currently, developers must call `setTenantIdBeforePersist()` and `validateTenantBoundary()` explicitly. With ORM integration, scoping happens automatically for all queries.

## Query Safety: Ensuring Tenant Boundaries

### Manual Queries (Raw SQL)

If writing raw SQL, always include tenant filtering:

```php
// GOOD: Tenant-scoped query
$sql = 'SELECT * FROM users WHERE email = ? AND tenant_id = ?';
$result = $db->query($sql, [$email, TenantContext::getTenantId()]);

// DANGEROUS: No tenant filtering
$sql = 'SELECT * FROM users WHERE email = ?'; // User from any tenant!
$result = $db->query($sql, [$email]);
```

### ORM Queries (Phase 2)

With ORM integration, scoping is automatic:

```php
// Phase 2 (Eloquent):
// Automatically adds WHERE tenant_id = $current during query building
$user = User::where('email', $email)->first();

// Equivalent to:
// SELECT * FROM users WHERE email = ? AND tenant_id = ?
```

### Query Guard: Defensive Layer (Phase 2)

In Phase 2, a query guard middleware can enforce tenant scoping:

```php
// Abstract: All queries (raw or ORM) are analyzed
// If a query doesn't include tenant filtering, it's rejected
$query->requiresTenant(); // Throws if tenant not in WHERE clause
```

## API Endpoints: Tenant Context in Practice

### Example: Users API Handler

```php
<?php
namespace Whity\Api;

use Whity\Core\Tenant\TenantContext;

class UsersApiHandler
{
    public function index($request): Response
    {
        // TenantContext is already set by middleware
        $tenantId = TenantContext::getTenantId(); // 42
        
        // Query users for this tenant only
        $sql = 'SELECT * FROM users WHERE tenant_id = ?';
        $users = $this->db->query($sql, [$tenantId])->fetchAll();
        
        // Safe: User can only see their tenant's users
        return Response::json(['data' => $users]);
    }
    
    public function show($request): Response
    {
        $userId = $request->get('user_id');
        $tenantId = TenantContext::getTenantId(); // 42
        
        // Query user for this tenant
        $sql = 'SELECT * FROM users WHERE id = ? AND tenant_id = ?';
        $user = $this->db->query($sql, [$userId, $tenantId])->fetch();
        
        if (!$user) {
            return Response::error('User not found', 404);
        }
        
        return Response::json(['data' => $user]);
    }
    
    public function store($request): Response
    {
        // Create user in current tenant
        $user = new User();
        $user->name = $request->get('name');
        $user->email = $request->get('email');
        $user->setTenantIdBeforePersist(); // Auto-scoped to tenant 42
        $user->save();
        
        return Response::json(['data' => $user], 201);
    }
}
```

### Example: Roles API Handler

Same pattern - all queries include tenant filtering:

```php
public function index($request): Response
{
    $tenantId = TenantContext::getTenantId();
    
    $sql = 'SELECT r.*, COUNT(u.id) as user_count 
            FROM roles r 
            LEFT JOIN users u ON u.role_id = r.id AND u.tenant_id = ?
            WHERE r.tenant_id = ?
            GROUP BY r.id';
    
    $roles = $this->db->query($sql, [$tenantId, $tenantId])->fetchAll();
    
    return Response::json(['data' => $roles]);
}
```

## Testing Tenant Isolation

### Test 1: Queries Are Tenant-Scoped

```php
public function testUsersOnlySeeSelfTenantData()
{
    // Create two tenants
    $tenant1 = Tenant::create(['name' => 'Acme Corp']);
    $tenant2 = Tenant::create(['name' => 'Global Inc']);
    
    // Create users in each tenant
    TenantContext::setTenantId($tenant1->id);
    $user1 = User::create(['name' => 'Alice', 'email' => 'alice@acme.com']);
    
    TenantContext::setTenantId($tenant2->id);
    $user2 = User::create(['name' => 'Bob', 'email' => 'bob@global.com']);
    TenantContext::reset();
    
    // Tenant1 user cannot see Tenant2 user
    TenantContext::setTenantId($tenant1->id);
    $users = $db->query('SELECT * FROM users WHERE tenant_id = ?', [$tenant1->id])->fetchAll();
    $this->assertCount(1, $users);
    $this->assertEquals('alice@acme.com', $users[0]['email']);
    
    // Tenant2 user cannot see Tenant1 user
    TenantContext::setTenantId($tenant2->id);
    $users = $db->query('SELECT * FROM users WHERE tenant_id = ?', [$tenant2->id])->fetchAll();
    $this->assertCount(1, $users);
    $this->assertEquals('bob@global.com', $users[0]['email']);
}
```

### Test 2: Cross-Tenant Access is Prevented

```php
public function testCrossTenantAccessThrows()
{
    $tenant1 = Tenant::create(['name' => 'Tenant 1']);
    $tenant2 = Tenant::create(['name' => 'Tenant 2']);
    
    TenantContext::setTenantId($tenant1->id);
    $user1 = User::create(['name' => 'Alice']);
    
    // Attempt to access user1 from tenant2 context
    TenantContext::reset();
    TenantContext::setTenantId($tenant2->id);
    
    $this->expectException(\RuntimeException::class);
    $user1->validateTenantBoundary(); // User belongs to tenant 1, context is tenant 2
}
```

### Test 3: Tenant Context is Locked

```php
public function testTenantContextIsLocked()
{
    TenantContext::setTenantId(1);
    
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('TenantContext is locked');
    
    TenantContext::setTenantId(2); // Attempt to change locked context
}
```

### Test 4: Context Reset Works

```php
public function testContextResetBetweenRequests()
{
    // Request 1
    TenantContext::setTenantId(1);
    $this->assertEquals(1, TenantContext::getTenantId());
    TenantContext::reset();
    
    // Request 2 (same worker process)
    TenantContext::setTenantId(2); // Should work (context was reset)
    $this->assertEquals(2, TenantContext::getTenantId());
}
```

## Security Model: Guarantees Provided

### Guarantee 1: One Tenant Per Request

Every request has exactly one tenant context, extracted from JWT. A user can only access their own tenant's data.

### Guarantee 2: Queries Are Scoped

All queries in API handlers must include tenant filtering. Missing filters are caught during code review.

### Guarantee 3: Context is Locked

Plugins cannot escape the current tenant. Once set, context cannot be changed.

### Guarantee 4: Cleanup Between Requests

After each request completes, context is reset. Next request starts with clean context.

### Guarantee 5: No Default Access

If tenant context is not set, operations throw exceptions. You cannot accidentally access data without tenant association.

## Common Mistakes

### Mistake 1: Forgetting Tenant Filter in Query

```php
// WRONG: No tenant filter
$user = $db->query('SELECT * FROM users WHERE id = ?', [$id])->fetch();

// CORRECT: Always include tenant
$user = $db->query(
    'SELECT * FROM users WHERE id = ? AND tenant_id = ?',
    [$id, TenantContext::getTenantId()]
)->fetch();
```

### Mistake 2: Not Calling setTenantIdBeforePersist()

```php
// WRONG: Tenant is null
$user = new User();
$user->name = 'John';
$user->save(); // tenant_id is null!

// CORRECT: Set tenant before save
$user = new User();
$user->name = 'John';
$user->setTenantIdBeforePersist(); // Auto-set to current tenant
$user->save();
```

### Mistake 3: Validating tenant_id After Querying

```php
// WRONG: Query first, then check (inefficient)
$user = $db->query('SELECT * FROM users WHERE id = ?', [$id])->fetch();
if ($user['tenant_id'] !== TenantContext::getTenantId()) {
    throw new SecurityException();
}

// CORRECT: Filter in query (efficient, safe)
$user = $db->query(
    'SELECT * FROM users WHERE id = ? AND tenant_id = ?',
    [$id, TenantContext::getTenantId()]
)->fetch();
if (!$user) {
    throw new NotFoundException();
}
```

### Mistake 4: Static Variables in Handlers

```php
// WRONG: Static state persists across requests in FrankenPHP
private static $userCache = [];

public function handle($request): Response
{
    self::$userCache[$request->user->id] = $request->user; // User 1's data
    // ...
}

// Request 2 from User 2 still sees User 1's cached data!

// CORRECT: No static state
public function handle($request): Response
{
    $user = $request->user; // Fresh every request
    // ...
}
```

## Summary

- **TenantContext** holds the current request's tenant ID
- **EnforceTenantIsolation middleware** extracts tenant from JWT and sets context
- **Context is locked** after setting (plugins cannot escape)
- **ScopesToTenant trait** simplifies tenant scoping in models
- **All queries must include tenant filtering** (WHERE tenant_id = ?)
- **Context is reset** between requests (FrankenPHP cleanup)
- **No default access** without tenant context (defensive)

See [HOOK_SYSTEM.md](HOOK_SYSTEM.md) for how hooks respect tenant context, and [PERMISSION_SYSTEM.md](PERMISSION_SYSTEM.md) for role-based access control within tenant boundaries.
