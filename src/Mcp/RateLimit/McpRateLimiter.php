<?php

declare(strict_types=1);

namespace Whity\Mcp\RateLimit;

use Whity\Core\Store\SharedStoreInterface;

/**
 * Per-tenant and per-principal fixed-window call-budget enforcer for MCP
 * (WC-a89ece0d).
 *
 * Two independent counters are maintained:
 *   - tenant counter    — caps total AI throughput for a tenant regardless of
 *                         which principal is making the calls.
 *   - principal counter — caps per-user/service throughput so one AI client
 *                         cannot monopolise the tenant budget.
 *
 * Fixed-window semantics follow SharedStoreInterface: the TTL is set once on
 * the first call in a window and never extended, so the window resets
 * atomically when the TTL elapses.  This is identical to the pattern used by
 * LoginThrottleService (WC-0abcc29f).
 */
final class McpRateLimiter
{
    public const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly SharedStoreInterface $store,
        private readonly int $tenantLimit    = 300,
        private readonly int $principalLimit = 60,
    ) {}

    /**
     * Atomically record this call and throw when either budget is exhausted.
     *
     * The tenant counter is checked first. If the tenant is under budget the
     * principal counter is checked next. Both counters are incremented before
     * the limit comparison so that over-budget increments still decay naturally
     * when the window expires — no cleanup pass is needed.
     *
     * @throws McpRateLimitException when the tenant or principal limit is hit.
     */
    public function checkAndRecord(int $tenantId, int $userId): void
    {
        $tenantCount = $this->store->increment(
            "mcp:rate:tenant:{$tenantId}",
            self::WINDOW_SECONDS,
        );
        if ($tenantCount > $this->tenantLimit) {
            throw new McpRateLimitException(self::WINDOW_SECONDS);
        }

        $principalCount = $this->store->increment(
            "mcp:rate:principal:{$userId}",
            self::WINDOW_SECONDS,
        );
        if ($principalCount > $this->principalLimit) {
            throw new McpRateLimitException(self::WINDOW_SECONDS);
        }
    }
}
