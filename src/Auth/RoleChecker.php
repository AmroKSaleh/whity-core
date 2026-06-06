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
 * user holds a specific role or permission. Authorization is always tenant
 * scoped: every public check ({@see self::hasRole()}, {@see self::hasPermission()},
 * {@see self::getEffectiveRolesForUser()}) takes the resolved tenant id so that
 * grants reached through tenant-scoped organizational units can never leak across
 * tenant boundaries.
 *
 * Effective-roles model (WC-54)
 * -----------------------------
 * A user's EFFECTIVE role set is the UNION of:
 *  1. Their DIRECT role (`users.role_id`).
 *  2. Every role assigned to the organizational unit the user belongs to
 *     (`users.ou_id`), AND every role assigned to that OU's ANCESTORS — the chain
 *     of `organizational_units.parent_id` walked UP to the root. A user in a child
 *     OU therefore inherits the roles granted to every OU above it. All OU role
 *     lookups are filtered by `ou_role_assignments.tenant_id = :tenantId`, so an
 *     OU role assigned in tenant A never grants anything in tenant B.
 *
 * The user's EFFECTIVE PERMISSION set is then the union of the hierarchy-resolved
 * permission set (see below) of EVERY effective role. So a permission is granted
 * if the user's direct role — or any OU/ancestor-OU role, or any role those
 * inherit through the role hierarchy — holds it.
 *
 * Role hierarchy (WC-15)
 * ----------------------
 * Roles may be arranged into an inheritance hierarchy via the
 * {@see roles}.parent_id column (super_admin -> admin -> editor -> viewer). A
 * higher role automatically inherits every permission granted to the roles
 * beneath it. The effective permission set for a role is resolved by walking up
 * the parent chain and unioning each role's directly-granted permissions.
 *
 * Cycle/depth safety
 * ------------------
 * A malformed hierarchy (e.g. A -> B -> A in roles, or a cyclic OU parent chain)
 * must never hang request processing. Every traversal — the role parent chain AND
 * the OU parent chain — tracks visited ids and is bounded by a hard
 * {@see self::MAX_HIERARCHY_DEPTH} safeguard; a detected cycle (or exceeding the
 * depth bound) is logged as a warning and resolution terminates gracefully with
 * whatever was collected so far.
 *
 * Worker-level caches
 * -------------------
 * Two derived, non-request-specific caches memoise resolution so authorization
 * never re-walks the graph on every check:
 *  - {@see self::$effectivePermissionCache}: roleId => permission list (a role's
 *    hierarchy-resolved permissions; tenant-independent).
 *  - {@see self::$effectiveUserPermissionCache}: "userId:tenantId" => permission
 *    list (the user's full effective permission set, which IS tenant scoped
 *    because OU membership/assignments are).
 * Both hold only derived data (no request-specific state), so they are safe to
 * share across the requests a FrankenPHP worker serves. They MUST be invalidated
 * whenever role/permission assignments, the role hierarchy, OU membership, or OU
 * role assignments change — {@see RolesApiHandler}, {@see UsersApiHandler} and
 * {@see OusApiHandler} call {@see self::clearCache()} after any mutating write.
 */
class RoleChecker
{
    /**
     * Hard upper bound on hierarchy traversal depth (role parent chain AND OU
     * parent chain).
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
     * persistent worker. A role's resolved permissions are tenant-independent, so
     * this cache is keyed by role id alone. Invalidated via {@see self::clearCache()}.
     *
     * @var array<int, array<int, string>>
     */
    private static array $effectivePermissionCache = [];

    /**
     * Process (worker) level cache of a user's full effective permission set,
     * keyed by "userId:tenantId".
     *
     * The set unions the hierarchy-resolved permissions of the user's direct role
     * AND every OU/ancestor-OU role. Because OU membership and OU role assignments
     * are tenant scoped, this cache MUST be keyed by tenant in addition to user —
     * the same user resolved under a different tenant may see a different set.
     * Derived data only; invalidated via {@see self::clearCache()}.
     *
     * @var array<string, array<int, string>>
     */
    private static array $effectiveUserPermissionCache = [];

    private Database $db;
    private PermissionRegistry $registry;
    private ?LoggerInterface $logger;

