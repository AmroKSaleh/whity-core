<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\TenantResolutionException;

/**
 * HTTP-layer tenant isolation enforcement middleware.
 *
 * Runs early in the request pipeline (before routing, RBAC and any database
 * access) and performs two jobs:
 *
 * 1. Resolution — delegates token -> tenant extraction to
 *    {@see TenantContext::resolve()}, which validates the JWT and locks the
 *    resolved tenant id into the request-scoped context. There is no duplicated
 *    extraction logic here; the context is the single source of truth.
 * 2. Enforcement — when the request addresses a resource that declares a tenant
 *    (a `/api/tenants/{id}` path segment, a `tenant_id` query parameter, or an
 *    `X-Tenant-Id` header), the caller's tenant is compared against it. A
 *    mismatch is refused with HTTP 403 *before* the handler runs, so a
 *    cross-tenant request never reaches the database.
 *
 * Bypass: callers with cross-tenant authority are allowed through. This is the
 * system tenant convention (tenant id 0) plus the explicit, non-request
 * {@see TenantContext::isSystemMode()} bypass used by trusted tooling. Every
 * bypass is recorded as a structured audit log entry that includes the caller's
 * tenant id, the target resource tenant id and the request path. This middleware
 * deliberately reuses the existing tenant-id-0 / system-mode mechanism rather
 * than introducing a parallel super-admin flag.
 *
 * Client responses never leak internal details: all resolution failures collapse
 * to a generic 401 and isolation violations to a generic 403.
 *
 * The per-request {@see TenantContext::reset()} lifecycle is owned by the HTTP
 * kernel (see HttpKernel::resetRequestState()); this middleware only resolves.
 */
class EnforceTenantIsolation
{
    /**
     * The reserved identifier for the system tenant.
     *
     * A caller resolved to this tenant id holds cross-tenant authority, matching
     * the platform-wide convention established in TenantsApiHandler.
     */
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Routes that are reachable without authentication / tenant context.
     *
     * @var list<string>
     */
    private const PUBLIC_ROUTES = [
        '/api/login',
        '/api/login/2fa',
        '/api/me',
        '/api/auth/refresh',
        '/api/auth/logout',
        '/api/navigation',
    ];

    private JwtParser $jwtParser;

    /**
     * Audit sink for privileged cross-tenant bypasses.
     *
     * Defaults to a {@see NullLogger} so the middleware is silent unless a real
     * PSR-3 logger is wired in (keeps test output clean; production injects the
     * application logger).
     */
    private LoggerInterface $logger;

    /**
     * @param JwtParser            $jwtParser JWT validator used by TenantContext::resolve().
     * @param LoggerInterface|null $logger    Optional PSR-3 audit logger. When null,
     *                                         a {@see NullLogger} is used.
     */
    public function __construct(JwtParser $jwtParser, ?LoggerInterface $logger = null)
    {
        $this->jwtParser = $jwtParser;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Resolve the tenant and enforce HTTP-layer tenant isolation.
     *
     * @param Request  $request The incoming HTTP request.
     * @param callable $next     The next middleware/handler in the pipeline.
     * @return Response HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        $path = $this->pathWithoutQuery($request->getPath());

        // Public endpoints carry no tenant context; let them through untouched.
        if ($this->isPublicRoute($path)) {
            return $next($request);
        }

        // Delegate token -> tenant extraction and validation to the context. Any
        // failure (missing/invalid token, missing/invalid tenant claim) collapses
        // to a generic 401 so internals are never leaked to the client.
        try {
            $tenantId = TenantContext::resolve($request, $this->jwtParser);
        } catch (TenantResolutionException) {
            return Response::error('Authentication required', 401);
        }

        // Re-parse the (now validated) token only to expose the decoded payload to
        // downstream handlers via Request::$user, mirroring the prior contract.
        $payload = $this->decodePayload($request);
        if ($payload !== null) {
            $request->user = (object) $payload;
        }

        // Determine the tenant the request is addressing, if any is declared.
        $resourceTenantId = $this->resolveResourceTenantId($request, $path);

        // No tenant-scoped target: defer finer-grained checks to the route
        // handler and the query-level ScopesToTenant layer.
        if ($resourceTenantId === null) {
            return $next($request);
        }

        // Same-tenant access is always permitted.
        if ($resourceTenantId === $tenantId) {
            return $next($request);
        }

        // Cross-tenant access: only the system tenant (id 0) or an explicit
        // system-mode bypass may proceed, and the privileged access is audited.
        if ($this->hasCrossTenantAuthority($tenantId)) {
            $this->auditCrossTenantBypass($tenantId, $resourceTenantId, $payload, $path);
            return $next($request);
        }

        // Otherwise refuse before any handler/database work runs.
        return Response::error('Access to the requested tenant is forbidden', 403);
    }

