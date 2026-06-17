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
    /** Backup of APP_ENV so eval-gate tests can toggle it safely (WC-160). */
    private mixed $previousAppEnv = null;
    private bool $hadAppEnv = false;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_plugins_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->router = new Router('');

        $this->hadAppEnv = array_key_exists('APP_ENV', $_ENV);
        $this->previousAppEnv = $_ENV['APP_ENV'] ?? null;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        if ($this->hadAppEnv) {
            $_ENV['APP_ENV'] = $this->previousAppEnv;
        } else {
            unset($_ENV['APP_ENV']);
        }
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
        return ['test:permission_one', 'test:permission_two'];
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
        $this->assertTrue($permissionRegistry->exists('test:permission_one'));
        $this->assertTrue($permissionRegistry->exists('test:permission_two'));

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

        // The manifest now carries a filesystem fingerprint so a warm cache can
        // self-invalidate when a file is modified/added/removed (WC-213).
        $this->assertArrayHasKey('fingerprint', $cacheContent);
    }

    /**
     * WC-213: an in-place modification of a plugin DIRECTORY's contents (here,
     * the entry file edited AND a second plugin class added alongside it) must
     * invalidate a warm manifest cache and force a full rescan, rather than
     * returning the stale cached FQCN map.
     *
     * The loader resolves a plugin's FQCN from its file PATH, so the cleanly
     * observable signal of an edit-in-place is a SECOND plugin class appearing
     * at a brand-new path inside the already-cached directory: the stale cache
     * (keyed on the single original path) never surfaces it, whereas a correct
     * rescan does. The original entry file is also rewritten with a different
     * length so its own "mtime:size" signature shifts.
     *
     * The manifest stores a filesystem fingerprint ("mtime:size" per file). The
     * edits change BOTH file sizes (different content length) AND, via touch()
     * to a future timestamp, the mtime — so the fingerprint reliably differs
     * even on filesystems with whole-second mtime granularity where a same-second
     * rewrite could otherwise keep the mtime unchanged.
     */
    public function testManifestCacheDetectsInPlaceModification(): void
    {
        $pluginSubDir = $this->tempDir . '/MutatingPlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';

        $originalCode = <<<'PHP'
<?php

namespace MutatingPlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'MutatingPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;
        file_put_contents($pluginFile, $originalCode);
        $cacheFile = $this->tempDir . '/manifest.json';

        // First loader: cold cache -> scans + writes the manifest (with fingerprint).
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->enableCache($cacheFile);
        $discovered = $loader->discover();
        $this->assertArrayHasKey('MutatingPlugin\\Plugin', $discovered);
        $this->assertArrayNotHasKey('MutatingPlugin\\Second', $discovered);

        // Edit the directory in place: rewrite the entry file (different length)
        // and add a SECOND plugin class at a new path inside the same folder.
        // touch() bumps mtimes to a future second so the signature differs even
        // under coarse mtime granularity.
        $editedEntry = <<<'PHP'
<?php

namespace MutatingPlugin;

use Whity\Core\PluginInterface;

// Edited in place: this comment lengthens the file so its size changes too.
class Plugin implements PluginInterface
{
    public function getName(): string { return 'MutatingPluginEdited'; }
    public function getVersion(): string { return '2.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;
        file_put_contents($pluginFile, $editedEntry);
        touch($pluginFile, time() + 5);

        $secondFile = $pluginSubDir . '/Second.php';
        file_put_contents($secondFile, <<<'PHP'
<?php

namespace MutatingPlugin;

use Whity\Core\PluginInterface;

class Second implements PluginInterface
{
    public function getName(): string { return 'MutatingPluginSecond'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
        touch($secondFile, time() + 5);
        clearstatcache();

        // A NEW loader on the SAME cache file exercises the warm-cache path.
        $loader2 = new PluginLoader($this->tempDir, $this->router);
        $loader2->enableCache($cacheFile);
        $discovered2 = $loader2->discover();

        // The modified directory must be re-scanned: the newly added class is
        // surfaced rather than the stale cached single-entry map.
        $this->assertArrayHasKey(
            'MutatingPlugin\\Second',
            $discovered2,
            'An in-place directory modification must be re-scanned, surfacing the new class'
        );
        $this->assertArrayHasKey('MutatingPlugin\\Plugin', $discovered2);
    }

    /**
     * WC-213: a brand-new plugin file added to the tree after a warm manifest was
     * written must be discovered by a fresh loader, not masked by the cache.
     */
    public function testManifestCacheDetectsAddedPluginFile(): void
    {
        $firstSubDir = $this->tempDir . '/FirstCachedPlugin';
        mkdir($firstSubDir, 0755, true);
        file_put_contents($firstSubDir . '/Plugin.php', <<<'PHP'
<?php

namespace FirstCachedPlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'FirstCachedPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
        $cacheFile = $this->tempDir . '/manifest.json';

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->enableCache($cacheFile);
        $discovered = $loader->discover();
        $this->assertArrayHasKey('FirstCachedPlugin\\Plugin', $discovered);
        $this->assertArrayNotHasKey('SecondCachedPlugin\\Plugin', $discovered);

        // Add a brand-new plugin AFTER the manifest was written.
        $secondSubDir = $this->tempDir . '/SecondCachedPlugin';
        mkdir($secondSubDir, 0755, true);
        file_put_contents($secondSubDir . '/Plugin.php', <<<'PHP'
<?php

namespace SecondCachedPlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'SecondCachedPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
        clearstatcache();

        $loader2 = new PluginLoader($this->tempDir, $this->router);
        $loader2->enableCache($cacheFile);
        $discovered2 = $loader2->discover();

        $this->assertArrayHasKey(
            'SecondCachedPlugin\\Plugin',
            $discovered2,
            'A newly added plugin file must be discovered despite a warm cache'
        );
        $this->assertArrayHasKey('FirstCachedPlugin\\Plugin', $discovered2);
    }

    /**
     * WC-213: an UNCHANGED tree with a warm, signature-matching manifest is still
     * served from cache (cache HIT, no regression of the caching benefit). The
     * cached map is honoured because the on-disk tree is untouched and the stored
     * fingerprint still matches, so discover() returns the same set.
     */
    public function testManifestCacheStillHitsWhenUnchanged(): void
    {
        $pluginSubDir = $this->tempDir . '/StableCachedPlugin';
        mkdir($pluginSubDir, 0755, true);
        file_put_contents($pluginSubDir . '/Plugin.php', <<<'PHP'
<?php

namespace StableCachedPlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'StableCachedPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
        $cacheFile = $this->tempDir . '/manifest.json';

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->enableCache($cacheFile);
        $first = $loader->discover();
        $this->assertArrayHasKey('StableCachedPlugin\\Plugin', $first);

        // No disk change. A fresh loader on the same cache must still resolve the
        // plugin from the matching-fingerprint manifest (cache HIT).
        clearstatcache();
        $loader2 = new PluginLoader($this->tempDir, $this->router);
        $loader2->enableCache($cacheFile);
        $second = $loader2->discover();

        $this->assertSame(
            array_keys($first),
            array_keys($second),
            'An unchanged tree with a matching fingerprint must serve the same cached set'
        );
        $this->assertArrayHasKey('StableCachedPlugin\\Plugin', $second);
    }

    /**
     * WC-213: a manifest written WITHOUT a `fingerprint` key (the pre-WC-213
     * on-disk format a previous worker/deploy may have left behind) is treated as
     * a cache miss and rebuilt — proving the warm-cache path cannot trust a
     * manifest that carries no filesystem signature.
     */
    public function testManifestWithoutFingerprintIsTreatedAsMiss(): void
    {
        $pluginSubDir = $this->tempDir . '/LegacyManifestPlugin';
        mkdir($pluginSubDir, 0755, true);
        file_put_contents($pluginSubDir . '/Plugin.php', <<<'PHP'
<?php

namespace LegacyManifestPlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'LegacyManifestPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
        $cacheFile = $this->tempDir . '/manifest.json';

        // Hand-write an OLD-FORMAT manifest (no `fingerprint` key) that points at
        // a non-existent FQCN. If the loader trusted it blindly it would return
        // the bogus entry; instead it must rescan and find the real plugin.
        file_put_contents($cacheFile, json_encode([
            'scanned_at' => time(),
            'plugins' => ['Bogus\\Stale' => $pluginSubDir . '/Plugin.php'],
        ]));

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->enableCache($cacheFile);
        $discovered = $loader->discover();

        $this->assertArrayHasKey(
            'LegacyManifestPlugin\\Plugin',
            $discovered,
            'A fingerprint-less manifest must be rebuilt from a full rescan'
        );
        $this->assertArrayNotHasKey('Bogus\\Stale', $discovered);
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
     * AC #2 (WC-212): modifying an already-loaded plugin file cannot redefine
     * the class in-process. In development reload() detects the change and
     * requests a worker recycle (so a fresh worker recompiles the new source);
     * the in-process instance keeps serving the OLD code until that recycle.
     */
    public function testReloadPicksUpModifiedPluginCode(): void
    {
        // The reload-of-modified-code path is gated to development (WC-160).
        $_ENV['APP_ENV'] = 'development';

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

        // A content change of an already-loaded class requests a worker recycle.
        $this->assertTrue(
            $loader->consumePendingWorkerRecycle(),
            'A modified already-loaded plugin must request a worker recycle'
        );

        // The class cannot be redefined in-process, so the live instance still
        // reflects the OLD code. The new code only arrives after the recycle
        // respawns a fresh worker that recompiles the (opcache-invalidated)
        // source.
        $plugins = $loader->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(
            '1.0.0',
            $plugins[0]->getVersion(),
            'A modified class cannot be redefined in-process; old code serves until recycle'
        );

        // consume() resets the flag (check-and-clear once per request).
        $this->assertFalse(
            $loader->consumePendingWorkerRecycle(),
            'consumePendingWorkerRecycle() must reset the flag after reading it'
        );
    }

    /**
     * isWorkerRecyclePending() is a NON-destructive peek for status reporting
     * (WC-212): repeated peeks keep returning true, the worker loop's
     * consumePendingWorkerRecycle() remains the SOLE authoritative consume, and
     * a peek by the admin reload handler must NOT defeat that consume.
     *
     * Reproduces the loop-then-handler ordering: in development the worker loop
     * has already reload()ed and advanced the fingerprint/hash before dispatch,
     * so if the in-request handler consumed the flag the loop's later consume
     * would return false and the worker would never recycle — serving stale
     * code forever. A peek must leave the flag intact for the loop.
     */
    public function testIsWorkerRecyclePendingIsNonDestructive(): void
    {
        // The reload-of-modified-code path is gated to development (WC-160).
        $_ENV['APP_ENV'] = 'development';

        $pluginSubDir = $this->tempDir . '/PeekablePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';

        $makeCode = static function (string $version): string {
            return <<<PHP
<?php

namespace PeekablePlugin;

use Whity\\Core\\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'PeekablePlugin'; }
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

        // Modify the already-loaded plugin and reload (sets the recycle flag).
        file_put_contents($pluginFile, $makeCode('2.0.0'));
        touch($pluginFile, time() + 5);
        clearstatcache();

        $this->assertTrue($loader->reload(), 'reload() should detect the modified file');

        // The admin reload handler peeks to REPORT the pending state. Peeking is
        // non-destructive: it returns true and a second peek STILL returns true.
        $this->assertTrue(
            $loader->isWorkerRecyclePending(),
            'isWorkerRecyclePending() must report the pending recycle'
        );
        $this->assertTrue(
            $loader->isWorkerRecyclePending(),
            'isWorkerRecyclePending() must NOT clear the flag (repeated peek still true)'
        );

        // The worker loop owns the single authoritative consume: after a peek,
        // the loop's consume still observes the pending recycle and clears it.
        $this->assertTrue(
            $loader->consumePendingWorkerRecycle(),
            'A peek must not defeat the loop consume; the recycle is still pending'
        );

        // Single-shot consume: the flag is now cleared for both reads.
        $this->assertFalse(
            $loader->consumePendingWorkerRecycle(),
            'consumePendingWorkerRecycle() must reset the flag after reading it'
        );
        $this->assertFalse(
            $loader->isWorkerRecyclePending(),
            'isWorkerRecyclePending() reflects the cleared flag after consume'
        );
    }

    /**
     * The loader never re-evaluates modified source under a `_Whity_Reload_`
     * versioned namespace anymore (WC-212): no class declared in the process
     * carries that segment after a modify+reload, and the live instance keeps
     * its plain FQCN.
     */
    public function testModifiedReloadNeverCreatesVersionedNamespace(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $pluginSubDir = $this->tempDir . '/NoVersionedNamespacePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';

        $makeCode = static function (string $version): string {
            return <<<PHP
<?php

namespace NoVersionedNamespacePlugin;

use Whity\\Core\\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'NoVersionedNamespacePlugin'; }
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

        file_put_contents($pluginFile, $makeCode('2.0.0'));
        touch($pluginFile, time() + 5);
        clearstatcache();

        $loader->reload();

        // No declared class anywhere in the process may carry the old eval-based
        // versioned namespace segment.
        foreach (get_declared_classes() as $declared) {
            $this->assertStringNotContainsString(
                '_Whity_Reload_',
                $declared,
                'The loader must never materialize a _Whity_Reload_ versioned class'
            );
        }

        // The live instance keeps its real, plain FQCN.
        $this->assertSame(
            'NoVersionedNamespacePlugin\\Plugin',
            get_class($loader->getPlugins()[0]),
            'The loaded plugin must keep its plain namespace, never a versioned one'
        );
    }

    /**
     * WC-160/WC-212: a changed-on-disk plugin must NOT start executing outside
     * development, and no worker recycle is signalled — the stale in-memory
     * class keeps serving and the operator must deploy/restart to pick up code.
     */
    public function testModifiedCodeIsNotEvaluatedOutsideDevelopment(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $pluginSubDir = $this->tempDir . '/GatedPlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';

        $makeCode = static function (string $version): string {
            return <<<PHP
<?php

namespace GatedPlugin;

use Whity\\Core\\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'GatedPlugin'; }
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

        file_put_contents($pluginFile, $makeCode('2.0.0'));
        touch($pluginFile, time() + 5);
        clearstatcache();

        $loader->reload();

        $plugins = $loader->getPlugins();
        $this->assertCount(1, $plugins);
        $this->assertSame(
            '1.0.0',
            $plugins[0]->getVersion(),
            'Outside development, modified plugin code must NOT execute — the loaded class stays'
        );
        $this->assertFalse(
            $loader->consumePendingWorkerRecycle(),
            'Outside development a changed plugin must NOT signal a worker recycle'
        );
    }

    /**
     * WC-160/WC-212 fail-safe: with APP_ENV unset the gate treats the
     * environment as non-development — the modified code does not execute and
     * no worker recycle is requested.
     */
    public function testModifiedCodeIsNotEvaluatedWhenAppEnvUnset(): void
    {
        unset($_ENV['APP_ENV']);

        $pluginSubDir = $this->tempDir . '/UnsetEnvPlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';

        $makeCode = static function (string $version): string {
            return <<<PHP
<?php

namespace UnsetEnvPlugin;

use Whity\\Core\\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'UnsetEnvPlugin'; }
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

        file_put_contents($pluginFile, $makeCode('2.0.0'));
        touch($pluginFile, time() + 5);
        clearstatcache();

        $loader->reload();

        $this->assertSame(
            '1.0.0',
            $loader->getPlugins()[0]->getVersion(),
            'With APP_ENV unset the modified code must not execute (fail safe)'
        );
        $this->assertFalse(
            $loader->consumePendingWorkerRecycle(),
            'With APP_ENV unset no worker recycle must be requested (fail safe)'
        );
    }

    /**
     * WC-212: re-touching a plugin file without changing its CONTENTS (same
     * content hash) must NOT signal a worker recycle — there is no new code to
     * load.
     */
    public function testUnchangedContentReloadDoesNotSignalRecycle(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $pluginSubDir = $this->tempDir . '/SameContentPlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginFile = $pluginSubDir . '/Plugin.php';

        $code = <<<'PHP'
<?php

namespace SameContentPlugin;

use Whity\Core\PluginInterface;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'SameContentPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP;
        file_put_contents($pluginFile, $code);

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Touch the file (mtime changes -> fingerprint changes) but the bytes
        // are identical, so the content hash is unchanged.
        file_put_contents($pluginFile, $code);
        touch($pluginFile, time() + 5);
        clearstatcache();

        $this->assertTrue($loader->reload(), 'reload() detects the mtime change');
        $this->assertFalse(
            $loader->consumePendingWorkerRecycle(),
            'Identical content must NOT request a worker recycle'
        );
    }

    /**
     * WC-212: adding a brand-new plugin file loads it in-process on the next
     * reload() and does NOT request a worker recycle — a brand-new class loads
     * cleanly without any redefinition.
     */
    public function testAddingPluginDoesNotSignalWorkerRecycle(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $this->assertCount(0, $loader->getPlugins());

        $pluginSubDir = $this->tempDir . '/FreshlyAddedPlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginCode = <<<'PHP'
<?php

namespace FreshlyAddedPlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'FreshlyAddedPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/freshly/added',
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
        clearstatcache();

        $this->assertTrue($loader->reload(), 'reload() detects the new plugin');
        $this->assertCount(1, $loader->getPlugins(), 'New plugin loads in-process');
        $this->assertNotNull($this->router->match(new Request('GET', '/api/freshly/added')));
        $this->assertFalse(
            $loader->consumePendingWorkerRecycle(),
            'Adding a brand-new plugin must NOT request a worker recycle'
        );
    }

    /**
     * WC-212: removing a plugin unregisters it on the next reload() and does
     * NOT request a worker recycle — a removal just tears down capabilities,
     * no code redefinition is involved.
     */
    public function testRemovingPluginDoesNotSignalWorkerRecycle(): void
    {
        $_ENV['APP_ENV'] = 'development';

        $pluginSubDir = $this->tempDir . '/ToRemovePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginCode = <<<'PHP'
<?php

namespace ToRemovePlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'ToRemovePlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/to-remove/ping',
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

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $this->assertCount(1, $loader->getPlugins());

        $this->removeDirectory($pluginSubDir);
        clearstatcache();

        $this->assertTrue($loader->reload(), 'reload() detects the removed plugin');
        $this->assertCount(0, $loader->getPlugins(), 'Removed plugin is unregistered');
        $this->assertFalse(
            $loader->consumePendingWorkerRecycle(),
            'Removing a plugin must NOT request a worker recycle'
        );
    }

    /**
     * WC-212: the eval-based hot-reload primitive is gone. The PluginLoader
     * source must no longer contain an `eval(` call or a `rewriteNamespace`
     * helper.
     */
    public function testEvalAndRewriteNamespaceAreRemovedFromLoaderSource(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(PluginLoader::class))->getFileName()
        );

        $this->assertStringNotContainsString(
            'eval(',
            $source,
            'PluginLoader must not contain an eval() call anymore (WC-212)'
        );
        $this->assertStringNotContainsString(
            'rewriteNamespace',
            $source,
            'PluginLoader must not contain a rewriteNamespace helper anymore (WC-212)'
        );
        $this->assertStringNotContainsString(
            '_Whity_Reload_',
            $source,
            'PluginLoader must not reference the versioned-namespace prefix anymore (WC-212)'
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
     * WC-10 AC #2: disabling an active plugin unregisters its routes (via WC-8's
     * unregisterByNamespace) and transitions its lifecycle to 'disabled'.
     */
    public function testDisablePluginUnregistersRoutesAndHooksAndSetsDisabled(): void
    {
        $hookManager = new \Whity\Core\Hooks\HookManager();

        $pluginSubDir = $this->tempDir . '/DisablablePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginCode = <<<'PHP'
<?php

namespace DisablablePlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'DisablablePlugin'; }
    public function getVersion(): string { return '1.2.3'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/disablable/ping',
            'handler' => [$this, 'handle'],
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return ['disablable:do']; }
    public function getHooks(): array
    {
        return ['disablable.event' => [$this, 'onEvent']];
    }
    public function getMigrations(): array { return []; }
    public function handle(Request $request): Response { return Response::json(['ok' => true]); }
    public function onEvent(array $data, array $context): array { $data['seen'] = true; return $data; }
}
PHP;
        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);

        $loader = new PluginLoader($this->tempDir, $this->router, null, $hookManager);
        $loader->load();

        $key = 'DisablablePlugin\\Plugin';

        // Route + hook are live and the plugin is active.
        $this->assertNotNull($this->router->match(new Request('GET', '/api/disablable/ping')));
        $this->assertNotEmpty($hookManager->getListeners('disablable.event'));
        $this->assertSame(\Whity\Core\PluginState::Active, $loader->getLifecycle($key)?->getState());

        // Disable the plugin at runtime.
        $disabled = $loader->disablePlugin($key);
        $this->assertTrue($disabled, 'disablePlugin() should report success for a known plugin');

        // Routes are unregistered, hooks removed, lifecycle is disabled.
        $this->assertNull(
            $this->router->match(new Request('GET', '/api/disablable/ping')),
            'Disabled plugin route should no longer match'
        );
        $this->assertEmpty(
            $hookManager->getListeners('disablable.event'),
            'Disabled plugin hooks should be unregistered'
        );
        $this->assertSame(\Whity\Core\PluginState::Disabled, $loader->getLifecycle($key)?->getState());
    }

    /**
     * Disabling an unknown plugin reports failure without side effects.
     */
    public function testDisableUnknownPluginReturnsFalse(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $this->assertFalse($loader->disablePlugin('Nope\\Missing'));
    }

    /**
     * Re-enabling a previously disabled plugin restores its routes and hooks so
     * it can serve traffic again, returning the lifecycle to 'active'.
     */
    public function testReEnableRestoresRoutesAndHooksAfterDisable(): void
    {
        $hookManager = new \Whity\Core\Hooks\HookManager();

        $pluginSubDir = $this->tempDir . '/CyclePlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginCode = <<<'PHP'
<?php

namespace CyclePlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'CyclePlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/cycle/ping',
            'handler' => [$this, 'handle'],
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array
    {
        return ['cycle.event' => [$this, 'onEvent']];
    }
    public function getMigrations(): array { return []; }
    public function handle(Request $request): Response { return Response::json(['ok' => true]); }
    public function onEvent(array $data, array $context): array { $data['seen'] = true; return $data; }
}
PHP;
        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);

        $loader = new PluginLoader($this->tempDir, $this->router, null, $hookManager);
        $loader->load();

        $key = 'CyclePlugin\\Plugin';

        $this->assertTrue($loader->disablePlugin($key));
        $this->assertNull($this->router->match(new Request('GET', '/api/cycle/ping')));

        $this->assertTrue($loader->reEnablePlugin($key));
        $this->assertSame(\Whity\Core\PluginState::Active, $loader->getLifecycle($key)?->getState());
        $this->assertNotNull(
            $this->router->match(new Request('GET', '/api/cycle/ping')),
            'Re-enabled plugin route should match again'
        );
        $this->assertNotEmpty(
            $hookManager->getListeners('cycle.event'),
            'Re-enabled plugin hooks should be restored'
        );
    }

    /**
     * WC-10 AC #1: per-plugin metadata exposes name, version, status, and the
     * route/permission counts the admin API surfaces.
     */
    public function testGetPluginMetadataExposesVersionStatusAndCounts(): void
    {
        $pluginSubDir = $this->tempDir . '/MetaPlugin';
        mkdir($pluginSubDir, 0755, true);
        $pluginCode = <<<'PHP'
<?php

namespace MetaPlugin;

use Whity\Core\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return 'MetaPlugin'; }
    public function getVersion(): string { return '3.1.4'; }
    public function getRoutes(): array
    {
        return [
            ['method' => 'GET', 'path' => '/api/meta/a', 'handler' => [$this, 'handle'], 'requiredRole' => null],
            ['method' => 'POST', 'path' => '/api/meta/b', 'handler' => [$this, 'handle'], 'requiredRole' => null],
        ];
    }
    public function getPermissions(): array { return ['meta:read', 'meta:write', 'meta:delete']; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request $request): Response { return Response::json(['ok' => true]); }
}
PHP;
        file_put_contents($pluginSubDir . '/Plugin.php', $pluginCode);

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $metadata = $loader->getPluginMetadata();
        $this->assertCount(1, $metadata);

        $meta = $metadata[0];
        $this->assertSame('MetaPlugin\\Plugin', $meta['id']);
        $this->assertSame('MetaPlugin', $meta['name']);
        $this->assertSame('3.1.4', $meta['version']);
        $this->assertSame('active', $meta['status']);
        $this->assertSame(2, $meta['routes_count']);
        $this->assertSame(3, $meta['permissions_count']);
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

    // -----------------------------------------------------------------------
    // WC-208: planUninstall / uninstallPlugin
    // -----------------------------------------------------------------------

    /**
     * Helper: write a minimal valid plugin file to the temp directory.
     * Returns the plugin's FQCN key as loaded by PluginLoader.
     */
    private function writeMinimalPlugin(string $className, string $routePath = '/api/uninstall-test'): string
    {
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Core\\PluginInterface;
use Whity\\Core\\Request;
use Whity\\Core\\Response;

class {$className} implements PluginInterface
{
    public function getName(): string { return '{$className}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [['method' => 'GET', 'path' => '{$routePath}', 'handler' => [\$this, 'handle'], 'requiredRole' => null]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request \$request): Response { return Response::json(['ok' => true]); }
}
PHP;
        file_put_contents($this->tempDir . '/' . $className . '.php', $code);
        return 'Whity\\Plugins\\' . $className;
    }

    public function testPlanUninstallReturnsMigrationsAndDirectoryPath(): void
    {
        $key = $this->writeMinimalPlugin('PlanUninstallPlugin', '/api/plan-uninstall');
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $plan = $loader->planUninstall($key);

        $this->assertNotNull($plan);
        $this->assertSame('PlanUninstallPlugin', $plan['plugin']);
        $this->assertArrayHasKey('status', $plan);
        $this->assertIsArray($plan['migrations_to_roll_back']);
        $this->assertArrayHasKey('directory', $plan);
        $this->assertArrayHasKey('will_remove_directory', $plan);
        // No mutations: plugin file must still exist.
        $this->assertFileExists($this->tempDir . '/PlanUninstallPlugin.php');
    }

    public function testPlanUninstallReturnsNullForUnknownPlugin(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $plan = $loader->planUninstall('Whity\\Plugins\\GhostPlugin');

        $this->assertNull($plan);
    }

    public function testUninstallPluginDisablesRollsBackAndRemovesDirectory(): void
    {
        $key = $this->writeMinimalPlugin('UninstallMePlugin', '/api/uninstall-me');
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        // Route must be live before uninstall.
        $this->assertNotNull($this->router->match(new Request('GET', '/api/uninstall-me')));

        // Use a fresh SQLite PDO — no real DB needed.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');

        $result = $loader->uninstallPlugin($key, $pdo);

        $this->assertTrue($result['disabled']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['directory_removed']);
        // Route must be gone.
        $this->assertNull($this->router->match(new Request('GET', '/api/uninstall-me')));
        // File must be gone.
        $this->assertFileDoesNotExist($this->tempDir . '/UninstallMePlugin.php');
    }

    /**
     * Verifies that when migration rollback succeeds (no errors), the plugin
     * directory is removed even when $force = false.
     *
     * Note: the true abort-on-error path (directory_removed = false with errors)
     * requires a PDO that fails on DELETE but not on SELECT; that scenario is
     * covered at the API handler integration level where a real DB can be
     * configured to fail mid-rollback.
     */
    public function testUninstallWithMigrationRollbackSucceedsAndRemovesDirectory(): void
    {
        $key = $this->writeMinimalPlugin('AbortPlugin', '/api/abort-uninstall');
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $pdoWithTable = new \PDO('sqlite::memory:');
        $pdoWithTable->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdoWithTable->exec('CREATE TABLE core_schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            execution_time_ms INTEGER
        )');
        $pdoWithTable->exec("INSERT INTO core_schema_migrations (migration_name, executed_at) VALUES ('plugin:AbortPlugin:CreateFoo', datetime('now'))");

        $result = $loader->uninstallPlugin($key, $pdoWithTable, false);

        $this->assertTrue($result['disabled']);
        // The one migration row was removed successfully — no errors, so dir is removed.
        $this->assertCount(1, $result['migrations_rolled_back']);
        $this->assertSame([], $result['errors']);
        $this->assertTrue($result['directory_removed']);
    }

    // -----------------------------------------------------------------------
    // WC-210: persistent admin lifecycle signal -> cross-worker convergence
    // -----------------------------------------------------------------------

    /**
     * Write a directory plugin (plugins/<Dir>/Plugin.php) declaring one GET
     * route, returning its FQCN key. Mirrors the directory-plugin layout used
     * by the lifecycle tests above.
     */
    private function writeDirectoryPlugin(string $dir, string $routePath): string
    {
        $subDir = $this->tempDir . '/' . $dir;
        if (!is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }
        $code = <<<PHP
<?php

namespace {$dir};

use Whity\\Core\\PluginInterface;
use Whity\\Core\\Request;
use Whity\\Core\\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return '{$dir}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [['method' => 'GET', 'path' => '{$routePath}', 'handler' => [\$this, 'handle'], 'requiredRole' => null]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request \$request): Response { return Response::json(['ok' => true]); }
}
PHP;
        file_put_contents($subDir . '/Plugin.php', $code);
        return $dir . '\\Plugin';
    }

    /**
     * THE crux (WC-210): disabling a directory plugin through loader A must
     * persist a signal so a SECOND loader over the same plugins dir (another
     * FrankenPHP worker) converges on the Disabled state on its next load(),
     * with the plugin's routes NOT registered.
     */
    public function testDisablePersistsSignalSoAFreshLoaderSeesDisabled(): void
    {
        $key = $this->writeDirectoryPlugin('WorkerConvergePlugin', '/api/worker-converge/ping');

        $loaderA = new PluginLoader($this->tempDir, $this->router);
        $loaderA->load();
        $this->assertSame(\Whity\Core\PluginState::Active, $loaderA->getLifecycle($key)?->getState());

        $this->assertTrue($loaderA->disablePlugin($key));
        // The persisted sentinel must exist on disk.
        $this->assertFileExists($this->tempDir . '/WorkerConvergePlugin/.disabled');

        // A SECOND worker loads the same dir into its own router.
        $routerB = new Router('');
        $loaderB = new PluginLoader($this->tempDir, $routerB);
        $loaderB->load();

        $this->assertSame(
            \Whity\Core\PluginState::Disabled,
            $loaderB->getLifecycle($key)?->getState(),
            'A fresh loader must converge on the persisted Disabled state'
        );
        $this->assertNull(
            $routerB->match(new Request('GET', '/api/worker-converge/ping')),
            'A fresh loader must NOT register a persistently disabled plugin\'s routes'
        );
    }

    /**
     * Re-enabling clears the persisted signal so a fresh loader sees it Active.
     */
    public function testReEnableClearsSignalSoAFreshLoaderSeesActive(): void
    {
        $key = $this->writeDirectoryPlugin('WorkerReenablePlugin', '/api/worker-reenable/ping');

        $loaderA = new PluginLoader($this->tempDir, $this->router);
        $loaderA->load();
        $this->assertTrue($loaderA->disablePlugin($key));
        $this->assertFileExists($this->tempDir . '/WorkerReenablePlugin/.disabled');

        $this->assertTrue($loaderA->reEnablePlugin($key));
        $this->assertFileDoesNotExist($this->tempDir . '/WorkerReenablePlugin/.disabled');

        $routerB = new Router('');
        $loaderB = new PluginLoader($this->tempDir, $routerB);
        $loaderB->load();

        $this->assertSame(
            \Whity\Core\PluginState::Active,
            $loaderB->getLifecycle($key)?->getState(),
            'A fresh loader must see a re-enabled plugin as Active again'
        );
        $this->assertNotNull(
            $routerB->match(new Request('GET', '/api/worker-reenable/ping')),
            'A fresh loader must register a re-enabled plugin\'s routes'
        );
    }

    /**
     * A directory plugin pre-seeded with a `.disabled` sentinel loads straight
     * into the Disabled state on first discovery (no in-process disable call).
     */
    public function testDirectorySentinelHonoredOnDiscovery(): void
    {
        $key = $this->writeDirectoryPlugin('PreDisabledPlugin', '/api/pre-disabled/ping');
        file_put_contents($this->tempDir . '/PreDisabledPlugin/.disabled', '');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $this->assertSame(\Whity\Core\PluginState::Disabled, $loader->getLifecycle($key)?->getState());
        $this->assertNull($this->router->match(new Request('GET', '/api/pre-disabled/ping')));
    }

    /**
     * WC-210 regression: writing a directory plugin's `.disabled` sentinel must
     * make reload() converge that worker on the Disabled state — not only a full
     * restart. The sentinel is a non-`.php` file, so the change-detection
     * fingerprint must account for it; otherwise reload() is a no-op and the
     * documented per-worker convergence (and the /api/plugins meta note) is a
     * lie for directory plugins (the SDK's recommended layout).
     */
    public function testReloadConvergesDirectoryPluginWhenSentinelAppearsOnDisk(): void
    {
        $key = $this->writeDirectoryPlugin('ReloadConvergePlugin', '/api/reload-converge/ping');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $this->assertSame(\Whity\Core\PluginState::Active, $loader->getLifecycle($key)?->getState());
        $this->assertNotNull($this->router->match(new Request('GET', '/api/reload-converge/ping')));

        // Another worker persisted a disable: the sentinel appears on disk while
        // every `.php` source is byte-for-byte unchanged.
        file_put_contents($this->tempDir . '/ReloadConvergePlugin/.disabled', '');

        $this->assertTrue(
            $loader->reload(),
            'reload() must detect the new .disabled sentinel even though no .php file changed'
        );
        $this->assertSame(
            \Whity\Core\PluginState::Disabled,
            $loader->getLifecycle($key)->getState(),
            'reload() must converge the worker on the persisted Disabled state'
        );
        $this->assertNull(
            $this->router->match(new Request('GET', '/api/reload-converge/ping')),
            'reload() must tear down a now-disabled directory plugin\'s routes'
        );

        // And removing the sentinel converges back to Active on the next reload.
        unlink($this->tempDir . '/ReloadConvergePlugin/.disabled');
        $this->assertTrue($loader->reload(), 'reload() must detect sentinel removal');
        $this->assertSame(\Whity\Core\PluginState::Active, $loader->getLifecycle($key)->getState());
        $this->assertNotNull($this->router->match(new Request('GET', '/api/reload-converge/ping')));
    }

    /**
     * Disabling a single-file plugin renames Foo.php -> Foo.php.disabled, which
     * discovery skips; a fresh loader therefore does not register it. The
     * single-file disabled file is still surfaced by the API (covered in the
     * handler test); here we assert the rename is the persisted signal.
     */
    public function testSingleFileDisabledRenameHonored(): void
    {
        $key = $this->writeMinimalPlugin('SingleFileTogglePlugin', '/api/single-toggle/ping');

        $loaderA = new PluginLoader($this->tempDir, $this->router);
        $loaderA->load();
        $this->assertTrue($loaderA->disablePlugin($key));

        $this->assertFileDoesNotExist($this->tempDir . '/SingleFileTogglePlugin.php');
        $this->assertFileExists($this->tempDir . '/SingleFileTogglePlugin.php.disabled');

        // A fresh worker skips the .php.disabled file entirely on discovery.
        $routerB = new Router('');
        $loaderB = new PluginLoader($this->tempDir, $routerB);
        $loaderB->load();
        $this->assertNull($routerB->match(new Request('GET', '/api/single-toggle/ping')));
        $this->assertNull($loaderB->getLifecycle($key));
    }

    /**
     * Disabling an already-disabled plugin and re-enabling an already-enabled
     * one are safe no-ops that leave the disk signal coherent.
     */
    public function testDisableAndReEnableAreIdempotent(): void
    {
        $key = $this->writeDirectoryPlugin('IdempotentPlugin', '/api/idempotent/ping');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $this->assertTrue($loader->disablePlugin($key));
        // Second disable: the lifecycle is already Disabled; signal stays put.
        $this->assertTrue($loader->disablePlugin($key));
        $this->assertFileExists($this->tempDir . '/IdempotentPlugin/.disabled');

        $this->assertTrue($loader->reEnablePlugin($key));
        // Second re-enable: already Active; signal stays cleared.
        $this->assertTrue($loader->reEnablePlugin($key));
        $this->assertFileDoesNotExist($this->tempDir . '/IdempotentPlugin/.disabled');
    }

    /**
     * A traversal-style identifier cannot escape the plugins dir when persisting
     * a signal: disablePlugin() reports false for an unknown/unsafe key and
     * writes nothing outside the plugin directory.
     */
    public function testTraversalSafeIdentifierWritesNothing(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $sentinelOutside = dirname($this->tempDir) . '/.disabled';
        @unlink($sentinelOutside);

        $this->assertFalse($loader->disablePlugin('..\\..\\Evil'));
        $this->assertFalse($loader->disablePlugin('Nope\\Missing'));
        $this->assertFileDoesNotExist($sentinelOutside);
    }

    /**
     * Auto-fail (consecutive errors) must remain worker-local: it must NOT write
     * any disk signal, so a fresh loader sees the plugin Active. This proves the
     * auto-fail path is deliberately not persisted (deferred to Phase-F).
     */
    public function testAutoFailDoesNotPersistAnyDiskSignal(): void
    {
        $key = $this->writeDirectoryPlugin('FlakyPlugin', '/api/flaky/ping');

        $loaderA = new PluginLoader($this->tempDir, $this->router);
        $loaderA->load();

        // Trip the lifecycle into Failed via consecutive recorded errors.
        $lifecycle = $loaderA->getLifecycle($key);
        $this->assertNotNull($lifecycle);
        for ($i = 0; $i < \Whity\Core\PluginLifecycle::MAX_CONSECUTIVE_ERRORS; $i++) {
            $lifecycle->recordError(new \RuntimeException('boom'));
        }
        $this->assertSame(\Whity\Core\PluginState::Failed, $lifecycle->getState());

        // No disk signal was written for the auto-fail.
        $this->assertFileDoesNotExist($this->tempDir . '/FlakyPlugin/.disabled');
        $this->assertFileExists($this->tempDir . '/FlakyPlugin/Plugin.php');

        // A fresh worker therefore loads the plugin as Active — auto-fail is local.
        $routerB = new Router('');
        $loaderB = new PluginLoader($this->tempDir, $routerB);
        $loaderB->load();
        $this->assertSame(\Whity\Core\PluginState::Active, $loaderB->getLifecycle($key)?->getState());
        $this->assertNotNull($routerB->match(new Request('GET', '/api/flaky/ping')));
    }
}

