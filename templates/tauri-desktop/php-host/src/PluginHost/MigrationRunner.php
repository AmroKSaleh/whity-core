<?php

declare(strict_types=1);

namespace Whity\PluginHost;

use Whity\Core\RBAC\PermissionRegistry;

/**
 * Runs each loaded plugin's pending MigrationInterface::up() once, tracked
 * in a local `_plugin_migrations` table so re-running the host is a no-op.
 *
 * Per the SDK's documented contract (MigrationInterface's own docblock): a
 * migration class must NOT manage transactions itself, because "the host
 * wraps each migration ... in its own transaction" — so this runner does
 * exactly that (beginTransaction/commit per migration, rollBack + rethrow on
 * failure), matching production's real behavior rather than skipping
 * transactions.
 *
 * Runs unconditionally on every boot, gated only by this tracking table with
 * no lock — fine for a single desktop process at PHP_HOST_WORKERS=1; would
 * race above that (see the plan doc).
 */
final class MigrationRunner
{
    /**
     * Pre-create bare, correctly-shaped versions of the host RBAC tables a
     * plugin migration writes to but does not own itself
     * (GrantDemoCatalogPermissionsToAdmin targets `permissions` /
     * `role_permissions`, which production's core creates, not the plugin).
     * SQLite's `ON CONFLICT (name) DO NOTHING` requires a matching UNIQUE
     * constraint to exist, or PDO::prepare() throws at parse time — this is
     * purely so that migration doesn't crash.
     *
     * Seeds the literal `admin` role here, BEFORE any plugin migration runs —
     * ordering is load-bearing: every real plugin's Grant*ToAdmin migration
     * only SELECTs the `admin` row (`WHERE r.name = 'admin'`), it never
     * upserts it, so without this seed those migrations silently no-op.
     */
    public function bootstrapHostSkeleton(\PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS roles (
                id INTEGER PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS permissions (
                id INTEGER PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS role_permissions (
                id INTEGER PRIMARY KEY,
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                UNIQUE(role_id, permission_id)
            )
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS _plugin_migrations (
                migration VARCHAR(255) PRIMARY KEY,
                plugin VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');
        // Backs Whity\Database\SequenceCounters (this host's SequenceAllocator),
        // registered in public/index.php — same shape as production's 092
        // migration minus the `tenants` FK, which this single-tenant offline
        // host has no table for.
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS sequence_counters (
                tenant_id INTEGER NOT NULL,
                name VARCHAR(128) NOT NULL,
                value BIGINT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (tenant_id, name)
            )
        ');

        $pdo->exec("INSERT INTO roles (name) VALUES ('admin') ON CONFLICT(name) DO NOTHING");
    }

    /**
     * Insert every permission slug the loaded plugins declared directly into
     * the `permissions` catalogue, independent of whether a plugin ships its
     * own grant migration — this is what makes the permission actually
     * grantable (role_permissions requires a matching row to exist) for a
     * plugin that doesn't ship a Grant*ToAdmin-style migration of its own.
     */
    public function persistPermissionCatalog(\PDO $pdo, PermissionRegistry $registry): void
    {
        $stmt = $pdo->prepare('INSERT INTO permissions (name) VALUES (:name) ON CONFLICT(name) DO NOTHING');
        foreach (array_keys($registry->getAll()) as $permission) {
            $stmt->execute([':name' => $permission]);
        }
    }

    public function run(PluginRuntimeLoader $loader, \PDO $pdo): void
    {
        $applied = $this->appliedMigrations($pdo);

        foreach ($loader->getLoadedPlugins() as $loadedPlugin) {
            foreach ($loadedPlugin->plugin->getMigrations() as $migrationFqcn) {
                if (in_array($migrationFqcn, $applied, true)) {
                    continue;
                }

                $this->applyOne($pdo, $loadedPlugin->plugin->getName(), $migrationFqcn);
            }
        }
    }

    private function applyOne(\PDO $pdo, string $pluginName, string $migrationFqcn): void
    {
        if (!class_exists($migrationFqcn)) {
            throw new \RuntimeException("Migration class {$migrationFqcn} could not be autoloaded");
        }

        /** @var \Whity\Sdk\MigrationInterface $migration */
        $migration = new $migrationFqcn();

        $pdo->beginTransaction();
        try {
            $migration->up($pdo);

            $stmt = $pdo->prepare(
                'INSERT INTO _plugin_migrations (migration, plugin) VALUES (:migration, :plugin)'
            );
            $stmt->execute([':migration' => $migrationFqcn, ':plugin' => $pluginName]);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), '_plugin_migrations')) {
                // A concurrent worker applied this exact migration first —
                // confirmed live, not hypothetical: running with >1
                // FrankenPHP worker races here at boot, since every worker
                // independently runs the migration runner on first start.
                // Rolling back this attempt's up() is safe BECAUSE migrations
                // are idempotent (IF NOT EXISTS-guarded, per the SDK
                // contract) — the winning worker's transaction is left
                // standing as the authoritative one. Not an error.
                return;
            }
            throw new \RuntimeException("Migration {$migrationFqcn} failed: " . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new \RuntimeException("Migration {$migrationFqcn} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<string> Migration FQCNs already applied.
     */
    private function appliedMigrations(\PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT migration FROM _plugin_migrations');

        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
