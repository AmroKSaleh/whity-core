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
 * Membership-aware permission model (WC-bc07b6de)
 * ------------------------------------------------
 * Effective permissions are resolved from the caller's MEMBERSHIP in the ACTIVE
 * tenant. A caller identified by (profile_id, active_tenant_id) maps to exactly
 * one `memberships` row (UNIQUE(profile_id, tenant_id)), which carries the
 * `role_id` and optional `ou_id` for that tenant. The same profile can be admin
 * in tenant A and read-only in tenant B — permissions are strictly per-membership.
 *
 * Primary API:
 *   - {@see self::hasPermissionForProfile()} — membership-aware, new callers use this.
 *   - {@see self::hasRoleForProfile()} — membership-aware role check.
 *   - {@see self::getEffectivePermissionsForProfile()} — full resolution set.
 *
 * Legacy dual-window compatibility:
 *   - {@see self::hasPermission()} and {@see self::hasRole()} still work during
 *     the Phase B transition. They first attempt a membership lookup (mapping the
 *     legacy user_id to a profile via migration_035_profile_ids, then resolving
 *     via memberships). If that lookup succeeds the membership path is used. If
 *     the 035 mapping is absent (fresh database, no legacy data) they fall back
 *     to the original users-table path so no existing behavior is weakened.
 *
 * Effective-roles model (WC-54)
 * -----------------------------
 * A caller's EFFECTIVE role set is the UNION of:
 *  1. Their DIRECT role (from `memberships.role_id`).
 *  2. Every role assigned to the organizational unit the caller belongs to
 *     (`memberships.ou_id`), AND every role assigned to that OU's ANCESTORS —
 *     the chain of `organizational_units.parent_id` walked UP to the root.
 *     A caller in a child OU therefore inherits the roles granted to every OU
 *     above it. All OU role lookups are filtered by
 *     `ou_role_assignments.tenant_id = :tenantId`, so an OU role assigned in
 *     tenant A never grants anything in tenant B.
 *
 * The caller's EFFECTIVE PERMISSION set is then the union of the
 * hierarchy-resolved permission set (see below) of EVERY effective role. So a
 * permission is granted if the membership role — or any OU/ancestor-OU role,
 * or any role those inherit through the role hierarchy — holds it.
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
 *  - {@see self::$effectiveUserPermissionCache}: cache key => permission list.
 *    Keys have the form "p:profileId:tenantId:delegationFlag" for membership-aware
 *    callers and "u:userId:tenantId:delegationFlag" for legacy-user callers.
 * Both hold only derived data (no request-specific state), so they are safe to
 * share across the requests a FrankenPHP worker serves. They MUST be invalidated
 * whenever role/permission assignments, the role hierarchy, OU membership, or OU
 * role assignments change — {@see RolesApiHandler}, {@see UsersApiHandler},
 * {@see OusApiHandler}, and {@see DelegationsApiHandler} call
 * {@see self::clearCache()} after any mutating write.
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
     * Process (worker) level cache of a caller's full effective permission set.
     *
     * Keys have the form:
     *  - "p:{profileId}:{tenantId}:{delegationFlag}" — membership-aware resolution
     *    (profile_id + active_tenant_id → memberships row).
     *  - "u:{userId}:{tenantId}:{delegationFlag}" — legacy-user resolution
     *    (falls back to users.role_id when no membership mapping exists).
     *
     * The delegation-awareness flag ("1" when a DelegatedPermissionResolver is
     * wired, "0" otherwise) prevents two checkers — the enforcement checker and
     * the bounding checker — from colliding on the same key, which would permit
     * transitive re-delegation escalation (WC-34).
     *
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
     * When wired, a caller's effective permission set is unioned with the LIVE
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

    // =========================================================================
    // Membership-aware public API (WC-bc07b6de — preferred for new callers)
    // =========================================================================

    /**
     * Check if a profile effectively has a specific role within a tenant,
     * resolving from the `memberships` table.
     *
     * A profile "has" a role when it is in their EFFECTIVE role set —
     * their membership role OR any role assigned to their membership OU or
     * any of its ancestors, all scoped to {@see $tenantId}. System tenant (id 0)
     * retains platform-wide authority: checks against tenant 0 return true when
     * the profile's system-tenant membership carries the required role.
     *
     * @param int    $profileId    The profile id (ADR 0005 §1).
     * @param string $requiredRole The role name to verify against.
     * @param int    $tenantId     The resolved tenant id (0 = system tenant).
     * @return bool True if the profile effectively has the required role.
     */
    public function hasRoleForProfile(int $profileId, string $requiredRole, int $tenantId): bool
    {
        $effectiveRoles = $this->getEffectiveRolesForProfile($profileId, $tenantId);
        return in_array($requiredRole, $effectiveRoles, true);
    }

    /**
     * Check if a profile effectively has a specific permission within a tenant,
     * resolving from the `memberships` table (membership-aware primary path).
     *
     * Resolution order:
     *  1. The permission must exist in the {@see PermissionRegistry}.
     *  2. The profile must have an ACTIVE membership in $tenantId (suspended
     *     memberships contribute no permissions — the token may still be valid
     *     but the HTTP middleware should already have rejected it; this is a
     *     second line of defence).
     *  3. The membership's role — and every OU/ancestor-OU role in that tenant —
     *     is expanded through the role hierarchy to build the effective set.
     *  4. Live delegations (when a DelegatedPermissionResolver is wired) are
     *     unioned in last.
     *
     * A `:read` permission string can never satisfy a `:write` check.
     *
     * @param int    $profileId  The profile id.
     * @param string $permission The permission string to verify (colon notation).
     * @param int    $tenantId   The resolved tenant id (0 = system tenant).
     * @return bool True if the profile effectively has the permission.
     */
    public function hasPermissionForProfile(int $profileId, string $permission, int $tenantId): bool
    {
        if (!$this->registry->exists($permission)) {
            return false;
        }
        return in_array($permission, $this->getEffectivePermissionsForProfile($profileId, $tenantId), true);
    }

    /**
     * Get a profile's effective roles (names) within a tenant, resolving from
     * the `memberships` table.
     *
     * Returns the union of:
     *  1. The profile's direct role from `memberships.role_id` in the given tenant.
     *  2. Roles assigned to the profile's OU and every ancestor OU (walking
     *     `organizational_units.parent_id` up to the root), via
     *     `ou_role_assignments`, filtered to {@see $tenantId}.
     *
     * @param int $profileId The profile id.
     * @param int $tenantId  The tenant ID for scoping (0 = system tenant).
     * @return array<int, string> Distinct effective role NAMES.
     */
    public function getEffectiveRolesForProfile(int $profileId, int $tenantId): array
    {
        $roleIds = $this->getEffectiveRoleIdsForProfile($profileId, $tenantId);
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
     * Resolve a profile's full EFFECTIVE permission set within a tenant,
     * using the `memberships` table as the authority for role resolution.
     *
     * The union, over every effective role of the profile (membership role +
     * every tenant-scoped OU/ancestor-OU role), of that role's hierarchy-resolved
     * permissions. This is the set {@see self::hasPermissionForProfile()} tests
     * against.
     *
     * Results are memoised in the per-profile worker-level cache (keyed by
     * "p:{profileId}:{tenantId}:{flag}"); {@see self::clearCache()} invalidates
     * them after any role/membership/delegation mutation.
     *
     * @param int $profileId The profile id.
     * @param int $tenantId  The resolved tenant id (0 = system tenant).
     * @return array<int, string> The effective permission strings.
     */
    public function getEffectivePermissionsForProfile(int $profileId, int $tenantId): array
    {
        $cacheKey = 'p:' . $profileId . ':' . $tenantId . ':' . ($this->delegationResolver !== null ? '1' : '0');
        if (isset(self::$effectiveUserPermissionCache[$cacheKey])) {
            return self::$effectiveUserPermissionCache[$cacheKey];
        }

        $permissions = [];

        $effectiveRoleIds = $this->getEffectiveRoleIdsForProfile($profileId, $tenantId);
        foreach ($effectiveRoleIds as $roleId) {
            foreach ($this->getEffectivePermissionsForRole($roleId, $tenantId) as $permission) {
                $permissions[$permission] = true;
            }
        }

        // WC-34: union in LIVE delegated permissions via the profile-id path.
        // The DelegatedPermissionResolver interface still takes a user_id; during
        // the transition we pass the profile_id as the "userId" argument because
        // the delegation rows now reference profile_ids (migration 037). The
        // resolver remains tenant-scoped and honours OU scope.
        if ($this->delegationResolver !== null) {
            $inScopeOuIds = $this->getOuChainIdsForProfile($profileId, $tenantId);
            foreach (
                $this->delegationResolver->delegatedPermissionsForUser(
                    $profileId,
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

    // =========================================================================
    // Legacy user-based API (preserved for Phase B dual-window compatibility)
    // =========================================================================

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
        // @tenant-guard-ignore: lookup by globally-unique user id (SERIAL PK); a PK match returns at most one row regardless of tenant
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
     * Dual-window: first attempts to resolve via the membership path (mapping
     * user_id → profile_id via migration_035_profile_ids, then querying
     * memberships). When the mapping is present the membership-aware path is used
     * so the same profile is admin in tenant A but not in tenant B. Falls back to
     * the legacy users.role_id path when no mapping exists.
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
     * Dual-window: when the user_id → profile_id mapping (from migration 035)
     * is present, the membership-aware resolution path is used. Otherwise falls
     * back to the legacy users-table resolution. Neither path can ever grant a
     * permission the user/membership does not hold; unknown permissions are
     * rejected at the registry gate before any DB lookup.
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
        // @tenant-guard-ignore: lookup by globally-unique user id (SERIAL PK); a PK match returns at most one row regardless of tenant
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
     * Dual-window: when the user_id → profile_id mapping (from migration 035)
     * is present, delegates entirely to {@see self::getEffectivePermissionsForProfile()}
     * so permissions are membership-aware (per-tenant). When no mapping exists
     * (fresh schema, no legacy users) falls back to the users-table resolution
     * path so pre-migration behaviour is unaffected.
     *
     * Results are memoised in the worker-level cache; {@see self::clearCache()}
     * invalidates them after any role/OU/membership/delegation mutation.
     *
     * @param int $userId   The user ID to resolve.
     * @param int $tenantId The resolved tenant id (0 = system tenant).
     * @return array<int, string> The effective permission strings.
     */
    public function getEffectivePermissionsForUser(int $userId, int $tenantId): array
    {
        $cacheKey = 'u:' . $userId . ':' . $tenantId . ':' . ($this->delegationResolver !== null ? '1' : '0');
        if (isset(self::$effectiveUserPermissionCache[$cacheKey])) {
            return self::$effectiveUserPermissionCache[$cacheKey];
        }

        // Dual-window: attempt membership-aware resolution via the 035 mapping.
        $profileId = $this->getProfileIdForUser($userId);
        if ($profileId !== null) {
            // Delegate to the membership-aware path. Cache under the legacy key so
            // the same result is returned on subsequent calls without re-querying.
            $resolved = $this->getEffectivePermissionsForProfile($profileId, $tenantId);
            self::$effectiveUserPermissionCache[$cacheKey] = $resolved;
            return $resolved;
        }

        // Legacy fallback: users-table resolution.
        $permissions = [];

        $effectiveRoleIds = $this->getEffectiveRoleIdsForUser($userId, $tenantId);
        foreach ($effectiveRoleIds as $roleId) {
            foreach ($this->getEffectivePermissionsForRole($roleId, $tenantId) as $permission) {
                $permissions[$permission] = true;
            }
        }

        // WC-34: union in LIVE delegated permissions on the legacy path too.
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
     * Resolve a user's full EFFECTIVE permission set within a tenant.
     *
     * Dual-window delegation to getEffectivePermissionsForUser.
     *
     * @param int $userId   The user ID to resolve.
     * @param int $tenantId The resolved tenant id (0 = system tenant).
     * @return array<int, string> Distinct effective role NAMES.
     */
    public function getEffectiveRolesForUser(int $userId, int $tenantId): array
    {
        // Dual-window: attempt membership-aware resolution via the 035 mapping.
        $profileId = $this->getProfileIdForUser($userId);
        if ($profileId !== null) {
            return $this->getEffectiveRolesForProfile($profileId, $tenantId);
        }

        // Legacy fallback.
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
     * Invalidate the worker-level effective-permission caches.
     *
     * Must be called whenever any input to authorization changes — role/permission
     * assignments, the role hierarchy, a user's OU membership, membership role
     * changes, membership suspension/removal, or delegation changes — so subsequent
     * checks see the new grants rather than a stale resolved set.
     *
     * {@see RolesApiHandler}, {@see UsersApiHandler}, {@see OusApiHandler}, and
     * {@see DelegationsApiHandler} invoke this after any such mutating write.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$effectivePermissionCache = [];
        self::$effectiveUserPermissionCache = [];
    }

    // =========================================================================
    // Membership-aware private helpers
    // =========================================================================

    /**
     * Resolve the distinct effective role IDs for a profile within a tenant,
     * using `memberships` as the authority.
     *
     * The union of:
     *  1. The membership's direct role_id (from `memberships.role_id`).
     *  2. Every role id assigned to the membership's OU and its ancestors
     *     (tenant scoped, via `ou_role_assignments`).
     *
     * @param int $profileId The profile id.
     * @param int $tenantId  The tenant ID for scoping.
     * @return array<int, int> Distinct effective role ids.
     */
    private function getEffectiveRoleIdsForProfile(int $profileId, int $tenantId): array
    {
        $roleIds = [];

        $membership = $this->getMembershipRow($profileId, $tenantId);
        if ($membership === null) {
            return [];
        }

        // Only ACTIVE memberships grant permissions. Suspended/invited memberships
        // contribute nothing — the HTTP layer should already have rejected the
        // request, but this is a belt-and-braces guard.
        if ((string) $membership['status'] !== 'active') {
            return [];
        }

        $directRoleId = $membership['role_id'] !== null ? (int) $membership['role_id'] : null;
        if ($directRoleId !== null) {
            $roleIds[$directRoleId] = true;
        }

        $ouId = $membership['ou_id'] !== null ? (int) $membership['ou_id'] : null;
        if ($ouId !== null) {
            foreach ($this->getOuChainRoleIds($ouId, $tenantId) as $roleId) {
                $roleIds[$roleId] = true;
            }
        }

        return array_keys($roleIds);
    }

    /**
     * Fetch the active membership row for a (profile, tenant) pair.
     *
     * Returns null when no membership exists OR when the membership status is not
     * 'active'. Only active memberships contribute to permission resolution.
     *
     * @param int $profileId The profile id.
     * @param int $tenantId  The tenant id.
     * @return array<string, mixed>|null The normalised membership row, or null.
     */
    private function getMembershipRow(int $profileId, int $tenantId): ?array
    {
        $statement = $this->db->query(
            'SELECT role_id, ou_id, status FROM memberships
             WHERE profile_id = :profileId AND tenant_id = :tenantId
             LIMIT 1',
            [':profileId' => $profileId, ':tenantId' => $tenantId]
        );
        $result = $statement->fetch();

        if ($result === false) {
            return null;
        }

        return [
            'role_id' => $result['role_id'] !== null ? (int) $result['role_id'] : null,
            'ou_id'   => $result['ou_id']   !== null ? (int) $result['ou_id']   : null,
            'status'  => (string) $result['status'],
        ];
    }

    /**
     * Collect the OU ids in scope for a profile's membership: the membership's
     * own OU and every ancestor OU (walking organizational_units.parent_id up to
     * the root), tenant scoped. Returns an empty list when the profile's
     * membership has no OU assignment.
     *
     * @param int $profileId The profile id.
     * @param int $tenantId  The tenant id.
     * @return array<int, int> Distinct OU ids (own OU + ancestors).
     */
    private function getOuChainIdsForProfile(int $profileId, int $tenantId): array
    {
        $membership = $this->getMembershipRow($profileId, $tenantId);
        if ($membership === null || $membership['ou_id'] === null) {
            return [];
        }

        return $this->buildOuChainIds((int) $membership['ou_id'], $tenantId);
    }

    // =========================================================================
    // Legacy user-based private helpers (dual-window fallback)
    // =========================================================================

    /**
     * Map a user_id to a profile_id via the migration_035_profile_ids mapping
     * table established by migration 035.
     *
     * Returns null when:
     *  - the mapping table does not exist (migration 035 never ran), or
     *  - the user_id has no mapping row.
     *
     * @param int $userId The legacy user id.
     * @return int|null The corresponding profile id, or null.
     */
    private function getProfileIdForUser(int $userId): ?int
    {
        try {
            // @tenant-guard-ignore: migration_035_profile_ids is a global cross-tenant mapping table
            $statement = $this->db->query(
                'SELECT profile_id FROM migration_035_profile_ids WHERE user_id = :userId LIMIT 1',
                [':userId' => $userId]
            );
            $result = $statement->fetch();
            if ($result === false) {
                return null;
            }
            return (int) $result['profile_id'];
        } catch (\Throwable $e) {
            // Table does not exist (fresh schema) — fall through to legacy path.
            return null;
        }
    }

    /**
     * Resolve the distinct effective role IDs for a user within a tenant
     * (legacy users-table path).
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
     * Resolve the primary role id for a user (legacy users table).
     *
     * @param int $userId The user ID.
     * @return int|null The role id, or null if the user has no role / does not exist.
     */
    private function getRoleIdForUser(int $userId): ?int
    {
        // @tenant-guard-ignore: lookup by globally-unique user id (SERIAL PK); a PK match returns at most one row regardless of tenant
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
     * Resolve the organizational unit id a user belongs to, tenant scoped
     * (legacy users table).
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
     * Collect the OU ids in scope for a user: their own OU and every ancestor OU,
     * tenant scoped. Returns an empty list when the user is in no OU.
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

        return $this->buildOuChainIds($ouId, $tenantId);
    }

    // =========================================================================
    // Shared OU helpers
    // =========================================================================

    /**
     * Build the full OU-chain ids list starting from $ouId, walking up to the root.
     *
     * Shared by both the membership-aware and legacy paths. Bounded by visited-set
     * cycle detection and a hard {@see self::MAX_HIERARCHY_DEPTH}.
     *
     * @param int $ouId     The starting OU id.
     * @param int $tenantId The tenant ID for scoping.
     * @return array<int, int> Distinct OU ids (own OU + ancestors).
     */
    private function buildOuChainIds(int $ouId, int $tenantId): array
    {
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
        // @tenant-guard-ignore: lookup by globally-unique role id (SERIAL PK)
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
        // @tenant-guard-ignore: role-hierarchy traversal by globally-unique role id (SERIAL PK); the role hierarchy is tenant-independent
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

    // =========================================================================
    // Warning helpers
    // =========================================================================

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
