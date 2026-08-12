<?php

declare(strict_types=1);

namespace DemoCatalog\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * CreateDemoCatalogItemLinesTable (WC-723 Door 2 reference — composition).
 *
 * A third tenant-scoped plugin table, and the OTHER half of the reference
 * implementation's delete story. `demo_catalog_item_notes` holds rows that must
 * OUTLIVE an item, so the item's declaration names them in `blocks_delete`.
 * These rows are the opposite: a line is PART of its item, meaningless without
 * it, and the item's declaration names this table in `cascade_delete`.
 *
 * Both halves have to exist in the reference implementation, because the point
 * an adopter has to take away is that neither is inferable from the schema.
 * These two tables are shaped identically — a tenant column, an `item_id`, some
 * payload — and they must be handled in opposite ways. Only the plugin knows
 * which is which, which is why both are declared.
 *
 * There is deliberately NO foreign key, the same convention the notes table
 * follows and the exact reason the cascade has to be declared: nothing at the
 * database level would ever remove these rows when their item goes, so before
 * `cascade_delete` existed they were silently orphaned by a delete that
 * reported success.
 *
 * No `status` column: a line has no lifecycle of its own. It exists exactly as
 * long as its item does, which is what being part of something means.
 */
final class CreateDemoCatalogItemLinesTable implements MigrationInterface
{
    /**
     * Apply the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS demo_catalog_item_lines (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                description VARCHAR(500) NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_demo_catalog_item_lines_tenant_item
             ON demo_catalog_item_lines(tenant_id, item_id)'
        );
    }

    /**
     * Revert the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS demo_catalog_item_lines');
    }
}
