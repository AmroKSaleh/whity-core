<?php

namespace Tests\Support;

/**
 * Test fixture builder for OUs security testing suite
 *
 * Provides static factory methods to create test data as plain arrays matching
 * database query results. All methods return arrays with correct column names
 * for use with mock PDO statements and integration tests.
 */
class TestFixtureBuilder
{
    /**
     * Counter for auto-incrementing OU role assignment IDs
     */
    private static int $ouRoleAssignmentIdCounter = 0;

    /**
     * Build a user fixture
     *
     * @param int $id User ID
     * @param int $tenantId Tenant ID
     * @param int $roleId Role ID
     * @param int|null $ouId Organizational Unit ID
     * @return array User data array with DB column names
     */
    public static function user(int $id, int $tenantId, int $roleId, ?int $ouId = null): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'ou_id' => $ouId,
            'email' => "user{$id}@example.com"
        ];
    }

    /**
     * Build a tenant fixture
     *
     * @param int $id Tenant ID
     * @param string $name Tenant name
     * @return array Tenant data array with DB column names
     */
    public static function tenant(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name
        ];
    }

    /**
     * Build an organizational unit (OU) fixture
     *
     * @param int $id OU ID
     * @param int $tenantId Tenant ID
     * @param string $name OU name
     * @param int|null $parentId Parent OU ID
     * @return array OU data array with DB column names
     */
    public static function ou(int $id, int $tenantId, string $name, ?int $parentId = null): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => self::slugify($name),
            'description' => ''
        ];
    }

    /**
     * Build an OU role assignment fixture
     *
     * @param int $ouId OU ID
     * @param int $roleId Role ID
     * @param int $tenantId Tenant ID
     * @return array OU role assignment data array with DB column names
     */
    public static function ouRoleAssignment(int $ouId, int $roleId, int $tenantId): array
    {
        return [
            'id' => ++self::$ouRoleAssignmentIdCounter,
            'ou_id' => $ouId,
            'role_id' => $roleId,
            'tenant_id' => $tenantId
        ];
    }

    /**
     * Build a JWT payload fixture
     *
     * @param int $userId User ID
     * @param int $tenantId Tenant ID
     * @param string $role User role name
     * @return array JWT payload data
     */
    public static function jwtPayload(int $userId, int $tenantId, string $role = 'admin'): array
    {
        return [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => "user{$userId}@example.com",
            'role' => $role,
            'exp' => time() + 3600
        ];
    }

    /**
     * Build a role fixture
     *
     * @param int $id Role ID
     * @param string $name Role name
     * @return array Role data array with DB column names
     */
    public static function role(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name
        ];
    }

    /**
     * Build a permission fixture
     *
     * @param string $name Permission name
     * @param string $description Permission description
     * @return array Permission data array with DB column names
     */
    public static function permission(string $name, string $description = ''): array
    {
        return [
            'name' => $name,
            'description' => $description
        ];
    }

    /**
     * Reset the auto-increment counter for OU role assignments
     *
     * Call this in test tearDown to reset the counter for the next test.
     *
     * @return void
     */
    public static function resetCounters(): void
    {
        self::$ouRoleAssignmentIdCounter = 0;
    }

    /**
     * Convert a string to kebab-case slug
     *
     * Converts spaces to hyphens and converts to lowercase.
     *
     * @param string $str String to slugify
     * @return string Slugified string in kebab-case
     */
    private static function slugify(string $str): string
    {
        // Convert to lowercase
        $slug = strtolower($str);
        // Replace spaces with hyphens
        $slug = str_replace(' ', '-', $slug);
        // Remove any non-alphanumeric characters except hyphens
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
        // Replace multiple consecutive hyphens with a single hyphen
        $slug = preg_replace('/-+/', '-', $slug);
        // Trim hyphens from start and end
        $slug = trim($slug, '-');
        return $slug;
    }
}
