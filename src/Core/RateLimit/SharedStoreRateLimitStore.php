<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

use Whity\Core\Store\SharedStoreInterface;

/**
 * Default rate-limit store, composed over {@see SharedStoreInterface} (WC-dc791066).
 *
 * Reuses the platform's existing atomic, worker-safe fixed-window counter
 * (DatabaseSharedStore in production, ArraySharedStore in tests) rather than
 * introducing a parallel counter table: the shared store already provides the
 * atomic increment-with-TTL guarantee a rate limiter needs. This class adds only
 * the rate-limit-specific layer the bare counter lacked — turning a raw count
 * into a {@see RateLimitDecision} and surfacing the window's seconds-until-reset
 * (via {@see SharedStoreInterface::ttl()}) so callers can emit an accurate
 * `Retry-After`.
 *
 * Worker-safe: stateless aside from the injected store; all counter state lives
 * in the shared backing store.
 */
final class SharedStoreRateLimitStore implements RateLimitStoreInterface
{
    public function __construct(private readonly SharedStoreInterface $store)
    {
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitDecision
    {
        $count   = $this->store->increment($key, $windowSeconds);
        $allowed = $count <= $limit;

        // Only read the reset time when blocked — an allowed request needs no
        // Retry-After, so the extra store round-trip is avoided on the hot path.
        $retryAfter = $allowed ? 0 : max(1, $this->store->ttl($key));

        return new RateLimitDecision(
            allowed: $allowed,
            limit: $limit,
            remaining: max(0, $limit - $count),
            retryAfter: $retryAfter,
            count: $count,
        );
    }

    public function reset(string $key): void
    {
        $this->store->delete($key);
    }
}
