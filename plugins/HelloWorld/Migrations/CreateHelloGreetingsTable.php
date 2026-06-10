<?php

declare(strict_types=1);

namespace HelloWorld\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * CreateHelloGreetingsTable migration
 *
 * Reference migration for the HelloWorld plugin, implementing the SDK
 * migration contract ({@see MigrationInterface}, WC-162): instance `up()` /
 * `down()` methods receiving a live PDO connection. The class FQCN is returned
 * from {@see \HelloWorld\HelloWorldPlugin::getMigrations()} so the platform
 * migration runner can apply it.
 *
 * The statements are idempotent (`IF NOT EXISTS`) so the migration is safe to
 * re-run, and `down()` cleanly reverts everything `up()` created.
 */
final class CreateHelloGreetingsTable implements MigrationInterface
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
            CREATE TABLE IF NOT EXISTS hello_greetings (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                message VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS idx_hello_greetings_tenant_id ON hello_greetings(tenant_id)'
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
        $pdo->exec('DROP TABLE IF EXISTS hello_greetings');
    }
}
