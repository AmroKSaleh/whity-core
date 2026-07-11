<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Closure;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;

/**
 * One dimension of rate limiting (WC-c0fb3700).
 *
 * A rule binds a counter namespace ({@see $name}) to a resolver that derives the
 * dimension's value for a given request, plus the budget ({@see $limit}) and
 * window ({@see $window}) that apply to it. The middleware composes the counter
 * key as `rl:{name}:{value}`; when the resolver returns null the dimension is
 * absent for this request and the middleware skips it — which is how a single
 * middleware class serves both the pre-auth (per-IP) and post-auth
 * (per-tenant / per-principal) pipeline positions.
 *
 * The factories {@see self::ip()}, {@see self::tenant()} and {@see self::principal()}
 * wire the three standard dimensions to their request-scoped sources.
 */
final class RateLimitRule
{
    /** @var Closure(Request): ?string */
    public readonly Closure $resolve;

    /** @var (Closure(Request): ?int)|null Per-request budget override; null → the fixed $limit. */
    private readonly ?Closure $limitResolver;

    /**
     * @param string                    $name          Counter namespace (e.g. 'ip', 'tenant', 'principal', 'platform').
     * @param Closure(Request): ?string $resolve       Returns the dimension value, or null to skip.
     * @param int                       $limit         Fixed maximum hits per window (≥ 1); also the fallback budget.
     * @param int                       $window        Window length in seconds (≥ 1).
     * @param (Closure(Request): ?int)|null $limitResolver Optional per-request budget; when it returns null the
     *                                                 fixed $limit is used. Lets a dimension's budget vary per
     *                                                 request (e.g. a per-tenant limit driven by the tenant's plan).
     */
    public function __construct(
        public readonly string $name,
        Closure $resolve,
        public readonly int $limit,
        public readonly int $window,
        ?Closure $limitResolver = null,
    ) {
        $this->resolve = $resolve;
        $this->limitResolver = $limitResolver;
    }

    /**
     * The budget to enforce for this request: the per-request resolver's value
     * when present, else the fixed {@see $limit}.
     */
    public function limitFor(Request $request): int
    {
        if ($this->limitResolver !== null) {
            $resolved = ($this->limitResolver)($request);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $this->limit;
    }

    /**
     * Sentinel bucket for requests that arrive without a usable client IP.
     *
     * Mapping the no-IP case to a shared key (rather than skipping the rule)
     * keeps the per-IP limiter FAIL-CLOSED: a flood of header-less requests is
     * still bounded, instead of slipping through uncounted.
     */
    public const IP_UNKNOWN = 'unknown';

    /**
     * Per-IP rule — keyed on the forwarding-header client IP. Suitable for the
     * pre-auth pipeline position, where no principal/tenant is known yet.
     *
     * TRUST BOUNDARY: the client IP is derived from forwarding headers
     * ({@see ClientIp}), which are only as trustworthy as the front proxy's
     * configuration. Without a trusted-proxy setup that strips client-supplied
     * `X-Forwarded-For`, this header is spoofable, so the per-IP limiter is a
     * BEST-EFFORT, defense-in-depth control for the unauthenticated surface. The
     * authoritative, spoof-proof limits are the per-tenant / per-principal rules
     * ({@see self::tenant()}, {@see self::principal()}), keyed on the validated
     * JWT identity. Hardening client-IP determination (Caddy `trusted_proxies` +
     * a validated client-IP source) is tracked as platform infra follow-up and
     * also fixes the pre-existing audit-IP exposure.
     *
     * When no IP can be derived the rule keys on {@see self::IP_UNKNOWN} so the
     * dimension is never silently skipped (fail-closed).
     */
    public static function ip(int $limit, int $window): self
    {
        return new self('ip', static fn (Request $r): string => ClientIp::fromRequest($r) ?? self::IP_UNKNOWN, $limit, $window);
    }

    /**
     * Per-tenant rule — keyed on the resolved {@see TenantContext} tenant id.
     * Resolves to null on unauthenticated requests AND for the system tenant
     * (id 0), which is trusted platform authority and is not rate limited.
     */
    public static function tenant(int $limit, int $window): self
    {
        return new self('tenant', static function (Request $r): ?string {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return null;
            }
            return (string) $tenantId;
        }, $limit, $window);
    }

    /**
     * Per-principal rule — keyed on the authenticated actor recorded in
     * {@see AuditContext} (set by EnforceTenantIsolation after token resolution).
     * Resolves to null when there is no authenticated principal.
     */
    public static function principal(int $limit, int $window): self
    {
        return new self('principal', static function (Request $r): ?string {
            $userId = AuditContext::getActorUserId();
            return $userId === null ? null : (string) $userId;
        }, $limit, $window);
    }

    /**
     * Plan-driven per-tenant rule — like {@see self::tenant()} (keyed on the
     * tenant id, skipping system tenant 0 + unauth) but the per-minute budget is
     * the tenant's `ratelimit.rpm` entitlement, so throughput scales with the
     * subscription tier. The entitlement default (-1) means "no plan-specific cap"
     * and falls back to $fallbackLimit — the platform baseline — so existing
     * behaviour is preserved until a plan sets a positive rpm.
     */
    public static function tenantEntitled(EntitlementService $entitlements, int $fallbackLimit, int $window): self
    {
        $resolve = static function (Request $r): ?string {
            $tenantId = TenantContext::getTenantId();
            return ($tenantId === null || $tenantId === 0) ? null : (string) $tenantId;
        };
        $limitResolver = static function (Request $r) use ($entitlements, $fallbackLimit): ?int {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null || $tenantId === 0) {
                return null; // dimension skipped anyway; use fallback defensively
            }
            $rpm = $entitlements->limit($tenantId, EntitlementRegistry::RATELIMIT_RPM);

            // A positive rpm is the plan's explicit budget; -1 (default/unlimited)
            // or any non-positive value means "no plan cap" → the platform baseline.
            return $rpm > 0 ? $rpm : $fallbackLimit;
        };

        return new self('tenant', $resolve, $fallbackLimit, $window, $limitResolver);
    }

    /**
     * Platform-wide rule — a single shared counter ('all') capping TOTAL request
     * throughput across every tenant and the unauthenticated surface. A safety
     * ceiling for the whole deployment (e.g. a sovereign single-customer box);
     * set generously so it never throttles normal load. Never skipped.
     */
    public static function platform(int $limit, int $window): self
    {
        return new self('platform', static fn (Request $r): string => 'all', $limit, $window);
    }
}
