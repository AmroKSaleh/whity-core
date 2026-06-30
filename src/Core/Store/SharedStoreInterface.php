<?php

declare(strict_types=1);

namespace Whity\Core\Store;

/**
 * Cross-worker key-counter store.
 *
 * Provides an atomic integer counter per named key with a TTL. State persists
 * across FrankenPHP workers (and restarts) via the backing implementation.
 * The primary consumer is the rate-limiter / brute-force-protection layer;
 * queue and realtime primitives build on this same surface.
 *
 * Fixed-window semantics: the TTL is set once on the first increment that
 * creates the key; it does NOT slide on subsequent increments. This means
 * "max N hits in the first T-second window that began on the first event."
 *
 * Implementations MUST guarantee that {@see increment()} is atomic — two
 * concurrent callers on different workers must never both observe value 1
 * after incrementing from 0.
 */
interface SharedStoreInterface
{
    /**
     * Atomically increment the counter for $key.
     *
     * If the key does not exist yet, it is created with counter = 1 and a
     * TTL of $ttlSeconds from now. If the key exists and has NOT expired,
     * the counter is incremented by 1 and the existing expiry is kept. If
     * the key exists but HAS expired, it is reset to counter = 1 with a
     * fresh TTL.
     *
     * @param int $ttlSeconds Window length in seconds. A value ≤ 0 creates a
     *                        key that is immediately considered expired (useful
     *                        in tests to simulate an elapsed window).
     * @return int The new counter value after incrementing (always ≥ 1).
     */
    public function increment(string $key, int $ttlSeconds): int;

    /**
     * Return the current counter value for $key.
     *
     * Returns 0 if the key does not exist or has expired. Does NOT modify
     * the stored value or TTL.
     *
     * @return int Current counter value (0 if missing or expired).
     */
    public function count(string $key): int;

    /**
     * Return the whole seconds remaining until $key's current window expires.
     *
     * Returns 0 when the key is missing, has already expired, or never expires
     * (a NULL TTL). For an active fixed-window key the value is the ceiling of
     * the time left, so it is always ≥ 1 while the window is live — exactly what
     * a rate limiter needs to emit an accurate `Retry-After` header. Does NOT
     * modify the stored counter or expiry.
     *
     * @return int Seconds until reset (≥ 1 while active, 0 otherwise).
     */
    public function ttl(string $key): int;

    /**
     * Immediately evict $key regardless of its TTL.
     *
     * No-op if the key does not exist.
     */
    public function delete(string $key): void;

    /**
     * Delete all entries whose TTL has elapsed.
     *
     * Safe to call from any cron / cleanup path. Returns the number of
     * entries removed.
     *
     * @return int Number of entries removed (≥ 0).
     */
    public function prune(): int;
}
