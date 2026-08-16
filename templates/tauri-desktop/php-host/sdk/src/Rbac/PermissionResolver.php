<?php

declare(strict_types=1);

namespace Whity\Sdk\Rbac;

/**
 * Read-only access to the host's AUTHORITATIVE permission resolution (SDK 1.22).
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
 * Resource-scoped checks (SDK 1.17; roles since 1.22)
 * ---------------------------------------------------
 * `$resourceType` / `$resourceId` are now HONOURED, not ignored. The host
 * resolves grants addressed at a single record through
 * `resource_role_assignments`, so a plugin can ask "may this caller act on THIS
 * document?" and receive an answer narrowed to that record.
 *
 * 1.17 fitted the PERMISSION side only. {@see self::hasRole()} kept asking the
 * tenant-wide question even though the host's role resolution already accepted a
 * resource scope, so a role granted at one record was resolvable and not
 * askable — and an adopter reasonably read that as needing a schema change to
 * `memberships` to staff a single record. It does not; 1.22 gives `hasRole()`
 * the same two optional arguments.
 *
 * Earlier minors deliberately omitted these parameters rather than accept and
 * discard them: a caller passing a resource would have believed it held a
 * record-scoped answer while actually holding the tenant-wide one, which fails
 * OPEN. They are additive — omitting them preserves the previous behaviour
 * exactly.
 *
 * The scoped answer is a SUPERSET of the unscoped one. A resource grant widens
 * authority at that resource; it never narrows it, and it is never a substitute
 * for tenant membership. A profile with no active membership in the tenant
 * resolves to nothing regardless of what is granted at the resource, so a grant
 * cannot become a back door into a tenant.
 *
 * `$resourceType` must be a type the host has registered (core ships `ou`;
 * plugins declare their own). An unregistered type resolves to no grants rather
 * than throwing — an unknown type can never be a reason to widen authority.
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
     * Pass `$resourceType` and `$resourceId` together to narrow the question to
     * one record; pass neither for the tenant-wide answer. Passing only one is
     * treated as passing neither — a half-specified resource is not a resource.
     *
     * @param int         $profileId    The profile whose authority is being tested.
     * @param int         $tenantId     The resolved tenant id (0 = system tenant).
     * @param string      $permission   A `resource:action` slug, e.g. `demo_catalog:manage`.
     * @param string|null $resourceType A registered resource type, e.g. `document`.
     * @param int|null    $resourceId   The id of that resource.
     * @return bool True when the profile effectively holds the permission.
     */
    public function hasPermission(
        int $profileId,
        int $tenantId,
        string $permission,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): bool;

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
     * Pass `$resourceType` and `$resourceId` together to narrow the question to
     * one record; pass neither for the tenant-wide answer. Passing only one is
     * treated as passing neither — a half-specified resource is not a resource.
     * A role granted at a single record is then askable, not merely resolvable:
     * "this profile holds role X at record A and role Y at record B" needs no
     * schema change to `memberships`, only these arguments.
     *
     * The parity identity holds for roles as it does for permissions, at BOTH
     * scopes — the host's effective-role set and this method must never disagree:
     *
     *     in_array($r, $host->getEffectiveRolesForProfile($id, $t, $ty, $rid), true)
     *         === $resolver->hasRole($id, $t, $r, $ty, $rid)
     *
     * The SDK publishes no `effectiveRoles()` counterpart to
     * {@see self::effectivePermissions()}: the effective ROLE set is a
     * deployment's private vocabulary, and a plugin that filters on it couples
     * itself to that vocabulary. The identity above is therefore stated against
     * the host resolver a plugin does not hold, and is pinned by the host suite.
     *
     * @param int         $profileId    The profile whose authority is being tested.
     * @param int         $tenantId     The resolved tenant id (0 = system tenant).
     * @param string      $role         The role NAME to test for.
     * @param string|null $resourceType A registered resource type, e.g. `document`.
     * @param int|null    $resourceId   The id of that resource.
     * @return bool True when the profile effectively holds the role.
     */
    public function hasRole(
        int $profileId,
        int $tenantId,
        string $role,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): bool;

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
     * The parity identity holds at BOTH scopes — the resource arguments must be
     * passed to both calls or the two disagree:
     *
     *     in_array($p, $r->effectivePermissions($id, $t, $ty, $rid), true)
     *         === $r->hasPermission($id, $t, $p, $ty, $rid)
     *
     * @param int         $profileId    The profile whose authority is being resolved.
     * @param int         $tenantId     The resolved tenant id (0 = system tenant).
     * @param string|null $resourceType A registered resource type, e.g. `document`.
     * @param int|null    $resourceId   The id of that resource.
     * @return list<string> Distinct `resource:action` slugs; empty when the
     *                      profile has no active membership in the tenant.
     */
    public function effectivePermissions(
        int $profileId,
        int $tenantId,
        ?string $resourceType = null,
        ?int $resourceId = null
    ): array;
}
