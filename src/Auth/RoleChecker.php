<?php

declare(strict_types=1);

namespace Whity\Auth;

use Psr\Log\LoggerInterface;
use Whity\Database\Database;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * Role Checker for verifying user roles and permissions from the database.
 *
 * Queries user roles and permissions from the database and verifies whether a
 * user holds a specific role or permission.
 *
 * Role hierarchy (WC-15)
 * ----------------------
 * Roles may be arranged into an inheritance hierarchy via the
 * {@see roles}.parent_id column (super_admin -> admin -> editor -> viewer). A
 * higher role automatically inherits every permission granted to the roles
 * beneath it. The effective permission set for a role is resolved by walking up
 * the parent chain and unioning each role's directly-granted permissions.
 *
 * Cycle safety
 * ------------
 * A malformed hierarchy (e.g. A -> B -> A) must never hang request processing.
 * Traversal therefore tracks visited role ids and is bounded by a hard
 * {@see self::MAX_HIERARCHY_DEPTH} safeguard; a detected cycle (or exceeding the
 * depth bound) is logged as a warning and resolution terminates gracefully with
 * the permissions collected so far.
 *
 * Worker-level cache
 * ------------------
 * Resolved effective permission sets are memoised in a process (worker) level
 * static cache to avoid re-walking the hierarchy on every authorization check.
 * The cache holds only derived, non-request-specific data (role id =>
 * permission list) so it is safe to share across the requests a FrankenPHP
 * worker serves. It MUST be invalidated whenever role/permission assignments or
 * the hierarchy change — {@see RolesApiHandler} calls {@see self::clearCache()}
 * after any mutating write.
 */
class RoleChecker
{
    /**
     * Hard upper bound on hierarchy traversal depth.
     *
     * Acts as a belt-and-braces safeguard alongside visited-set cycle detection:
     * even a pathological or corrupted hierarchy can never loop or recurse beyond
     * this many levels.
     */
    private const MAX_HIERARCHY_DEPTH = 64;

    /**
     * Process (worker) level cache of resolved effective permissions per role.
     *
     * Structure: [roleId => ['perm:a', 'perm:b', ...]]. Derived data only; never
     * holds request-specific state, so it is safe to reuse across requests on a
     * persistent worker. Invalidated via {@see self::clearCache()} on any
     * role/permission assignment change.
     *
     * @var array<int, array<int, string>>
     */
    private static array $effectivePermissionCache = [];

    private Database $db;
    private PermissionRegistry $registry;
    private ?LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param Database              $db       The database connection instance.
     * @param PermissionRegistry    $registry The permission registry instance.
     * @param LoggerInterface|null  $logger   Optional PSR-3 logger used to warn on
     *                                         circular hierarchies. When null, no
     *                                         output is emitted (keeps tests clean).
     */
    public function __construct(Database $db, PermissionRegistry $registry, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->registry = $registry;
        $this->logger = $logger;
    }

    /**
     * Get the role name for a specific user.
     *
     * Queries the database to retrieve the role name for the given user ID using
     * a JOIN between the users and roles tables.
     *
     * @param int $userId The user ID to query.
     * @return string|null The role name if the user exists, null otherwise.
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
     * Check if a user has a specific role.
     *
     * @param int    $userId       The user ID to check.
     * @param string $requiredRole The role name to verify against.
     * @return bool True if the user has the required role, false otherwise.
     */
    public function hasRole(int $userId, string $requiredRole): bool
    {
        $userRole = $this->getRoleForUser($userId);
        return $userRole === $requiredRole;
    }

    /**
     * Check if a user has a specific permission.
     *
     * Resolution order:
     *  1. The permission must exist in the {@see PermissionRegistry} (an unknown
     *     permission can never be granted).
     *  2. A direct grant on the user's role is honoured (backward-compatible with
     *     the historical {@see role_permissions} check the middleware relies on).
     *  3. Otherwise the permission is resolved through the role hierarchy: the
     *     user's role inherits every permission of the roles beneath it.
     *
     * Both the direct-grant check (step 2) and the hierarchy resolution (step 3)
     * read grants through the SAME correct join — `role_permissions` is linked to
     * `permissions` by `permission_id` and matched on `permissions.name` (WC-54).
     * There is no `role_permissions.permission_string` column; the historical
     * query that referenced one 500-ed every permission-gated route against the
     * real database.
     *
     * The signature and semantics ("does this user effectively have this
     * permission?") are unchanged, so callers such as the RBAC middleware need no
     * modification.
     *
     * @param int    $userId     The user ID to check.
     * @param string $permission The permission string to verify (colon notation).
     * @return bool True if the user has the permission (directly or inherited).
     */
    public function hasPermission(int $userId, string $permission): bool
    {
        // Step 1: an unregistered permission can never be granted.
        if (!$this->registry->permissionExists($permission)) {
            return false;
        }

        // Step 2: direct grant on the user's primary role. A single query reaches
        // role_permissions only through the user join, exactly as production data
        // is reachable, and short-circuits before any hierarchy walk — preserving
        // backward-compatible middleware semantics.
        if ($this->userRoleHasDirectPermission($userId, $permission)) {
            return true;
        }

        // Step 3: hierarchy-inherited grant. Resolve the effective permission set
        // for the user's role by walking up the parent chain.
        $roleId = $this->getRoleIdForUser($userId);
        if ($roleId === null) {
            return false;
        }

        return in_array($permission, $this->getEffectivePermissionsForRole($roleId), true);
    }

