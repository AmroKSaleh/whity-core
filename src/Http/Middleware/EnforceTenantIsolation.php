<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\JwtParser;
use Whity\Core\Audit\AuditContext;
use Whity\Core\RateLimit\ClientIp;
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
        // WC-206: versioned auth surface under /api/v1/.
        '/api/v1/login',
        '/api/v1/login/2fa',
        // WC-235: public self-service registration. Unauthenticated by design —
        // it provisions a brand-new tenant + owner, so there is no session/tenant
        // to resolve yet; RegisterApiHandler validates + rate-limiting throttles.
        '/api/v1/register',
        // ADR 0005 §6: multi-membership tenant selection. Public like login/2fa —
        // the caller holds only the short-lived selection cookie (no session yet);
        // AuthHandler::handleSelectTenant re-validates membership before minting.
        '/api/v1/auth/select-tenant',
        '/api/v1/me',
        '/api/v1/auth/refresh',
        '/api/v1/auth/logout',
        // WC-b-logout-others: sign out of all OTHER sessions/devices. Self-
        // validates the current access token (cookie or Bearer) via
        // resolveAccessClaims, then bumps the epoch + re-mints — same
        // self-authenticating pattern as /me and /auth/refresh above.
        '/api/v1/me/logout-others',
        // WC-175 (#191): /api/navigation is NO LONGER public. It is now
        // caller-aware — NavigationApiHandler resolves the authenticated user
        // and tenant and RBAC-filters items — so an unauthenticated request
        // must resolve to 401 here instead of enumerating gated items, exactly
        // like /api/frontend/features.
        // Health monitoring (WC-4): unauthenticated liveness/readiness probe.
        // Kept UNVERSIONED (/api/health, not /api/v1/health) so load-balancer
        // probes targeting /api/health always work regardless of the API version.
        // WC-206: /api/version is also unversioned and probe-safe.
        '/api/health',
        '/api/version',
        // WC-209: the dynamic OpenAPI document. Unversioned and unauthenticated
        // (matching the static /openapi.json already served by Caddy) — it
        // exposes only route shapes, never tenant data, so it bypasses tenant
        // resolution like the other infrastructure probes.
        '/api/openapi.json',
        // WC-233: public effective branding endpoint — resolves tenant by host,
        // returns only branding fields (never other settings), no auth required.
        '/api/v1/branding',
        // KeyHub KiCad plugin native-client login — issues JWTs to the desktop app.
        '/api/v1/keyhub/auth/token',
        // WC-b-device-tokens: device-credential exchange. Self-authenticating via
        // the long-lived device credential (type='device') — like the MCP bearer
        // surface, the standard access-token flow would reject it, so it bypasses
        // tenant resolution and validates the credential inside the handler. Only
        // the EXCHANGE is public; device register/list/revoke stay session-gated.
        '/api/v1/devices/token',
        // MCP Streamable-HTTP endpoint — handles its own auth via mcp token type
        // (ADR-0006); the per-call contract validates the token and sets
        // TenantContext inside the dispatcher. The standard access-token flow
        // would reject an mcp token here, so /mcp bypasses tenant resolution.
        '/mcp',
    ];

    /**
     * Route prefixes that are reachable without authentication / tenant context.
     * Any path that starts with one of these prefixes is treated as public.
     *
     * @var list<string>
     */
    private const PUBLIC_ROUTE_PREFIXES = [
        // WC-233: public asset-serving route for branding images. The full path
        // includes the tenant id and filename, so an exact-match list is not
        // practical — a prefix check is used instead.
        '/api/v1/branding/asset/',
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
     * Optional membership gate for the new {profile_id, active_tenant_id}
     * claims (WC-d4340daf, ADR 0005 §5). When wired, a new-claims token whose
     * active_tenant_id is not backed by a live membership is refused with a
     * typed 403 before any handler runs. Legacy tokens (no new claims) are
     * never gated — the dual-window fallback path. Null (e.g. CLI wiring)
     * disables the gate entirely, preserving prior behaviour.
     */
    private ?ActiveTenantMembershipGuard $membershipGuard;

    /**
     * @param JwtParser                        $jwtParser        JWT validator used by TenantContext::resolve().
     * @param LoggerInterface|null             $logger           Optional PSR-3 audit logger. When null,
     *                                                            a {@see NullLogger} is used.
     * @param ActiveTenantMembershipGuard|null $membershipGuard  Optional active_tenant_id membership
     *                                                            gate (WC-d4340daf); null disables it.
     */
    public function __construct(
        JwtParser $jwtParser,
        ?LoggerInterface $logger = null,
        ?ActiveTenantMembershipGuard $membershipGuard = null
    ) {
        $this->jwtParser = $jwtParser;
        $this->logger = $logger ?? new NullLogger();
        $this->membershipGuard = $membershipGuard;
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

        // Dual-claim membership gate (WC-d4340daf, ADR 0005 §5): when the token
        // carries the new {profile_id, active_tenant_id} claims, the declared
        // active tenant must be backed by a live 'active' membership (or the
        // system tenant 0). A suspended/revoked membership is refused here with
        // a typed 403 — the HTTP layer, before any handler/database work —
        // without waiting for token expiry; a malformed/partial new-claim set
        // (a shape this codebase never issues) is refused with 401 like any
        // other invalid token. Responses stay generic — the typed detail is
        // logged, never leaked. Legacy tokens (no new claims) skip the gate:
        // pre-migration users have no membership rows yet.
        //
        // ORDERING: the gate runs BEFORE TenantContext::resolve() so a refused
        // tenant is never locked into the request-scoped context — nothing
        // (audit hooks, observers) can ever read an unapproved tenant id.
        if ($this->membershipGuard !== null && $payload !== null) {
            try {
                $this->membershipGuard->assert($payload);
            } catch (\Whity\Auth\Exception\InvalidMembershipException $e) {
                $claimedTenant = $payload['active_tenant_id'] ?? null;
                $this->logger->warning('Tenant isolation: active tenant membership refused', [
                    'event' => 'tenant_isolation.membership_denied',
                    'http_status' => $e->httpStatus,
                    'reason' => $e->getMessage(),
                    'tenant_id' => is_numeric($claimedTenant) ? (int) $claimedTenant : null,
                    'user_id' => $this->userIdFromPayload($payload),
                    'path' => $path,
                ]);

                return $e->httpStatus === 401
                    ? Response::error('Authentication required', 401)
                    : Response::error('Access to the requested tenant is forbidden', 403);
            }
        }

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
        //
        // WC-c35c4ce0 security follow-up (a): use userIdFromPayload() rather than
        // reading payload['user_id'] inline, so post-cutover tokens that carry
        // only profile_id (no legacy user_id) still stamp a non-null audit actor.
        AuditContext::set($this->userIdFromPayload($payload), $this->clientIp($request));

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
     * Post-cutover: profile_id is the canonical actor identity (ADR 0005 §1).
     *
     * @param array<string, mixed>|null $payload The decoded JWT payload.
     * @return int|null The profile_id, or null when absent.
     */
    private function userIdFromPayload(?array $payload): ?int
    {
        if ($payload === null) {
            return null;
        }

        // Post-cutover: profile_id is the canonical actor identity (ADR 0005 §1).
        if (isset($payload['profile_id']) && is_int($payload['profile_id'])) {
            return $payload['profile_id'];
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
     * Checks both the exact-match list ({@see self::PUBLIC_ROUTES}) and the
     * prefix list ({@see self::PUBLIC_ROUTE_PREFIXES}). The prefix check enables
     * public access to parametrised paths (e.g. asset-serving routes whose full
     * path includes a tenant id and filename segment) without enumerating every
     * possible combination.
     *
     * @param string $path The request path (without query string).
     * @return bool True if the route is public.
     */
    private function isPublicRoute(string $path): bool
    {
        if (in_array($path, self::PUBLIC_ROUTES, true)) {
            return true;
        }
        foreach (self::PUBLIC_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Trusted client-IP extraction for the audit context (WC-34, WC-b19ff21a).
     *
     * Delegates to {@see ClientIp}, which reads ONLY the internal proxy-set header
     * — raw client-supplied `X-Forwarded-For` / `X-Real-IP` are not trusted, so an
     * attacker can no longer poison audit IPs by sending a forwarding header.
     * Returns null when absent (capped at 45 chars for the audit column).
     *
     * @param Request $request The incoming request.
     * @return string|null The client IP, or null.
     */
    private function clientIp(Request $request): ?string
    {
        return ClientIp::fromRequest($request);
    }
}
