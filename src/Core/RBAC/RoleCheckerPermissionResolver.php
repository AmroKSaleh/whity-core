<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Auth\RoleChecker;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * The host's {@see PermissionResolver} implementation (WC-712).
 *
 * A narrow, READ-ONLY facade over the very {@see RoleChecker} instance the RBAC
 * middleware enforces with, registered in the service container so plugins can
 * ask the same authorization question and get the same answer.
 *
 * Why a facade instead of registering RoleChecker itself
 * -----------------------------------------------------
 * {@see RoleChecker} is not only a resolver: it also owns the process-level
 * effective-permission cache ({@see RoleChecker::clearCache()}) and holds the
 * live {@see \Whity\Database\Database} handle. Handing that whole object to
 * plugin code would expose cache-invalidation control and a database connection
 * behind an interface that is meant to answer questions, not grant capability.
 * This facade exposes three read-only methods and nothing else; the SDK
 * interface it satisfies carries no host types, so an out-of-repo plugin can
 * type-hint it with only `whity/plugin-sdk` installed.
 *
 * Same checker, same answer
 * -------------------------
 * The entry point passes the DELEGATION-AWARE checker — the one wired into
 * {@see \Whity\Http\RbacMiddleware} — so a live, non-revoked delegation unlocks
 * a plugin's in-handler check exactly as it unlocks a route-level gate. Wiring
 * the delegation-UNAWARE bounding checker here instead would silently reinstate
 * the divergence this class exists to remove.
 *
 * Catalogue gating (the invariant that makes the two methods agree)
 * ----------------------------------------------------------------
 * {@see RoleChecker::hasPermissionForProfile()} is gated on the
 * {@see PermissionRegistry}: an unregistered slug is never granted, so a stale
 * `role_permissions` row naming a permission no longer declared by core or by
 * any loaded plugin cannot authorize anything. {@see
 * RoleChecker::getEffectivePermissionsForProfile()} is deliberately NOT gated —
 * it returns the raw resolved set because core's document-designer feature
 * stores arbitrary tenant-defined tags in the same column and needs them back
 * verbatim.
 *
 * Exposing that ungated set through {@see self::effectivePermissions()} would
 * recreate, inside this very contract, the divergence it was written to close:
 * `in_array($p, $r->effectivePermissions(...))` could be true while
 * `$r->hasPermission(..., $p)` is false. So this facade filters the set through
 * the registry, making the documented equivalence hold by construction —
 * verified in PermissionResolverTest.
 */
final class RoleCheckerPermissionResolver implements PermissionResolver
{
    /**
     * @param RoleChecker        $roleChecker The authoritative resolver — MUST be the same
     *                                        (delegation-aware) instance the RBAC middleware uses.
     * @param PermissionRegistry $registry    The permission catalogue, used to gate the effective
     *                                        set exactly as hasPermissionForProfile() gates a check.
     */
    public function __construct(
        private readonly RoleChecker $roleChecker,
        private readonly PermissionRegistry $registry,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function hasPermission(int $profileId, int $tenantId, string $permission): bool
    {
        return $this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId);
    }

    /**
     * @inheritDoc
     */
    public function hasRole(int $profileId, int $tenantId, string $role): bool
    {
        return $this->roleChecker->hasRoleForProfile($profileId, $role, $tenantId);
    }

    /**
     * @inheritDoc
     */
    public function effectivePermissions(int $profileId, int $tenantId): array
    {
        $resolved = $this->roleChecker->getEffectivePermissionsForProfile($profileId, $tenantId);

        return array_values(array_filter(
            $resolved,
            fn (string $permission): bool => $this->registry->exists($permission)
        ));
    }
}
