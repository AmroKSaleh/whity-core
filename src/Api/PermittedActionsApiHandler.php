<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\Middleware\EnforceTenantIsolation;

/**
 * Permitted-Actions API Handler (#868) — `POST /api/v1/me/permitted-actions`.
 *
 * What it answers
 * ---------------
 * "For each of these concrete requests, would you let me make it?" The caller
 * hands over a batch of `{method, path}` pairs — the exact requests it is about
 * to render an affordance for — and gets back an allow/deny per pair.
 *
 * Why it exists
 * -------------
 * The `inbox` block type shows the actions awaiting the current user, and the
 * whole reason that type belongs in core rather than in each product is this
 * endpoint. A plugin that answered the question itself would be re-deriving
 * authorization beside the host's, which is not duplication but a second
 * source of truth for a decision the middleware already owns — and the two
 * drift silently, in whichever direction the plugin's copy was last edited.
 *
 * The identity that must hold
 * ---------------------------
 * `allowed: true` implies {@see \Whity\Http\RbacMiddleware} would admit that
 * exact request — including its tenant guard, not only its RBAC checks (a path
 * on the pre-auth public list never has a tenant resolved for it, so the
 * middleware refuses it with 401 before RBAC is consulted; such a path is
 * reported as NOT permitted). It holds because this is not a re-implementation of the
 * middleware's decision: it is the SAME route lookup ({@see Router::match()}'s
 * predicate, method + compiled pattern, first registration wins) feeding the
 * SAME two {@see RoleChecker} calls in the same order, against the same
 * resolved tenant. Nothing is re-derived, so there is nothing to drift.
 *
 * Deny is reported the way the middleware reports it: `required` carries the
 * permission slug that refused, mirroring the 403 body's `required` key, and is
 * null when a role (or a missing route) refused — the same asymmetry, matched
 * rather than "improved".
 *
 * A path matching NO registered route is denied. That is the honest answer —
 * the request would 404 — and it also turns a typo'd endpoint in a block
 * descriptor into a missing button rather than a button that 404s on click.
 *
 * The per-record narrowing
 * ------------------------
 * A check may carry `resourceType`/`resourceId`/`scopedPermission` — the
 * per-record predicate a plugin's handler applies INSIDE the request, which no
 * route table can express (`inbox.actions[].scopedPermission`). It is evaluated
 * as an ADDITIONAL conjunct: the route gate above is evaluated regardless, so
 * this can only ever REMOVE an action from the permitted set.
 *
 * That direction is the entire safety argument, and it is why the resource
 * arguments are not passed to the route-gate check itself. Resource-scoped
 * grants are additive by construction (SDK 1.17/1.22: the scoped answer is a
 * SUPERSET of the unscoped one), while the middleware asks the tenant-wide
 * question — so scoping the ROUTE gate would answer "allowed" for requests the
 * middleware refuses, and the block would show a button that 403s. Scoping only
 * the extra conjunct cannot: the intersection of the middleware's own answer
 * with anything is still within the middleware's answer.
 *
 * Authorization of this endpoint itself
 * -------------------------------------
 * Registered with NO required role/permission — any authenticated caller may
 * ask which of their own actions are permitted — but the handler fails closed
 * (unresolved tenant or missing authenticated user => 403), mirroring
 * `/api/me/capabilities` and `/api/frontend/features`. Every check is resolved
 * for the CALLER's own profile and the request's resolved tenant; neither is
 * ever read from the body, so this cannot be used to probe another user's
 * authority.
 *
 * It grants nothing. The answers are UI hints; every write is still gated by
 * the route's own RBAC when it is actually made.
 */
final class PermittedActionsApiHandler
{
    /** Maximum checks accepted in one batch. A page of inbox items times its actions. */
    public const MAX_CHECKS = 200;