    /**
     * Optional resolver for delegated permissions (WC-34).
     *
     * When wired, a user's effective permission set is unioned with the LIVE
     * (non-revoked) permissions delegated to them — directly or through any of
     * their effective roles, within OU scope — so a delegation actually grants
     * access through {@see self::hasPermission()}. When null (e.g. legacy tests),
     * delegation contributes nothing and resolution behaves exactly as before.
     */
    private ?DelegatedPermissionResolver $delegationResolver;

    /**
     * Constructor.
     *
     * @param Database                        $db                 The database connection instance.
     * @param PermissionRegistry              $registry           The permission registry instance.
     * @param LoggerInterface|null            $logger             Optional PSR-3 logger used to warn on
     *                                                            circular hierarchies. When null, no
     *                                                            output is emitted (keeps tests clean).
     * @param DelegatedPermissionResolver|null $delegationResolver Optional delegated-permission
     *                                                            resolver (WC-34). When null, delegated
     *                                                            grants are not consulted.
     */
    public function __construct(
        Database $db,
        PermissionRegistry $registry,
        ?LoggerInterface $logger = null,
        ?DelegatedPermissionResolver $delegationResolver = null
    ) {
        $this->db = $db;
        $this->registry = $registry;
        $this->logger = $logger;
        $this->delegationResolver = $delegationResolver;
    }

    /**
     * Get the role name for a specific user.
     *
     * Queries the database to retrieve the DIRECT role name for the given user ID
     * using a JOIN between the users and roles tables. This is the user's primary
     * role only; it does NOT consider OU-inherited roles (use
     * {@see self::getEffectiveRolesForUser()} for the full set).
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
     * Check if a user effectively has a specific role within a tenant.
     *
     * A user "has" a role when it is in their EFFECTIVE role set — their direct
     * role ({@see users}.role_id) OR any role assigned to their organizational
     * unit or any of its ancestors, all scoped to {@see $tenantId}. OU role
     * assignments are therefore additive: a user in an OU that has the `admin`
     * role assigned satisfies a `hasRole($userId, 'admin', $tenantId)` check even
     * if their direct role is `user`. Cross-tenant OU roles never count because
     * the OU-role lookup is filtered by tenant id.
     *
     * @param int    $userId       The user ID to check.
     * @param string $requiredRole The role name to verify against.
     * @param int    $tenantId     The resolved tenant id (0 = system tenant).
     * @return bool True if the user effectively has the required role.
     */
    public function hasRole(int $userId, string $requiredRole, int $tenantId): bool
    {
        $effectiveRoles = $this->getEffectiveRolesForUser($userId, $tenantId);

        return in_array($requiredRole, $effectiveRoles, true);
    }

    /**
     * Check if a user effectively has a specific permission within a tenant.
     *
     * Resolution order:
     *  1. The permission must exist in the {@see PermissionRegistry} (an unknown
     *     permission can never be granted).
     *  2. Otherwise the permission is resolved against the user's EFFECTIVE
     *     permission set: the union, over every effective role (direct role +
     *     OU/ancestor-OU roles, all tenant scoped), of that role's
     *     hierarchy-resolved permissions ({@see self::getEffectivePermissionsForRole()}).
     *
     * Direct-role grants, role-hierarchy inheritance and OU-inherited grants are
     * all read through the SAME `role_permissions.permission_id -> permissions.name`
     * join, so the paths can never diverge on which schema they read (WC-54). There
     * is no `role_permissions.permission_string` column.
     *
     * @param int    $userId     The user ID to check.
     * @param string $permission The permission string to verify (colon notation).
     * @param int    $tenantId   The resolved tenant id (0 = system tenant).
     * @return bool True if the user effectively has the permission.
     */
    public function hasPermission(int $userId, string $permission, int $tenantId): bool
    {
        // Step 1: an unregistered permission can never be granted.
        if (!$this->registry->exists($permission)) {
            return false;
        }

        // Step 2: resolve the user's full effective permission set (direct role +
        // OU/ancestor-OU roles, each expanded through the role hierarchy) and test
        // membership.
        return in_array($permission, $this->getEffectivePermissionsForUser($userId, $tenantId), true);
    }

