<?php

declare(strict_types=1);

namespace DemoCatalog\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * CreateDemoCatalogItemNotesTable (WC-723 Door 2 reference).
 *
 * A second, tenant-scoped plugin table whose rows POINT AT `demo_catalog_items`.
 * It exists so the reference implementation has a real reference graph to
 * declare: without a referencing table, `blocks_delete` would be a declaration
 * with nothing to guard and the guard could never be shown to actually refuse.
 *
 * There is deliberately NO foreign key — that is the established convention
 * between plugin tables here, and it is precisely why the referential guard has
 * to exist at all: nothing at the database level stops the parent row being
 * deleted out from under these.
 *
 * `status` carries the note's own lifecycle so the reference declaration can
 * demonstrate `ignore_when`: a note that is itself trashed must not pin its item
 * alive, or a guard becomes a leak rather than a protection.
 */
final class CreateDemoCatalogItemNotesTable implements MigrationInterface
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
            CREATE TABLE IF NOT EXISTS demo_catalog_item_notes (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                body VARCHAR(2000) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\',
                created_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_demo_catalog_item_notes_tenant_item
             ON demo_catalog_item_notes(tenant_id, item_id)'
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
        $pdo->exec('DROP TABLE IF EXISTS demo_catalog_item_notes');
    }
}
