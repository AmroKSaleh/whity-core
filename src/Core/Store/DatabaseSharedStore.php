<?php

declare(strict_types=1);

namespace Whity\Core\Store;

use PDO;

/**
 * PostgreSQL-backed (SQLite-compatible) SharedStoreInterface implementation.
 *
 * All writes use a single atomic UPSERT so concurrent FrankenPHP workers never
 * race on the same counter. The SQL dialect is compatible with both PostgreSQL
 * (production) and SQLite 3.35+ (integration tests via SchemaFromMigrations).
 *
 * Fixed-window semantics: the TTL is set on the first increment that creates a
 * key and is never extended by subsequent increments on the same window. Expired
 * rows are reset (counter → 1, fresh TTL) on the next increment that arrives
 * after expiry.
 */
final class DatabaseSharedStore implements SharedStoreInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        // Single atomic UPSERT:
        //   • New key → insert with counter = 1 and the supplied TTL.
        //   • Existing key, not expired → increment counter, keep original expiry.
        //   • Existing key, expired → reset counter to 1, apply fresh TTL.
        $stmt = $this->pdo->prepare("
            INSERT INTO shared_store (store_key, counter, expires_at, updated_at)
            VALUES (:key, 1, :expires_at, CURRENT_TIMESTAMP)
            ON CONFLICT (store_key) DO UPDATE SET
                counter    = CASE
                                 WHEN shared_store.expires_at IS NOT NULL
                                  AND shared_store.expires_at <= CURRENT_TIMESTAMP
                                 THEN 1
                                 ELSE shared_store.counter + 1
                             END,
                expires_at = CASE
                                 WHEN shared_store.expires_at IS NOT NULL
                                  AND shared_store.expires_at <= CURRENT_TIMESTAMP
                                 THEN EXCLUDED.expires_at
                                 ELSE shared_store.expires_at
                             END,
                updated_at = CURRENT_TIMESTAMP
            RETURNING counter
        ");
        $stmt->execute([':key' => $key, ':expires_at' => $expiresAt]);

        $row = $stmt->fetch(PDO::FETCH_NUM);
        return (int) ($row[0] ?? 1);
    }

    public function count(string $key): int
    {
        $stmt = $this->pdo->prepare("
            SELECT counter
              FROM shared_store
             WHERE store_key = :key
               AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
        ");
        $stmt->execute([':key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row === false ? 0 : (int) $row[0];
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM shared_store WHERE store_key = :key');
        $stmt->execute([':key' => $key]);
    }

    public function prune(): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM shared_store
             WHERE expires_at IS NOT NULL
               AND expires_at <= CURRENT_TIMESTAMP
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
