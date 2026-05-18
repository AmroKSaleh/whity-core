# Permission System

The permission system provides dynamic, in-memory permission management with database-backed role assignments. Plugins define permissions; the framework ensures only assigned permissions grant access.

## Overview

Whity's permission system has two parts:

**PermissionRegistry** - In-memory store of all available permissions. Plugins register their permissions here when enabled. When a plugin is deleted, its permissions instantly disappear from the system (no orphaned DB rows).

**role_permissions table** - Database table that maps roles to permission strings. Administrators assign permissions to roles via the admin API, and the framework checks this table when enforcing access control.

This two-part design eliminates sync nightmares: the source of truth (plugin code) always matches the database state because orphaned permissions can't exist.

## How Permissions Work

### Step 1: Plugin Registers Permissions

When a plugin is enabled, it registers the permissions it provides:

```php
<?php
namespace Plugins\Calculator;

use Whity\Core\PluginInterface;
use Whity\Core\RBAC\PermissionRegistry;

class Plugin implements PluginInterface
{
    public function id(): string { return 'calculator'; }
    
    public function onEnable(PermissionRegistry $registry): void
    {
        $registry->registerPermissions('calculator', [
            'calculator.use',      // Run calculations
            'calculator.admin',    // Configure calculator
            'calculator.export',   // Export results
        ]);
    }
}
```

These permissions exist **in-memory only** until someone assigns them to a role in the database.

### Step 2: Admin Assigns Permissions to Roles

Via the admin API (`POST /api/roles/{id}/permissions`), administrators assign registered permissions to roles:

```json
POST /api/roles/3/permissions
{
    "permission": "calculator.use",
    "action": "grant"  // or "revoke"
}
```

This creates a row in the `role_permissions` table:

```sql
role_id | permission_string
--------|-------------------
   3    | calculator.use
   3    | calculator.export
```

### Step 3: Request Handler Checks Permission

In an API handler, check if the user has the permission:

```php
<?php
namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Request;

class CalculatorApiHandler
{
    private RoleChecker $roleChecker;
    
    public function handle(Request $request): Response
    {
        // Check if user can use calculator
        if (!$this->roleChecker->hasPermission(
            $request->user->user_id,
            'calculator.use'
        )) {
            return Response::error('Permission denied', 403);
        }
        
        // User has permission, proceed
        return $this->calculate($request->input());
    }
}
```

### Step 4: User Can Access Feature

When `RoleChecker::hasPermission()` is called:

1. Check if permission exists in PermissionRegistry (calculator.use registered?)
2. Check if role_permissions table has a row for this user's role + permission
3. Return true only if both checks pass

```php
// In RoleChecker::hasPermission()
public function hasPermission(int $userId, string $permission): bool
{
    // Step 1: Check permission is registered
    if (!$this->registry->permissionExists($permission)) {
        return false; // Calculator plugin deleted? Permission doesn't exist
    }
    
    // Step 2: Check user's role has this permission assigned
    $sql = 'SELECT 1 FROM role_permissions rp 
            JOIN users u ON u.role_id = rp.role_id 
            WHERE u.id = :userId AND rp.permission_string = :permission';
    $result = $this->db->query($sql, [
        ':userId' => $userId,
        ':permission' => $permission
    ]);
    
    return $result->fetch() !== false;
}
```

## Dynamic Permissions

### What Are Dynamic Permissions?

Traditional permission systems are hardcoded: developers add permissions to the database in migrations, and they exist forever. Dynamic permissions are defined by plugins at runtime.

```php
// Traditional (hardcoded in migration):
INSERT INTO permissions VALUES ('tasks:read', 'Read tasks');
// Problem: Permission exists even if plugin is deleted

// Dynamic (defined in plugin code):
$registry->registerPermissions('tasks', ['tasks:read']);
// Benefit: Permission only exists while plugin is enabled
```

### Plugin Permissions in-Memory Registry

The `PermissionRegistry` holds all active permissions:

```php
$registry->registerPermissions('calculator', [
    'calculator.use',
    'calculator.admin',
    'calculator.export'
]);

// Check if permission is registered
$registry->permissionExists('calculator.use'); // true

// Get all permissions for a plugin
$perms = $registry->getPluginPermissions('calculator');
// Returns: ['calculator.use', 'calculator.admin', 'calculator.export']

// Get all active permissions across all plugins
$all = $registry->getAllActivePermissions();
// Returns: ['calculator' => [...], 'tasks' => [...], ...]
```

### Single Source of Truth

The plugin code is the only source of truth:

```php
// plugins/calculator/Plugin.php
public function onEnable(PermissionRegistry $registry): void
{
    $registry->registerPermissions('calculator', [
        'calculator.use',
        'calculator.admin',
    ]);
}
```

If the plugin is deleted:
- Its entry is removed from PermissionRegistry
- The permissions `calculator.use` and `calculator.admin` no longer exist
- Any user with these permissions assigned will be denied access

