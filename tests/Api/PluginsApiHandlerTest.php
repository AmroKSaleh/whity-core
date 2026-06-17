<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\PluginsApiHandler;
use Whity\Core\PluginLoader;
use Whity\Core\PluginState;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Tests for the plugin lifecycle surface exposed via PluginsApiHandler.
 */
class PluginsApiHandlerTest extends TestCase
{
    private string $tempDir;
    private Router $router;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_plugins_api_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->router = new Router('');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testListIncludesLifecycleStateFromLoader(): void
    {
        $this->writeThrowingPlugin('StatusPlugin', '/api/status/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $match = $this->router->match(new Request('GET', '/api/status/run'));
        $handler = $match['handler'];
        for ($i = 0; $i < 3; $i++) {
            $handler(new Request('GET', '/api/status/run'), []);
        }

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->list(new Request('GET', '/api/plugins'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);

        $failed = null;
        foreach ($payload['data'] as $plugin) {
            if (($plugin['state'] ?? null) === 'failed') {
                $failed = $plugin;
                break;
            }
        }
        $this->assertNotNull($failed, 'Failed plugin should appear in the list with its state');
        $this->assertSame(3, $failed['consecutive_errors']);
        $this->assertIsArray($failed['last_error']);
        $this->assertSame('intentional plugin explosion', $failed['last_error']['message']);
    }

    public function testReEnableTransitionsFailedPluginBackToActive(): void
    {
        $this->writeThrowingPlugin('ReEnablePlugin', '/api/reenable/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $match = $this->router->match(new Request('GET', '/api/reenable/run'));
        $handler = $match['handler'];
        for ($i = 0; $i < 3; $i++) {
            $handler(new Request('GET', '/api/reenable/run'), []);
        }

        $key = 'Whity\\Plugins\\ReEnablePlugin';
        $this->assertSame(PluginState::Failed, $loader->getLifecycle($key)?->getState());

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->reEnable(new Request('POST', '/api/plugins/x/re-enable'), ['id' => $key]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PluginState::Active, $loader->getLifecycle($key)?->getState());
    }

    public function testReEnableUnknownPluginReturns404(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->reEnable(
            new Request('POST', '/api/plugins/x/re-enable'),
            ['id' => 'Nope\\Missing']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListStillWorksWithoutLoader(): void
    {
        // Backward-compatible filesystem listing when no loader is wired.
        file_put_contents($this->tempDir . '/SomePlugin.php', "<?php\n");

        $apiHandler = new PluginsApiHandler($this->tempDir);
        $response = $apiHandler->list(new Request('GET', '/api/plugins'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload['data']);
    }

    public function testReEnableWithoutLoaderReturnsServiceUnavailable(): void
    {
        $apiHandler = new PluginsApiHandler($this->tempDir);
        $response = $apiHandler->reEnable(
            new Request('POST', '/api/plugins/x/re-enable'),
            ['id' => 'Whity\\Plugins\\Foo']
        );

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testListReportsMetadataShapeWithStatusAndCounts(): void
    {
        $this->writeMetadataPlugin('MetaApiPlugin', '/api/metaapi/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->list(new Request('GET', '/api/plugins'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload['data']);

        $entry = null;
        foreach ($payload['data'] as $plugin) {
            if (($plugin['name'] ?? null) === 'MetaApiPlugin') {
                $entry = $plugin;
                break;
            }
        }

        $this->assertNotNull($entry, 'Loaded plugin should be listed with metadata');
        // AC #1 contract: name, version, status, routes_count, permissions_count.
        $this->assertSame('MetaApiPlugin', $entry['name']);
        $this->assertSame('4.2.0', $entry['version']);
        $this->assertSame('active', $entry['status']);
        $this->assertSame(1, $entry['routes_count']);
        $this->assertSame(2, $entry['permissions_count']);
    }

    public function testDisableActivePluginUnregistersRoutesAndSetsDisabledStatus(): void
    {
        $this->writeMetadataPlugin('DisableApiPlugin', '/api/disableapi/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Route is live before disabling.
        $this->assertNotNull($this->router->match(new Request('GET', '/api/disableapi/run')));

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->disable(
            new Request('POST', '/api/plugins/DisableApiPlugin/disable'),
            ['name' => 'DisableApiPlugin']
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('disabled', $payload['data']['state']);

        // AC #2: routes unregistered, lifecycle disabled.
        $this->assertNull(
            $this->router->match(new Request('GET', '/api/disableapi/run')),
            'Disabled plugin route must no longer match'
        );
        $key = 'Whity\\Plugins\\DisableApiPlugin';
        $this->assertSame(PluginState::Disabled, $loader->getLifecycle($key)?->getState());
    }

    public function testEnableReEnablesDisabledPluginViaLoader(): void
    {
        $this->writeMetadataPlugin('EnableApiPlugin', '/api/enableapi/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $key = 'Whity\\Plugins\\EnableApiPlugin';
        $this->assertTrue($loader->disablePlugin($key));
        $this->assertNull($this->router->match(new Request('GET', '/api/enableapi/run')));

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->enable(
            new Request('POST', '/api/plugins/EnableApiPlugin/enable'),
            ['name' => 'EnableApiPlugin']
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PluginState::Active, $loader->getLifecycle($key)?->getState());
        $this->assertNotNull(
            $this->router->match(new Request('GET', '/api/enableapi/run')),
            'Re-enabled plugin route should be live again'
        );
    }

    public function testDisableUnknownPluginViaLoaderReturns404(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->disable(
            new Request('POST', '/api/plugins/Ghost/disable'),
            ['name' => 'Ghost']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // WC-208: uninstall endpoint
    // -----------------------------------------------------------------------

    public function testUninstallReturnsNotFoundForUnknownPlugin(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader, $pdo);
        $response = $apiHandler->uninstall(
            new Request('POST', '/api/plugins/Ghost/uninstall'),
            ['id' => 'Ghost']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDryRunReturnsUninstallPlanWithoutMutating(): void
    {
        $this->writeMetadataPlugin('DryRunPlugin', '/api/dryrun/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader, $pdo);

        $request = new Request(
            'POST',
            '/api/plugins/DryRunPlugin/uninstall',
            ['Content-Type' => 'application/json'],
            (string) json_encode(['dry_run' => true])
        );

        $response = $apiHandler->uninstall($request, ['id' => 'DryRunPlugin']);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $data = $payload['data'];
        $this->assertArrayHasKey('plugin', $data);
        $this->assertArrayHasKey('migrations_to_roll_back', $data);
        $this->assertArrayHasKey('will_remove_directory', $data);

        // Must NOT have mutated: plugin file still exists, route still live.
        $this->assertFileExists($this->tempDir . '/DryRunPlugin.php');
        $this->assertNotNull($this->router->match(new Request('GET', '/api/dryrun/run')));
    }

    public function testUninstallDisablesPluginAndRollsBackMigrations(): void
    {
        $this->writeMetadataPlugin('ExecuteUninstallPlugin', '/api/execute-uninstall/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader, $pdo);

        $request = new Request(
            'POST',
            '/api/plugins/ExecuteUninstallPlugin/uninstall',
            ['Content-Type' => 'application/json'],
            (string) json_encode(['dry_run' => false, 'force' => false])
        );

        $response = $apiHandler->uninstall($request, ['id' => 'ExecuteUninstallPlugin']);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $data = $payload['data'];
        $this->assertTrue($data['disabled']);
        $this->assertTrue($data['directory_removed']);
        $this->assertSame([], $data['errors']);

        // File must be gone after uninstall.
        $this->assertFileDoesNotExist($this->tempDir . '/ExecuteUninstallPlugin.php');
    }

    public function testUninstallWithoutLoaderAndWithoutFileReturns404(): void
    {
        // No loader, no file on disk.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $apiHandler = new PluginsApiHandler($this->tempDir, null, $pdo);
        $response = $apiHandler->uninstall(
            new Request('POST', '/api/plugins/NoSuchPlugin/uninstall'),
            ['id' => 'NoSuchPlugin']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUninstallRejectsTraversalIdentifier(): void
    {
        // A sentinel file OUTSIDE the plugin dir that traversal would target.
        $outside = $this->tempDir . '/outside_secret.php';
        file_put_contents($outside, "<?php // must survive\n");

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        // Plugin dir is a child so "../outside_secret" would escape it.
        $pluginDir = $this->tempDir . '/plugins';
        mkdir($pluginDir, 0755, true);

        $apiHandler = new PluginsApiHandler($pluginDir, null, $pdo);

        foreach (['..\\..\\evil', '../../evil', '..\\outside_secret', '../outside_secret'] as $bad) {
            $response = $apiHandler->uninstall(
                new Request('POST', '/api/plugins/x/uninstall'),
                ['id' => $bad]
            );
            $this->assertSame(400, $response->getStatusCode(), "identifier '{$bad}' must be rejected");
            $payload = json_decode($response->getBody(), true);
            $this->assertSame('Invalid plugin identifier', $payload['error'] ?? null);
            // The raw identifier must not be echoed back.
            $this->assertStringNotContainsString($bad, $response->getBody());
        }

        // Nothing outside the plugin dir was deleted.
        $this->assertFileExists($outside);
    }

    public function testUninstallRejectsDottedIdentifier(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $apiHandler = new PluginsApiHandler($this->tempDir, null, $pdo);
        $response = $apiHandler->uninstall(
            new Request('POST', '/api/plugins/x/uninstall'),
            ['id' => 'Some.Dotted.Id']
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('Invalid plugin identifier', $payload['error'] ?? null);
    }

    public function testUninstallRemovesFileNotSiblingDirectoryWhenGateMatchedFile(): void
    {
        // Both a Foo.php file AND a Foo/ directory exist. The reachability gate
        // (hasPluginFile) matches the .php FILE, so ONLY the file must be removed.
        file_put_contents($this->tempDir . '/Foo.php', "<?php // plugin file\n");
        mkdir($this->tempDir . '/Foo', 0755, true);
        file_put_contents($this->tempDir . '/Foo/keep.txt', "must survive\n");

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        // No loader: forces the filesystem-fallback path.
        $apiHandler = new PluginsApiHandler($this->tempDir, null, $pdo);
        $response = $apiHandler->uninstall(
            new Request('POST', '/api/plugins/Foo/uninstall'),
            ['id' => 'Foo']
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['data']['directory_removed']);

        // The FILE the gate matched is gone; the sibling directory survives.
        $this->assertFileDoesNotExist($this->tempDir . '/Foo.php');
        $this->assertDirectoryExists($this->tempDir . '/Foo');
        $this->assertFileExists($this->tempDir . '/Foo/keep.txt');
    }

    // -----------------------------------------------------------------------
    // WC-210: worker-local propagation indicator + persisted-signal coherence
    // -----------------------------------------------------------------------

    /**
     * The plugin list carries a typed `meta` block declaring the listing is
     * worker-local and admin changes converge across workers on reload/restart.
     */
    public function testListCarriesWorkerLocalPropagationMeta(): void
    {
        $this->writeMetadataPlugin('MetaIndicatorPlugin', '/api/meta-indicator/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader);
        $response = $apiHandler->list(new Request('GET', '/api/plugins'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('meta', $payload);
        $this->assertTrue($payload['meta']['worker_local']);
        $this->assertIsString($payload['meta']['note']);
        $this->assertStringContainsString('reload', $payload['meta']['note']);
    }

    /**
     * A single-file plugin disabled on disk (Foo.php.disabled) must still appear
     * in the listing with status 'disabled' rather than vanishing.
     */
    public function testDisabledSingleFilePluginStillListedAsDisabled(): void
    {
        // A plugin file that has been persistently disabled on disk.
        file_put_contents($this->tempDir . '/GhostDisabled.php.disabled', "<?php // disabled\n");

        $apiHandler = new PluginsApiHandler($this->tempDir, null);
        $response = $apiHandler->list(new Request('GET', '/api/plugins'));

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);

        $entry = null;
        foreach ($payload['data'] as $plugin) {
            if (($plugin['id'] ?? null) === 'GhostDisabled') {
                $entry = $plugin;
                break;
            }
        }

        $this->assertNotNull($entry, 'A disabled single-file plugin must still be listed');
        $this->assertFalse($entry['enabled']);
        $this->assertSame('disabled', $entry['status']);
    }

    /**
     * WC-208 uninstall still works after the WC-210 disk signal: disabling a
     * single-file plugin now renames Foo.php -> Foo.php.disabled, and the
     * orchestrated uninstall must still locate and remove the renamed file.
     */
    public function testUninstallStillRemovesSingleFilePluginAfterDiskSignal(): void
    {
        $this->writeMetadataPlugin('SignalUninstallPlugin', '/api/signal-uninstall/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader, $pdo);
        $request = new Request(
            'POST',
            '/api/plugins/SignalUninstallPlugin/uninstall',
            ['Content-Type' => 'application/json'],
            (string) json_encode(['dry_run' => false, 'force' => false])
        );

        $response = $apiHandler->uninstall($request, ['id' => 'SignalUninstallPlugin']);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['data']['disabled']);
        $this->assertTrue($payload['data']['directory_removed']);

        // Neither the enabled nor the disabled variant may survive.
        $this->assertFileDoesNotExist($this->tempDir . '/SignalUninstallPlugin.php');
        $this->assertFileDoesNotExist($this->tempDir . '/SignalUninstallPlugin.php.disabled');
    }

    /**
     * WC-208 uninstall still works for a directory plugin after WC-210: the
     * disable writes a `.disabled` sentinel into the folder, and the whole
     * directory (sentinel included) must still be removed.
     */
    public function testUninstallStillRemovesDirectoryPluginAfterSentinel(): void
    {
        $subDir = $this->tempDir . '/DirUninstallPlugin';
        mkdir($subDir, 0755, true);
        $code = <<<'PHP'
<?php

namespace DirUninstallPlugin;

use Whity\Sdk\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'DirUninstallPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [['method' => 'GET', 'path' => '/api/dir-uninstall/run', 'handler' => [$this, 'handle'], 'requiredRole' => null]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request $request): Response { return Response::json(['ok' => true]); }
}
PHP;
        file_put_contents($subDir . '/Plugin.php', $code);

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $apiHandler = new PluginsApiHandler($this->tempDir, $loader, $pdo);
        $request = new Request(
            'POST',
            '/api/plugins/DirUninstallPlugin/uninstall',
            ['Content-Type' => 'application/json'],
            (string) json_encode(['dry_run' => false])
        );

        $response = $apiHandler->uninstall($request, ['id' => 'DirUninstallPlugin']);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['data']['directory_removed']);
        $this->assertDirectoryDoesNotExist($subDir);
    }

    private function writeMetadataPlugin(string $class, string $path): void
    {
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Sdk\\PluginInterface;
use Whity\\Core\\Request;
use Whity\\Core\\Response;

class {$class} implements PluginInterface
{
    public function getName(): string { return '{$class}'; }
    public function getVersion(): string { return '4.2.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '{$path}',
            'handler' => [\$this, 'handle'],
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return ['fixture:read', 'fixture:write']; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request \$request): Response
    {
        return Response::json(['ok' => true]);
    }
}
PHP;
        file_put_contents($this->tempDir . '/' . $class . '.php', $code);
    }

    private function writeThrowingPlugin(string $class, string $path): void
    {
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Sdk\\PluginInterface;
use Whity\\Core\\Request;
use Whity\\Core\\Response;

class {$class} implements PluginInterface
{
    public function getName(): string { return '{$class}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '{$path}',
            'handler' => [\$this, 'handle'],
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request \$request): Response
    {
        throw new \\RuntimeException('intentional plugin explosion');
    }
}
PHP;
        file_put_contents($this->tempDir . '/' . $class . '.php', $code);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
