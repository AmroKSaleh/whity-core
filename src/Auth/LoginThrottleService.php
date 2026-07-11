<?php

declare(strict_types=1);

namespace Whity\Auth;

use Whity\Core\Store\SharedStoreInterface;

/**
 * WC-0abcc29f: per-account and per-source-IP failed-login throttle.
 *
 * Wraps SharedStoreInterface to enforce two independent fixed-window counters:
 * one keyed by user ID and one keyed by client IP address. Either counter
 * reaching its threshold causes isThrottled() to return true, regardless of
 * the other counter's value.
 *
 * TTL is set once on the first failure in a window and never extended by
 * subsequent failures (fixed-window semantics from SharedStoreInterface).
 */
final class LoginThrottleService
{
    /** Defaults (used when the operator sets no override). */
    public const DEFAULT_USER_THRESHOLD = 10;
    public const DEFAULT_IP_THRESHOLD   = 20;
    public const DEFAULT_WINDOW_SECONDS = 900; // 15 minutes

    private readonly int $userThreshold;
    private readonly int $ipThreshold;
    private readonly int $windowSeconds;

    /**
     * Thresholds/window are operator-configurable (composition root reads the
     * LOGIN_THROTTLE_* env), matching the HTTP RATE_LIMIT_* rules. Each is clamped
     * to at least 1 so a stray 0/negative can never silently disable the
     * brute-force protection.
     */
    public function __construct(
        private readonly SharedStoreInterface $store,
        int $userThreshold = self::DEFAULT_USER_THRESHOLD,
        int $ipThreshold = self::DEFAULT_IP_THRESHOLD,
        int $windowSeconds = self::DEFAULT_WINDOW_SECONDS,
    ) {
        $this->userThreshold = max(1, $userThreshold);
        $this->ipThreshold   = max(1, $ipThreshold);
        $this->windowSeconds = max(1, $windowSeconds);
    }

    /**
     * Returns true if either the per-user OR the per-IP counter is at or above
     * its respective threshold. Null arguments skip the corresponding check.
     */
    public function isThrottled(?int $userId, ?string $ip): bool
    {
        if ($userId !== null && $this->store->count("login:fail:user:{$userId}") >= $this->userThreshold) {
            return true;
        }

        if ($ip !== null && $this->store->count("login:fail:ip:{$ip}") >= $this->ipThreshold) {
            return true;
        }

        return false;
    }

    /**
     * Increment failure counters. Null arguments skip the corresponding counter.
     */
    public function recordFailure(?int $userId, ?string $ip): void
    {
        if ($userId !== null) {
            $this->store->increment("login:fail:user:{$userId}", $this->windowSeconds);
        }

        if ($ip !== null) {
            $this->store->increment("login:fail:ip:{$ip}", $this->windowSeconds);
        }
    }

    /**
     * Evict the per-user counter on successful login so a legitimate user is
     * not locked out after a password-reset or temporary lockout.
     */
    public function clearUser(int $userId): void
    {
        $this->store->delete("login:fail:user:{$userId}");
    }
}
