<?php

declare(strict_types=1);

namespace DemoCatalog\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * CreateDemoCatalogItemsTable migration.
 *
 * Reference migration for the DemoCatalog plugin (WC-features-pilot): a
 * tenant-scoped table backing a minimal list/detail resource, used as the
 * pilot for extracting web/'s feature UI into a client-safe, data-source
 * agnostic shared package (see packages/features).
 *
 * Statements are idempotent (`IF NOT EXISTS`) so the migration is safe to
 * re-run, and `down()` cleanly reverts everything `up()` created.
 */
final class CreateDemoCatalogItemsTable implements MigrationInterface
{
    /**
     * Apply the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        // `DEFAULT (NOW())` (parenthesised) is valid on both PostgreSQL and
        // SQLite, matching the HelloWorld reference migration's convention.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS demo_catalog_items (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                description VARCHAR(2000),
                status VARCHAR(50) NOT NULL DEFAULT \'active\',
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                updated_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_demo_catalog_items_tenant_id ON demo_catalog_items(tenant_id)'
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
        $pdo->exec('DROP TABLE IF EXISTS demo_catalog_items');
    }
}
