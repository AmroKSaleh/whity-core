<?php

declare(strict_types=1);

namespace Whity\Core\Delegation;

use Whity\Api\Exception\PermissionNotDelegableException;
use Whity\Auth\DelegatedPermissionResolver;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * Delegation domain service (WC-34).
 *
 * Owns the two halves of permission delegation:
 *
 *  1. CREATE-time enforcement of the HARD subset invariant — a grantor may
 *     delegate only a SUBSET of their OWN effective permissions. The grantor's
 *     effective set is computed via {@see RoleChecker} (direct role + role
 *     hierarchy + OU inheritance), and any requested permission outside it is
 *     rejected with {@see PermissionNotDelegableException}. Enforced server-side,
 *     always — the API permission gate (`delegation:manage`) controls WHO may
 *     manage delegations, never WHAT they may delegate.
 *
 *  2. RESOLUTION — as a {@see DelegatedPermissionResolver}, it feeds the LIVE
 *     delegated permissions a user holds back into {@see RoleChecker} so a
 *     non-revoked delegation actually grants access, tenant- and OU-scoped.
 *
 * The service issues no SQL itself; all persistence goes through
 * {@see DelegationRepository}.
 */
class DelegationService implements DelegatedPermissionResolver
{
    private DelegationRepository $repository;
    private RoleChecker $roleChecker;
    private PermissionRegistry $registry;

    /**
     * @param DelegationRepository $repository  Delegation data layer.
     * @param RoleChecker          $roleChecker Authoritative effective-permission resolver (grantor bound).
     * @param PermissionRegistry   $registry    Permission catalogue (existence validation).
     */
    public function __construct(
        DelegationRepository $repository,
        RoleChecker $roleChecker,
        PermissionRegistry $registry
    ) {
        $this->repository = $repository;
        $this->roleChecker = $roleChecker;
        $this->registry = $registry;
    }

    /**
     * Create a delegation granting a subset of the grantor's effective
     * permissions to a role or a profile, tenant- and optionally OU-scoped.
     *
     * Enforces the subset invariant: every requested permission MUST be in the
     * grantor's current effective permission set, otherwise the whole request is
     * rejected ({@see PermissionNotDelegableException}) and nothing is written.
     * Unknown permissions (not in the registry) are likewise treated as
     * non-delegable, since an unregistered permission is never effectively held.
     * One row is written per granted permission so each is individually revocable.
     *
     * WC-bc07b6de: `$grantorProfileId` is a profile id (not a legacy user id).
     * The effective-permission resolution uses the membership-aware path via
     * {@see RoleChecker::getEffectivePermissionsForProfile()}.
     *
     * @param int                $tenantId         The owning tenant (the grantor's resolved tenant).
     * @param int                $grantorProfileId The profile creating the delegation.
     * @param string             $granteeType      {@see DelegationRepository::GRANTEE_ROLE}/{@see DelegationRepository::GRANTEE_PROFILE}.
     * @param int                $granteeId        The target role id or profile id.
     * @param array<int, string> $permissions      The `resource:action` strings to delegate.
     * @param int|null           $ouId             Optional OU-subtree scope (null = tenant-wide).
     * @return array<int, int> The ids of the created delegation rows.
     *
     * @throws PermissionNotDelegableException When any requested permission is outside the grantor's effective set.
     */
    public function delegate(
        int $tenantId,
        int $grantorProfileId,
        string $granteeType,
        int $granteeId,
        array $permissions,
        ?int $ouId
    ): array {
        // De-duplicate and drop empties while preserving order.
        $requested = [];
        foreach ($permissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission !== '') {
                $requested[$permission] = true;
            }
        }
        $requested = array_keys($requested);

        // Enforce the HARD subset invariant against the grantor's CURRENT
        // effective permissions. The dual-window path is used here so both the new
        // membership-aware (profile_id) callers AND legacy (user_id) callers during
        // Phase B work correctly. getEffectivePermissionsForUser() tries the profile
        // mapping first (migration_035_profile_ids) and falls back to the users table
        // if no mapping exists. This is the single authoritative gate.
        $grantorEffective = $this->roleChecker->getEffectivePermissionsForUser($grantorProfileId, $tenantId);
        $effectiveLookup = array_fill_keys($grantorEffective, true);

