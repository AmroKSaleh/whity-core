<?php

declare(strict_types=1);

namespace Whity\Sdk\Rbac;

/**
 * Read-only access to the host's AUTHORITATIVE permission resolution (SDK 1.16).
 *
 * Why this exists
 * ---------------
 * The host enforces a route's declared `requiredPermission` in its RBAC
 * middleware, but that gate is FLAT: it answers one question, once, before the
 * handler runs. A plugin that needs a second decision *inside* a handler — "may
 * this caller see the archived rows?", "may they act on THIS record?" — has, up
 * to now, had no way to ask the host. Plugins receive a raw `\PDO`, so the only
 * option was to re-derive the answer in hand-written SQL.
 *
 * A hand-written re-derivation is a security defect, not merely duplication.
 * Real resolution is not one join: it gates on ACTIVE membership, walks the
 * organizational-unit ancestor chain, walks the role-inheritance hierarchy with
 * cycle and depth guards, unions live (non-revoked, OU-scoped) delegations, and
 * validates the slug against the host's permission catalogue. Any partial
 * re-implementation drifts from what the middleware enforces, and the system
 * then holds TWO different answers to the SAME authorization question. This
 * contract exists so a plugin resolves through the one path the host itself
 * uses.
 *
 * Obtaining an implementation
 * ---------------------------
 * The host registers its implementation in the service container under this
 * interface name; a plugin resolves it at request time:
 *
 *     $rbac = \Whity\app(\Whity\Sdk\Rbac\PermissionResolver::class);
 *     if (!$rbac->hasPermission($profileId, $tenantId, 'demo_catalog:manage')) {
 *         return Response::error('Insufficient permissions', 403);
 *     }
 *
 * A host that has not wired one fails CLOSED: the lookup throws rather than
 * returning a permissive stub.
 *
 * Authority
 * ---------
 * READ ONLY. Every method answers a question; none grants, revokes, or caches
 * anything, and the contract deliberately exposes no cache-invalidation or
 * database surface. Holding this object confers no authority a plugin does not
 * already have — it only lets the plugin ask the question CORRECTLY.
 *
 * Tenant scoping
 * --------------
 * Authorization is always tenant scoped. `$tenantId` is the resolved tenant of
 * the caller being asked about (0 = the system tenant); a grant reached through
 * a tenant-scoped organizational unit or delegation can never leak across a
 * tenant boundary. Plugins should pass the tenant resolved for the current
 * request rather than one taken from client input.
 *
 * Resource-scoped checks
 * ----------------------
 * This contract intentionally has NO `$resourceType` / `$resourceId`
 * parameters. Role grants are currently addressable only to a tenant or an
 * organizational unit, so a resource argument would be accepted and then
 * silently IGNORED — a caller passing one would believe it had received a
 * narrow, record-scoped answer while actually receiving the tenant-wide one.
 * That fails OPEN, which is the exact failure mode this contract exists to
 * prevent. Resource-scoped overloads are additive and will be introduced in a
 * later minor once the host can actually honour them.
 */
interface PermissionResolver
{
    /**
     * Whether a profile effectively holds a permission within a tenant.
     *
     * True only when the slug exists in the host's permission catalogue AND is
     * in the profile's effective set for that tenant. An unknown or unregistered
     * slug is never granted, and a `:read` permission never satisfies a `:write`
     * check — there is no prefix or wildcard matching.
     *
     * @param int    $profileId  The profile whose authority is being tested.
     * @param int    $tenantId   The resolved tenant id (0 = system tenant).
     * @param string $permission A `resource:action` slug, e.g. `demo_catalog:manage`.
     * @return bool True when the profile effectively holds the permission.
     */
    public function hasPermission(int $profileId, int $tenantId, string $permission): bool;

    /**
     * Whether a profile effectively holds a role within a tenant.
     *
     * The effective role set is the union of the profile's membership role and
     * every role assigned to its organizational unit and that unit's ancestors,
     * all scoped to the tenant.
     *
     * Prefer {@see self::hasPermission()}: a permission expresses the capability
     * actually required, whereas a role name couples the plugin to one
     * deployment's role vocabulary.
     *
     * @param int    $profileId The profile whose authority is being tested.
     * @param int    $tenantId  The resolved tenant id (0 = system tenant).
     * @param string $role      The role NAME to test for.
     * @return bool True when the profile effectively holds the role.
     */
    public function hasRole(int $profileId, int $tenantId, string $role): bool;

    /**
     * The profile's full effective permission set within a tenant.
     *
     * Useful for filtering a result set in one pass instead of issuing a check
     * per row. The returned list is exactly the set {@see self::hasPermission()}
     * answers true for, so the two can never disagree:
     *
     *     in_array($p, $r->effectivePermissions($id, $t), true)
     *         === $r->hasPermission($id, $t, $p)
     *
     * Order is unspecified. The set is a UI/filtering hint only; it grants
     * nothing on its own, and every write must still be gated by an explicit
     * check.
     *
     * @param int $profileId The profile whose authority is being resolved.
     * @param int $tenantId  The resolved tenant id (0 = system tenant).
     * @return list<string> Distinct `resource:action` slugs; empty when the
     *                      profile has no active membership in the tenant.
     */
    public function effectivePermissions(int $profileId, int $tenantId): array;
}
