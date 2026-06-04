<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Tests for PluginLoader class
 */
class PluginLoaderTest extends TestCase
{
    private string $tempDir;
    private Router $router;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_plugins_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->router = new Router();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * Test loads plugin from file
     */
    public function testLoadsPluginFromFile(): void
    {
        // Create a test plugin file
        $pluginCode = <<<'PHP'
<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class TestAdminStatsForFileLoad implements PluginInterface
{
    public function getName(): string
    {
        return 'TestAdminStatsForFileLoad';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/admin/stats',
                'handler' => [$this, 'handle'],
                'requiredRole' => 'admin',
            ]
        ];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        return Response::json(['stats' => 'data']);
    }
}
PHP;

        file_put_contents($this->tempDir . '/TestAdminStatsForFileLoad.php', $pluginCode);

        // Load plugins
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Verify plugin was registered
        $plugins = $loader->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertInstanceOf(PluginInterface::class, $plugins[0]);

        // Verify route was registered with router
        $request = new Request('GET', '/api/admin/stats');
        $match = $this->router->match($request);
        $this->assertNotNull($match);
        $this->assertSame('admin', $match['requiredRole']);
    }

    /**
     * Test does not load non-plugin files
     */
    public function testDoesNotLoadNonPluginFiles(): void
    {
        // Create a non-plugin PHP file
        $nonPluginCode = <<<'PHP'
<?php

namespace Whity\Plugins;

class NotAPlugin
{
    public function doSomething()
    {
        return 'not a plugin';
    }
}
PHP;

        file_put_contents($this->tempDir . '/NotAPlugin.php', $nonPluginCode);

        // Load plugins
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Verify no plugins were loaded
        $plugins = $loader->getPlugins();
        $this->assertCount(0, $plugins);
    }

    /**
     * Test loader logs a warning and skips class if it does not implement PluginInterface
     */
    public function testSkipsNonPluginClassWithWarning(): void
    {
        // Create a non-plugin class file
        $nonPluginCode = <<<'PHP'
<?php

namespace Whity\Plugins;

class InvalidPluginNotImplementingInterface
{
    public function doSomething()
    {
        return 'invalid';
    }
}
PHP;

        file_put_contents($this->tempDir . '/InvalidPluginNotImplementingInterface.php', $nonPluginCode);

        // Mock Logger
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('does not implement PluginInterface'));

        // Load plugins with mocked logger
        $loader = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $loader->load();

        // Verify no plugins were registered
        $this->assertCount(0, $loader->getPlugins());
    }

    /**
     * Test registers permissions and hooks when loader runs
     */
    public function testRegistersPermissionsAndHooks(): void
    {
        $pluginCode = <<<'PHP'
<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class TestPermissionsAndHooksPlugin implements PluginInterface
{
    public function getName(): string { return 'TestPermissionsAndHooksPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    
    public function getPermissions(): array {
        return ['test.permission.one', 'test.permission.two'];
    }
    
    public function getHooks(): array {
        return [
            'test.hook.event' => [$this, 'handleHook']
        ];
    }
    
    public function getMigrations(): array { return []; }
    
    public function handleHook(array $data, array $context): array {
        $data['hook_called'] = true;
        return $data;
    }
}
PHP;

        file_put_contents($this->tempDir . '/TestPermissionsAndHooksPlugin.php', $pluginCode);

        // Mock/Instantiate dependencies
        $permissionRegistry = new \Whity\Core\RBAC\PermissionRegistry();
        $hookManager = new \Whity\Core\Hooks\HookManager();

        // Load plugins
        $loader = new PluginLoader($this->tempDir, $this->router, $permissionRegistry, $hookManager);
        $loader->load();

        // Verify permissions are registered
        $this->assertTrue($permissionRegistry->permissionExists('test.permission.one'));
        $this->assertTrue($permissionRegistry->permissionExists('test.permission.two'));

        // Verify hooks are registered and callable
        $listeners = $hookManager->getListeners('test.hook.event');
        $this->assertNotEmpty($listeners);
        
        $result = $hookManager->dispatch('test.hook.event', ['initial' => 'value']);
        $this->assertTrue($result['hook_called']);
    }

    /**
     * Test loads multiple plugins from directory
     */
    public function testLoadsMultiplePlugins(): void
    {
        // Create first plugin
        $plugin1Code = <<<'PHP'
<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class TestAdminStats1 implements PluginInterface
{
    public function getName(): string { return 'TestAdminStats1'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/admin/stats',
                'handler' => [$this, 'handle'],
                'requiredRole' => 'admin',
            ]
        ];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }

    public function handle(Request $request): Response
    {
        return Response::json(['stats' => 'data']);
    }
}
PHP;

        // Create second plugin
        $plugin2Code = <<<'PHP'
