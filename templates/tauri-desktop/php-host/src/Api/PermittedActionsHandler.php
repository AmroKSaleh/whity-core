<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Router;
use Whity\PluginHost\OfflineIdentity;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * Serves `POST /__whity/permitted-actions` — the offline host's counterpart to
 * production's `POST /api/v1/me/permitted-actions`
 * ({@see \Whity\Api\PermittedActionsApiHandler}), and the server half of the
 * `inbox` block type (#868).
 *
 * What it answers: "for each of these concrete requests, would you let me make
 * it?" The desktop block renderer hands over the `{method, path}` pairs it is
 * about to render buttons for, and gets back an allow/deny per pair.
 *
 * The identity that must hold, offline as on the server: `allowed: true` implies
 * {@see \Whity\Http\RbacGate} would admit that exact request. It holds for the
 * same reason it does there — this is not a re-implementation of the gate's
 * decision but the SAME route lookup ({@see Router::match()}'s predicate)
 * feeding the SAME {@see PermissionResolver} calls in the same order (role
 * first, then permission), for the one fixed {@see OfflineIdentity}.
 *
 * A path matching no registered route is denied: the request would 404, and a
 * typo'd endpoint in a block descriptor becomes a missing button rather than a
 * button that fails on click.
 *
 * `scopedPermission` is evaluated as an ADDITIONAL conjunct, never a
 * replacement for the route gate, so it can only ever REMOVE an action from the
 * permitted set. That direction matters more offline than on the server:
 * {@see \Whity\Core\RBAC\DeviceRoleChecker} answers the tenant-wide question for
 * a resource-scoped call (documented — this device has no organizational
 * structure to walk), so a scoped check here is no narrower than the
 * tenant-wide one. Being unable to narrow is harmless; being able to widen past
 * the gate would not be.
 *
 * Infrastructure endpoint like `/__whity/frontend-features`, not a plugin route,
 * so it bypasses `$router`/`$rbacGate` dispatch — but it never bypasses the
 * ANSWER those produce, which is the whole point of it.
 */
final class PermittedActionsHandler
{
    /** Maximum checks accepted in one batch. Matches the server handler. */
    public const MAX_CHECKS = 200;

    /** The HTTP methods a check may name — the write verbs an inbox action can carry. */
    private const ALLOWED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly Router $router,
        private readonly PermissionResolver $resolver,
        private readonly OfflineIdentity $identity,
    ) {
    }

    /**
     * Resolve a decoded request body.
     *
     * @param mixed $body The decoded JSON body: `{checks: [...]}`.
     * @return array{ok: true, data: list<array{ref: string|null, allowed: bool, required: string|null}>}|array{ok: false, error: string}
     */
    public function resolve(mixed $body): array
    {
        $checks = is_array($body) ? ($body['checks'] ?? null) : null;
        if (!is_array($checks) || !array_is_list($checks)) {
            return ['ok' => false, 'error' => "A 'checks' list is required"];
        }

        if (count($checks) > self::MAX_CHECKS) {
            return ['ok' => false, 'error' => 'At most ' . self::MAX_CHECKS . ' checks may be resolved in one request'];
        }

        $results = [];
        foreach ($checks as $check) {
            $results[] = $this->resolveOne($check);
        }

        return ['ok' => true, 'data' => $results];
    }

    /**
     * @param mixed $check
     * @return array{ref: string|null, allowed: bool, required: string|null}
     */
    private function resolveOne(mixed $check): array
    {
        $ref = is_array($check) && isset($check['ref']) && is_string($check['ref']) ? $check['ref'] : null;
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

        $route = $this->matchRoute($method, $path);
        if ($route === null) {
            return $denied;
        }

        $profileId = $this->identity->profileId;
        $tenantId = $this->identity->tenantId;

        // ---- the route gate: exactly RbacGate::authorize(), in its order ----
        $requiredRole = $route['requiredRole'];
        if (is_string($requiredRole) && !$this->resolver->hasRole($profileId, $tenantId, $requiredRole)) {
            // A role refusal carries no `required` key, matching the gate's 403 body.
            return $denied;
        }

        $requiredPermission = $route['requiredPermission'];
        if (is_string($requiredPermission)
            && !$this->resolver->hasPermission($profileId, $tenantId, $requiredPermission)
        ) {
            return ['ref' => $ref, 'allowed' => false, 'required' => $requiredPermission];
        }

        // ---- the per-record narrowing: a plugin handler's own check ----
        $scopedPermission = $check['scopedPermission'] ?? null;
        $resourceType = $check['resourceType'] ?? null;
        $resourceId = $check['resourceId'] ?? null;
        if (is_string($resourceId) && preg_match('/^\d+$/', $resourceId) === 1) {
            $resourceId = (int) $resourceId;
        }

        if (is_string($scopedPermission) && $scopedPermission !== ''
            && is_string($resourceType) && $resourceType !== ''
            && is_int($resourceId)
            && !$this->resolver->hasPermission($profileId, $tenantId, $scopedPermission, $resourceType, $resourceId)
        ) {
            return ['ref' => $ref, 'allowed' => false, 'required' => $scopedPermission];
        }

        return ['ref' => $ref, 'allowed' => true, 'required' => null];
    }

    /**
     * The registered route a `method` + concrete `path` would dispatch to.
     *
     * Mirrors {@see Router::match()} exactly — same iteration order, same method
     * equality, same compiled pattern, first hit wins — so this cannot select a
     * different route than dispatch would.
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