    /**
     * Get all permissions directly granted to a user through their role.
     *
     * Reads grants through the real schema join (`role_permissions.permission_id`
     * -> `permissions.name`), the same correct query the rest of this class uses
     * (WC-54). Does NOT include hierarchy-inherited permissions; use
     * {@see self::getEffectivePermissionsForRole()} for the inherited set.
     *
     * @param int $userId The user ID to query.
     * @return array<int, string> Permission strings granted directly to the user.
     */
    public function getPermissionsForUser(int $userId): array
    {
        $sql = 'SELECT DISTINCT p.name
                FROM role_permissions rp
                JOIN users u ON u.role_id = rp.role_id
                JOIN permissions p ON p.id = rp.permission_id
                WHERE u.id = :userId';
        $statement = $this->db->query($sql, [':userId' => $userId]);
        $results = $statement->fetchAll();

        if ($results === false) {
            return [];
        }

        return array_map(static fn($row) => $row['name'], $results);
    }

    /**
     * Resolve the effective permission set for a role, including everything
     * inherited from roles beneath it in the hierarchy.
     *
     * Walks up the parent chain starting at $roleId, unioning each role's
     * directly-granted permissions. Traversal is protected by visited-set cycle
     * detection and a hard {@see self::MAX_HIERARCHY_DEPTH} bound: a circular or
     * over-deep hierarchy is logged as a warning and resolution terminates with
     * the permissions gathered so far rather than looping forever.
     *
     * Results are memoised in the worker-level cache; {@see self::clearCache()}
     * invalidates them after any role/permission assignment change.
     *
     * @param int      $roleId   The role whose effective permissions to resolve.
     * @param int|null $tenantId Optional tenant id for structured warning context.
     * @return array<int, string> The effective (own + inherited) permission strings.
     */
    public function getEffectivePermissionsForRole(int $roleId, ?int $tenantId = null): array
    {
        if (isset(self::$effectivePermissionCache[$roleId])) {
            return self::$effectivePermissionCache[$roleId];
        }

        $permissions = [];
        $visited = [];
        $currentRoleId = $roleId;
        $depth = 0;

        while ($currentRoleId !== null) {
            // Cycle detection: a role we have already visited means the hierarchy
            // loops back on itself. Stop and warn rather than spin forever.
            if (isset($visited[$currentRoleId])) {
                $this->warnCircularHierarchy($roleId, $currentRoleId, $tenantId, array_keys($visited));
                break;
            }

            // Depth safeguard: bounds even a non-repeating-but-pathological chain.
            if ($depth >= self::MAX_HIERARCHY_DEPTH) {
                $this->warnMaxDepthExceeded($roleId, $tenantId);
                break;
            }

            $visited[$currentRoleId] = true;
            $depth++;

            foreach ($this->getDirectPermissionsForRole($currentRoleId) as $permission) {
                $permissions[$permission] = true;
            }

            $currentRoleId = $this->getParentRoleId($currentRoleId);
        }

        $resolved = array_keys($permissions);
        self::$effectivePermissionCache[$roleId] = $resolved;

        return $resolved;
    }

    /**
     * Get effective roles for a user including OU-inherited roles.
     *
     * Returns roles from both direct assignment and OU membership via a UNION of:
     *  1. The user's direct role from users.role_id.
     *  2. Roles assigned to the user's OU via ou_role_assignments.
     *
     * @param int $userId   The user ID to query.
     * @param int $tenantId The tenant ID for scoping.
     * @return array<int, string> Map of role_id => role_name.
     */
    public function getEffectiveRolesForUser(int $userId, int $tenantId): array
    {
        $sql = '
            SELECT DISTINCT r.id, r.name
            FROM roles r
            WHERE r.id IN (
                -- Direct role from users table
                SELECT u.role_id FROM users u WHERE u.id = :userId AND u.tenant_id = :tenantId
                UNION
                -- Roles from OU assignments
                SELECT ora.role_id FROM ou_role_assignments ora
                WHERE ora.ou_id = (SELECT ou_id FROM users WHERE id = :userId AND tenant_id = :tenantId)
                  AND ora.tenant_id = :tenantId
            )
        ';

        $statement = $this->db->query($sql, [
            ':userId' => $userId,
            ':tenantId' => $tenantId
        ]);
        $results = $statement->fetchAll();

        if ($results === false || empty($results)) {
            return [];
        }

        $roles = [];
        foreach ($results as $row) {
            $roles[(int)$row['id']] = $row['name'];
        }
        return $roles;
    }