    /**
     * Whether the resolved caller holds cross-tenant authority.
     *
     * Reuses the established mechanisms: the system tenant id (0) and the
     * explicit, non-request {@see TenantContext::isSystemMode()} bypass.
     *
     * @param int $tenantId The caller's resolved tenant id.
     * @return bool True when the caller may cross tenant boundaries.
     */
    private function hasCrossTenantAuthority(int $tenantId): bool
    {
        return $tenantId === self::SYSTEM_TENANT_ID || TenantContext::isSystemMode();
    }

    /**
     * Emit a structured audit record for a permitted cross-tenant access.
     *
     * @param int                       $tenantId         The caller's tenant id.
     * @param int                       $resourceTenantId The target resource tenant id.
     * @param array<string, mixed>|null $payload          The decoded JWT payload, if available.
     * @param string                    $path             The request path (without query string).
     * @return void
     */
    private function auditCrossTenantBypass(
        int $tenantId,
        int $resourceTenantId,
        ?array $payload,
        string $path
    ): void {
        $userId = null;
        if ($payload !== null && isset($payload['user_id']) && is_int($payload['user_id'])) {
            $userId = $payload['user_id'];
        }

        $this->logger->warning('Tenant isolation: cross-tenant access permitted via privileged bypass', [
            'event' => 'tenant_isolation.cross_tenant_bypass',
            'tenant_id' => $tenantId,
            'resource_tenant_id' => $resourceTenantId,
            'user_id' => $userId,
            'system_mode' => TenantContext::isSystemMode(),
            'path' => $path,
        ]);
    }

    /**
     * Determine the tenant id the request is addressing, if declared.
     *
     * Recognised signals, in priority order:
     *  - a trailing/segment tenant id under the `/api/tenants/{id}` resource;
     *  - a `tenant_id` query-string parameter;
     *  - an `X-Tenant-Id` request header.
     *
     * @param Request $request The incoming HTTP request.
     * @param string  $path    The request path with the query string stripped.
     * @return int|null The declared resource tenant id, or null if none.
     */
    private function resolveResourceTenantId(Request $request, string $path): ?int
    {
        // Path-encoded tenant resource: /api/tenants/{id}
        if (preg_match('#^/api/tenants/(\d+)(?:/.*)?$#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        // tenant_id query parameter (parsed from the raw path when present).
        $queryTenant = $this->tenantIdFromQuery($request->getPath());
        if ($queryTenant !== null) {
            return $queryTenant;
        }

        // Explicit X-Tenant-Id header.
        $header = $request->getHeader('X-Tenant-Id');
        if ($header !== null && ctype_digit($header)) {
            return (int) $header;
        }

        return null;
    }

    /**
     * Extract a numeric `tenant_id` value from the query component of a raw path.
     *
     * @param string $rawPath The request path, possibly including a query string.
     * @return int|null The parsed tenant id, or null when absent/non-numeric.
     */
    private function tenantIdFromQuery(string $rawPath): ?int
    {
        $query = parse_url($rawPath, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $value = $params['tenant_id'] ?? null;

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Strip the query string from a request path.
     *
     * @param string $rawPath The raw request path.
     * @return string The path component only.
     */
    private function pathWithoutQuery(string $rawPath): string
    {
        $path = parse_url($rawPath, PHP_URL_PATH);

        return is_string($path) ? $path : $rawPath;
    }

    /**
     * Decode the request's JWT payload for downstream consumption.
     *
     * The token has already been validated by {@see TenantContext::resolve()};
     * this re-parse only exposes the claims as {@see Request::$user}. Returns null
     * when no token is recoverable (should not happen post-resolution).
     *
     * @param Request $request The incoming HTTP request.
     * @return array<string, mixed>|null The decoded payload, or null.
     */
    private function decodePayload(Request $request): ?array
    {
        $token = $this->extractToken($request);
        if ($token === null) {
            return null;
        }

        return $this->jwtParser->parse($token);
    }

    /**
     * Extract a JWT from the Authorization Bearer header or access_token cookie.
     *
     * Mirrors the extraction performed inside {@see TenantContext::resolve()} so
     * the decoded payload can be re-derived without changing the context API.
     *
     * @param Request $request The incoming HTTP request.
     * @return string|null The token, or null when none is present.
     */
    private function extractToken(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+(\S+)$/', $authHeader, $matches) === 1) {
            return $matches[1];
        }

        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader !== null) {
            foreach (explode(';', $cookieHeader) as $cookie) {
                $parts = explode('=', trim($cookie), 2);
                if (count($parts) === 2 && $parts[0] === 'access_token') {
                    return $parts[1];
                }
            }
        }

        return null;
    }

    /**
     * Check whether a route is public (no JWT / tenant context required).
     *
     * @param string $path The request path (without query string).
     * @return bool True if the route is public.
     */
    private function isPublicRoute(string $path): bool
    {
        return in_array($path, self::PUBLIC_ROUTES, true);
    }
}
