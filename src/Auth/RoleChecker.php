<?php

namespace Whity\Auth;

use Whity\Database\Database;

/**
 * Role Checker for verifying user roles from the database
 *
 * Queries user roles from the database and provides methods to verify
 * if a user has a specific role. Uses JOIN with the roles table to
 * retrieve role information.
 */
class RoleChecker
{
    private Database $db;

    /**
     * Constructor
     *
     * @param Database $db The database connection instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
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
}