    /**
     * Invalidate the worker-level effective-permission cache.
     *
     * Must be called whenever role/permission assignments or the role hierarchy
     * change so that subsequent authorization checks see the new grants rather
     * than a stale resolved set. {@see RolesApiHandler} invokes this after any
     * mutating write (create/update/delete).
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$effectivePermissionCache = [];
    }

    /**
     * Resolve the primary role id for a user.
     *
     * @param int $userId The user ID.
     * @return int|null The role id, or null if the user has no role / does not exist.
     */
    private function getRoleIdForUser(int $userId): ?int
    {
        $statement = $this->db->query(
            'SELECT role_id FROM users WHERE id = :userId',
            [':userId' => $userId]
        );
        $result = $statement->fetch();

        if ($result === false || $result['role_id'] === null) {
            return null;
        }

        return (int) $result['role_id'];
    }

    /**
     * Whether the role assigned to a user directly holds a given permission.
     *
     * The authoritative direct-grant query (WC-54): `role_permissions` reached
     * through the user's role and joined to `permissions` on `permission_id`,
     * matched on `permissions.name`. This mirrors the historical step-2 probe's
     * `:userId` + `:permission` contract — role_permissions rows are reachable
     * only via the user join, exactly as in production — while fixing the phantom
     * `role_permissions.permission_string` column that 500-ed the query against
     * the real schema. It reads grants through the SAME `permission_id ->
     * permissions.name` join the hierarchy walk uses
     * ({@see self::getDirectPermissionsForRole()}), so the direct-grant and
     * inherited paths can never diverge on which schema they read.
     *
     * @param int    $userId     The user whose role is checked.
     * @param string $permission The permission name (colon notation) to look for.
     * @return bool True when the user's role directly holds the permission.
     */
    private function userRoleHasDirectPermission(int $userId, string $permission): bool
    {
        $statement = $this->db->query(
            'SELECT 1 FROM role_permissions rp
             JOIN users u ON u.role_id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = :userId AND p.name = :permission',
            [':userId' => $userId, ':permission' => $permission]
        );

        return $statement->fetch() !== false;
    }

    /**
     * Get the permission strings granted directly to a single role.
     *
     * Joins role_permissions -> permissions and returns the permission names
     * (colon-notation slugs), excluding any inherited permissions.
     *
     * @param int $roleId The role id.
     * @return array<int, string> The directly-granted permission strings.
     */
    private function getDirectPermissionsForRole(int $roleId): array
    {
        $statement = $this->db->query(
            'SELECT p.name FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :roleId',
            [':roleId' => $roleId]
        );
        $results = $statement->fetchAll();

        if ($results === false || $results === []) {
            return [];
        }

        return array_map(static fn($row) => $row['name'], $results);
    }

    /**
     * Get the parent role id for a role, or null if it is a hierarchy root.
     *
     * @param int $roleId The role id.
     * @return int|null The parent role id, or null when there is no parent.
     */
    private function getParentRoleId(int $roleId): ?int
    {
        $statement = $this->db->query(
            'SELECT parent_id FROM roles WHERE id = :roleId',
            [':roleId' => $roleId]
        );
        $result = $statement->fetch();

        if ($result === false || $result['parent_id'] === null) {
            return null;
        }

        return (int) $result['parent_id'];
    }

    /**
     * Emit a structured warning that a circular role hierarchy was detected.
     *
     * @param int            $startRoleId  The role whose resolution started the walk.
     * @param int            $repeatRoleId The role that closed the cycle.
     * @param int|null       $tenantId     Tenant id for structured context.
     * @param array<int,int> $chain        The visited role-id chain leading to the cycle.
     * @return void
     */
    private function warnCircularHierarchy(int $startRoleId, int $repeatRoleId, ?int $tenantId, array $chain): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Circular role hierarchy detected; permission resolution terminated', [
            'event' => 'rbac.role_hierarchy.cycle_detected',
            'tenant_id' => $tenantId,
            'start_role_id' => $startRoleId,
            'repeated_role_id' => $repeatRoleId,
            'visited_chain' => $chain,
        ]);
    }

    /**
     * Emit a structured warning that the hierarchy depth safeguard was hit.
     *
     * @param int      $startRoleId The role whose resolution started the walk.
     * @param int|null $tenantId    Tenant id for structured context.
     * @return void
     */
    private function warnMaxDepthExceeded(int $startRoleId, ?int $tenantId): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Role hierarchy exceeded maximum depth; permission resolution terminated', [
            'event' => 'rbac.role_hierarchy.max_depth_exceeded',
            'tenant_id' => $tenantId,
            'start_role_id' => $startRoleId,
            'max_depth' => self::MAX_HIERARCHY_DEPTH,
        ]);
    }
}
