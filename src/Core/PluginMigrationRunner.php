<?php

declare(strict_types=1);

namespace Whity\Core;

use PDO;
use Throwable;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\PluginInterface;

/**
 * Forward runner for a single plugin's declared migrations (WC-220).
 *
 * Extracts the proven per-migration execution semantics from
 * {@see \Whity\Cli\Commands\MigrationsCommand} into a focused, reusable service
 * so the migration-on-enable path (WC-220) applies migrations EXACTLY as the CLI
 * `migrate run` does:
 *  - each migration runs inside its OWN explicit transaction together with its
 *    tracking row, so a mid-migration failure leaves neither half-applied DDL
 *    nor a tracking row behind;
 *  - tracking rows are recorded under `plugin:<PluginName>:<ClassName>` with
 *    ON CONFLICT DO NOTHING, so a re-run skips already-recorded migrations
 *    (a SECOND enable is a migration no-op);
 *  - the hijack guards (the migration must not end the host transaction, and the
 *    in-transaction tracking-row sentinel must hold) are preserved so a
 *    migration that calls beginTransaction/commit/rollBack fails the run and is
 *    not recorded.
 *
 * Declarations that are not loadable classes implementing
 * {@see MigrationInterface}, or whose record name collides with one already
 * collected for the same plugin, are skipped — mirroring the CLI's collection
 * leniency. Unlike the CLI this service throws on a migration EXECUTION failure
 * so the caller (enable) can leave the plugin disabled and surface a typed error.
 */
final class PluginMigrationRunner
{
    /** Tracking-name prefix for plugin-declared migrations (WC-164). */
    private const PLUGIN_PREFIX = 'plugin:';

    /**
     * @param PDO $pdo Live database connection used for migration + tracking.
     */
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Apply every NOT-yet-recorded migration a plugin declares, in declaration
     * order, each in its own transaction. Already-recorded migrations are
     * skipped (idempotent). Returns the tracking names actually applied.
     *
     * @param PluginInterface $plugin The (staged/loaded) plugin instance.
     * @return list<string> The tracking names applied during this call.
     * @throws \RuntimeException When a migration's up() fails (after rollback).
     */
    public function applyForPlugin(PluginInterface $plugin): array
    {
        $this->ensureMigrationTable();

        $applied = [];
        foreach ($this->collect($plugin) as $entry) {
            if ($this->trackingRowVisible($entry['record'])) {
                continue;
            }
            $this->executeUp($entry);
            $applied[] = $entry['record'];
        }

        return $applied;
    }

    /**
     * Collect a plugin's valid, de-duplicated migration declarations.
     *
     * @param PluginInterface $plugin The plugin instance.
     * @return list<array{record: string, plugin: string, fqcn: class-string<MigrationInterface>}>
     * @throws \RuntimeException When getMigrations() throws (malformed plugin).
     */
    private function collect(PluginInterface $plugin): array
    {
        $pluginName = $plugin->getName();

        try {
            $declared = $plugin->getMigrations();
        } catch (Throwable $e) {
            throw new \RuntimeException(
                "Plugin {$pluginName} getMigrations() threw " . get_class($e),
                0,
                $e
            );
        }

        $entries = [];
        $seen = [];
        foreach ($declared as $fqcn) {
            if (!is_string($fqcn) || !class_exists($fqcn)) {
                continue;
            }
            if (!is_subclass_of($fqcn, MigrationInterface::class)) {
                continue;
            }

            $short = substr((string) strrchr('\\' . $fqcn, '\\'), 1);
            $record = self::PLUGIN_PREFIX . $pluginName . ':' . $short;
            if (isset($seen[$record])) {
                continue;
            }
            $seen[$record] = true;
            /** @var class-string<MigrationInterface> $fqcn */
            $entries[] = ['record' => $record, 'plugin' => $pluginName, 'fqcn' => $fqcn];
        }

        return $entries;
    }

    /**
     * Ensure the core_schema_migrations tracking table exists (idempotent).
     *
     * Uses the same DEFAULT CURRENT_TIMESTAMP DDL as the CLI runner so it parses
     * on both PostgreSQL and SQLite (the RealEngine test path).
     *
     * @return void
     */
    private function ensureMigrationTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS core_schema_migrations (
                id SERIAL PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                execution_time_ms INTEGER
            )'
        );
    }

    /**
     * Run a single migration's up() + record its tracking row in ONE transaction.
     *
     * Mirrors {@see \Whity\Cli\Commands\MigrationsCommand::executePluginMigration()}
     * for direction 'up', including both hijack guards and the phantom-row cleanup
     * on failure.
     *
     * @param array{record: string, plugin: string, fqcn: class-string<MigrationInterface>} $entry
     * @return void
     * @throws \RuntimeException When the migration fails (transaction rolled back).
     */
    private function executeUp(array $entry): void
    {
        $fqcn = $entry['fqcn'];
        /** @var MigrationInterface $migration */
        $migration = new $fqcn();
        $start = microtime(true);

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
                 VALUES (?, NOW(), 0)
                 ON CONFLICT (migration_name) DO NOTHING'
            );
            $stmt->execute([$entry['record']]);

            $migration->up($this->pdo);

            // Guard 1: the migration must not have ended our transaction.
            if (!$this->pdo->inTransaction()) {
                throw new \RuntimeException(
                    'the migration ended the host transaction itself; migrations must not call beginTransaction/commit/rollBack'
                );
            }

            // Guard 2 (sentinel): a rollBack()+beginTransaction() swap would pass
            // guard 1 but revert the in-transaction tracking row.
            if (!$this->trackingRowVisible($entry['record'])) {
                throw new \RuntimeException(
                    'the migration replaced the host transaction (sentinel check failed); migrations must not call beginTransaction/commit/rollBack'
                );
            }

            $upd = $this->pdo->prepare(
                'UPDATE core_schema_migrations SET execution_time_ms = ? WHERE migration_name = ?'
            );
            $upd->execute([(int) ((microtime(true) - $start) * 1000), $entry['record']]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Remove any phantom row a commit-hijack may have persisted.
            try {
                $del = $this->pdo->prepare('DELETE FROM core_schema_migrations WHERE migration_name = ?');
                $del->execute([$entry['record']]);
            } catch (Throwable) {
                // Tracking table unreachable; nothing to clean up.
            }

            throw new \RuntimeException(
                "Plugin migration {$entry['record']} failed (up): " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Whether a tracking row is visible from the current connection state.
     *
     * @param string $record The tracking name.
     * @return bool True when the row exists.
     */
    private function trackingRowVisible(string $record): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT 1 FROM core_schema_migrations WHERE migration_name = ?');
            $stmt->execute([$record]);
            $visible = $stmt->fetch() !== false;
            $stmt->closeCursor();

            return $visible;
        } catch (Throwable) {
            // Table not yet present => nothing recorded.
            return false;
        }
    }
}
