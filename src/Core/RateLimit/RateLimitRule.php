<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Closure;
use Whity\Core\Audit\AuditContext;
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

    /**
     * @param string                   $name    Counter namespace (e.g. 'ip', 'tenant', 'principal').
     * @param Closure(Request): ?string $resolve Returns the dimension value, or null to skip.
     * @param int                      $limit   Maximum hits permitted per window (≥ 1).
     * @param int                      $window  Window length in seconds (≥ 1).
     */
    public function __construct(
        public readonly string $name,
        Closure $resolve,
        public readonly int $limit,
        public readonly int $window,
    ) {
        $this->resolve = $resolve;
    }

    /**
     * Per-IP rule — keyed on the forwarding-header client IP. Suitable for the
     * pre-auth pipeline position, where no principal/tenant is known yet.
     */
    public static function ip(int $limit, int $window): self
    {
        return new self('ip', static fn (Request $r): ?string => ClientIp::fromRequest($r), $limit, $window);
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
}
