<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * The plugin migration contract (SDK v1.0).
 *
 * A plugin declares its schema by returning migration class FQCNs from
 * {@see PluginInterface::getMigrations()}; each class implements this
 * interface. NOTE: host-side EXECUTION of plugin migrations is not wired yet —
 * it lands with the migration-runner work (whity-core #164), which will
 * instantiate the class and invoke {@see up()} / {@see down()} with a live PDO
 * connection. Until then, declared migrations are collected but not run; the
 * interface ships in 1.0 so plugin authors target the final shape now.
 *
 * Rules the host will enforce (and plugin authors must respect):
 * - Statements should be idempotent (`IF NOT EXISTS` / `IF EXISTS`) so re-runs
 *   are safe; the host additionally records applied migrations per plugin.
 * - {@see down()} must cleanly revert everything {@see up()} created.
 * - Tenant-owned tables carry a `tenant_id` column; queries against them must
 *   be tenant-scoped by the plugin's handlers.
 */
interface MigrationInterface
{
    /**
     * Apply the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function up(\PDO $pdo): void;

    /**
     * Revert the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function down(\PDO $pdo): void;
}