    /** The HTTP methods a check may name — the write verbs an inbox action can carry. */
    private const ALLOWED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly RoleChecker $roleChecker,
        private readonly Router $router,
    ) {
    }

    /**
     * POST /api/v1/me/permitted-actions
     *
     * Body: `{ checks: [ { ref, method, path, resourceType?, resourceId?, scopedPermission? } ] }`
     * Answer: `{ data: [ { ref, allowed, required } ] }`, one entry per check,
     * in request order.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Captured path parameters (unused).
     */
    public function resolve(Request $request, array $params = []): Response
    {
        try {
            // Fail closed without a resolved tenant: authorization is tenant
            // scoped, so without one there is no answer to give.
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            // Fail closed without an authenticated, well-typed acting profile.
            $actor = $request->user;
            $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
                ? $actor->profile_id
                : null;
            if ($profileId === null) {
                return Response::error('Authentication required', 403);
            }

            $body = JsonBody::parsed($request);
            $checks = $body['checks'] ?? null;
            if (!is_array($checks) || !array_is_list($checks)) {
                return Response::error("A 'checks' list is required", 422);
            }

            if (count($checks) > self::MAX_CHECKS) {
                return Response::error(
                    'At most ' . self::MAX_CHECKS . ' checks may be resolved in one request',
                    422
                );
            }

            $results = [];
            foreach ($checks as $check) {
                $results[] = $this->resolveOne($check, $profileId, $tenantId);
            }

            return Response::json(['data' => $results], 200);
        } catch (\Throwable) {
            // Never leak internal exception details; fail closed.
            return Response::error('Failed to resolve permitted actions', 500);
        }
    }

    /**
     * Resolve a single check.
     *
     * @param mixed $check A `{ref, method, path, ...}` object from the batch.
     * @return array{ref: string|null, allowed: bool, required: string|null}
     */
    private function resolveOne(mixed $check, int $profileId, int $tenantId): array
    {
        $ref = is_array($check) && isset($check['ref']) && is_string($check['ref'])
            ? $check['ref']
            : null;

        $denied = ['ref' => $ref, 'allowed' => false, 'required' => null];

        if (!is_array($check)) {
            return $denied;
        }

        $method = $check['method'] ?? null;
        $path = $check['path'] ?? null;
        if (!is_string($method) || !is_string($path)) {
            return $denied;
        }

        $method = strtoupper($method);
        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return $denied;
        }

        // A path on the pre-auth public list never has a tenant resolved for it,
        // and RbacMiddleware refuses a gated route on one with `401 Unresolved
        // tenant context` BEFORE it consults RBAC at all. Answering the narrower
        // RBAC question here would be right about the wrong layer: the request
        // still fails, and the block would render a button that 401s. Asked
        // through the middleware's own predicate rather than a second copy of
        // the list — a second copy is the drift this endpoint exists to avoid.
        if (EnforceTenantIsolation::pathBypassesTenantResolution($path)) {
            return $denied;
        }

        $route = $this->matchRoute($method, $path);
        if ($route === null) {
            // No such route — the request would 404. Denying is the honest
            // answer and keeps a typo'd endpoint from rendering a dead button.
            return $denied;
        }

        // ---- the route gate: exactly RbacMiddleware::handle(), in its order ----
        // Deliberately UNSCOPED. See the class docblock: resource-scoped grants
        // widen, the middleware does not consult them, so scoping here would
        // answer "allowed" for a request the middleware refuses.
        $requiredRole = $route['requiredRole'];
        if (is_string($requiredRole)
            && !$this->roleChecker->hasRoleForProfile($profileId, $requiredRole, $tenantId)
        ) {
            // A role refusal carries no `required` key, matching the middleware's 403 body.
            return $denied;
        }

        $requiredPermission = $route['requiredPermission'];
        if (is_string($requiredPermission)
            && !$this->roleChecker->hasPermissionForProfile($profileId, $requiredPermission, $tenantId)
        ) {
            return ['ref' => $ref, 'allowed' => false, 'required' => $requiredPermission];
        }

        // ---- the per-record narrowing: a plugin handler's own check ----
        $scoped = $this->resolveScopedPredicate($check, $profileId, $tenantId);
        if ($scoped !== null) {
            return $scoped;
        }

        return ['ref' => $ref, 'allowed' => true, 'required' => null];
    }

    /**
     * Evaluate a check's optional `scopedPermission` at its `resourceType`/
     * `resourceId`, returning a DENIED result when it refuses and null when it
     * is absent, malformed, or satisfied.
     *
     * Malformed is treated as absent rather than as a denial: the shape is
     * already gated by {@see \Whity\Sdk\Frontend\Blocks\BlockValidator} before
     * a descriptor ships, so a malformed one here means a caller other than the
     * block renderer, and refusing to narrow leaves the middleware's own answer
     * standing — which is the answer this endpoint promises.
     *
     * @param array<mixed> $check
     * @return array{ref: string|null, allowed: bool, required: string|null}|null
     */
    private function resolveScopedPredicate(array $check, int $profileId, int $tenantId): ?array
    {
        $scopedPermission = $check['scopedPermission'] ?? null;
        if (!is_string($scopedPermission) || $scopedPermission === '') {
            return null;
        }

        $resourceType = $check['resourceType'] ?? null;
        if (!is_string($resourceType) || $resourceType === '') {
            return null;
        }

        // The item id arrives as whatever the plugin's row carried, so accept the
        // integer or its decimal string form and nothing else. A non-numeric id
        // is not a resource this host can address; leave the answer unnarrowed.
        $resourceId = $check['resourceId'] ?? null;
        if (is_string($resourceId) && preg_match('/^\d+$/', $resourceId) === 1) {
            $resourceId = (int) $resourceId;
        }
        if (!is_int($resourceId)) {
            return null;
        }

        $ref = isset($check['ref']) && is_string($check['ref']) ? $check['ref'] : null;

        if (!$this->roleChecker->hasPermissionForProfile(
            $profileId,
            $scopedPermission,
            $tenantId,
            $resourceType,
            $resourceId
        )) {
            return ['ref' => $ref, 'allowed' => false, 'required' => $scopedPermission];
        }

        return null;
    }

    /**
     * The registered route a `method` + concrete `path` would dispatch to, or
     * null when none matches.
     *
     * Mirrors {@see Router::match()} exactly — same iteration over the routes in
     * registration order, same method equality, same compiled pattern, first hit
     * wins — so this cannot select a different route than dispatch would. It is
     * not called via `match()` only because that takes a Request and returns the
     * bound handler; this needs the RBAC descriptor and nothing else.
     *
     * @return array{requiredRole: ?string, requiredPermission: ?string}|null
     */
    private function matchRoute(string $method, string $path): ?array
    {
        foreach ($this->router->getRoutes() as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path) === 1) {
                return [
                    'requiredRole' => $route['requiredRole'],
                    'requiredPermission' => $route['requiredPermission'] ?? null,
                ];
            }
        }

        return null;
    }
}
