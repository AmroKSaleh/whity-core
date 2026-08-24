<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Auth\RoleChecker;

/**
 * A per-request "does this caller hold permission P?" closure, optionally asked
 * AT one organizational unit.
 *
 * WHY THIS EXISTS
 * ---------------
 * Six handlers in the documents subsystem — templates, blocks, render, issue,
 * collections and routing — each carried a byte-identical private
 * `permissionResolver()`. That was harmless while the answer was one flat set.
 * It stopped being harmless the moment the question grew a second dimension:
 * "held at this unit" had to be added in six places, and a handler that kept the
 * one-argument version would silently DISCARD the unit and answer the
 * tenant-wide question instead — a wrong answer with nothing to distinguish it
 * from a right one, because PHP passes surplus arguments to a userland closure
 * without complaint.
 *
 * So the six copies became one, and the extra dimension is defined once.
 *
 * WHY `getEffectivePermissionsForProfile` AND NOT `hasPermissionForProfile`
 * ------------------------------------------------------------------------
 * The latter is gated on the in-memory {@see PermissionRegistry}, which knows
 * only permissions core or a loaded plugin declared. A document template's
 * `required_permission` is an ARBITRARY tenant-defined tag ("documents:use:contracts"),
 * declared by nobody, so the gated check would refuse every tag and hide every
 * gated row from everyone. The raw resolved set is what this question needs.
 *
 * MEMOIZED PER SCOPE, NOT RESOLVED ONCE
 * -------------------------------------
 * A list request asks about several units, so the answer cannot be a single
 * pre-computed set — but neither may it be a query per ROW, which would make
 * visibility filtering cost a round trip per row for an answer that cannot
 * change inside one request. One resolution per DISTINCT scope, cached for the
 * life of the closure.
 *
 * `0` keys the tenant-wide set. No unit can collide with it:
 * `organizational_units.id` is a SERIAL and starts at 1.
 *
 * ASKED AT A UNIT, THE ANSWER IS ADDITIVE
 * ---------------------------------------
 * {@see RoleChecker} unions roles granted at the resource onto the profile's
 * tenant-wide ones, so a scoped ask can only ever return a SUPERSET of the
 * unscoped one. That is the right semantic for an exception — "let this one
 * secretary see the contract templates in her own department" is one
 * `resource_role_assignments` row — and it is why the WHERE dimension of
 * template visibility is a separate, narrowing predicate rather than a resource
 * scope on this call. {@see \Whity\Core\Ou\OuReachResolver} carries that
 * argument in full.
 */
final class ScopedPermissionSet
{
    /**
     * A resolver over the caller's EFFECTIVE permission set in one tenant.
     *
     * The returned closure takes a permission and, optionally, the id of an
     * organizational unit to ask at instead of tenant-wide.
     *
     * @return callable(string, int|null=): bool
     */
    public static function forProfile(RoleChecker $roleChecker, int $callerId, int $tenantId): callable
    {
        /** @var array<int, array<string, true>> $cache */
        $cache = [];

        return static function (string $permission, ?int $atOuId = null) use (
            &$cache,
            $roleChecker,
            $callerId,
            $tenantId
        ): bool {
            $key = $atOuId ?? 0;
            if (!array_key_exists($key, $cache)) {
                $cache[$key] = array_fill_keys(
                    $roleChecker->getEffectivePermissionsForProfile(
                        $callerId,
                        $tenantId,
                        $atOuId === null ? null : ResourceTypeRegistry::TYPE_OU,
                        $atOuId
                    ),
                    true
                );
            }

            return isset($cache[$key][$permission]);
        };
    }
}
