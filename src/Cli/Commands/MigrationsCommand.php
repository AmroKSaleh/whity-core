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
                'rollback' => $this->dispatchRollback($argv),
                '--help', '-h', 'help' => $this->showHelp(),
                default => $this->unknownAction($action),
            };
        } catch (\Throwable $e) {
            // \Throwable, not \Exception: a malformed plugin file raises a
            // ParseError (an \Error) from the loader's require_once, and the
            // migrate command must degrade to exit 1, not a fatal.
            echo "\033[0;31m✗ Error: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Dispatch rollback to the appropriate mode based on arguments.
     *
     * Modes:
     *  - No arguments: existing LIFO single-migration rollback (unchanged).
     *  - `--plugin <PluginName> [--force]`: roll back all migrations for the
     *    given plugin in reverse execution order.
     *  - `<MigrationName> [--force]`: roll back exactly the named core
     *    migration (file stem, e.g. "002_create_permissions").
     *
     * @param list<string> $argv Remaining arguments after "rollback".
     */
    private function dispatchRollback(array $argv): int
    {
        $force = in_array('--force', $argv, true);
        $argv  = array_values(array_filter($argv, static fn(string $a): bool => $a !== '--force'));

        // --plugin <PluginName>
        $pluginIdx = array_search('--plugin', $argv, true);
        if ($pluginIdx !== false) {
            $pluginName = $argv[$pluginIdx + 1] ?? null;
            if ($pluginName === null || str_starts_with($pluginName, '--')) {
                echo "\033[0;31m✗ Rollback error: --plugin requires a plugin name argument\033[0m\n";
                return 1;
            }
            return $this->rollbackPlugin($pluginName, $force);
        }

        // Named migration
        if (!empty($argv)) {
            return $this->rollbackNamed($argv[0], $force);
        }

        // Default: global LIFO
        return $this->rollback();
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
            $declaredRecords = [];
            foreach ($this->pluginMigrations() as $entry) {
                $declaredRecords[$entry['record']] = true;
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

            // Executed plugin records whose declaring plugin/class is gone
            // (renamed, uninstalled): surface them instead of hiding them.
            foreach ($executed as $name => $row) {
                if (str_starts_with((string) $name, self::PLUGIN_PREFIX) && !isset($declaredRecords[$name])) {
                    $migrations[] = [
                        (string) $name,
                        'Orphaned (plugin not loaded)',
                        $row['executed_at'] ?? 'N/A'
                    ];
                }
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
     * Roll back exactly one named core migration by its file-stem name.
     *
     * Safety: refuses by default when any migration that was executed AFTER
     * the target references one of the tables the target creates in its `up()`
     * SQL.  Pass `$force = true` to skip the check.
     *
     * @param string $name  File-stem name (e.g. "001_create_users_roles").
     * @param bool   $force Bypass the dependency check.
     */
    private function rollbackNamed(string $name, bool $force): int
    {
        try {
            $executed = $this->getExecutedMigrations();

            if (!isset($executed[$name])) {
                echo "\033[0;31m✗ Rollback error: migration '{$name}' has not been executed\033[0m\n";
                return 1;
            }

            $file = $this->migrationDir . '/' . $name . '.php';
            if (!file_exists($file)) {
                echo "\033[0;31m✗ Rollback error: migration file {$name}.php not found\033[0m\n";
                return 1;
            }

            if (!$force) {
                $blockers = $this->findDependentMigrations($name, $file, $executed);
                if (!empty($blockers)) {
                    echo "\033[0;31m✗ Rollback blocked: the following migrations executed after '{$name}' depend on its tables:\033[0m\n";
                    foreach ($blockers as $blocker) {
                        echo "    - {$blocker}\n";
                    }
                    echo "\nUse --force to roll back anyway.\n";
                    return 1;
                }
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
     * Roll back all executed migrations that belong to the named plugin, in
     * reverse execution order.
     *
     * Plugin migrations are identified by their tracking record:
     * `plugin:<PluginName>:<ClassName>`.  The rollback uses the
     * `executePluginMigration` path when the plugin is loaded (real migration
     * class available), or removes the tracking row directly for orphaned
     * records (plugin no longer installed) — consistent with the uninstall
     * use-case this feature targets.
     *
     * @param string $pluginName The plugin's declared name (case-sensitive).
     * @param bool   $force      Currently unused but accepted for consistency.
     */
    private function rollbackPlugin(string $pluginName, bool $force): int
    {
        try {
            $prefix   = self::PLUGIN_PREFIX . $pluginName . ':';
            $executed = $this->getExecutedMigrations();

            // Collect matching records, ordered by id DESC (reverse execution).
            $targets = [];
            foreach ($executed as $migName => $row) {
                if (str_starts_with((string) $migName, $prefix)) {
                    $targets[] = ['name' => (string) $migName, 'row' => $row];
                }
            }

            // Sort by id DESC so the most-recently-run migration rolls back first.
            usort($targets, static function (array $a, array $b): int {
                return (int) ($b['row']['id'] ?? 0) <=> (int) ($a['row']['id'] ?? 0);
            });

            if (empty($targets)) {
                echo "\n\033[1;33m⚠ No executed migrations found for plugin '{$pluginName}'\033[0m\n\n";
                return 0;
            }

            echo "\n\033[1;33mRolling back migrations for plugin '{$pluginName}'...\033[0m\n";

            foreach ($targets as $target) {
                $migName = $target['name'];
                echo "  Rolling back: $migName... ";

                $entry = $this->findPluginMigrationByRecord($migName);
                if ($entry !== null) {
                    $this->executePluginMigration($entry, 'down');
                } else {
                    // Plugin is no longer installed; remove the orphaned tracking
                    // row so the record does not block a future re-install.
                    $stmt = $this->db->getPdo()->prepare(
                        'DELETE FROM core_schema_migrations WHERE migration_name = ?'
                    );
                    $stmt->execute([$migName]);
                }

                echo "\033[0;32m✓\033[0m\n";
            }

            $count = count($targets);
            echo "\n\033[0;32m✓ Successfully rolled back $count migration(s) for plugin '{$pluginName}'\033[0m\n\n";

            return 0;
        } catch (\Exception $e) {
            echo "\033[0;31m✗ Plugin rollback failed: " . $e->getMessage() . "\033[0m\n";
            return 1;
        }
    }

    /**
     * Find core migrations executed AFTER `$targetName` whose `up()` SQL
     * references any table created by the target migration.
     *
     * The dependency detection reads the PHP source of each later migration
     * file and searches for the table names extracted from the target's
     * `up()` method via a simple CREATE TABLE pattern match.  This is a
     * best-effort static analysis: it catches `CREATE TABLE [IF NOT EXISTS]
     * <name>` and then looks for those names in the text of subsequent files.
     *
     * @param string            $targetName  File-stem of the target migration.
     * @param string            $targetFile  Absolute path to the target file.
     * @param array<string, mixed> $executed Executed migrations map (name → row).
     * @return list<string> Names of blocking dependent migrations.
     */
    private function findDependentMigrations(string $targetName, string $targetFile, array $executed): array
    {
        // Extract table names created by the target migration.
        $targetSrc   = (string) file_get_contents($targetFile);
        $tableNames  = $this->extractCreatedTableNames($targetSrc);

        if (empty($tableNames)) {
            return [];
        }

        // Determine the target's execution order by its id.
        $targetId = (int) ($executed[$targetName]['id'] ?? 0);

        $blockers = [];

        foreach ($executed as $migName => $row) {
            if ($migName === $targetName) {
                continue;
            }

            // Only migrations executed AFTER the target (higher id) matter.
            if ((int) ($row['id'] ?? 0) <= $targetId) {
                continue;
            }

            // Skip plugin migrations — they live under a different path.
            if (str_starts_with((string) $migName, self::PLUGIN_PREFIX)) {
                continue;
            }

            $candidateFile = $this->migrationDir . '/' . $migName . '.php';
            if (!file_exists($candidateFile)) {
                continue;
            }

            $candidateSrc = (string) file_get_contents($candidateFile);
            foreach ($tableNames as $table) {
                if (preg_match('/\b' . preg_quote($table, '/') . '\b/i', $candidateSrc)) {
                    $blockers[] = (string) $migName;
                    break;
                }
            }
        }

        return $blockers;
    }

    /**
     * Extract table names from `CREATE TABLE [IF NOT EXISTS] <name>` statements
     * in the given PHP source.
     *
     * @param string $src PHP source code of a migration file.
     * @return list<string> Unique table names found.
     */
    private function extractCreatedTableNames(string $src): array
    {
        preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([`"\']?(\w+)[`"\']?)/i',
            $src,
            $matches
        );

        /** @var list<string> $names */
        $names = array_unique(array_filter(array_map('trim', $matches[2])));
        return array_values($names);
    }

    /**
     * Rollback last migration
     */
    private function rollback(): int
    {
        try {
            // id is the tiebreaker: a batch run records many migrations with
            // (near-)identical executed_at values, and rolling back the wrong
            // one would corrupt schema state.
            $stmt = $this->db->getPdo()->prepare('
                SELECT migration_name FROM core_schema_migrations
                ORDER BY executed_at DESC, id DESC LIMIT 1
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
            $stmt = $this->db->getPdo()->prepare('SELECT id, migration_name, executed_at FROM core_schema_migrations');
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

            try {
                $declared = $plugin->getMigrations();
            } catch (\Throwable $e) {
                echo "\033[1;33m⚠ Skipping {$pluginName}: getMigrations() threw " . get_class($e) . "\033[0m\n";
                continue;
            }

            foreach ($declared as $fqcn) {
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
            // The tracking row is written FIRST, inside the runner's
            // transaction, where it doubles as a HIJACK SENTINEL: if the
            // migration ends our transaction — even if it then opens a fresh
            // one, which would satisfy a naive inTransaction() check — the
            // row's state flips back and the verification below detects it.
            // Atomicity is unchanged: row and schema change commit together.
            if ($direction === 'up') {
                $stmt = $pdo->prepare('
                    INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
                    VALUES (?, NOW(), 0)
                    ON CONFLICT (migration_name) DO NOTHING
                ');
                $stmt->execute([$entry['record']]);

                $migration->up($pdo);
            } else {
                $stmt = $pdo->prepare('DELETE FROM core_schema_migrations WHERE migration_name = ?');
                $stmt->execute([$entry['record']]);

                $migration->down($pdo);
            }

            // Guard 1: the migration must not have ended our transaction.
            if (!$pdo->inTransaction()) {
                throw new \Exception(
                    'the migration ended the host transaction itself; migrations must not call beginTransaction/commit/rollBack'
                );
            }

            // Guard 2 (sentinel): a rollBack()+beginTransaction() swap passes
            // guard 1 but reverts the tracking row's in-transaction state.
            $expectRow = $direction === 'up';
            if ($this->trackingRowVisible($pdo, $entry['record']) !== $expectRow) {
                throw new \Exception(
                    'the migration replaced the host transaction (sentinel check failed); migrations must not call beginTransaction/commit/rollBack'
                );
            }

            if ($direction === 'up') {
                $upd = $pdo->prepare('UPDATE core_schema_migrations SET execution_time_ms = ? WHERE migration_name = ?');
                $upd->execute([(int)((microtime(true) - $start) * 1000), $entry['record']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            // A misbehaving migration may have ended the transaction itself.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($direction === 'up') {
                // A commit-hijack may have already PERSISTED the sentinel row
                // without the full migration: remove any such phantom in
                // autocommit. Safe in every other failure shape — the row was
                // rolled back with our transaction, so this is a no-op — and
                // only rows written by THIS run can exist here (only pending,
                // unrecorded migrations are executed).
                try {
                    $del = $pdo->prepare('DELETE FROM core_schema_migrations WHERE migration_name = ?');
                    $del->execute([$entry['record']]);
                } catch (\Throwable) {
                    // Tracking table unreachable; nothing to clean up.
                }
            }

            throw new \Exception(
                "Plugin migration {$entry['record']} failed ({$direction}): " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Whether the tracking row is visible from the CURRENT transaction/state.
     *
     * @param PDO $pdo The connection.
     * @param string $record The tracking name.
     * @return bool True when the row is visible.
     */
    private function trackingRowVisible(PDO $pdo, string $record): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM core_schema_migrations WHERE migration_name = ?');
        $stmt->execute([$record]);
        $visible = $stmt->fetch() !== false;
        $stmt->closeCursor();

        return $visible;
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
        echo "  status                              Show migration status (default)\n";
        echo "  run                                 Run pending migrations\n";
        echo "  rollback                            Rollback last migration (LIFO)\n";
        echo "  rollback <MigrationName>            Rollback exactly one named migration\n";
        echo "  rollback --plugin <PluginName>      Rollback all migrations for a plugin\n";
        echo "  rollback [...] --force              Bypass dependent-migration safety check\n";
        echo "  help                                Show this help message\n\n";
        return 0;
    }

    private function unknownAction(string $action): int
    {
        echo "\033[0;31m✗ Unknown action: $action\033[0m\n";
        $this->showHelp();
        return 1;
    }
}
