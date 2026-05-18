<?php

require /app/vendor/autoload.php;
require /app/src/helpers.php;

use Whity\Database\Database;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;

$db = Database::connect();
$permissionRegistry = new PermissionRegistry();
$roleChecker = new RoleChecker($db, $permissionRegistry);

// Get effective roles for user 2 (who is in OU 2 with editor role assigned)
$effectiveRoles = $roleChecker->getEffectiveRolesForUser(2, 1);

echo "Effective roles for user 2 in tenant 1:\n";
foreach ($effectiveRoles as $roleId => $roleName) {
    echo "  - Role ID: $roleId, Name: $roleName\n";
}

// Verify user has both 'user' role (direct) and 'editor' role (from OU)
$hasUserRole = in_array('user', array_values($effectiveRoles));
$hasEditorRole = in_array('editor', array_values($effectiveRoles));

echo "\nRole Inheritance Verification:\n";
echo "  Has 'user' role (direct): " . ($hasUserRole ? "YES ✓" : "NO ✗") . "\n";
echo "  Has 'editor' role (from OU): " . ($hasEditorRole ? "YES ✓" : "NO ✗") . "\n";

if ($hasUserRole && $hasEditorRole) {
    echo "\n✅ Role inheritance is working correctly!\n";
} else {
    echo "\n❌ Role inheritance not working as expected\n";
}
'EOF'
