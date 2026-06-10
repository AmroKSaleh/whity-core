<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * RBAC Middleware for enforcing role-based access control
 *
 * Validates JWT tokens from the Authorization header (or `access_token` cookie)
 * and enforces the role and/or permission required by the matched route before
 * the downstream handler runs. In the kernel pipeline the claims decoded once by
 * EnforceTenantIsolation are reused via {@see Request::ATTR_JWT_CLAIMS} (WC-159);
 * the token is parsed here only in standalone use. On success the decoded JWT
 * payload is attached to the request as {@see Request::$user} for use by handlers.
 *
 * Authorization decisions are always made against the authoritative server-side
 * store via {@see RoleChecker}; role/permission claims that may be present in the
 * JWT payload are NEVER trusted for access-control decisions (see issue #54). A
 * route with neither a required role nor a required permission is treated as
 * unprotected and any authenticated request passes through (fail-open).
 */
class RbacMiddleware
{
    private JwtParser $jwtParser;
    private RoleChecker $roleChecker;

    /**
     * Constructor
     *
     * @param JwtParser $jwtParser JWT token parser
     * @param RoleChecker $roleChecker Role and permission verification service
     */
    public function __construct(JwtParser $jwtParser, RoleChecker $roleChecker)
    {
        $this->jwtParser = $jwtParser;
        $this->roleChecker = $roleChecker;
    }

    /**
     * Handle request with RBAC validation
     *
     * Extracts and validates the JWT from the Authorization header (or
     * `access_token` cookie), enforces the required role and/or permission
     * against the authoritative store, and attaches the decoded payload to the
     * request before delegating to the next handler.
     *
     * When a required permission is supplied and the check fails, the 403
     * response body includes the offending permission under the `required` key
     * so clients can surface which capability they are missing.
     *
     * @param Request $request The incoming HTTP request
     * @param callable $next The next middleware/handler in the pipeline
     * @param ?string $requiredRole Optional role name to enforce authorization
     * @param ?string $requiredPermission Optional permission (resource:action) to enforce authorization
     * @return Response HTTP response
     */
    public function handle(
        Request $request,
        callable $next,
        ?string $requiredRole = null,
        ?string $requiredPermission = null
    ): Response {
        // Fail-open: routes with no role/permission requirement are unprotected.
        if ($requiredRole === null && $requiredPermission === null) {
            return $next($request);
        }

        // Extract the bearer token from the Authorization header or cookie.
        $token = $this->extractTokenFromRequest($request);
        if ($token === null) {
            return Response::error('Missing or invalid Authorization header', 401);
        }

        // Single decode (WC-159): reuse the claims stashed by the upstream
        // EnforceTenantIsolation middleware when present (a stashed null means
        // the token was already checked and rejected). Parse only when the
        // attribute is absent, i.e. standalone use outside the kernel pipeline.
        if ($request->hasAttribute(Request::ATTR_JWT_CLAIMS)) {
            // Fail closed on any non-array stash (defense-in-depth against a
            // buggy writer): treat it exactly like an invalid token.
            $stashed = $request->getAttribute(Request::ATTR_JWT_CLAIMS);
            /** @var array<string, mixed>|null $payload */
            $payload = is_array($stashed) ? $stashed : null;
        } else {
            // Parse and validate the JWT (signature + expiry handled by the parser).
            $payload = $this->jwtParser->parse($token);
        }
        if ($payload === null) {
            return Response::error('Invalid or expired token', 401);
        }

        // The user identity must be present and well-typed.
        $userId = $payload['user_id'] ?? null;
        if (!is_int($userId)) {
            return Response::error('Invalid token payload', 401);
        }

        // Authorization is tenant scoped (WC-54): effective roles/permissions
        // include grants reached through the user's organizational unit, which are
        // tenant-bound, so the resolved tenant id is required to evaluate them.
        // EnforceTenantIsolation runs before RBAC and locks the context; an absent
        // tenant means the request was never tenant-resolved, so fail closed.
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Unresolved tenant context', 401);
        }

        // Enforce the required role against the authoritative store.
        if ($requiredRole !== null && !$this->roleChecker->hasRole($userId, $requiredRole, $tenantId)) {
            return $this->forbidden();
        }

        // Enforce the required permission against the authoritative store. The
        // permission name is echoed back so clients know what they are missing.
        if ($requiredPermission !== null && !$this->roleChecker->hasPermission($userId, $requiredPermission, $tenantId)) {
            return $this->forbidden($requiredPermission);
        }

        // Attach the validated payload for downstream handlers.
        $request->user = (object) $payload;

        return $next($request);
    }

    /**
     * Build a structured 403 Forbidden response.
     *
     * When a permission caused the denial it is included under the `required`
     * key so the caller can identify the missing capability. Internal details
     * (user identity, role, query results) are never leaked.
     *
     * @param string|null $requiredPermission The permission that was missing, if any.
     * @return Response The 403 JSON response.
     */
    private function forbidden(?string $requiredPermission = null): Response
    {
        $body = ['error' => 'Insufficient permissions'];
        if ($requiredPermission !== null) {
            $body['required'] = $requiredPermission;
        }

        return Response::json($body, 403);
    }

    /**
     * Extract the bearer token from the request.
     *
     * Prefers the `Authorization: Bearer <token>` header and falls back to the
     * `access_token` cookie when the header is absent or malformed. Uses the
     * same capture as EnforceTenantIsolation/TenantContext so the stasher and
     * every consumer derive the identical token from the identical header
     * (WC-159 review: substr(7) diverged on extra whitespace).
     *
     * @param Request $request The incoming HTTP request.
     * @return string|null The token string, or null when none is present.
     */
    private function extractTokenFromRequest(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader === null) {
            return null;
        }

        // Parse a cookie header of the form "name1=value1; name2=value2".
        foreach (explode(';', $cookieHeader) as $cookie) {
            $parts = explode('=', trim($cookie), 2);
            if (count($parts) === 2 && $parts[0] === 'access_token') {
                return $parts[1];
            }
        }

        return null;
    }
}
