<?php

namespace Whity\Cli\Commands;

use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Database\Database;
use Whity\Sdk\MigrationInterface;
use PDO;

/**
 * Migrations CLI Command
 *
 * Handles database migrations directly without requiring authentication.
 * This is the secure way to run migrations during deployment/setup.
 *
 * Two migration sources are executed (WC-164):
 *  - CORE migrations: numbered files under database/migrations with static
 *    up()/down() receiving the {@see Database} wrapper (unchanged behaviour).
 *  - PLUGIN migrations: classes declared via PluginInterface::getMigrations()
 *    implementing {@see \Whity\Sdk\MigrationInterface} (instance up()/down()
 *    over PDO). Each runs inside an explicit transaction together with its
 *    tracking row, and is recorded in core_schema_migrations under the
 *    per-plugin namespace `plugin:<PluginName>:<MigrationClass>`.
 *
 * Usage:
 *   php public/index.php migrate status  - Show migration status
 *   php public/index.php migrate run     - Run pending migrations
 *   php public/index.php migrate rollback - Rollback last migration
 */
class MigrationsCommand
{
    /** Tracking-name prefix for plugin-declared migrations (WC-164). */
    private const PLUGIN_PREFIX = 'plugin:';

    private Database $db;
    private string $migrationDir;
    private ?PluginLoader $pluginLoader;
    private bool $pluginsLoaded = false;

    /**
     * @param Database|null $db Injected connection (tests); defaults to Database::connect().
     * @param PluginLoader|null $pluginLoader Injected loader (tests); defaults to a loader over /plugins.
     * @param string|null $migrationDir Injected core-migration directory (tests).
     */
    public function __construct(
        private ?Database $injectedDb = null,
        ?PluginLoader $pluginLoader = null,
        private ?string $injectedMigrationDir = null
    ) {
        $this->pluginLoader = $pluginLoader;
    }