        $denied = [];
        foreach ($requested as $permission) {
            // A permission the grantor does not hold — OR one that is not even a
            // registered permission — cannot be delegated.
            if (!isset($effectiveLookup[$permission]) || !$this->registry->exists($permission)) {
                $denied[] = $permission;
            }
        }

        if ($denied !== []) {
            throw PermissionNotDelegableException::forPermissions($denied);
        }

        $ids = [];
        foreach ($requested as $permission) {
            $ids[] = $this->repository->insert(
                $tenantId,
                $grantorProfileId,
                $granteeType,
                $granteeId,
                $permission,
                $ouId
            );
        }

        return $ids;
    }

    /**
     * List delegations visible to the tenant with optional filters.
     *
     * @param int         $tenantId       The acting tenant (0 = system sees all).
     * @param string|null $granteeType    Filter by grantee type, or null.
     * @param int|null    $granteeId      Filter by grantee id, or null.
     * @param int|null    $grantorUserId  Filter by grantor, or null.
     * @param bool        $includeRevoked Whether to include revoked rows.
     * @return array<int, array<string, mixed>> Normalised delegation rows.
     */
    /**
     * Count delegations matching the given filters.
     *
     * @param int         $tenantId
     * @param string|null $granteeType
     * @param int|null    $granteeId
     * @param int|null    $grantorUserId
     * @param bool        $includeRevoked
     * @return int Total matching rows.
     */
    public function count(
        int $tenantId,
        ?string $granteeType = null,
        ?int $granteeId = null,
        ?int $grantorUserId = null,
        bool $includeRevoked = false
    ): int {
        return $this->repository->count(
            $tenantId,
            $granteeType,
            $granteeId,
            $grantorUserId,
            $includeRevoked
        );
    }

    /**
     * @param int         $tenantId
     * @param string|null $granteeType
     * @param int|null    $granteeId
     * @param int|null    $grantorUserId
     * @param bool        $includeRevoked
     * @param int|null    $limit
     * @param int         $offset
     * @return array<int, array<string, mixed>> Normalised delegation rows.
     */
    public function list(
        int $tenantId,
        ?string $granteeType = null,
        ?int $granteeId = null,
        ?int $grantorUserId = null,
        bool $includeRevoked = false,
        ?int $limit = null,
        int $offset = 0
    ): array {
        return $this->repository->list(
            $tenantId,
            $granteeType,
            $granteeId,
            $grantorUserId,
            $includeRevoked,
            $limit,
            $offset
        );
    }

    /**
     * Fetch a single delegation by id, tenant scoped.
     *
     * @param int $id       The delegation id.
     * @param int $tenantId The acting tenant (0 = system).
     * @return array<string, mixed>|null The row, or null when not visible/absent.
     */
    public function find(int $id, int $tenantId): ?array
    {
        return $this->repository->findById($id, $tenantId);
    }

    /**
     * Revoke a delegation (non-destructive). Tenant scoped.
     *
     * @param int $id       The delegation id.
     * @param int $tenantId The acting tenant (0 = system may revoke any).
     * @return bool True when a live delegation was revoked; false when none matched.
     */
    public function revoke(int $id, int $tenantId): bool
    {
        return $this->repository->revoke($id, $tenantId) > 0;
    }

    /**
     * {@inheritDoc}
     *
     * Unions the live delegated permissions targeting the user DIRECTLY with
     * those targeting any of the user's effective roles, filtered to the user's
     * OU scope. Tenant scoped throughout.
     */
    public function delegatedPermissionsForUser(
        int $userId,
        int $tenantId,
        array $effectiveRoleIds,
        array $inScopeOuIds
    ): array {
        $permissions = [];

        // Delegations made to the profile directly (grantee_type = 'profile').
        // The $userId argument is now a profile_id (WC-bc07b6de).
        foreach (
            $this->repository->livePermissionsForGrantee(
                $tenantId,
                DelegationRepository::GRANTEE_PROFILE,
                $userId,
                $inScopeOuIds
            ) as $permission
        ) {
            $permissions[$permission] = true;
        }

        // Delegations made to any of the user's effective roles.
        foreach ($effectiveRoleIds as $roleId) {
            foreach (
                $this->repository->livePermissionsForGrantee(
                    $tenantId,
                    DelegationRepository::GRANTEE_ROLE,
                    $roleId,
                    $inScopeOuIds
                ) as $permission
            ) {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }
}
