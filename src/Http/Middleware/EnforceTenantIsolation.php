<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Auth\JwtParser;
use Whity\Core\Audit\AuditContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\TenantResolutionException;

/**
 * HTTP-layer tenant isolation enforcement middleware.
 *
 * Runs early in the request pipeline (before routing, RBAC and any database
 * access) and performs two jobs:
 *
 * 1. Resolution — decodes the request's JWT exactly once, stashes the claims on
 *    the Request as {@see Request::ATTR_JWT_CLAIMS} (WC-159), and delegates
 *    tenant extraction to {@see TenantContext::resolve()}, which reads the
 *    stashed claims, validates the tenant claim and locks the resolved tenant
 *    id into the request-scoped context. Downstream middleware (RBAC) and
 *    handlers consume the stashed claims instead of re-decoding the token.
 * 2. Enforcement — when the request addresses a resource that *declares* a
 *    tenant (a `/api/tenants/{id}` path segment, a `tenant_id` query parameter,
 *    or an `X-Tenant-Id` header), the caller's JWT-derived tenant is compared
 *    against it. A mismatch is refused with HTTP 403 *before* the handler runs,
 *    so a cross-tenant request never reaches the database.
 *
 * Trust boundary (WC-193). The JWT-derived {@see TenantContext} is the SOLE
 * source of truth for *who* the caller is. The path/query/header tenant signals
 * are attacker-suppliable *declared targets*: they say which tenant the request
 * claims to address, never who the caller is. They are consumed ONLY by this
 * gate and feed exactly one of three outcomes:
 *   - declared target == caller's JWT tenant  -> allow (it can only ever match);
 *   - declared target != caller's JWT tenant, caller has cross-tenant authority
 *     (system tenant 0 / system mode) -> allow + audit (the audited bypass);
 *   - declared target != caller's JWT tenant, ordinary caller -> 403 + audit.
 * A declared target can therefore NEVER widen a non-system caller's reach: the
 * only non-error path for such a caller is the match path, which by definition
 * keeps it inside its own JWT tenant. No handler reads these signals for
 * scoping — every tenant-owned query binds its `tenant_id` predicate from
 * {@see TenantContext} (WC-161/190/191), proven by the real-engine suites — so
 * even were this gate removed, the header/query could not select another
 * tenant's rows. The gate is the early, audited refusal; the predicate is the
 * structural guarantee.
 *
 * Bypass: callers with cross-tenant authority are allowed through. This is the
 * system tenant convention (tenant id 0) plus the explicit, non-request
 * {@see TenantContext::isSystemMode()} bypass used by trusted tooling. Every
 * bypass is recorded as a structured audit log entry that includes the caller's
 * tenant id, the target resource tenant id and the request path. This middleware
 * deliberately reuses the existing tenant-id-0 / system-mode mechanism rather
 * than introducing a parallel super-admin flag. Conversely, every *refused*
 * cross-tenant attempt is also audited (WC-193) so an attacker probing other
 * tenants via the header/query leaves a structured trail rather than a silent
 * 403.
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
        // WC-175 (#191): /api/navigation is NO LONGER public. It is now
        // caller-aware — NavigationApiHandler resolves the authenticated user
        // and tenant and RBAC-filters items — so an unauthenticated request
        // must resolve to 401 here instead of enumerating gated items, exactly
        // like /api/frontend/features.
        // Health monitoring (WC-4): unauthenticated liveness/readiness probe.
        // Must stay reachable without a JWT or tenant context so external
        // monitors can poll it even while auth/tenant subsystems are unhealthy.
        '/api/health',
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

        // Single decode (WC-159): parse the JWT exactly once per request and
        // stash the claims (array|null) on the Request. Downstream consumers —
        // TenantContext::resolve() below, RbacMiddleware, handlers — read the
        // stashed claims instead of re-decoding the token.
        $token = $this->extractToken($request);
        $payload = $token === null ? null : $this->jwtParser->parse($token);
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, $payload);

        // Delegate token -> tenant extraction and validation to the context. Any
        // failure (missing/invalid token, missing/invalid tenant claim) collapses
        // to a generic 401 so internals are never leaked to the client.
        try {
            $tenantId = TenantContext::resolve($request, $this->jwtParser);
        } catch (TenantResolutionException) {
            return Response::error('Authentication required', 401);
        }

        // Expose the decoded payload to downstream handlers via Request::$user,
        // mirroring the prior contract.
        if ($payload !== null) {
            $request->user = (object) $payload;
        }

        // Populate the request-scoped audit context (WC-34) so the AuditLogger —
        // which subscribes to hooks deep inside the handlers and has no access to
        // the Request — can stamp the acting user and client IP on every entry.
        // Reset between requests by the kernel and the worker loop.
        $actorUserId = ($payload !== null && isset($payload['user_id']) && is_int($payload['user_id']))
            ? $payload['user_id']
            : null;
        AuditContext::set($actorUserId, $this->clientIp($request));

        // Determine the tenant the request *declares* it is addressing, if any.
        // This is an attacker-suppliable target (path/query/header), NOT the
        // caller's identity — $tenantId (from the JWT) is the source of truth.
        $resourceTenantId = $this->resolveResourceTenantId($request, $path);

        // No tenant-scoped target declared: defer finer-grained checks to the
        // route handler, whose queries carry explicit TenantContext-derived
        // tenant_id predicates (WC-161 — there is no query-rewriting layer;
        // isolation is proven per table by CrossTenantRejectionRealEngineTest).
        if ($resourceTenantId === null) {
            return $next($request);
        }

        // Same-tenant access is always permitted. For an ordinary caller this is
        // the ONLY non-error continuation, and it keeps the request inside the
        // caller's own JWT tenant by construction — a declared target can match
        // or be refused, never escalate. (WC-193)
        if ($resourceTenantId === $tenantId) {
            return $next($request);
        }

        // Cross-tenant access: only the system tenant (id 0) or an explicit
        // system-mode bypass may proceed, and the privileged access is audited.
        if ($this->hasCrossTenantAuthority($tenantId)) {
            $this->auditCrossTenantBypass($tenantId, $resourceTenantId, $payload, $path);
            return $next($request);
        }

        // Otherwise refuse before any handler/database work runs. The refusal is
        // audited (WC-193): a non-system caller declaring another tenant via the
        // path/query/header is an escalation probe, not a silent 403.
        $this->auditCrossTenantDenied($tenantId, $resourceTenantId, $payload, $path);

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
        $this->logger->warning('Tenant isolation: cross-tenant access permitted via privileged bypass', [
            'event' => 'tenant_isolation.cross_tenant_bypass',
            'tenant_id' => $tenantId,
            'resource_tenant_id' => $resourceTenantId,
            'user_id' => $this->userIdFromPayload($payload),
            'system_mode' => TenantContext::isSystemMode(),
            'path' => $path,
        ]);
    }

    /**
     * Emit a structured audit record for a refused cross-tenant access (WC-193).
     *
     * A non-system caller whose declared resource tenant (path/query/header)
     * differs from its JWT tenant is refused. Recording the attempt turns an
     * otherwise-silent 403 into a structured signal for detecting escalation
     * probes, while the response itself stays generic.
     *
     * @param int                       $tenantId         The caller's tenant id.
     * @param int                       $resourceTenantId The declared target tenant id.
     * @param array<string, mixed>|null $payload          The decoded JWT payload, if available.
     * @param string                    $path             The request path (without query string).
     * @return void
     */
    private function auditCrossTenantDenied(
        int $tenantId,
        int $resourceTenantId,
        ?array $payload,
        string $path
    ): void {
        $this->logger->warning('Tenant isolation: cross-tenant access denied', [
            'event' => 'tenant_isolation.cross_tenant_denied',
            'tenant_id' => $tenantId,
            'resource_tenant_id' => $resourceTenantId,
            'user_id' => $this->userIdFromPayload($payload),
            'path' => $path,
        ]);
    }

    /**
     * Extract the acting user id from a decoded JWT payload, if present.
     *
     * @param array<string, mixed>|null $payload The decoded JWT payload.
     * @return int|null The integer user_id claim, or null when absent/non-int.
     */
    private function userIdFromPayload(?array $payload): ?int
    {
        if ($payload !== null && isset($payload['user_id']) && is_int($payload['user_id'])) {
            return $payload['user_id'];
        }

        return null;
    }

    /**
     * Determine the tenant id the request *declares* it is addressing.
     *
     * SECURITY (WC-193): the return value is a *declared target*, never the
     * caller's identity. All three signals are attacker-suppliable, so the
     * value is treated as untrusted input: it is only ever compared against the
     * JWT-derived {@see TenantContext} by the caller of this method, and can
     * therefore only match-or-403 for a non-system caller (see handle()). It is
     * NEVER used as a scoping input by any handler. Parsing fails closed —
     * anything that is not a plain non-negative decimal integer resolves to
     * null (no declared target), so a crafted value (`-1`, `+2`, `0x2`, ` 2 `,
     * `2; …`) cannot coincide with a real tenant id or smuggle past the gate.
     *
     * Recognised signals, in priority order:
     *  - a trailing/segment tenant id under the `/api/tenants/{id}` resource;
     *  - a `tenant_id` query-string parameter;
     *  - an `X-Tenant-Id` request header.
     *
     * @param Request $request The incoming HTTP request.
     * @param string  $path    The request path with the query string stripped.
     * @return int|null The declared resource tenant id, or null if none/invalid.
     */
    private function resolveResourceTenantId(Request $request, string $path): ?int
    {
        // Path-encoded tenant resource: /api/tenants/{id}. The {id} is a run of
        // ASCII digits only, so it shares the same fail-closed parsing as the
        // header/query below.
        if (preg_match('#^/api/tenants/(\d+)(?:/.*)?$#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        // tenant_id query parameter (parsed from the raw path when present).
        $queryTenant = $this->tenantIdFromQuery($request->getPath());
        if ($queryTenant !== null) {
            return $queryTenant;
        }

        // Explicit X-Tenant-Id header. ctype_digit() accepts ONLY a non-empty
        // run of ASCII digits — it rejects signs, decimals, hex, whitespace and
        // the empty string — so a malformed header resolves to "no declared
        // target" and defers to the handler's JWT-derived scoping.
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
     * Extract a JWT from the Authorization Bearer header or access_token cookie.
     *
     * Mirrors the extraction performed inside {@see TenantContext::resolve()} so
     * the claims can be decoded once here and stashed for the whole pipeline.
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

    /**
     * Best-effort client IP extraction from forwarding headers (WC-34).
     *
     * Prefers the first hop in `X-Forwarded-For`, then `X-Real-IP`. Returns null
     * when neither is present. Capped at 45 chars (IPv6 max) for the audit column.
     *
     * @param Request $request The incoming request.
     * @return string|null The client IP, or null.
     */
    private function clientIp(Request $request): ?string
    {
        $forwarded = $request->getHeader('X-Forwarded-For');
        if (is_string($forwarded) && $forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return substr($first, 0, 45);
            }
        }

        $realIp = $request->getHeader('X-Real-IP');
        if (is_string($realIp) && $realIp !== '') {
            return substr(trim($realIp), 0, 45);
        }

        return null;
    }
}
