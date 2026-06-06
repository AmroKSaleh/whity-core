<?php

declare(strict_types=1);

namespace Whity\Auth;

/**
 * Resolves the LIVE delegated permissions a user has within a tenant (WC-34).
 *
 * {@see RoleChecker} optionally depends on this interface so delegated grants
 * enter the SAME effective-permission resolution as direct/role/OU grants —
 * making a non-revoked delegation actually grant access through
 * {@see RoleChecker::hasPermission()}. The interface keeps RoleChecker decoupled
 * from the concrete delegation data layer (and trivially fakeable in tests).
 *
 * Implementations MUST be tenant-scoped and honour the delegation lifecycle
 * (revoked delegations contribute nothing) and OU scoping (an OU-scoped
 * delegation applies only when the user falls within that OU subtree).
 */
interface DelegatedPermissionResolver
{
    /**
     * The distinct live delegated permission strings granted to this user within
     * the tenant — both delegations targeting the user directly and delegations
     * targeting any of the user's effective roles, filtered by OU scope.
     *
     * @param int            $userId            The user whose delegated grants to resolve.
     * @param int            $tenantId          The resolved tenant id.
     * @param array<int,int> $effectiveRoleIds  The user's effective role ids (direct + OU-inherited).
     * @param array<int,int> $inScopeOuIds      OU ids in scope for the user (their OU + ancestors).
     * @return array<int, string> Distinct delegated `resource:action` strings.
     */
    public function delegatedPermissionsForUser(
        int $userId,
        int $tenantId,
        array $effectiveRoleIds,
        array $inScopeOuIds
    ): array;
}
