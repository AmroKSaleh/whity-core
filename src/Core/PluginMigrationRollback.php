<?php

declare(strict_types=1);

namespace Whity\Core;

use PDO;

/**
 * Reusable service for rolling back plugin-declared migrations.
 *
 * Plugin migrations are tracked in core_schema_migrations under the naming
 * convention `plugin:<PluginName>:<ClassName>`.  This service locates all
 * matching rows for a given plugin name, removes them in reverse execution
 * order (id DESC), and returns a structured result. It does NOT call
 * disablePlugin — callers are responsible for lifecycle transitions.
 *
 * NOTE: Because plugin classes may be unavailable at uninstall time (the
 * plugin directory is about to be removed), this service only deletes the
 * tracking rows — it does not attempt to call the migration's down() method.
 * Schema cleanup is the plugin author's responsibility (documentation contract).
 */
class PluginMigrationRollback
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Return the tracking names of all executed migrations for the given plugin,
     * in reverse execution order (most-recently-run first).
     *
     * @param string $pluginName The plugin's declared name (case-sensitive).
     * @return list<string>
     */
    public function listMigrationsForPlugin(string $pluginName): array
    {
        $prefix = 'plugin:' . $pluginName . ':';

        try {
            $stmt = $this->pdo->prepare(
                'SELECT migration_name FROM core_schema_migrations
                 WHERE migration_name LIKE ?
                 ORDER BY id DESC'
            );
            $stmt->execute([$prefix . '%']);

            /** @var list<string> $names */
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return $names;
        } catch (\PDOException) {
            return [];
        }
    }

    /**
     * Delete all migration tracking rows for the plugin (in reverse id order).
     *
     * Returns a structured result so the caller can report what happened or
     * surface errors to the user.
     *
     * @param string $pluginName The plugin's declared name (case-sensitive).
     * @return array{rolled_back: list<string>, errors: list<string>}
     */
    public function rollback(string $pluginName): array
    {
        $names = $this->listMigrationsForPlugin($pluginName);
        $rolledBack = [];
        $errors = [];

        foreach ($names as $name) {
            try {
                $stmt = $this->pdo->prepare(
                    'DELETE FROM core_schema_migrations WHERE migration_name = ?'
                );
                $stmt->execute([$name]);
                $rolledBack[] = $name;
            } catch (\PDOException $e) {
                $errors[] = "Failed to remove tracking row '{$name}': " . $e->getMessage();
            }
        }

        return ['rolled_back' => $rolledBack, 'errors' => $errors];
    }
}
