<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\PluginLoader;
use Whity\Core\PluginState;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Hooks\HookManager;

/**
 * Integration tests for per-plugin error isolation in PluginLoader.
 *
 * These tests reuse the fixture-plugin pattern (writing plugin source to a temp
 * directory) established in PluginLoaderTest.
 */
class PluginIsolationTest extends TestCase
{
    private string $tempDir;
    private Router $router;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_isolation_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->router = new Router('');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * AC #1: Plugin A throws during request handling; Plugin B still operates
     * normally, and Plugin A's error is logged with a stack trace.
     */
    public function testFailingPluginIsIsolatedFromHealthyPlugin(): void
    {
        $this->writeThrowingPlugin('FailingPlugin', '/api/fail/run');
        $this->writeHealthyPlugin('HealthyPlugin', '/api/ok/run');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Plugin'),
                $this->callback(static function (array $context): bool {
                    return isset($context['exception'], $context['trace'])
                        && $context['trace'] !== '';
                })
            );

        $loader = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $loader->load();

        // Invoke the failing plugin route - the wrapper must swallow the Throwable
        // and return a safe 500 response (never leak the raw exception message).
        $failMatch = $this->router->match(new Request('GET', '/api/fail/run'));
        $this->assertNotNull($failMatch);
        $failResponse = ($failMatch['handler'])(new Request('GET', '/api/fail/run'), []);

        $this->assertInstanceOf(Response::class, $failResponse);
        $this->assertSame(500, $failResponse->getStatusCode());
        $this->assertStringNotContainsString('intentional plugin explosion', $failResponse->getBody());

