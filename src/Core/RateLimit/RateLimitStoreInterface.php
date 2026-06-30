<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

/**
 * Rate-limit decision surface (WC-dc791066).
 *
 * A thin, fixed-window rate limiter expressed in rate-limit terms: callers pass
 * a pre-composed key (e.g. "ip:1.2.3.4", "mcp:tenant:7", "login:user@x") plus
 * the limit and window, and receive a {@see RateLimitDecision}. The dimension
 * encoded in the key (per-IP / per-principal / per-tenant / per-credential) is
 * the caller's concern — the kernel rate-limit middleware (WC-c0fb3700) owns
 * key construction; this contract only counts and decides.
 *
 * The default implementation ({@see SharedStoreRateLimitStore}) is backed by the
 * existing {@see \Whity\Core\Store\SharedStoreInterface} so counters are atomic
 * and persist across FrankenPHP workers and restarts. Implementations MUST be
 * worker-safe and hold no process-local counter state.
 */
interface RateLimitStoreInterface
{
    /**
     * Record one hit against $key and decide whether it is within budget.
     *
     * Fixed-window semantics: the window opens on the first hit for a key and
     * resets atomically when it elapses (a hit after expiry starts a fresh
     * window). The hit is ALWAYS counted — an over-limit call still increments,
     * so the decision is `allowed = count <= $limit`.
     *
     * @param string $key           Pre-composed, dimension-qualified counter key.
     * @param int    $limit         Maximum hits permitted within the window (≥ 1).
     * @param int    $windowSeconds Window length in seconds.
     * @return RateLimitDecision The outcome, including Retry-After when blocked.
     */
    public function hit(string $key, int $limit, int $windowSeconds): RateLimitDecision;

    /**
     * Clear the window for $key, restoring its full budget immediately.
     *
     * Used by admin tooling and tests (e.g. lifting a lockout). No-op when the
     * key does not exist.
     */
    public function reset(string $key): void;
}
