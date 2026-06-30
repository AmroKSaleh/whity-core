<?php

declare(strict_types=1);

namespace Whity\Core\Store;

/**
 * In-memory SharedStoreInterface implementation for tests and dev tooling.
 *
 * State lives only in the current PHP process — NOT suitable for production
 * (FrankenPHP has multiple workers; each would maintain its own isolated
 * counter). Use DatabaseSharedStore (or RedisSharedStore once available) for
 * any workload that requires cross-worker or cross-restart persistence.
 */
final class ArraySharedStore implements SharedStoreInterface
{
    /** @var array<string, array{counter: int, expires_at: float|null}> */
    private array $entries = [];

    public function increment(string $key, int $ttlSeconds): int
    {
        $now = microtime(true);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null || ($entry['expires_at'] !== null && $entry['expires_at'] <= $now)) {
            $this->entries[$key] = [
                'counter'    => 1,
                'expires_at' => $now + $ttlSeconds,
            ];
            return 1;
        }

        $this->entries[$key]['counter']++;
        return $this->entries[$key]['counter'];
    }

    public function count(string $key): int
    {
        $now   = microtime(true);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null || ($entry['expires_at'] !== null && $entry['expires_at'] <= $now)) {
            return 0;
        }

        return $entry['counter'];
    }

    public function ttl(string $key): int
    {
        $now   = microtime(true);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null || $entry['expires_at'] === null || $entry['expires_at'] <= $now) {
            return 0;
        }

        return (int) ceil($entry['expires_at'] - $now);
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }

    public function prune(): int
    {
        $now     = microtime(true);
        $removed = 0;

        foreach (array_keys($this->entries) as $key) {
            $entry = $this->entries[$key];
            if ($entry['expires_at'] !== null && $entry['expires_at'] <= $now) {
                unset($this->entries[$key]);
                $removed++;
            }
        }

        return $removed;
    }
}