    /**
     * Get all permissions directly granted to a user through their primary role.
     *
     * Reads grants through the real schema join (`role_permissions.permission_id`
     * -> `permissions.name`), the same correct query the rest of this class uses
     * (WC-54). Does NOT include hierarchy-inherited or OU-inherited permissions;
     * use {@see self::getEffectivePermissionsForUser()} for the full set.
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
     * Resolve a user's full EFFECTIVE permission set within a tenant.
     *
     * The union, over every effective role of the user (direct role + every
     * tenant-scoped OU/ancestor-OU role), of that role's hierarchy-resolved
     * permissions ({@see self::getEffectivePermissionsForRole()}). This is the set
     * {@see self::hasPermission()} tests against.
     *
     * Results are memoised in the per-user worker-level cache (keyed by
     * userId:tenantId because OU membership/assignments are tenant scoped);
     * {@see self::clearCache()} invalidates them after any role/OU mutation.
     *
     * @param int $userId   The user ID to resolve.
     * @param int $tenantId The resolved tenant id (0 = system tenant).
     * @return array<int, string> The effective permission strings.
     */
    public function getEffectivePermissionsForUser(int $userId, int $tenantId): array
    {
        $cacheKey = $userId . ':' . $tenantId;
        if (isset(self::$effectiveUserPermissionCache[$cacheKey])) {
            return self::$effectiveUserPermissionCache[$cacheKey];
        }

        $permissions = [];

        $effectiveRoleIds = $this->getEffectiveRoleIdsForUser($userId, $tenantId);
        foreach ($effectiveRoleIds as $roleId) {
            foreach ($this->getEffectivePermissionsForRole($roleId, $tenantId) as $permission) {
                $permissions[$permission] = true;
            }
        }

        // WC-34: union in LIVE delegated permissions. A delegation made to the
        // user directly, or to any of their effective roles, grants access too —
        // honouring the delegation lifecycle (revoked delegations contribute
        // nothing) and OU scope (an OU-scoped delegation applies only when the
        // user falls within that OU subtree). Resolution stays tenant scoped
        // because the resolver itself filters by tenant.
        if ($this->delegationResolver !== null) {
            $inScopeOuIds = $this->getOuChainIdsForUser($userId, $tenantId);
            foreach (
                $this->delegationResolver->delegatedPermissionsForUser(
                    $userId,
                    $tenantId,
                    $effectiveRoleIds,
                    $inScopeOuIds
                ) as $permission
            ) {
                $permissions[$permission] = true;
            }
        }

        $resolved = array_keys($permissions);
        self::$effectiveUserPermissionCache[$cacheKey] = $resolved;

        return $resolved;
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
     * Results are memoised in the per-role worker-level cache; {@see self::clearCache()}
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
     * Get a user's effective roles (names) within a tenant.
     *
     * Returns the union of:
     *  1. The user's direct role from {@see users}.role_id.
     *  2. Roles assigned to the user's organizational unit AND every ancestor OU
     *     (walking {@see organizational_units}.parent_id up to the root), via
     *     {@see ou_role_assignments}, filtered to {@see $tenantId}.
     *
     * OU role assignments are additive and tenant scoped: an assignment made in
     * another tenant can never appear here. The OU parent-chain walk is bounded by
     * cycle/depth safety identical to the role hierarchy.
     *
     * @param int $userId   The user ID to query.
     * @param int $tenantId The tenant ID for scoping (0 = system tenant).
     * @return array<int, string> Distinct effective role NAMES (direct + OU-inherited).
     */
    public function getEffectiveRolesForUser(int $userId, int $tenantId): array
    {
        $roleIds = $this->getEffectiveRoleIdsForUser($userId, $tenantId);
        if ($roleIds === []) {
            return [];
        }

        $names = [];
        foreach ($roleIds as $roleId) {
            $name = $this->getRoleName($roleId);
            if ($name !== null) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Invalidate the worker-level effective-permission caches.
     *
     * Must be called whenever any input to authorization changes — role/permission
     * assignments, the role hierarchy, a user's OU membership, or OU role
     * assignments — so subsequent checks see the new grants rather than a stale
     * resolved set. {@see RolesApiHandler}, {@see UsersApiHandler} and
     * {@see OusApiHandler} invoke this after any such mutating write.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$effectivePermissionCache = [];
        self::$effectiveUserPermissionCache = [];
    }

    /**
     * Resolve the distinct effective role IDs for a user within a tenant.
     *
     * The union of the user's direct role id and every role id assigned to their
     * OU and its ancestors (tenant scoped). This is the integer backbone shared by
     * {@see self::getEffectiveRolesForUser()} (which maps to names) and
     * {@see self::getEffectivePermissionsForUser()} (which expands to permissions).
     *
     * @param int $userId   The user ID.
     * @param int $tenantId The tenant ID for scoping.
     * @return array<int, int> Distinct effective role ids.
     */
    private function getEffectiveRoleIdsForUser(int $userId, int $tenantId): array
    {
        $roleIds = [];

        $directRoleId = $this->getRoleIdForUser($userId);
        if ($directRoleId !== null) {
            $roleIds[$directRoleId] = true;
        }

        $ouId = $this->getOuIdForUser($userId, $tenantId);
        if ($ouId !== null) {
            foreach ($this->getOuChainRoleIds($ouId, $tenantId) as $roleId) {
                $roleIds[$roleId] = true;
            }
        }

        return array_keys($roleIds);
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
     * Resolve the organizational unit id a user belongs to, tenant scoped.
     *
     * The tenant predicate guarantees a user record is only read in the context of
     * its own tenant, so a mismatched (userId, tenantId) pair yields no OU and
     * therefore no OU-inherited roles.
     *
     * @param int $userId   The user ID.
     * @param int $tenantId The tenant ID for scoping.
     * @return int|null The OU id, or null when the user is not in an OU.
     */
    private function getOuIdForUser(int $userId, int $tenantId): ?int
    {
        $statement = $this->db->query(
            'SELECT ou_id FROM users WHERE id = :userId AND tenant_id = :tenantId',
            [':userId' => $userId, ':tenantId' => $tenantId]
        );
        $result = $statement->fetch();

        if ($result === false || $result['ou_id'] === null) {
            return null;
        }

        return (int) $result['ou_id'];
    }

    /**
     * Collect every role id assigned to an OU and all of its ancestors.
     *
     * Walks the {@see organizational_units}.parent_id chain UP from $ouId to the
     * root, unioning the {@see ou_role_assignments} role ids found at each level.
     * All assignment lookups are filtered by {@see $tenantId}, and the parent-chain
     * lookups are tenant scoped too, so the walk can never cross into another
     * tenant's hierarchy. Bounded by visited-set cycle detection and a hard
     * {@see self::MAX_HIERARCHY_DEPTH}, mirroring the role-hierarchy walk.
     *
     * @param int $ouId     The OU the user belongs to.
     * @param int $tenantId The tenant ID for scoping.
     * @return array<int, int> Distinct role ids inherited through the OU chain.
     */
    private function getOuChainRoleIds(int $ouId, int $tenantId): array
    {
        $roleIds = [];
        $visited = [];
        $currentOuId = $ouId;
        $depth = 0;

        while ($currentOuId !== null) {
            if (isset($visited[$currentOuId])) {
                $this->warnCircularOuChain($ouId, $currentOuId, $tenantId, array_keys($visited));
                break;
            }

            if ($depth >= self::MAX_HIERARCHY_DEPTH) {
                $this->warnOuMaxDepthExceeded($ouId, $tenantId);
                break;
            }

            $visited[$currentOuId] = true;
            $depth++;

            foreach ($this->getRoleIdsAssignedToOu($currentOuId, $tenantId) as $roleId) {
                $roleIds[$roleId] = true;
            }

            $currentOuId = $this->getParentOuId($currentOuId, $tenantId);
        }

        return array_keys($roleIds);
    }

    /**
     * Collect the OU ids in scope for a user: the user's own OU and every
     * ancestor OU (walking {@see organizational_units}.parent_id up to the root),
     * tenant scoped. Returns an empty list when the user is in no OU.
     *
     * This is the OU-subtree membership a user satisfies "from below": a
     * delegation scoped to OU X applies to a user whose OU is X or any descendant
     * of X — which is exactly when X appears in this user's OU + ancestor chain.
     * Bounded by the same visited-set cycle detection and {@see self::MAX_HIERARCHY_DEPTH}
     * as the role-id walk (WC-34).
     *
     * @param int $userId   The user ID.
     * @param int $tenantId The tenant ID for scoping.
     * @return array<int, int> Distinct OU ids (own OU + ancestors), tenant scoped.
     */
    private function getOuChainIdsForUser(int $userId, int $tenantId): array
    {
        $ouId = $this->getOuIdForUser($userId, $tenantId);
        if ($ouId === null) {
            return [];
        }

        $ouIds = [];
        $visited = [];
        $currentOuId = $ouId;
        $depth = 0;

        while ($currentOuId !== null) {
            if (isset($visited[$currentOuId])) {
                $this->warnCircularOuChain($ouId, $currentOuId, $tenantId, array_keys($visited));
                break;
            }

            if ($depth >= self::MAX_HIERARCHY_DEPTH) {
                $this->warnOuMaxDepthExceeded($ouId, $tenantId);
                break;
            }

            $visited[$currentOuId] = true;
            $depth++;
            $ouIds[$currentOuId] = true;

            $currentOuId = $this->getParentOuId($currentOuId, $tenantId);
        }

        return array_keys($ouIds);
    }

    /**
     * Get the role ids directly assigned to a single OU, tenant scoped.
     *
     * @param int $ouId     The OU id.
     * @param int $tenantId The tenant ID for scoping.
     * @return array<int, int> The assigned role ids.
     */
    private function getRoleIdsAssignedToOu(int $ouId, int $tenantId): array
    {
        $statement = $this->db->query(
            'SELECT role_id FROM ou_role_assignments WHERE ou_id = :ouId AND tenant_id = :tenantId',
            [':ouId' => $ouId, ':tenantId' => $tenantId]
        );
        $results = $statement->fetchAll();

        if ($results === false || $results === []) {
            return [];
        }

        return array_map(static fn($row) => (int) $row['role_id'], $results);
    }

    /**
     * Get the parent OU id for an OU, tenant scoped, or null at the root.
     *
     * @param int $ouId     The OU id.
     * @param int $tenantId The tenant ID for scoping.
     * @return int|null The parent OU id, or null when there is no parent.
     */
    private function getParentOuId(int $ouId, int $tenantId): ?int
    {
        $statement = $this->db->query(
            'SELECT parent_id FROM organizational_units WHERE id = :ouId AND tenant_id = :tenantId',
            [':ouId' => $ouId, ':tenantId' => $tenantId]
        );
        $result = $statement->fetch();

        if ($result === false || $result['parent_id'] === null) {
            return null;
        }

        return (int) $result['parent_id'];
    }

    /**
     * Resolve a role's name by id.
     *
     * @param int $roleId The role id.
     * @return string|null The role name, or null when the role does not exist.
     */
    private function getRoleName(int $roleId): ?string
    {
        $statement = $this->db->query(
            'SELECT name FROM roles WHERE id = :roleId',
            [':roleId' => $roleId]
        );
        $result = $statement->fetch();

        if ($result === false) {
            return null;
        }

        return $result['name'];
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

    /**
     * Emit a structured warning that a circular OU parent chain was detected.
     *
     * @param int            $startOuId  The OU whose resolution started the walk.
     * @param int            $repeatOuId The OU that closed the cycle.
     * @param int            $tenantId   Tenant id for structured context.
     * @param array<int,int> $chain      The visited OU-id chain leading to the cycle.
     * @return void
     */
    private function warnCircularOuChain(int $startOuId, int $repeatOuId, int $tenantId, array $chain): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Circular organizational-unit hierarchy detected; OU role inheritance terminated', [
            'event' => 'rbac.ou_hierarchy.cycle_detected',
            'tenant_id' => $tenantId,
            'start_ou_id' => $startOuId,
            'repeated_ou_id' => $repeatOuId,
            'visited_chain' => $chain,
        ]);
    }

    /**
     * Emit a structured warning that the OU parent-chain depth safeguard was hit.
     *
     * @param int $startOuId The OU whose resolution started the walk.
     * @param int $tenantId  Tenant id for structured context.
     * @return void
     */
    private function warnOuMaxDepthExceeded(int $startOuId, int $tenantId): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning('Organizational-unit hierarchy exceeded maximum depth; OU role inheritance terminated', [
            'event' => 'rbac.ou_hierarchy.max_depth_exceeded',
            'tenant_id' => $tenantId,
            'start_ou_id' => $startOuId,
            'max_depth' => self::MAX_HIERARCHY_DEPTH,
        ]);
    }
}
