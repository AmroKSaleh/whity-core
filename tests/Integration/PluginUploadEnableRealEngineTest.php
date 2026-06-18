<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\PluginPackageFixtures;
use Whity\Api\PluginsApiHandler;
use Whity\Core\PluginInstaller;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Http\UploadedFile;

/**
 * WC-220: upload → enable → migration-on-enable, on a REAL SQL engine.
 *
 * Runs on in-memory SQLite locally (real-PostgreSQL via the CI
 * postgres-integration job exercises the same handler + migration-runner SQL).
 * Proves the staged-install + migration-on-enable contract end to end:
 *  - after staging, the plugin appears DISABLED and no migration is recorded;
 *  - enabling applies the declared migration (now recorded under
 *    plugin:<Name>:<Class>) and the plugin registers (lifecycle active);
 *  - a SECOND enable is a migration no-op (already-recorded migrations skipped);
 *  - a plugin whose migration THROWS stays disabled, records nothing, and the
 *    enable surfaces a typed error (no raw exception text).
 */
final class PluginUploadEnableRealEngineTest extends TestCase
{
    private string $pluginDir;
    private string $workDir;
    private PDO $pdo;
    private Router $router;
    private PluginLoader $loader;
    private PluginsApiHandler $handler;

    protected function setUp(): void
    {
        $this->pluginDir = sys_get_temp_dir() . '/whity_enable_plugins_' . uniqid();
        $this->workDir = sys_get_temp_dir() . '/whity_enable_work_' . uniqid();
        mkdir($this->pluginDir, 0775, true);
        mkdir($this->workDir, 0775, true);

        $this->pdo = $this->makePdo();

        $this->router = new Router('');
        $this->loader = new PluginLoader($this->pluginDir, $this->router);
        $this->loader->load();
        $this->handler = new PluginsApiHandler($this->pluginDir, $this->loader, $this->pdo);
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->pluginDir);
        $this->removeRecursive($this->workDir);
    }

    /**
     * Build the in-memory SQLite PDO used as the "real engine" locally. Provides
     * the NOW() UDF and the core_schema_migrations table the runner records into.
     */
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS core_schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration_name TEXT NOT NULL UNIQUE,
                executed_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                execution_time_ms INTEGER
            )'
        );

        return $pdo;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile(
            $path,
            (int) filesize($path),
            UPLOAD_ERR_OK,
            basename($path),
            'application/zip'
        );
    }

    /** Count tracking rows for a plugin name. */
    private function recordedMigrations(string $pluginName): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM core_schema_migrations WHERE migration_name LIKE ?'
        );
        $stmt->execute(['plugin:' . $pluginName . ':%']);

        return (int) $stmt->fetchColumn();
    }

    private function enable(string $name): Response
    {
        return $this->handler->enable(new Request('POST', '/api/plugins/' . $name . '/enable'), ['name' => $name]);
    }

    private function statusOf(string $name): ?string
    {
        foreach ($this->loader->getPluginMetadata() as $meta) {
            if ($meta['name'] === $name) {
                return $meta['status'];
            }
        }

        return null;
    }

    public function testStageThenEnableRunsMigrationThenSecondEnableIsNoOp(): void
    {
        $installer = new PluginInstaller($this->pluginDir, $this->loader);

        $zip = PluginPackageFixtures::validDirectoryZip($this->workDir, 'EnableMe');
        $entry = $installer->installFromUpload($this->upload($zip));

        // After staging: disabled, no migration recorded yet.
        self::assertSame('disabled', $entry['status']);
        self::assertSame('disabled', $this->statusOf('EnableMe'));
        self::assertSame(0, $this->recordedMigrations('EnableMe'));

        // Enable: applies the declared migration and activates the plugin.
        $response = $this->enable('EnableMe');
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame(1, $this->recordedMigrations('EnableMe'), 'migration must be recorded on enable');
        self::assertSame('active', $this->statusOf('EnableMe'));

        // The migration actually ran (its table exists).
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='enableme_items'");
        self::assertNotFalse($tables);
        self::assertSame('enableme_items', $tables->fetchColumn());

        // A SECOND enable is a migration no-op: still exactly one tracking row.
        $second = $this->enable('EnableMe');
        self::assertSame(200, $second->getStatusCode(), $second->getBody());
        self::assertSame(1, $this->recordedMigrations('EnableMe'));
    }

    public function testEnableWithFailingMigrationLeavesPluginDisabledAndRecordsNothing(): void
    {
        $installer = new PluginInstaller($this->pluginDir, $this->loader);

        $zip = PluginPackageFixtures::throwingMigrationZip($this->workDir, 'ThrowingMig');
        $installer->installFromUpload($this->upload($zip));
        self::assertSame('disabled', $this->statusOf('ThrowingMig'));

        $response = $this->enable('ThrowingMig');

        // Typed error surfaced (422), no raw exception text.
        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        self::assertArrayHasKey('error', $payload);
        self::assertStringNotContainsStringIgnoringCase('sql', (string) $payload['error']);

        // Plugin stays DISABLED, nothing recorded.
        self::assertSame('disabled', $this->statusOf('ThrowingMig'));
        self::assertSame(0, $this->recordedMigrations('ThrowingMig'));
        self::assertFileExists($this->pluginDir . '/ThrowingMig/' . PluginLoader::DIR_DISABLED_SENTINEL);
    }

    private function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removeRecursive($path . '/' . (string) $entry);
        }
        @rmdir($path);
    }
}
