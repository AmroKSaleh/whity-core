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

    private function writeMetadataPlugin(string $class, string $path): void
    {
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Core\\PluginInterface;
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

use Whity\\Core\\PluginInterface;
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
