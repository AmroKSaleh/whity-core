<?php

namespace Whity\Auth;

use Whity\Database\Database;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * Role Checker for verifying user roles and permissions from the database
 *
 * Queries user roles and permissions from the database and provides methods to verify
 * if a user has a specific role or permission. Uses JOIN with the roles and permissions
 * tables to retrieve role and permission information.
 */
class RoleChecker
{
    private Database $db;
    private PermissionRegistry $registry;

    /**
     * Constructor
     *
     * @param Database $db The database connection instance
     * @param PermissionRegistry $registry The permission registry instance
     */
    public function __construct(Database $db, PermissionRegistry $registry)
    {
        $this->db = $db;
        $this->registry = $registry;
    }

    /**
     * Get the role name for a specific user
     *
     * Queries the database to retrieve the role name for the given user ID.
     * Uses a JOIN between users and roles tables.
     *
     * @param int $userId The user ID to query
     * @return string|null The role name if user exists, null otherwise
     */
    public function getRoleForUser(int $userId): ?string
    {
        $sql = 'SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :userId';
        $statement = $this->db->query($sql, [':userId' => $userId]);
        $result = $statement->fetch();

        if ($result === false) {
            return null;
        }

        return $result['name'];
    }

    /**
     * Check if a user has a specific role
     *
     * Verifies that the user's role matches the required role.
     *
     * @param int $userId The user ID to check
     * @param string $requiredRole The role name to verify against
     * @return bool True if user has the required role, false otherwise
     */
    public function hasRole(int $userId, string $requiredRole): bool
    {
        $userRole = $this->getRoleForUser($userId);
        return $userRole === $requiredRole;
    }

    /**
     * Check if a user has a specific permission
     *
     * Validates that the permission exists in the registry and that the user
     * has been granted this permission through their role.
     *
     * @param int $userId The user ID to check
     * @param string $permission The permission string to verify against
     * @return bool True if user has the permission, false otherwise
     */
    public function hasPermission(int $userId, string $permission): bool
    {
        // Step 1: Check if permission exists in registry
        if (!$this->registry->permissionExists($permission)) {
            return false;
        }

        // Step 2: Query DB for role_permissions
        $sql = 'SELECT 1 FROM role_permissions rp JOIN users u ON u.role_id = rp.role_id WHERE u.id = :userId AND rp.permission_string = :permission';
        $statement = $this->db->query($sql, [':userId' => $userId, ':permission' => $permission]);
        $result = $statement->fetch();

        return $result !== false;
    }

    /**
     * Get all permissions for a specific user
     *
     * Retrieves all permissions granted to the user through their assigned role.
     *
     * @param int $userId The user ID to query
     * @return array<string> Array of permission strings for the user
     */
    public function getPermissionsForUser(int $userId): array
    {
        $sql = 'SELECT DISTINCT rp.permission_string FROM role_permissions rp JOIN users u ON u.role_id = rp.role_id WHERE u.id = :userId';
        $statement = $this->db->query($sql, [':userId' => $userId]);
        $results = $statement->fetchAll();

        if ($results === false) {
            return [];
        }

        return array_map(static fn($row) => $row['permission_string'], $results);
    }
}