        // Plugin B is unaffected and handles its request normally.
        $okMatch = $this->router->match(new Request('GET', '/api/ok/run'));
        $this->assertNotNull($okMatch);
        $okResponse = ($okMatch['handler'])(new Request('GET', '/api/ok/run'), []);
        $this->assertSame(200, $okResponse->getStatusCode());
        $this->assertStringContainsString('healthy', $okResponse->getBody());
    }

    /**
     * AC #2: After 3 consecutive errors the plugin transitions to 'failed', the
     * status surface reflects it with error details, and it can be re-enabled.
     */
    public function testPluginFailsAfterThreeConsecutiveErrorsAndCanBeReEnabled(): void
    {
        $this->writeThrowingPlugin('FlakyPlugin', '/api/flaky/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $key = 'Whity\\Plugins\\FlakyPlugin';
        $this->assertSame(PluginState::Active, $loader->getLifecycle($key)?->getState());

        $match = $this->router->match(new Request('GET', '/api/flaky/run'));
        $this->assertNotNull($match);
        $handler = $match['handler'];

        // Three failed invocations push the plugin into the failed state.
        for ($i = 0; $i < 3; $i++) {
            $response = $handler(new Request('GET', '/api/flaky/run'), []);
            $this->assertSame(500, $response->getStatusCode());
        }

        $this->assertSame(PluginState::Failed, $loader->getLifecycle($key)?->getState());

        // Admin status surface shows the failed plugin with error details.
        $statuses = $loader->getPluginStatuses();
        $flaky = null;
        foreach ($statuses as $status) {
            if ($status['id'] === $key) {
                $flaky = $status;
                break;
            }
        }
        $this->assertNotNull($flaky);
        $this->assertSame('failed', $flaky['state']);
        $this->assertIsArray($flaky['last_error']);
        $this->assertSame('intentional plugin explosion', $flaky['last_error']['message']);

        // Manual re-enable returns the plugin to the active state.
        $this->assertTrue($loader->reEnablePlugin($key));
        $this->assertSame(PluginState::Active, $loader->getLifecycle($key)?->getState());
        $this->assertSame(0, $loader->getLifecycle($key)?->getConsecutiveErrors());
    }

    /**
     * A failed plugin short-circuits to a safe 503 without invoking the plugin
     * code again (the error boundary refuses to run a disabled handler).
     */
    public function testFailedPluginHandlerShortCircuits(): void
    {
        $this->writeThrowingPlugin('DeadPlugin', '/api/dead/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $match = $this->router->match(new Request('GET', '/api/dead/run'));
        $this->assertNotNull($match);
        $handler = $match['handler'];

        for ($i = 0; $i < 3; $i++) {
            $handler(new Request('GET', '/api/dead/run'), []);
        }

        $key = 'Whity\\Plugins\\DeadPlugin';
        $this->assertSame(PluginState::Failed, $loader->getLifecycle($key)?->getState());

        // Subsequent calls are short-circuited with a service-unavailable response.
        $response = $handler(new Request('GET', '/api/dead/run'), []);
        $this->assertSame(503, $response->getStatusCode());
        // Error count stays capped; the plugin code was not executed again.
        $this->assertSame(3, $loader->getLifecycle($key)?->getConsecutiveErrors());
    }

    /**
     * A successful invocation resets the consecutive-error counter so transient
     * failures do not accumulate toward the disable threshold.
     */
    public function testSuccessResetsConsecutiveErrorCounterInBoundary(): void
    {
        $this->writeToggleablePlugin('TogglePlugin', '/api/toggle/run');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $key = 'Whity\\Plugins\\TogglePlugin';
        $match = $this->router->match(new Request('GET', '/api/toggle/run'));
        $this->assertNotNull($match);
        $handler = $match['handler'];

        // Fail twice (header X-Fail), then succeed once -> counter resets.
        $failReq = new Request('GET', '/api/toggle/run', ['X-Fail' => '1']);
        $handler($failReq, []);
        $handler($failReq, []);
        $this->assertSame(2, $loader->getLifecycle($key)?->getConsecutiveErrors());

        $okReq = new Request('GET', '/api/toggle/run');
        $okResponse = $handler($okReq, []);
        $this->assertSame(200, $okResponse->getStatusCode());
        $this->assertSame(0, $loader->getLifecycle($key)?->getConsecutiveErrors());
        $this->assertSame(PluginState::Active, $loader->getLifecycle($key)?->getState());
    }

    /**
     * A hook callback that throws is isolated: dispatch continues, the data is
     * unchanged by the failing listener, and the error is recorded.
     */
    public function testFailingHookCallbackIsIsolated(): void
    {
        $hookManager = new HookManager();
        $this->writeThrowingHookPlugin('HookBomb', 'demo.event');

        $loader = new PluginLoader($this->tempDir, $this->router, null, $hookManager);
        $loader->load();

        // Dispatch must not bubble the Throwable out of the hook.
        $result = $hookManager->dispatch('demo.event', ['value' => 1]);
        $this->assertSame(['value' => 1], $result);

        $key = 'Whity\\Plugins\\HookBomb';
        $this->assertSame(1, $loader->getLifecycle($key)?->getConsecutiveErrors());
    }

    /**
     * Composition with hot-reload: reloading a failed plugin whose file changed
     * resets its lifecycle to a fresh active state.
     */
    public function testReloadOfModifiedFailedPluginResetsLifecycle(): void
    {
        $subDir = $this->tempDir . '/ReloadPlugin';
        mkdir($subDir, 0755, true);
        $file = $subDir . '/Plugin.php';
        file_put_contents($file, $this->throwingNamespacedPluginSource('ReloadPlugin', '/api/reload/run', '1.0.0'));

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $key = 'ReloadPlugin\\Plugin';
        $match = $this->router->match(new Request('GET', '/api/reload/run'));
        $this->assertNotNull($match);
        $handler = $match['handler'];
        for ($i = 0; $i < 3; $i++) {
            $handler(new Request('GET', '/api/reload/run'), []);
        }
        $this->assertSame(PluginState::Failed, $loader->getLifecycle($key)?->getState());

        // Modify the plugin file on disk and reload.
        file_put_contents($file, $this->throwingNamespacedPluginSource('ReloadPlugin', '/api/reload/run', '2.0.0'));
        touch($file, time() + 5);
        clearstatcache();

        $this->assertTrue($loader->reload());

        // Fresh lifecycle: back to active, error counter cleared.
        $this->assertSame(PluginState::Active, $loader->getLifecycle($key)?->getState());
        $this->assertSame(0, $loader->getLifecycle($key)?->getConsecutiveErrors());
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

    private function writeHealthyPlugin(string $class, string $path): void
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
        return Response::json(['status' => 'healthy']);
    }
}
PHP;
        file_put_contents($this->tempDir . '/' . $class . '.php', $code);
    }

    private function writeToggleablePlugin(string $class, string $path): void
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
        if (\$request->getHeader('X-Fail') !== null) {
            throw new \\RuntimeException('toggled failure');
        }
        return Response::json(['ok' => true]);
    }
}
PHP;
        file_put_contents($this->tempDir . '/' . $class . '.php', $code);
    }

    private function writeThrowingHookPlugin(string $class, string $event): void
    {
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Sdk\\PluginInterface;

class {$class} implements PluginInterface
{
    public function getName(): string { return '{$class}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array
    {
        return ['{$event}' => [\$this, 'onEvent']];
    }
    public function getMigrations(): array { return []; }
    public function onEvent(array \$data, array \$context): array
    {
        throw new \\RuntimeException('hook blew up');
    }
}
PHP;
        file_put_contents($this->tempDir . '/' . $class . '.php', $code);
    }

    private function throwingNamespacedPluginSource(string $ns, string $path, string $version): string
    {
        return <<<PHP
<?php

namespace {$ns};

use Whity\\Sdk\\PluginInterface;
use Whity\\Core\\Request;
use Whity\\Core\\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return '{$ns}'; }
    public function getVersion(): string { return '{$version}'; }
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
