<?php

declare(strict_types=1);

namespace Taxonomy\Migrations;

use PDO;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Schema\MigrationSchema;

/**
 * Creates (offline host) or ADOPTS (server, at cutover) the `tag_groups` and
 * `tags` tables as two-way-syncable resources for the Taxonomy plugin — the
 * Relations pattern (ADR 0003 / desktop feature-parity effort).
 *
 * Strangler-fig aware: on the desktop offline host neither table exists, so
 * `CREATE TABLE IF NOT EXISTS` builds them fresh; on the server, core migration
 * 063 already created them, so the CREATE no-ops and {@see MigrationSchema::
 * addColumnIfMissing()} augments the EXISTING tables with the sync columns
 * {@see \Whity\Sdk\Sync\SyncController} needs.
 *
 * Two offline-compat choices, both lessons from the Relations port:
 *  - `DEFAULT (NOW())` is PARENTHESISED — SQLite's DDL only accepts a function
 *    in a DEFAULT when wrapped in parens (the offline SqliteCompatPdo registers
 *    NOW() as a UDF). Bare `DEFAULT NOW()` crash-loops the offline host.
 *  - the fresh offline `display_name` is `TEXT` (a JSON string), NOT `JSONB`:
 *    core uses JSONB on Postgres, but the offline SQLite engine has no JSONB and
 *    stores the localized `{ar,en}` object as text either way. The CREATE only
 *    runs offline (server's JSONB column is untouched — IF NOT EXISTS no-ops).
 * Both are caught by {@see \Tests\Plugins\TaxonomyPluginOfflineConformanceTest}.
 */
final class CreateTaxonomyTables implements MigrationInterface
{
    use MigrationSchema;

    /**
     * @var array<string, string> sync column => SQL definition (constant defaults only).
     *
     * `updated_at` is NOT here — core's `tag_groups`/`tags` (migration 063)
     * already carry it (unlike core `persons`), so only the five sync-specific
     * columns are augmented onto the adopted tables.
     */
    private const SYNC_COLUMNS = [
        'version'     => 'INTEGER NOT NULL DEFAULT 1',
        'client_uuid' => 'VARCHAR(36)',
        'deleted_at'  => 'TIMESTAMP NULL',
        'updated_by'  => 'INTEGER',
        'change_seq'  => 'BIGINT NOT NULL DEFAULT 0',
    ];

    public function up(PDO $pdo): void
    {
        // Fresh build for the offline host; a no-op where core already owns them.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS tag_groups (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                group_key VARCHAR(64) NOT NULL,
                display_name TEXT NOT NULL DEFAULT '{}',
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                updated_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS tags (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                name VARCHAR(128) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                updated_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )"
        );

        foreach (['tag_groups', 'tags'] as $table) {
            foreach (self::SYNC_COLUMNS as $column => $definition) {
                $this->addColumnIfMissing($pdo, $table, $column, $definition);
            }

            // Backfill a stable uuid for every pre-existing row before the unique index.
            $idStmt = $pdo->query("SELECT id FROM {$table} WHERE client_uuid IS NULL");
            $ids = $idStmt === false ? [] : $idStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($ids !== []) {
                $update = $pdo->prepare("UPDATE {$table} SET client_uuid = :uuid WHERE id = :id AND client_uuid IS NULL");
                foreach ($ids as $id) {
                    $update->execute([':uuid' => self::uuid4(), ':id' => (int) $id]);
                }
            }

            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_{$table}_tenant_uuid ON {$table}(tenant_id, client_uuid)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_tenant_seq ON {$table}(tenant_id, change_seq)");
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (['tag_groups', 'tags'] as $table) {
            $pdo->exec("DROP INDEX IF EXISTS idx_{$table}_tenant_uuid");
            $pdo->exec("DROP INDEX IF EXISTS idx_{$table}_tenant_seq");
            foreach (array_keys(self::SYNC_COLUMNS) as $column) {
                $this->dropColumnIfExists($pdo, $table, $column);
            }
        }
        // The base tables are intentionally NOT dropped: on the server they are
        // core-owned (dropping would take live data); a fresh offline host
        // re-creates them on next up().
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