```php
// After deleting calculator plugin:
$roleChecker->hasPermission($userId, 'calculator.use');
// Returns false because permission no longer exists in registry
```

No database cleanup needed, no orphaned rows, no sync issues.

## Permission Assignment

### Database Schema

The `role_permissions` table stores permission assignments:

```sql
CREATE TABLE role_permissions (
    id SERIAL PRIMARY KEY,
    role_id INTEGER NOT NULL REFERENCES roles(id),
    permission_string VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL
);
```

Each row grants a role one permission. A role can have multiple permissions, and multiple roles can have the same permission.

### Assigning Permissions to Roles

Via the admin API, grant/revoke permissions:

```bash
# Grant permission
curl -X POST http://localhost:8000/api/roles/3/permissions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"permission": "calculator.use", "action": "grant"}'

# Revoke permission
curl -X POST http://localhost:8000/api/roles/3/permissions \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"permission": "calculator.use", "action": "revoke"}'
```

Or directly in the database:

```sql
-- Grant calculator.use to the "user" role
INSERT INTO role_permissions (role_id, permission_string, created_at)
VALUES (2, 'calculator.use', NOW());

-- Revoke calculator.use from the "user" role
DELETE FROM role_permissions
WHERE role_id = 2 AND permission_string = 'calculator.use';
```

### Getting Role Permissions

Retrieve all permissions assigned to a role:

```sql
SELECT permission_string FROM role_permissions WHERE role_id = 3;
-- Result:
-- calculator.use
-- calculator.export
-- tasks.read
```

Or via RoleChecker:

```php
$userPerms = $roleChecker->getPermissionsForUser($userId);
// Returns: ['calculator.use', 'calculator.export', 'tasks.read']
```

## Permission Checking

### Check if User Has Permission

```php
use Whity\Auth\RoleChecker;

// In a handler:
if (!$roleChecker->hasPermission($user->id, 'calculator.use')) {
    return Response::error('Permission denied', 403);
}

// Proceed with the operation
```

### Get All User Permissions

```php
$permissions = $roleChecker->getPermissionsForUser($user->id);
// Returns: ['calculator.use', 'calculator.export', 'tasks.read']

// Use in UI to show/hide features
foreach ($permissions as $permission) {
    // Render menu items for permitted features
}
```

### Check User Role

```php
// Get user's role name
$roleName = $roleChecker->getRoleForUser($user->id);
// Returns: 'admin', 'user', etc.

// Check if user has a specific role
if ($roleChecker->hasRole($user->id, 'admin')) {
    // Show admin panel
}
```

## Deleted Plugins: Graceful Denial

When a plugin is deleted, its permissions instantly deny access:

### Scenario: Calculator Plugin is Deleted

1. **Before deletion:**
   - "Calculator" role has permission `calculator.use`
   - PermissionRegistry contains `calculator.use`
   - Users can access calculator

2. **Admin deletes plugin:**
   - Plugin's `onDisable()` is called (plugin cleans up)
   - PermissionRegistry removes `calculator.use`
   - Database rows in `role_permissions` remain (cleanup is optional)

3. **After deletion:**
   - User requests calculator access
   - `RoleChecker::hasPermission('calculator.use')` is called
   - Check 1: Permission exists in registry? No! (plugin deleted)
   - Returns `false` immediately
   - User is denied, even though database row exists

```php
// After calculator plugin is deleted:
$registry->permissionExists('calculator.use'); // false
$roleChecker->hasPermission($userId, 'calculator.use'); // false

// No action needed; denial is automatic
```

### Optional: Database Cleanup

Administrators can optionally clean up orphaned permissions:

```php
// Manual query to remove orphaned permissions
DELETE FROM role_permissions 
WHERE permission_string NOT IN (
    SELECT permission FROM (
        SELECT 'users.read' UNION
        SELECT 'users.create' UNION
        SELECT 'tasks.read' UNION
        -- ... all registered permissions
    ) AS registered
);
```

But this is optional - the framework doesn't require it. Access control is enforced at the registry level.

## Examples

### Example 1: Simple Permission Check

```php
<?php
namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Response;

class DocumentDownloadHandler
{
    public function handle($request): Response
    {
        // User wants to download a document
        if (!$this->roleChecker->hasPermission(
            $request->user->user_id,
            'documents.download'
        )) {
            return Response::error('You cannot download documents', 403);
        }
        
        // User has permission, proceed
        return $this->downloadDocument($request->get('document_id'));
    }
}
```

### Example 2: Feature-Based UI

```php
<?php
namespace Plugins\Dashboard;

class DashboardController
{
    public function show($request)
    {
        $perms = $this->roleChecker->getPermissionsForUser(
            $request->user->user_id
        );
        
        $menuItems = [
            ['label' => 'Users', 'visible' => in_array('users.read', $perms)],
            ['label' => 'Roles', 'visible' => in_array('roles.read', $perms)],
            ['label' => 'Reports', 'visible' => in_array('reports.read', $perms)],
        ];
        
        return view('dashboard', ['menu' => $menuItems]);
    }
}
```

