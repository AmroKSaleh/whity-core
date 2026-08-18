<?php

declare(strict_types=1);

namespace Relations\Migrations;

use PDO;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Schema\MigrationSchema;

/**
 * Creates (offline host) or ADOPTS (server, at cutover) the `persons` graph-node
 * table as a two-way-syncable resource for the Relations plugin.
 *
 * Strangler-fig aware: on the desktop offline host there is no core `persons`
 * table, so `CREATE TABLE IF NOT EXISTS` builds it fresh; on the server (and in
 * the whity-core test schema) core migration 018/041 already created it, so the
 * CREATE no-ops and {@see MigrationSchema::addColumnIfMissing()} augments the
 * EXISTING table with the sync columns — the same set DemoCatalog carries
 * ({@see \Whity\Sdk\Sync\SyncController}). This is what lets the plugin take over
 * the live table at cutover without moving a single row.
 *
 * `profile_id` is intentionally NOT built here. On the server the column is
 * core-owned (migration 041 rekeyed persons to profiles and owns its unique
 * constraint), so this migration never touches it; on the offline host there is
 * no `profiles` table to reference, and slice 1's PersonResource neither reads
 * nor writes it. The profile-linkage slice adds the column offline — with the
 * correct declared reference — when it is actually used.
 */
final class CreatePersonsTable implements MigrationInterface
{
    use MigrationSchema;

    /**
     * @var array<string, string> sync column => SQL definition (constant defaults only).
     *
     * `updated_at` is here (not just in the CREATE) because core's `persons`
     * (migration 018) only has `created_at` — the adopted table must gain
     * `updated_at`, which {@see \Whity\Sdk\Sync\SyncController} stamps on writes.
     */
    private const SYNC_COLUMNS = [
        'version'     => 'INTEGER NOT NULL DEFAULT 1',
        'client_uuid' => 'VARCHAR(36)',
        'deleted_at'  => 'TIMESTAMP NULL',
        'updated_by'  => 'INTEGER',
        'change_seq'  => 'BIGINT NOT NULL DEFAULT 0',
        'updated_at'  => 'TIMESTAMP NULL',
    ];

    public function up(PDO $pdo): void
    {
        // Fresh build for the offline host; a no-op where core already owns it.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS persons (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                display_name VARCHAR(255) NOT NULL,
                birth_date DATE NULL,
                deceased BOOLEAN NOT NULL DEFAULT false,
                notes TEXT NULL,
                -- DEFAULT (NOW()) parenthesised is valid on BOTH PostgreSQL and
                -- SQLite: the offline host SqliteCompatPdo registers NOW() as a
                -- UDF, but SQLite DDL only accepts a function in a DEFAULT when
                -- wrapped in parens. Bare DEFAULT NOW() throws a syntax error
                -- offline, and this fresh CREATE only runs there (on the server,
                -- core migration 018 already owns persons, so IF NOT EXISTS is a
                -- no-op there, which is why no PG/SQLite test with core persons
                -- ever exercised this line).
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                updated_at TIMESTAMP NULL
            )'
        );

        foreach (self::SYNC_COLUMNS as $column => $definition) {
            $this->addColumnIfMissing($pdo, 'persons', $column, $definition);
        }

        // Backfill a stable uuid for every pre-existing row before the unique index.
        $idStmt = $pdo->query('SELECT id FROM persons WHERE client_uuid IS NULL');
        $ids = $idStmt === false ? [] : $idStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($ids !== []) {
            $update = $pdo->prepare('UPDATE persons SET client_uuid = :uuid WHERE id = :id AND client_uuid IS NULL');
            foreach ($ids as $id) {
                $update->execute([':uuid' => self::uuid4(), ':id' => (int) $id]);
            }
        }

        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_persons_tenant_uuid ON persons(tenant_id, client_uuid)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_persons_tenant_seq ON persons(tenant_id, change_seq)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX IF EXISTS idx_persons_tenant_uuid');
        $pdo->exec('DROP INDEX IF EXISTS idx_persons_tenant_seq');
        foreach (array_keys(self::SYNC_COLUMNS) as $column) {
            $this->dropColumnIfExists($pdo, 'persons', $column);
        }
        // The base `persons` table is intentionally NOT dropped here: on the
        // server it is core-owned, and dropping it would take the live data with
        // it. A fresh offline host simply re-creates it on next up().
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
