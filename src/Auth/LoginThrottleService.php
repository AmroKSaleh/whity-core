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
    private const USER_THRESHOLD = 10;
    private const IP_THRESHOLD   = 20;
    private const WINDOW_SECONDS = 900; // 15 minutes

    public function __construct(private readonly SharedStoreInterface $store) {}

    /**
     * Returns true if either the per-user OR the per-IP counter is at or above
     * its respective threshold. Null arguments skip the corresponding check.
     */
    public function isThrottled(?int $userId, ?string $ip): bool
    {
        if ($userId !== null && $this->store->count("login:fail:user:{$userId}") >= self::USER_THRESHOLD) {
            return true;
        }

        if ($ip !== null && $this->store->count("login:fail:ip:{$ip}") >= self::IP_THRESHOLD) {
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
            $this->store->increment("login:fail:user:{$userId}", self::WINDOW_SECONDS);
        }

        if ($ip !== null) {
            $this->store->increment("login:fail:ip:{$ip}", self::WINDOW_SECONDS);
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
