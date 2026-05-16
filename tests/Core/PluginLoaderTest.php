<?php

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

class AdminStats implements PluginInterface
{
    public function getRoute(): string
    {
        return '/api/admin/stats';
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getRequiredRole(): ?string
    {
        return 'admin';
    }

    public function handle(Request $request): Response
    {
        return Response::json(['stats' => 'data']);
    }
}
PHP;

        file_put_contents($this->tempDir . '/AdminStats.php', $pluginCode);

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

class AdminStats implements PluginInterface
{
    public function getRoute(): string
    {
        return '/api/admin/stats';
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getRequiredRole(): ?string
    {
        return 'admin';
    }

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

class HealthCheck implements PluginInterface
{
    public function getRoute(): string
    {
        return '/health';
    }

    public function getMethod(): string
    {
        return 'GET';
    }

    public function getRequiredRole(): ?string
    {
        return null;
    }

    public function handle(Request $request): Response
    {
        return Response::json(['status' => 'healthy']);
    }
}
PHP;

        file_put_contents($this->tempDir . '/AdminStats.php', $plugin1Code);
        file_put_contents($this->tempDir . '/HealthCheck.php', $plugin2Code);

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
    public function getRoute(): string
    {
        return '/test';
    }

    public function getMethod(): string
    {
        return 'POST';
    }

    public function getRequiredRole(): ?string
    {
        return null;
    }

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
