<?php

declare(strict_types=1);

namespace HelloWorld\Migrations;

use Whity\Database\Database;

/**
 * CreateHelloGreetingsTable migration
 *
 * Reference migration for the HelloWorld plugin. Plugin migrations follow the
 * same shape as the core migrations under `database/migrations/`: a class with
 * static `up()` and `down()` methods that each receive a {@see Database}
 * instance. The class FQCN is returned from
 * {@see \HelloWorld\HelloWorldPlugin::getMigrations()} so the platform
 * migration runner can apply it.
 *
 * The statements are idempotent (`IF NOT EXISTS`) so the migration is safe to
 * re-run.
 */
final class CreateHelloGreetingsTable
{
    /**
     * Apply the migration.
     *
     * @param Database $db The database abstraction.
     * @return void
     */
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS hello_greetings (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                message VARCHAR(255) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_hello_greetings_tenant_id ON hello_greetings(tenant_id)'
        );
    }

    /**
     * Revert the migration.
     *
     * @param Database $db The database abstraction.
     * @return void
     */
    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS hello_greetings');
    }
}
