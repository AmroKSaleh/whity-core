<?php

declare(strict_types=1);

namespace DemoCatalog\Migrations;

use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Schema\MigrationSchema;

/**
 * Drops `demo_catalog_change_seq` — the plugin's own change-feed counter, now
 * that the host allocates the number.
 *
 * What was wrong with owning it
 * -----------------------------
 * {@see AddSyncColumnsToDemoCatalogItems} created a one-row global counter and
 * the handler advanced it through a driver branch:
 *
 *     pgsql:  UPDATE demo_catalog_change_seq SET seq = seq + 1 RETURNING seq
 *     sqlite: UPDATE demo_catalog_change_seq SET seq = seq + 1
 *             SELECT seq FROM demo_catalog_change_seq
 *
 * The SQLite half is a read-then-write across two statements. Two concurrent
 * writers can interleave between them and both read the same `seq`, so two rows
 * get stamped with one cursor — and an incremental pull, which asks for
 * `change_seq > :cursor`, then returns one of those rows and never the other.
 * For a sync feed that is silent data loss rather than a visible duplicate.
 *
 * What replaced it
 * ----------------
 * {@see \Whity\Sdk\Sql\SequenceAllocator}, resolved from the host's container.
 * The plugin now ships NO table, NO migration and NO SQL for numbering — the
 * whole concern is a declaration that it wants a number. The allocation is one
 * statement with no interval for two callers to observe the same value; the
 * host proves that with two live connections rather than asserting it.
 *
 * Why a separate migration
 * ------------------------
 * `AddSyncColumnsToDemoCatalogItems` has already been applied and RECORDED on
 * any deployment that ran it, so editing its `up()` to stop creating the table
 * would change what a FRESH install does and nothing at all on an existing one.
 * Retirement is a new forward step, exactly like any other schema change.
 *
 * Reversal
 * --------
 * `down()` recreates the table, seeded at 0, so a rollback leaves the schema as
 * the previous migration left it. It does NOT restore the old counter VALUE —
 * nothing has read it since this migration ran, and the feed cursor only has to
 * be monotonic, not continuous. A client holding an old cursor re-reads rows it
 * already has, which the feed is idempotent about.
 */
final class RetireDemoCatalogChangeSeqTable implements MigrationInterface
{
    use MigrationSchema;

    /**
     * Apply the migration.
     */
    public function up(\PDO $pdo): void
    {
        // `DROP TABLE IF EXISTS` parses on both engines, so this needs no
        // existence check — but asking first keeps the migration honest about
        // whether it had anything to do, which is what makes a re-run a no-op
        // rather than a silent one.
        if ($this->tableExists($pdo, 'demo_catalog_change_seq')) {
            $pdo->exec('DROP TABLE IF EXISTS demo_catalog_change_seq');
        }
    }

    /**
     * Revert the migration.
     */
    public function down(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS demo_catalog_change_seq (seq BIGINT NOT NULL)');

        $countStmt = $pdo->query('SELECT COUNT(*) FROM demo_catalog_change_seq');
        $seeded = $countStmt === false ? 0 : (int) $countStmt->fetchColumn();
        if ($seeded === 0) {
            $pdo->exec('INSERT INTO demo_catalog_change_seq (seq) VALUES (0)');
        }
    }
}
