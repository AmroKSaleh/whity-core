<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-91f2: shared cross-worker key-counter store.
 *
 * Creates the `shared_store` table used by rate-limiters, brute-force
 * protection, and other primitives that need atomic counters persisted across
 * FrankenPHP persistent workers. This is a SANCTIONED GLOBAL table — rows are
 * platform-wide (no tenant_id column); see SanctionedGlobalTables.
 *
 * Schema notes:
 *   - store_key PRIMARY KEY: uniqueness is enforced at DB level so the UPSERT
 *     in DatabaseSharedStore::increment() is truly atomic.
 *   - counter BIGINT: supports very high-volume rate-limit windows without
 *     overflow (max ~9.2 × 10^18 increments per key/window).
 *   - expires_at TIMESTAMP (nullable): NULL means the entry never expires.
 *     DatabaseSharedStore uses a fixed-window TTL so this is always set.
 *   - idx_shared_store_expires_at: backs the prune() DELETE and count() WHERE
 *     clause that filters out expired rows.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateSharedStore
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS shared_store (
                store_key  VARCHAR(255) NOT NULL PRIMARY KEY,
                counter    BIGINT       NOT NULL DEFAULT 0,
                expires_at TIMESTAMP,
                updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_shared_store_expires_at
                ON shared_store (expires_at)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS shared_store');
    }
}