    public function execute(array $argv): int
    {
        try {
            $this->db = $this->injectedDb ?? Database::connect();
            $baseDir = dirname(__DIR__, 3);
            $this->migrationDir = $this->injectedMigrationDir ?? $baseDir . '/database/migrations';

            $action = array_shift($argv) ?: 'status';

            return match ($action) {
                'status' => $this->status(),
                'run' => $this->run(),
                'rollback' => $this->rollback(),
                '--help', '-h', 'help' => $this->showHelp(),
                default => $this->unknownAction($action),
            };
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Error: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Show migration status
     */
    private function status(): int
    {
        try {
            $executed = $this->getExecutedMigrations();
            $files = $this->getMigrationFiles();

            $pending = 0;
            $migrations = [];

            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $isExecuted = isset($executed[$name]);

                if (!$isExecuted) {
                    $pending++;
                }

                $migrations[] = [
                    $name,
                    $isExecuted ? 'Executed' : 'Pending',
                    $isExecuted ? ($executed[$name]['executed_at'] ?? 'N/A') : 'N/A'
                ];
            }

            // Plugin-declared migrations (WC-164), listed after core ones.
            foreach ($this->pluginMigrations() as $entry) {
                $isExecuted = isset($executed[$entry['record']]);

                if (!$isExecuted) {
                    $pending++;
                }

                $migrations[] = [
                    $entry['record'],
                    $isExecuted ? 'Executed' : 'Pending',
                    $isExecuted ? ($executed[$entry['record']]['executed_at'] ?? 'N/A') : 'N/A'
                ];
            }

            echo "\n\033[1;33mMigration Status\033[0m\n";
            $this->renderTable(['Migration', 'Status', 'Executed At'], $migrations);

            if ($pending === 0) {
                echo "\n\033[0;32m✓ All migrations have been executed\033[0m\n";
            } else {
                echo "\n\033[1;33m⚠ $pending pending migration(s)\033[0m\n";
            }

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Failed to get migration status: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Run pending migrations
     */
    private function run(): int
    {
        try {
            // Ensure the migration tracking table exists BEFORE running any
            // migration. Without this, the earliest migrations (which run before
            // the table-creating migration) execute successfully but their
            // tracking rows are silently dropped, breaking idempotency and
            // preventing them from ever being rolled back.
            $this->ensureMigrationTable();

            $executed = $this->getExecutedMigrations();
            $files = $this->getMigrationFiles();
            $count = 0;

            echo "\n\033[1;33mRunning migrations...\033[0m\n";

            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (!isset($executed[$name])) {
                    echo "  Running: $name... ";
                    $this->executeMigration($file, 'up');
                    echo "\033[0;32m✓\033[0m\n";
                    $count++;
                }
            }

            // Plugin-declared migrations (WC-164) run after core migrations,
            // in plugin load order, each inside its own transaction.
            foreach ($this->pluginMigrations() as $entry) {
                if (!isset($executed[$entry['record']])) {
                    echo "  Running: {$entry['record']}... ";
                    $this->executePluginMigration($entry, 'up');
                    echo "\033[0;32m✓\033[0m\n";
                    $count++;
                }
            }

            echo "\n";
            if ($count === 0) {
                echo "\033[0;32m✓ All migrations already executed\033[0m\n";
            } else {
                echo "\033[0;32m✓ Successfully ran $count migration(s)\033[0m\n";
            }

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Migration failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Rollback last migration
     */
    private function rollback(): int
    {
        try {
            $stmt = $this->db->getPdo()->prepare('
                SELECT migration_name FROM core_schema_migrations
                ORDER BY executed_at DESC LIMIT 1
            ');
            $stmt->execute();
            $last = $stmt->fetch();
            // Release the read cursor: an open SELECT on core_schema_migrations
            // would block the in-transaction DELETE on the same connection
            // (SQLite reports "database table is locked").
            $stmt->closeCursor();

            if (!$last) {
                echo "\n\033[1;33m⚠ No migrations to rollback\033[0m\n\n";
                return 0;
            }

            $name = $last['migration_name'];

            // Plugin migration (WC-164): resolve the class through the loaded
            // plugins instead of a file under database/migrations.
            if (str_starts_with($name, self::PLUGIN_PREFIX)) {
                $entry = $this->findPluginMigrationByRecord($name);
                if ($entry === null) {
                    throw new \Exception(
                        "Cannot roll back {$name}: the declaring plugin is not installed/loaded"
                    );
                }

                echo "\n\033[1;33mRolling back: $name... \033[0m";
                $this->executePluginMigration($entry, 'down');
                echo "\033[0;32m✓\033[0m\n\n";
                echo "\033[0;32m✓ Successfully rolled back $name\033[0m\n\n";

                return 0;
            }

            $file = $this->migrationDir . '/' . $name . '.php';

            if (!file_exists($file)) {
                throw new \Exception("Migration file {$name}.php not found");
            }

            echo "\n\033[1;33mRolling back: $name... \033[0m";
            $this->executeMigration($file, 'down');
            echo "\033[0;32m✓\033[0m\n\n";

            echo "\033[0;32m✓ Successfully rolled back $name\033[0m\n\n";

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Rollback failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Get list of executed migrations from database
     */
    private function getExecutedMigrations(): array
    {
        try {
            $stmt = $this->db->getPdo()->prepare('SELECT migration_name, executed_at FROM core_schema_migrations');
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $executed = [];
            foreach ($results as $row) {
                $executed[$row['migration_name']] = $row;
            }
            return $executed;
        } catch (\PDOException $e) {
            // If the migrations table doesn't exist yet, return empty array
            if (strpos($e->getMessage(), 'core_schema_migrations') !== false) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Ensure the core_schema_migrations tracking table exists.
     *
     * This is created idempotently (IF NOT EXISTS) before any migration runs
     * so that every migration — including those ordered before the migration
     * that would otherwise create this table — is correctly recorded. Migration
     * 005 also creates this table with the same definition, so running it later
     * is a harmless no-op.
     */
    private function ensureMigrationTable(): void
    {
        // DEFAULT CURRENT_TIMESTAMP is standard SQL: identical to NOW() on
        // PostgreSQL and — unlike NOW() — also parseable by SQLite, which the
        // real-engine runner tests use (SQLite parses the full DDL even when
        // IF NOT EXISTS makes it a no-op).
        $this->db->exec('
            CREATE TABLE IF NOT EXISTS core_schema_migrations (
                id SERIAL PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                execution_time_ms INTEGER
            )
        ');
    }

    /**
     * Get list of migration files from directory
     */
    private function getMigrationFiles(): array
    {
        $files = scandir($this->migrationDir);
        $migrationFiles = [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $migrationFiles[] = $this->migrationDir . '/' . $file;
            }
        }
        sort($migrationFiles);
        return $migrationFiles;
    }

    /**
     * Execute a migration file
     */
    private function executeMigration(string $file, string $direction): void
    {
        require_once $file;
        $name = pathinfo($file, PATHINFO_FILENAME);

        // Extract class name (e.g., 001_create_users -> CreateUsers)
        $parts = explode('_', $name);
        array_shift($parts); // Remove prefix number
        $className = 'Database\\Migrations\\' . implode('', array_map('ucfirst', $parts));

        if (!class_exists($className)) {
            throw new \Exception("Migration class {$className} not found in {$file}");
        }

        $start = microtime(true);

        if ($direction === 'up') {
            $className::up($this->db);

            // Record the migration. ON CONFLICT keeps recording idempotent so a
            // migration that has already been tracked is never duplicated.
            try {
                $stmt = $this->db->getPdo()->prepare('
                    INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
                    VALUES (?, NOW(), ?)
                    ON CONFLICT (migration_name) DO NOTHING
                ');
                $stmt->execute([$name, (int)((microtime(true) - $start) * 1000)]);
            } catch (\PDOException $e) {
                // If the migrations table doesn't exist yet, silently skip recording
                if (strpos($e->getMessage(), 'core_schema_migrations') === false) {
                    throw $e;
                }
            }
        } else {
            $className::down($this->db);

            // Remove the migration record
            try {
                $stmt = $this->db->getPdo()->prepare('DELETE FROM core_schema_migrations WHERE migration_name = ?');
                $stmt->execute([$name]);
            } catch (\PDOException $e) {
                if (strpos($e->getMessage(), 'core_schema_migrations') === false) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Lazily build (default) or reuse the plugin loader and load plugins once.
     */
    private function pluginLoader(): PluginLoader
    {
        if ($this->pluginLoader === null) {
            $this->pluginLoader = new PluginLoader(
                dirname(__DIR__, 3) . '/plugins',
                new Router(),
                null,
                new HookManager()
            );
        }

        if (!$this->pluginsLoaded) {
            $this->pluginLoader->load();
            $this->pluginsLoaded = true;
        }

        return $this->pluginLoader;
    }

    /**
     * Collect the plugin-declared migrations through the SDK contract.
     *
     * Declarations that are not loadable classes implementing
     * {@see MigrationInterface}, or whose record name collides with one already
     * collected, are skipped with a visible warning — one broken plugin must
     * not block core or other plugins' migrations (quarantining is WC-165).
     *
     * @return list<array{record: string, plugin: string, fqcn: class-string<MigrationInterface>}>
     */
    private function pluginMigrations(): array
    {
        $entries = [];
        $seen = [];

        foreach ($this->pluginLoader()->getPlugins() as $plugin) {
            $pluginName = $plugin->getName();

            foreach ($plugin->getMigrations() as $fqcn) {
                if (!is_string($fqcn) || !class_exists($fqcn)) {
                    $shown = is_string($fqcn) ? $fqcn : gettype($fqcn);
                    echo "\033[1;33m⚠ Skipping unknown migration class {$shown} declared by {$pluginName}\033[0m\n";
                    continue;
                }

                if (!is_subclass_of($fqcn, MigrationInterface::class)) {
                    echo "\033[1;33m⚠ Skipping {$fqcn} (declared by {$pluginName}): does not implement Whity\\Sdk\\MigrationInterface\033[0m\n";
                    continue;
                }

                $short = substr((string) strrchr('\\' . $fqcn, '\\'), 1);
                $record = self::PLUGIN_PREFIX . $pluginName . ':' . $short;

                if (isset($seen[$record])) {
                    echo "\033[1;33m⚠ Skipping {$fqcn}: tracking name {$record} already declared\033[0m\n";
                    continue;
                }

                $seen[$record] = true;
                $entries[] = ['record' => $record, 'plugin' => $pluginName, 'fqcn' => $fqcn];
            }
        }

        return $entries;
    }

    /**
     * Resolve a recorded plugin-migration name back to its declaration.
     *
     * @param string $record The tracking name (plugin:<Plugin>:<Class>).
     * @return array{record: string, plugin: string, fqcn: class-string<MigrationInterface>}|null
     */
    private function findPluginMigrationByRecord(string $record): ?array
    {
        foreach ($this->pluginMigrations() as $entry) {
            if ($entry['record'] === $record) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Execute a plugin migration inside an explicit transaction.
     *
     * The schema change AND its tracking row commit (or roll back) together,
     * so a mid-migration failure leaves neither half-applied DDL nor a
     * tracking row behind. Recording uses ON CONFLICT DO NOTHING, so adopting
     * hand-created/never-tracked state stays idempotent.
     *
     * @param array{record: string, plugin: string, fqcn: class-string<MigrationInterface>} $entry
     * @param string $direction 'up' or 'down'.
     */
    private function executePluginMigration(array $entry, string $direction): void
    {
        $pdo = $this->db->getPdo();
        $fqcn = $entry['fqcn'];
        $migration = new $fqcn();
        $start = microtime(true);

        $pdo->beginTransaction();

        try {
            if ($direction === 'up') {
                $migration->up($pdo);

                $stmt = $pdo->prepare('
                    INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
                    VALUES (?, NOW(), ?)
                    ON CONFLICT (migration_name) DO NOTHING
                ');
                $stmt->execute([$entry['record'], (int)((microtime(true) - $start) * 1000)]);
            } else {
                $migration->down($pdo);

                $stmt = $pdo->prepare('DELETE FROM core_schema_migrations WHERE migration_name = ?');
                $stmt->execute([$entry['record']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            // A misbehaving migration may have ended the transaction itself.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new \Exception(
                "Plugin migration {$entry['record']} failed ({$direction}): " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Render a table to the console
     */
    private function renderTable(array $headers, array $rows): void
    {
        if (empty($rows)) {
            echo "  No migrations found.\n";
            return;
        }

        // Calculate column widths
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen((string)$cell));
            }
        }

        // Render header
        echo "  ";
        foreach ($headers as $i => $header) {
            echo str_pad($header, $widths[$i] + 2);
        }
        echo "\n";

        echo "  ";
        foreach ($widths as $width) {
            echo str_repeat('-', $width) . "  ";
        }
        echo "\n";

        // Render rows
        foreach ($rows as $row) {
            echo "  ";
            foreach ($row as $i => $cell) {
                echo str_pad((string)$cell, $widths[$i] + 2);
            }
            echo "\n";
        }
    }

    private function showHelp(): int
    {
        echo "\n\033[1;33mMigrations Command\033[0m\n";
        echo "Manage database migrations without requiring authentication.\n\n";
        echo "\033[1mUsage:\033[0m\n";
        echo "  php public/index.php migrate <action>\n\n";
        echo "\033[1mActions:\033[0m\n";
        echo "  status      Show migration status (default)\n";
        echo "  run         Run pending migrations\n";
        echo "  rollback    Rollback last migration\n";
        echo "  help        Show this help message\n\n";
        return 0;
    }

    private function unknownAction(string $action): int
    {
        echo "\033[0;31m✗ Unknown action: $action\033[0m\n";
        $this->showHelp();
        return 1;
    }
}
