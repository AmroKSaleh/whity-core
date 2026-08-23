<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * The plugin loader's registry-change seam (#952).
 *
 * Whatever the host memoized off the plugin registry — the MCP tool catalogue,
 * above all — has to be rebuilt when that registry moves. Announcing to an MCP
 * client that its cached tool list is stale while continuing to serve the very
 * list it cached would be worse than the silence #952 is about: the client would
 * refetch, be handed the same stale definitions, and now believe it is current.
 *
 * The other half is what must NOT fire it. A worker boot is not a change, and
 * with eight FrankenPHP workers booting it would be eight announcements of
 * nothing; a reload that found no disk change is not a change either.
 */
final class PluginLoaderRegistryChangeTest extends TestCase
{
    private string $tempDir;
    private Router $router;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_registrychange_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->router = new Router('');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testInitialLoadDoesNotAnnounceAChange(): void
    {
        $this->writePlugin('BootPlugin', '/api/boot/ping');

        $triggers = [];
        $loader   = new PluginLoader($this->tempDir, $this->router);
        $loader->onRegistryChanged($this->recorder($triggers));

        $loader->load();

        self::assertSame([], $triggers, 'a worker boot is not a registry change');
    }

    public function testReloadWithNoDiskChangeDoesNotAnnounce(): void
    {
        $this->writePlugin('QuietPlugin', '/api/quiet/ping');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $triggers = [];
        $loader->onRegistryChanged($this->recorder($triggers));

        self::assertFalse($loader->reload());
        self::assertSame([], $triggers);
    }

    public function testAddingAPluginAnnouncesOnReload(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $triggers = [];
        $loader->onRegistryChanged($this->recorder($triggers));

        $this->writePlugin('LatePlugin', '/api/late/ping');
        clearstatcache();

        self::assertTrue($loader->reload());
        self::assertSame(['reload'], $triggers);
    }

    public function testRemovingAPluginAnnouncesOnReload(): void
    {
        $this->writePlugin('DoomedPlugin', '/api/doomed/ping');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $triggers = [];
        $loader->onRegistryChanged($this->recorder($triggers));

        $this->removeDirectory($this->tempDir . '/DoomedPlugin');
        clearstatcache();

        self::assertTrue($loader->reload());
        self::assertSame(['reload'], $triggers);
    }

    public function testDisablingAPluginAnnounces(): void
    {
        $this->writePlugin('ToggleOffPlugin', '/api/toggle-off/ping');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $triggers = [];
        $loader->onRegistryChanged($this->recorder($triggers));

        self::assertTrue($loader->disablePlugin('ToggleOffPlugin\\Plugin'));
        self::assertSame(['disable'], $triggers);
    }

    public function testReEnablingAPluginAnnounces(): void
    {
        $this->writePlugin('ToggleOnPlugin', '/api/toggle-on/ping');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $loader->disablePlugin('ToggleOnPlugin\\Plugin');

        $triggers = [];
        $loader->onRegistryChanged($this->recorder($triggers));

        self::assertTrue($loader->reEnablePlugin('ToggleOnPlugin\\Plugin'));
        self::assertSame(['enable'], $triggers);
    }

    public function testDisablingAnUnknownPluginAnnouncesNothing(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $triggers = [];
        $loader->onRegistryChanged($this->recorder($triggers));

        self::assertFalse($loader->disablePlugin('NoSuch\\Plugin'));
        self::assertSame([], $triggers);
    }

    // ── Failure isolation ─────────────────────────────────────────────────────

    /**
     * A notification is an optimisation on top of a correct server. The
     * established rule here is that one participant's failure costs itself its
     * contribution and costs the host nothing — a listener that throws must not
     * abort the reload, and the plugin whose arrival triggered it must still be
     * registered and serving.
     */
    public function testAThrowingListenerDoesNotBreakTheReload(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $loader->onRegistryChanged(static function (string $trigger): void {
            throw new \RuntimeException('notification backend is down');
        });

        $this->writePlugin('SurvivorPlugin', '/api/survivor/ping');
        clearstatcache();

        self::assertTrue($loader->reload(), 'the reload still reports the change it applied');
        self::assertCount(1, $loader->getPlugins(), 'the new plugin is registered despite the failure');
        self::assertNotNull(
            $this->router->match(new \Whity\Core\Request('GET', '/api/survivor/ping')),
            'and its routes are live',
        );
    }

    public function testAThrowingListenerDoesNotBreakADisable(): void
    {
        $this->writePlugin('ToggleIsolatedPlugin', '/api/toggle-isolated/ping');

        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();
        $loader->onRegistryChanged(static function (string $trigger): void {
            throw new \RuntimeException('notification backend is down');
        });

        self::assertTrue($loader->disablePlugin('ToggleIsolatedPlugin\\Plugin'));
        self::assertNull($this->router->match(new \Whity\Core\Request('GET', '/api/toggle-isolated/ping')));
    }

    public function testUnregisteringTheListenerStopsAnnouncements(): void
    {
        $loader = new PluginLoader($this->tempDir, $this->router);
        $loader->load();

        $triggers = [];
        $loader->onRegistryChanged($this->recorder($triggers));
        $loader->onRegistryChanged(null);

        $this->writePlugin('IgnoredPlugin', '/api/ignored/ping');
        clearstatcache();

        self::assertTrue($loader->reload());
        self::assertSame([], $triggers);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param list<string> $triggers */
    private function recorder(array &$triggers): \Closure
    {
        return static function (string $trigger) use (&$triggers): void {
            $triggers[] = $trigger;
        };
    }

    private function writePlugin(string $namespace, string $path): void
    {
        $dir = $this->tempDir . '/' . $namespace;
        mkdir($dir, 0755, true);

        $code = <<<PHP
<?php

namespace {$namespace};

use Whity\\Sdk\\PluginInterface;
use Whity\\Core\\Request;
use Whity\\Core\\Response;

class Plugin implements PluginInterface
{
    public function getName(): string { return '{$namespace}'; }
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
    public function handle(Request \$request): Response { return Response::json(['ok' => true]); }
}
PHP;

        file_put_contents($dir . '/Plugin.php', $code);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