<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class TestHealthCheck1 implements PluginInterface
{
    public function getName(): string { return 'TestHealthCheck1'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/health',
                'handler' => [$this, 'handle'],
                'requiredRole' => null,
            ]
        ];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }

    public function handle(Request $request): Response
    {
        return Response::json(['status' => 'healthy']);
    }
}
PHP;

        file_put_contents($this->tempDir . '/TestAdminStats1.php', $plugin1Code);
        file_put_contents($this->tempDir . '/TestHealthCheck1.php', $plugin2Code);

        // Load plugins
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Verify both plugins were loaded
        $plugins = $loader->getPlugins();
        $this->assertCount(2, $plugins);

        // Verify both routes are registered
        $request1 = new Request('GET', '/api/admin/stats');
        $match1 = $this->router->match($request1);
        $this->assertNotNull($match1);
        $this->assertSame('admin', $match1['requiredRole']);

        $request2 = new Request('GET', '/health');
        $match2 = $this->router->match($request2);
        $this->assertNotNull($match2);
        $this->assertNull($match2['requiredRole']);
    }

    /**
     * Test ignores non-PHP files
     */
    public function testIgnoresNonPhpFiles(): void
    {
        // Create non-PHP files
        file_put_contents($this->tempDir . '/readme.txt', 'This is not PHP');
        file_put_contents($this->tempDir . '/config.json', '{}');

        // Load plugins
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Verify no plugins were loaded
        $plugins = $loader->getPlugins();
        $this->assertCount(0, $plugins);
    }

    /**
     * Test plugin handler is invoked correctly
     */
    public function testPluginHandlerIsInvoked(): void
    {
        $pluginCode = <<<'PHP'
<?php

namespace Whity\Plugins;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class TestPlugin implements PluginInterface
{
    public function getName(): string { return 'TestPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'POST',
                'path' => '/test',
                'handler' => [$this, 'handle'],
                'requiredRole' => null,
            ]
        ];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }

    public function handle(Request $request): Response
    {
        return Response::json(['message' => 'test response']);
    }
}
PHP;

        file_put_contents($this->tempDir . '/TestPlugin.php', $pluginCode);

        // Load plugins
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Match route and call handler
        $request = new Request('POST', '/test');
        $match = $this->router->match($request);
        $this->assertNotNull($match);

        // Call handler
        $handler = $match['handler'];
        $response = $handler($request);

        // Verify response
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('test response', $response->getBody());
    }

    /**
     * Test recursive plugin discovery and PSR-4 loading
     */
    public function testRecursiveDiscoveryAndPsr4Loading(): void
    {
        $pluginDirName = 'MyNestedPlugin';
        $pluginSubDir = $this->tempDir . '/' . $pluginDirName;
        mkdir($pluginSubDir, 0755, true);

        $pluginCode = <<<'PHP'
<?php

namespace MyNestedPlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'MyNestedPlugin'; }
    public function getVersion(): string { return '2.0.0'; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/nested/hello',
                'handler' => [$this, 'handle'],
                'requiredRole' => null,
            ]
        ];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }

    public function handle(Request $request): Response
    {
        return Response::json(['nested' => 'ok']);
    }
}
PHP;

        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);

        // Discovers and loads
        $loader = new PluginLoader($this->tempDir, $this->router);
        $discovered = $loader->discover();

        $this->assertArrayHasKey('MyNestedPlugin\\Plugin', $discovered);
        $this->assertSame(
            str_replace('\\', '/', (string) realpath($pluginSubDir . '/Plugin.php')),
            str_replace('\\', '/', (string) realpath($discovered['MyNestedPlugin\\Plugin']))
        );

        $loader->load();
        $plugins = $loader->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertSame('MyNestedPlugin', $plugins[0]->getName());

        // Verify route
        $request = new Request('GET', '/api/nested/hello');
        $match = $this->router->match($request);
        $this->assertNotNull($match);
    }

    /**
     * Test loader skips folders containing no valid plugin class and logs a warning
     */
    public function testSkipsInvalidFoldersWithWarning(): void
    {
        $invalidSubDir = $this->tempDir . '/InvalidPluginFolder';
        mkdir($invalidSubDir, 0755, true);

        // Put a non-plugin PHP file inside
        $nonPluginCode = <<<'PHP'
<?php
namespace InvalidPluginFolder;
class HelperClass {
    public function run() {}
}
PHP;
        file_put_contents($invalidSubDir . '/HelperClass.php', $nonPluginCode);

        // Mock Logger
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('No valid plugin class found in directory'));

        $loader = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $discovered = $loader->discover();

        $this->assertCount(0, $discovered);
    }

    /**
     * Test loader creates manifest cache and reads from it
     */
    public function testManifestCaching(): void
    {
        $pluginDirName = 'CachedPlugin';
        $pluginSubDir = $this->tempDir . '/' . $pluginDirName;
        mkdir($pluginSubDir, 0755, true);

        $pluginCode = <<<'PHP'
<?php

namespace CachedPlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'CachedPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;

        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);
        $cacheFile = $this->tempDir . '/manifest.json';

        // 1. First run: cache is empty, scans the directory and saves it
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->enableCache($cacheFile);
        $this->assertFileDoesNotExist($cacheFile);

        $discovered = $loader->discover();
        $this->assertArrayHasKey('CachedPlugin\\Plugin', $discovered);
        $this->assertFileExists($cacheFile);

        // Verify cache file content
        $cacheContent = json_decode((string) file_get_contents($cacheFile), true);
        $this->assertArrayHasKey('plugins', $cacheContent);
        $this->assertArrayHasKey('CachedPlugin\\Plugin', $cacheContent['plugins']);

        // 2. Second run: load using the cached manifest
        $loader2 = new PluginLoader($this->tempDir, $this->router);
        $loader2->enableCache($cacheFile);

        // We modify the cache file to point to a simulated class name to verify it was read from cache
        $cacheContent['plugins'] = ['CachedPlugin\\Plugin' => $cacheContent['plugins']['CachedPlugin\\Plugin']];
        file_put_contents($cacheFile, json_encode($cacheContent));

        $discovered2 = $loader2->discover();
        $this->assertArrayHasKey('CachedPlugin\\Plugin', $discovered2);
    }

    /**
     * Test loader catches constructor errors in plugins, logs them, and continues loading
     */
    public function testReflectionLoadingWithConstructorError(): void
    {
        // 1. Create a plugin with constructor error
        $badPluginCode = <<<'PHP'
<?php
namespace Whity\Plugins;
use Whity\Core\PluginInterface;
class BadPlugin implements PluginInterface
{
    public function __construct()
    {
        throw new \Exception("Constructor error in bad plugin");
    }
    public function getName(): string { return 'BadPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;

        // 2. Create a good plugin
        $goodPluginCode = <<<'PHP'
<?php
namespace Whity\Plugins;
use Whity\Core\PluginInterface;
class GoodPlugin implements PluginInterface
{
    public function getName(): string { return 'GoodPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;

        file_put_contents($this->tempDir . '/BadPlugin.php', $badPluginCode);
        file_put_contents($this->tempDir . '/GoodPlugin.php', $goodPluginCode);

        // Mock Logger to expect an error log
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Constructor error in bad plugin'));

        $loader = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $loader->load();

        // Check that GoodPlugin was loaded but BadPlugin was not
        $plugins = $loader->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertSame('GoodPlugin', $plugins[0]->getName());
    }

    /**
     * Test routes are registered in the Router with correct plugin namespace prefix
     */
    public function testRoutesRegisteredWithNamespacePrefix(): void
    {
        $pluginCode = <<<'PHP'
<?php
namespace MyPrefixPlugin;
use Whity\Core\PluginInterface;
class Plugin implements PluginInterface
{
    public function getName(): string { return 'MyPrefixPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/prefix/hello',
                'handler' => [$this, 'handle'],
                'requiredRole' => null,
            ]
        ];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle($request) {}
}
PHP;

        $pluginSubDir = $this->tempDir . '/MyPrefixPlugin';
        mkdir($pluginSubDir, 0755, true);
        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Retrieve registered routes from the router and check namespace prefix
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes);

        $matched = false;
        foreach ($routes as $route) {
            if ($route['path'] === '/api/prefix/hello') {
                $this->assertSame('MyPrefixPlugin', $route['namespacePrefix']);
                $matched = true;
            }
        }
        $this->assertTrue($matched, 'Route should be registered with correct namespace prefix.');
    }

    /**
     * AC #1: A new plugin folder dropped into /plugins/ while the worker is alive
     * is discovered and its routes become accessible on the next reload() call.
     */
    public function testReloadDiscoversNewlyAddedPlugin(): void
    {
        // First load: empty plugin directory
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $this->assertCount(0, $loader->getPlugins());

        // Drop a new plugin folder onto disk after the first load (simulating a
        // running worker)
        $pluginSubDir = $this->tempDir . '/HotAddedPlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginCode = <<<'PHP'
<?php

namespace HotAddedPlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'HotAddedPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/hot/added',
            'handler' => [$this, 'handle'],
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request $request): Response { return Response::json(['ok' => true]); }
}
PHP;
        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);

        // Reload picks up the change
        $reloaded = $loader->reload();
        $this->assertTrue($reloaded, 'reload() should report that a change was applied');

        $this->assertCount(1, $loader->getPlugins());
        $match = $this->router->match(new Request('GET', '/api/hot/added'));
        $this->assertNotNull($match, 'Newly added plugin route should be accessible after reload');
    }

    /**
     * reload() is a cheap no-op when nothing on disk changed.
     */
    public function testReloadIsNoOpWhenNothingChanged(): void
    {
        $pluginSubDir = $this->tempDir . '/StablePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginCode = <<<'PHP'
<?php

namespace StablePlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'StablePlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;
        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $this->assertCount(1, $loader->getPlugins());

        // No disk change -> reload reports nothing happened and does not duplicate plugins
        $this->assertFalse($loader->reload(), 'reload() should be a no-op when nothing changed');
        $this->assertCount(1, $loader->getPlugins());
    }

    /**
     * AC #2: Modifying an existing plugin file causes the next reload() to run the
     * UPDATED code rather than the stale in-memory class.
     */
    public function testReloadPicksUpModifiedPluginCode(): void
    {
        $pluginSubDir = $this->tempDir . '/MutablePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';

        $makeCode = static function (string $version): string {
            return <<<PHP
<?php

namespace MutablePlugin;

use Whity\\Core\\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'MutablePlugin'; }
    public function getVersion(): string { return '{$version}'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;
        };

        file_put_contents($pluginFile, $makeCode('1.0.0'));

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $this->assertCount(1, $loader->getPlugins());
        $this->assertSame('1.0.0', $loader->getPlugins()[0]->getVersion());

        // Rewrite the plugin file with new code. Bump mtime explicitly so the
        // change is detectable even on coarse-grained filesystem clocks.
        file_put_contents($pluginFile, $makeCode('2.0.0'));
        touch($pluginFile, time() + 5);
        clearstatcache();

        $reloaded = $loader->reload();
        $this->assertTrue($reloaded, 'reload() should detect the modified file');

        $plugins = $loader->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(
            '2.0.0',
            $plugins[0]->getVersion(),
            'reload() must instantiate the UPDATED plugin code, not the cached class'
        );
    }

    /**
     * Removal handling: deleting a plugin folder unregisters its routes and hooks
     * on the next reload().
     */
    public function testReloadUnregistersRemovedPluginRoutesAndHooks(): void
    {
        $hookManager = new \Whity\Core\Hooks\HookManager();

        $pluginSubDir = $this->tempDir . '/RemovablePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';
        $pluginCode = <<<'PHP'
<?php

namespace RemovablePlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'RemovablePlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/removable/ping',
            'handler' => [$this, 'handle'],
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array
    {
        return ['removable.event' => [$this, 'onEvent']];
    }
    public function getMigrations(): array { return []; }
    public function handle(Request $request): Response { return Response::json(['ok' => true]); }
    public function onEvent(array $data, array $context): array { $data['seen'] = true; return $data; }
}
PHP;
        file_put_contents($pluginFile, $pluginCode);

        $loader = new PluginLoader($this->tempDir, $this->router, null, $hookManager);
        $loader->load();

        // Route + hook are live
        $this->assertNotNull($this->router->match(new Request('GET', '/api/removable/ping')));
        $this->assertNotEmpty($hookManager->getListeners('removable.event'));

        // Delete the plugin folder from disk (simulating removal at runtime)
        $this->removeDirectory($pluginSubDir);
        clearstatcache();

        $reloaded = $loader->reload();
        $this->assertTrue($reloaded, 'reload() should detect the removed plugin');

        $this->assertCount(0, $loader->getPlugins());
        $this->assertNull(
            $this->router->match(new Request('GET', '/api/removable/ping')),
            'Removed plugin route should no longer match'
        );
        $this->assertEmpty(
            $hookManager->getListeners('removable.event'),
            'Removed plugin hooks should be unregistered'
        );
    }

    /**
     * The loader exposes a stable fingerprint of the plugin tree so callers can
     * cheaply decide whether a reload is warranted.
     */
    public function testFingerprintChangesWhenPluginFilesChange(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $emptyFingerprint = $loader->getFingerprint();

        $pluginSubDir = $this->tempDir . '/FingerprintPlugin';
        mkdir($pluginSubDir, 0755, true);
        file_put_contents(
            $pluginSubDir . '/Plugin.php',
            "<?php\nnamespace FingerprintPlugin;\nuse Whity\\Core\\PluginInterface;\n"
            . "class Plugin implements PluginInterface {\n"
            . "    public function getName(): string { return 'FingerprintPlugin'; }\n"
            . "    public function getVersion(): string { return '1.0.0'; }\n"
            . "    public function getRoutes(): array { return []; }\n"
            . "    public function getPermissions(): array { return []; }\n"
            . "    public function getHooks(): array { return []; }\n"
            . "    public function getMigrations(): array { return []; }\n}\n"
        );
        clearstatcache();

        $this->assertNotSame(
            $emptyFingerprint,
            $loader->getFingerprint(),
            'Fingerprint must change when plugin files are added'
        );
    }

    /**
     * Helper method to recursively remove directory
     */
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

