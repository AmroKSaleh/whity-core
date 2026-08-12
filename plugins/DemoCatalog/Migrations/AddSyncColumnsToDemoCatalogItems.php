<?php

declare(strict_types=1);

namespace DemoCatalog\Migrations;

use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Schema\MigrationSchema;

/**
 * AddSyncColumnsToDemoCatalogItems (WC-desktop-sync).
 *
 * Turns the pilot's tenant-scoped CRUD table into a two-way-syncable resource by
 * adding the columns an offline-first client needs to reconcile against the
 * server:
 *   - `version`      server-authoritative revision, bumped on every write —
 *                    powers optimistic-concurrency (If-Match / baseVersion → 409);
 *   - `client_uuid`  stable id an offline-created row already has before it earns
 *                    a server SERIAL id — unique PER TENANT, so create is idempotent
 *                    on retry;
 *   - `deleted_at`   soft-delete tombstone, so deletions propagate on pull;
 *   - `updated_by`   acting profile id (attribution for the conflict resolver);
 *   - `change_seq`   the row's position in a GLOBAL monotonic change sequence,
 *                    stamped on every write — the incremental-pull cursor. A
 *                    clock-skew-immune integer, unlike `updated_at`.
 *
 * The sequence source is a one-row global counter table `demo_catalog_change_seq`
 * (holds no tenant data — declared global in the conformance registry). Every
 * write bumps it and stamps the row, so a per-tenant `change_seq > :cursor` feed
 * is a correct incremental pull. Known limitation (documented): under concurrent
 * long transactions a higher seq can commit before a lower one, so a reader could
 * skip the late-committing lower seq; mitigated by stamping seq inside the short
 * write transaction. A fully rigorous log would use commit-ordered capture — out
 * of scope for this pilot.
 *
 * Idempotent + SQLite/Postgres-safe: each column is DECLARED through
 * {@see MigrationSchema::addColumnIfMissing()} rather than guarded by a
 * hand-written dialect branch (so re-runs are no-ops on either engine),
 * defaults are constants (valid for SQLite ALTER), and `client_uuid` is
 * backfilled per-row in PHP before its unique index.
 *
 * This migration used to carry its own private `columnExists()` — the
 * `information_schema` / `PRAGMA table_info` branch every plugin author ends up
 * writing. Its PostgreSQL query filtered on `table_name` alone with no schema
 * predicate, so it would have answered for a same-named table in ANY schema.
 * The SDK helper constrains the lookup to the connection's own search path.
 */
final class AddSyncColumnsToDemoCatalogItems implements MigrationInterface
{
    use MigrationSchema;

    /** @var array<string, string> column => SQL definition (constant defaults only). */
    private const COLUMNS = [
        'version'     => 'INTEGER NOT NULL DEFAULT 1',
        'client_uuid' => 'VARCHAR(36)',
        'deleted_at'  => 'TIMESTAMP NULL',
        'updated_by'  => 'INTEGER',
        'change_seq'  => 'BIGINT NOT NULL DEFAULT 0',
    ];

    public function up(\PDO $pdo): void
    {
        foreach (self::COLUMNS as $column => $definition) {
            $this->addColumnIfMissing($pdo, 'demo_catalog_items', $column, $definition);
        }

        // Backfill a stable uuid for every pre-existing row before the unique index.
        $idStmt = $pdo->query('SELECT id FROM demo_catalog_items WHERE client_uuid IS NULL');
        $ids = $idStmt === false ? [] : $idStmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($ids !== []) {
            $update = $pdo->prepare(
                'UPDATE demo_catalog_items SET client_uuid = :uuid WHERE id = :id AND client_uuid IS NULL'
            );
            foreach ($ids as $id) {
                $update->execute([':uuid' => self::uuid4(), ':id' => (int) $id]);
            }
        }

        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_demo_catalog_items_tenant_uuid
             ON demo_catalog_items(tenant_id, client_uuid)'
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_demo_catalog_items_tenant_seq
             ON demo_catalog_items(tenant_id, change_seq)'
        );

        // Global monotonic change-sequence counter (one row; holds no tenant data).
        $pdo->exec('CREATE TABLE IF NOT EXISTS demo_catalog_change_seq (seq BIGINT NOT NULL)');
        $countStmt = $pdo->query('SELECT COUNT(*) FROM demo_catalog_change_seq');
        $seeded = $countStmt === false ? 0 : (int) $countStmt->fetchColumn();
        if ($seeded === 0) {
            $pdo->exec('INSERT INTO demo_catalog_change_seq (seq) VALUES (0)');
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS demo_catalog_change_seq');
        $pdo->exec('DROP INDEX IF EXISTS idx_demo_catalog_items_tenant_uuid');
        $pdo->exec('DROP INDEX IF EXISTS idx_demo_catalog_items_tenant_seq');
        // Postgres has DROP COLUMN IF EXISTS; SQLite >= 3.35 has DROP COLUMN but
        // no IF EXISTS for it. The helper closes that gap.
        foreach (array_keys(self::COLUMNS) as $column) {
            $this->dropColumnIfExists($pdo, 'demo_catalog_items', $column);
        }
    }

    /** RFC-4122 v4 UUID (no ext dependency). */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
