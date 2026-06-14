<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Me Capabilities API Handler (WC-176, #205).
 *
 * Exposes the caller's effective permission slugs at `GET /api/me/capabilities`
 * so a bespoke admin page can hide write controls the caller lacks — the
 * schema-driven CRUD renderer derives this from OpenAPI per resource (WC-175,
 * #199), but a hand-built screen has no such metadata and needs the caller's
 * resolved permission set directly.
 *
 * PERMISSIONS ONLY: this endpoint returns the effective permission strings and
 * nothing else. Identity/profile and tenant-membership data are deliberately
 * out of scope (parked in a separate epic) so the contract stays minimal.
 *
 * Authorization
 * -------------
 * The route registers with NO required role/permission (any authenticated
 * caller may ask "which permissions do I hold here?"), so this handler fails
 * closed itself, mirroring {@see NavigationApiHandler} and
 * {@see FrontendFeaturesApiHandler}: an unresolved {@see TenantContext} or a
 * missing/invalid authenticated user is refused with 403 before anything is
 * resolved. Unlike `/api/me` (public, answered from JWT claims alone), this
 * endpoint needs a RESOLVED tenant for {@see RoleChecker}, so it is NOT a public
 * route — an unauthenticated request resolves to 401 in the tenant middleware.
 *
 * Source of truth
 * ---------------
 * The permission set is exactly {@see RoleChecker::getEffectivePermissionsForUser()}
 * — the same authoritative, tenant-scoped, delegation-aware set RbacMiddleware
 * enforces on every gated route. The handler does NO direct database access
 * beyond RoleChecker, and never trusts the client: the slugs are UI hints only
 * and grant nothing, with data access still enforced per route.
 */
final class MeCapabilitiesApiHandler
{
    private RoleChecker $roleChecker;

    /**
     * @param RoleChecker $roleChecker Authoritative RBAC resolver for the caller's effective permissions.
     */
    public function __construct(RoleChecker $roleChecker)
    {
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/me/capabilities — the caller's effective permission slugs.
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: { permissions: string[] } }` (200; an empty
     *                  set is valid) or a 403 when fail-closed.
     */
    public function list(Request $request): Response
    {
        try {
            // Fail closed when the tenant context is unresolved: RoleChecker is
            // tenant scoped, so without a tenant there is no answer to give.
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            // Fail closed without an authenticated, well-typed acting user.
            $actor = $request->user;
            $userId = is_object($actor) && isset($actor->user_id) && is_int($actor->user_id)
                ? $actor->user_id
                : null;
            if ($userId === null) {
                return Response::error('Authentication required', 403);
            }

            // The authoritative effective set, sorted for deterministic output.
            $permissions = $this->roleChecker->getEffectivePermissionsForUser($userId, $tenantId);
            sort($permissions);

            return Response::json(['data' => ['permissions' => $permissions]], 200);
        } catch (\Throwable) {
            // Never leak internal exception details to clients.
            return Response::error('Failed to fetch capabilities', 500);
        }
    }
}
