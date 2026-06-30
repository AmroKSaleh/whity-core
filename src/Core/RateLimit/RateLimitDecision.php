<?php

declare(strict_types=1);

namespace Whity\Core\RateLimit;

/**
 * The outcome of a single rate-limit check (WC-dc791066).
 *
 * Immutable value object returned by {@see RateLimitStoreInterface::hit()}. It
 * carries everything a caller needs to both decide whether to proceed and to
 * emit the standard rate-limit response headers:
 *
 *   - {@see $allowed}    — false once the window's budget is exhausted.
 *   - {@see $limit}      — the ceiling that applied to this check (X-RateLimit-Limit).
 *   - {@see $remaining}  — calls still permitted in the window (X-RateLimit-Remaining),
 *                          floored at 0.
 *   - {@see $retryAfter} — seconds the caller should wait before retrying
 *                          (Retry-After). 0 when the request was allowed; ≥ 1 when
 *                          blocked, derived from the live window's reset time.
 *   - {@see $count}      — hits recorded in the current window so far (≥ 1).
 *
 * FrankenPHP worker-safe: holds only the scalar result of one check, no shared
 * or request-spanning state.
 */
final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $retryAfter,
        public int $count,
    ) {}
}
