<?php

declare(strict_types=1);

namespace Documents\Migrations;

use PDO;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Schema\MigrationSchema;

/**
 * Adopts (server, at cutover) or CREATES (desktop offline host) the Document
 * Designer`s `document_templates` and `document_blocks` tables as two-way-
 * syncable resources -- the Taxonomy adopt-and-augment pattern (ADR 0003 /
 * desktop feature-parity effort).
 *
 * Strangler-fig aware: on the server, core migration 059 already created both
 * tables, so `CREATE TABLE IF NOT EXISTS` no-ops and {@see MigrationSchema::
 * addColumnIfMissing()} only augments them with the sync columns
 * {@see \Whity\Sdk\Sync\SyncController} needs; on the empty offline host neither
 * table exists, so the CREATE builds them fresh.
 *
 * The CREATE therefore ONLY ever executes on the offline SQLite engine (on the
 * server it no-ops, so its DDL is never parsed). It is written for THAT engine,
 * using the exact SQLite-safe forms the Taxonomy/Relations ports proved out --
 * because the offline shim (RealEnginePdo/SqliteCompatPdo) rewrites almost
 * nothing:
 *  - `DEFAULT (NOW())` is PARENTHESISED -- the offline engine only accepts a
 *    function default when wrapped in parens (NOW() is a registered UDF). A bare
 *    `DEFAULT NOW()` crash-loops the offline host (the Relations incident).
 *  - `id SERIAL PRIMARY KEY` (NOT core`s `BIGSERIAL`): the offline shim rewrites
 *    only `SERIAL PRIMARY KEY` -> `INTEGER PRIMARY KEY` (the autoincrement rowid
 *    alias); a `BIGSERIAL` would survive as a non-autoincrement column. The
 *    server keeps its BIGSERIAL (this CREATE no-ops there).
 *  - `data` is `TEXT` (NOT core`s `JSONB`): the offline engine has no JSONB; the
 *    payload is stored as JSON text either way (the resource json-encodes it), so
 *    TEXT builds without needing a JSONB->TEXT shim rule. The server keeps JSONB.
 *  - `is_system BOOLEAN NOT NULL DEFAULT 0` (integer literal, not `FALSE`) so the
 *    default parses on the offline engine regardless of boolean-keyword support.
 * All of this is proven by {@see \Tests\Plugins\DocumentsPluginOfflineConformanceTest}.
 */
final class AugmentDocumentDesignerTables implements MigrationInterface
{
    use MigrationSchema;

    /**
     * @var array<string, string> sync column => SQL definition (constant defaults only).
     *
     * `created_at`/`updated_at` are NOT here -- both fresh CREATEs already carry
     * them (and core`s tables do too), so only the five sync-specific columns are
     * augmented onto the adopted tables (Taxonomy`s exact set).
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
        // One literal CREATE per table (offline fresh build; a no-op where core
        // already owns them). The FK to tenants(id) is intentionally dropped from
        // the fresh offline build -- the offline host carries no tenants table.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS document_templates (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                data TEXT NOT NULL,
                scope VARCHAR(16) NOT NULL DEFAULT 'personal',
                required_permission VARCHAR(128),
                is_system BOOLEAN NOT NULL DEFAULT 0,
                created_by BIGINT,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                updated_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS document_blocks (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                data TEXT NOT NULL,
                scope VARCHAR(16) NOT NULL DEFAULT 'personal',
                required_permission VARCHAR(128),
                is_system BOOLEAN NOT NULL DEFAULT 0,
                created_by BIGINT,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                updated_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )"
        );

        foreach (['document_templates', 'document_blocks'] as $table) {
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
        foreach (['document_templates', 'document_blocks'] as $table) {
            $pdo->exec("DROP INDEX IF EXISTS idx_{$table}_tenant_uuid");
            $pdo->exec("DROP INDEX IF EXISTS idx_{$table}_tenant_seq");
            foreach (array_keys(self::SYNC_COLUMNS) as $column) {
                $this->dropColumnIfExists($pdo, $table, $column);
            }
        }
        // The base tables are intentionally NOT dropped: on the server they are
        // core-owned (dropping would take live data); a fresh offline host
        // re-creates them on the next up().
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
