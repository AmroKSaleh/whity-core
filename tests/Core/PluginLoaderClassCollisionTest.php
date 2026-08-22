<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * Two plugin files declaring the same class must not fatal the host (#841).
 *
 * `PluginLoader::discover()` used to `require_once` every discovered file
 * unguarded and only then ask `class_exists()`. A second file declaring an
 * already-declared class therefore raised `Cannot redeclare class` — a FATAL,
 * not an exception, so the per-plugin lifecycle error boundary could not catch
 * it. Discovery runs at boot, so every request 500'd until an operator deleted
 * a directory from disk; there was no in-product exit.
 *
 * ## The fixtures mirror a copied directory, deliberately
 *
 * {@see PluginLoader::resolveClassFromFile()} derives the discovered name from
 * the PATH: `plugins/Widget-old/Plugin.php` derives `Widget-old\Plugin`. But a
 * directory copied before editing still declares the ORIGINAL
 * `namespace Widget;`, so the derived and declared names DIFFER — and it is the
 * declared one that collides.
 *
 * That gap is the whole difficulty of this bug. A guard written against the
 * derived name reads as correct and prevents nothing, because the two names it
 * compares are never the two that clash. So these fixtures always give the
 * FIRST plugin a directory whose name matches its namespace (a healthy plugin,
 * which must still load) and the SECOND a mismatched directory (the copy).
 *
 * NOTE ON RUNNING THESE AGAINST THE UNFIXED LOADER: they do not fail, they
 * ABORT — the fatal takes the PHP process with it, so PHPUnit reports no result
 * at all rather than a red test. That is the defect, stated precisely.
 */
class PluginLoaderClassCollisionTest extends TestCase
{
    private string $tempDir;
    private Router $router;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_collision_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->router = new Router('');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    /**
     * A valid plugin body declaring `{$namespace}\{$class}`.
     */
    private function pluginSource(string $namespace, string $class): string
    {
        return <<<PHP
        <?php

        namespace {$namespace};

        use Whity\\Sdk\\PluginInterface;

        class {$class} implements PluginInterface
        {
            public function getName(): string
            {
                return '{$class}';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            public function getRoutes(): array
            {
                return [];
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
        }
        PHP;
    }

    /**
     * Write a directory plugin at `plugins/{$dir}/{$class}.php` declaring
     * `{$namespace}\{$class}`. Passing a $dir that differs from $namespace is
     * how a copied-and-not-renamed directory is expressed.
     */
    private function writeDirectoryPlugin(string $dir, string $namespace, string $class): void
    {
        mkdir($this->tempDir . '/' . $dir, 0755, true);
        file_put_contents(
            $this->tempDir . '/' . $dir . '/' . $class . '.php',
            $this->pluginSource($namespace, $class)
        );
    }

    /** @return list<string> Names of the plugins the loader ended up with. */
    private function loadedPluginNames(PluginLoader $loader): array
    {
        return array_map(
            static fn (object $plugin): string => $plugin->getName(),
            array_values($loader->getPlugins())
        );
    }

    /**
     * The reported scenario: `plugins/Widget-old/` copied from `plugins/Widget/`.
     */
    public function testCopiedPluginDirectoryDoesNotFatalTheHost(): void
    {
        $this->writeDirectoryPlugin('CollideAlpha', 'CollideAlpha', 'CollideAlphaPlugin');
        // The copy: different directory, same namespace, same class.
        $this->writeDirectoryPlugin('CollideAlpha-old', 'CollideAlpha', 'CollideAlphaPlugin');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loader = new PluginLoader($this->tempDir, $this->router, null, null, $logger);

        // Reaching the next line at all is the assertion this test exists for:
        // before the fix, discovery aborted the process here.
        $loader->load();

        $this->assertSame(
            ['CollideAlphaPlugin'],
            $this->loadedPluginNames($loader),
            'The original must load; the copy must be refused, not loaded twice.'
        );
    }

    /**
     * The refusal has to be attributable, or an operator cannot act on it.
     */
    public function testCollisionIsLoggedNamingBothFiles(): void
    {
        $this->writeDirectoryPlugin('CollideBeta', 'CollideBeta', 'CollideBetaPlugin');
        $this->writeDirectoryPlugin('CollideBeta-copy', 'CollideBeta', 'CollideBetaPlugin');

        $warnings = [];
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            }
        );

        (new PluginLoader($this->tempDir, $this->router, null, null, $logger))->load();

        $collisions = array_values(array_filter(
            $warnings,
            static fn (string $w): bool => str_contains($w, 'already declared by')
        ));

        $this->assertNotEmpty($collisions, 'A refused plugin must say why it was refused.');

        $message = $collisions[0];
        $this->assertStringContainsString('CollideBeta\\CollideBetaPlugin', $message);
        // Both sides: knowing only that something collided does not tell an
        // operator which directory to remove.
        $this->assertStringContainsString('CollideBeta-copy', $message);
        $this->assertStringContainsString('CollideBeta' . DIRECTORY_SEPARATOR . 'CollideBetaPlugin.php', $message);
        // The remedy, since the operator has to choose which copy survives.
        $this->assertStringContainsString('rename the namespace', $message);
    }

    /**
     * A collision must cost the offending plugin its load, and nothing else.
     */
    public function testUnrelatedPluginsStillLoadAlongsideACollision(): void
    {
        $this->writeDirectoryPlugin('CollideGamma', 'CollideGamma', 'CollideGammaPlugin');
        $this->writeDirectoryPlugin('CollideGamma-old', 'CollideGamma', 'CollideGammaPlugin');
        $this->writeDirectoryPlugin('HealthyGamma', 'HealthyGamma', 'HealthyGammaPlugin');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loader = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $loader->load();

        $names = $this->loadedPluginNames($loader);

        sort($names);
        $this->assertSame(
            ['CollideGammaPlugin', 'HealthyGammaPlugin'],
            $names,
            'A defective plugin costing itself its load is the pattern; costing its neighbours theirs is not.'
        );
    }

    /**
     * The guard must not mistake a legitimate re-visit for a collision.
     *
     * `require_once` already makes reaching the same path twice a no-op, and
     * discovery genuinely does so. A guard that refused on "this name is
     * already declared" alone would break every plugin on the second scan.
     */
    public function testSameFileSeenTwiceIsNotTreatedAsACollision(): void
    {
        $this->writeDirectoryPlugin('StableRescan', 'StableRescan', 'StableRescanPlugin');

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);

        $first = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $first->load();
        $this->assertSame(['StableRescanPlugin'], $this->loadedPluginNames($first));

        // A second loader over the same directory in the same process: the
        // class is already declared — by the very file about to be required.
        $second = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $second->load();

        $this->assertSame(
            ['StableRescanPlugin'],
            $this->loadedPluginNames($second),
            'Re-scanning an already-loaded plugin must still find it.'
        );
    }

    /**
     * A single-file plugin colliding with a directory plugin.
     *
     * `plugins/Foo.php` derives `Whity\Plugins\Foo` while declaring whatever it
     * likes, so the two discovery paths can collide with each other and not
     * only within themselves.
     */
    public function testSingleFilePluginCollidingWithADirectoryPluginIsRefused(): void
    {
        $this->writeDirectoryPlugin('CollideDelta', 'CollideDelta', 'CollideDeltaPlugin');
        file_put_contents(
            $this->tempDir . '/StrayDelta.php',
            $this->pluginSource('CollideDelta', 'CollideDeltaPlugin')
        );

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $loader = new PluginLoader($this->tempDir, $this->router, null, null, $logger);
        $loader->load();

        $this->assertSame(['CollideDeltaPlugin'], $this->loadedPluginNames($loader));
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
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