### Example 3: Multi-Level Permission Check

```php
<?php
namespace Whity\Api;

class UserUpdateHandler
{
    public function handle($request): Response
    {
        $userId = $request->get('user_id');
        
        // Check base permission
        if (!$this->roleChecker->hasPermission(
            $request->user->user_id,
            'users.update'
        )) {
            return Response::error('Cannot update users', 403);
        }
        
        // Check if updating own user (always allowed)
        if ($userId === $request->user->user_id) {
            return $this->updateUser($userId, $request->input());
        }
        
        // Check admin permission to update other users
        if (!$this->roleChecker->hasPermission(
            $request->user->user_id,
            'users.update.all'
        )) {
            return Response::error('Can only update own profile', 403);
        }
        
        return $this->updateUser($userId, $request->input());
    }
}
```

## Migration Guide: Plugins

### If You Have Hardcoded Permissions

If your plugin currently checks permissions in a hardcoded way:

```php
// OLD (before Phase 2):
if ($request->user->role !== 'admin') {
    return Response::error('Admin only', 403);
}
```

Migrate to the dynamic permission system:

```php
// NEW (Phase 2):
public function onEnable(PermissionRegistry $registry): void
{
    $registry->registerPermissions('my-plugin', [
        'my-plugin.admin',
        'my-plugin.use',
    ]);
}

// In handler:
if (!$roleChecker->hasPermission($request->user->user_id, 'my-plugin.admin')) {
    return Response::error('Permission denied', 403);
}
```

Benefits:
- Permissions are visible in admin panel
- Administrators can assign permissions without code changes
- Graceful handling when plugin is deleted
- Follows framework convention

### Step-by-Step Migration

1. **Register permissions in `onEnable()`:**

```php
public function onEnable(PermissionRegistry $registry): void
{
    $registry->registerPermissions($this->id(), [
        'my-plugin.use',
        'my-plugin.admin',
    ]);
}
```

2. **Replace role checks with permission checks:**

```php
// Before:
if ($request->user->role !== 'admin') return error();

// After:
if (!$roleChecker->hasPermission($request->user->user_id, 'my-plugin.admin')) {
    return error();
}
```

3. **Test that permissions work:**

```bash
# Create a role
curl -X POST /api/roles \
  -d '{"name": "power-user"}' \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Assign permission to role
curl -X POST /api/roles/2/permissions \
  -d '{"permission": "my-plugin.use", "action": "grant"}' \
  -H "Authorization: Bearer $ADMIN_TOKEN"

# Test access with non-admin user (power-user role)
curl -X GET /api/my-plugin/data \
  -H "Authorization: Bearer $POWER_USER_TOKEN"
# Should now work!
```

## Best Practices

### 1. Permission Naming Convention

Use hierarchical permission names: `plugin.resource.action`

```php
// GOOD
[
    'calculator.basic.use',      // Use basic calculator
    'calculator.advanced.use',   // Use advanced functions
    'calculator.admin',          // Administer calculator
]

// OK (less specific)
[
    'calculator.use',
    'calculator.admin',
]

// AVOID (too generic)
[
    'calculator',
    'admin',
]
```

### 2. Register Permissions in Plugin

Always register in `onEnable()`, never in migrations or configuration files:

```php
// GOOD: Plugin is source of truth
public function onEnable(PermissionRegistry $registry): void
{
    $registry->registerPermissions($this->id(), [
        'my-plugin.read',
        'my-plugin.write',
    ]);
}

// AVOID: Hardcoded in migration
// Doesn't work with dynamic deletion
```

### 3. Check Permissions Early

Verify permissions at the API handler level, not deep in business logic:

```php
// GOOD: Check at handler entry
public function handle($request): Response
{
    if (!$this->roleChecker->hasPermission($request->user->user_id, 'reports.read')) {
        return Response::error('Permission denied', 403);
    }
    return $this->generateReport();
}

// AVOID: Checking deep in service
public function generateReport(): array
{
    if (!$this->roleChecker->hasPermission(...)) { // Bad place
        return error;
    }
}
```

### 4. Document Your Permissions

List permissions in your plugin's README:

```markdown
## Permissions

This plugin registers the following permissions:

- `my-plugin.use` - Use the plugin's main feature
- `my-plugin.admin` - Administer plugin settings
- `my-plugin.export` - Export plugin data
```

## Summary

- **Permissions** control what features users can access
- **PermissionRegistry** holds active permissions (in-memory)
- **role_permissions table** stores role-to-permission assignments (database)
- **Plugins define** their own permissions in `onEnable()`
- **Administrators assign** permissions to roles via the admin API
- **Handlers check** permissions with `RoleChecker::hasPermission()`
- **Deleted plugins** gracefully deny their permissions immediately

See [HOOK_SYSTEM.md](HOOK_SYSTEM.md) for event handling and [TENANT_ISOLATION.md](TENANT_ISOLATION.md) for multi-tenant permission scoping.
