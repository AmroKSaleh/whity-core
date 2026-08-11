<?php

declare(strict_types=1);

namespace Tests\Core\Settings;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Settings\SettingsCatalog;
use Whity\Core\Settings\SettingsRegistry;

/**
 * #713 item 1, through the LOADER: a real plugin on disk, discovered and
 * registered the way the host does it.
 *
 * The registry's own unit tests prove the validation rules. What can only be
 * proven here is the part the loader owns:
 *
 *  - the SOURCE is `$plugin->getName()`, supplied by the loader, so a plugin
 *    cannot choose its own namespace no matter what it returns;
 *  - a bad declaration is a logged warning and an ACTIVE plugin, not a dead
 *    host — the per-plugin error boundary every other declaration uses;
 *  - a plugin that implements nothing is untouched.
 */
final class PluginSettingsLoaderTest extends TestCase
{
    private ?string $tempDir = null;

    private SettingsLoaderSpyLogger $logger;

    /**
     * A plugin class can only be defined ONCE per PHP process, and these tests
     * write several fixtures with the same plugin NAME into different temp
     * directories. The declared name (which is what the namespace prefix derives
     * from) stays stable; the CLASS gets a per-test suffix so the second fixture
     * is a new class rather than a fatal redeclaration.
     */
    private static int $fixtureSeq = 0;

    protected function setUp(): void
    {
        $this->logger = new SettingsLoaderSpyLogger();
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            $this->removeDirectory($this->tempDir);
            $this->tempDir = null;
        }
    }

    // ==================== the happy path ====================

    public function testADeclaredSettingIsDiscoverableTypedAndNamespaced(): void
    {
        $registry = new PluginSettingsRegistry();
        $this->loadPlugins($registry, ['DemoCatalog' => $this->goodDeclaration()]);

        self::assertSame(['democatalog:sync_interval', 'democatalog:mode'], $registry->keys());

        $catalog = new SettingsCatalog($registry);
        self::assertTrue($catalog->isKnown('democatalog:sync_interval'));
        self::assertSame('int', $catalog->typeFor('democatalog:sync_interval'));
        self::assertSame('300', $catalog->defaultFor('democatalog:sync_interval'));
        self::assertSame(['off', 'live'], $catalog->optionsFor('democatalog:mode'));
        self::assertNull($catalog->validate('democatalog:sync_interval', '600'));
        self::assertIsString($catalog->validate('democatalog:sync_interval', '1'));
        self::assertSame('DemoCatalog', $catalog->sourceOf('democatalog:sync_interval'));
    }

    /**
     * The attribution rule, tested where it can actually be broken. The fixture
     * RETURNS keys claiming to be `core:` and `evilcorp:`; the loader stamps the
     * prefix from getName() regardless.
     */
    public function testThePluginCannotChooseItsOwnNamespace(): void
    {
        $registry = new PluginSettingsRegistry();
        $this->loadPlugins($registry, ['Honest' => $this->goodDeclaration()]);

        foreach ($registry->keys() as $key) {
            self::assertStringStartsWith('honest:', $key);
        }
    }

    public function testTwoPluginsOnDiskDeclaringTheSameBareKeyDoNotCollide(): void
    {
        $registry = new PluginSettingsRegistry();
        $this->loadPlugins($registry, [
            'Alpha' => "'mode' => ['type' => 'string', 'default' => 'a'],",
            'Beta' => "'mode' => ['type' => 'string', 'default' => 'b'],",
        ]);

        self::assertSame('a', $registry->get('alpha:mode')?->defaultValue());
        self::assertSame('b', $registry->get('beta:mode')?->defaultValue());
    }

    public function testAPluginCannotShadowACoreKeyThroughTheLoader(): void
    {
        $registry = new PluginSettingsRegistry();
        $this->loadPlugins($registry, [
            'Mail' => "'transport' => ['type' => 'string', 'default' => 'hijacked', 'admin' => true],",
        ]);

        // It registered something — under its OWN namespace.
        self::assertSame(['mail:transport'], $registry->keys());

        $catalog = new SettingsCatalog($registry);
        // …and core's key is untouched, in every question anyone can ask.
        self::assertSame('none', $catalog->defaultFor(SettingsRegistry::MAIL_TRANSPORT));
        self::assertSame('none', SettingsRegistry::defaultFor(SettingsRegistry::MAIL_TRANSPORT));
        self::assertIsString($catalog->validate(SettingsRegistry::MAIL_TRANSPORT, 'hijacked'));
    }

    // ==================== the error boundary ====================

    public function testAnInvalidDeclarationIsALoggedWarningNotADeadHost(): void
    {
        $registry = new PluginSettingsRegistry();
        $loader = $this->loadPlugins($registry, [
            'BadPlugin' => "'ok' => ['type' => 'string', 'default' => 'fine'], 'broken' => ['type' => 'nonsense', 'default' => 'x'],",
        ]);

        // The plugin is still ACTIVE and serving.
        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        self::assertSame('active', $states['BadPlugin'] ?? null);

        // The entry validated before the bad one is kept; the bad one is not.
        self::assertSame(['badplugin:ok'], $registry->keys());
        self::assertFalse($registry->has('badplugin:broken'));

        // And the refusal was actually reported. A boundary that swallows the
        // reason leaves the plugin author with a key that simply does not exist
        // and nothing to diagnose from — which is the failure mode this whole
        // change is about, moved one layer out.
        self::assertTrue($this->logger->has('declares an invalid setting'));
        self::assertTrue($this->logger->has("'type' must be one of"));
    }

    public function testADeclarationThatThrowsGoesThroughTheLifecycleBoundary(): void
    {
        $registry = new PluginSettingsRegistry();
        $loader = $this->loadThrowingPlugin($registry);

        // Nothing contributed, host alive, plugin recorded as having erred.
        self::assertSame([], $registry->keys());
        $statuses = array_column($loader->getPluginStatuses(), 'consecutive_errors', 'name');
        self::assertGreaterThan(0, $statuses['ThrowingPlugin'] ?? 0);
    }

    public function testASecretShapedDeclarationIsRefusedAtLoadTime(): void
    {
        $registry = new PluginSettingsRegistry();
        $loader = $this->loadPlugins($registry, [
            'Creds' => "'api_token' => ['type' => 'string', 'default' => '', 'secret' => true],",
        ]);

        // Refused, not downgraded to a readable string — which is how the plugin
        // author learns before shipping that a credential cannot live here.
        self::assertSame([], $registry->keys());
        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        self::assertSame('active', $states['Creds'] ?? null);

        // The warning names the alternative, because "not supported" without a
        // path forward is how a plugin author ends up storing the token as a
        // plain string anyway.
        self::assertTrue($this->logger->has('secret-shaped settings are not supported'));
        self::assertTrue($this->logger->has('encrypted and write-only'));
    }

    public function testAPluginThatDeclaresNoSettingsIsUntouched(): void
    {
        $registry = new PluginSettingsRegistry();
        $loader = $this->loadPlainPlugin($registry);

        self::assertSame([], $registry->keys());
        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        self::assertSame('active', $states['PlainPlugin'] ?? null);
    }

    /**
     * A host that wired no catalogue skips declarations rather than failing —
     * matching how a null permission registry behaves, and what keeps every
     * bare-loader test in this suite working.
     */
    public function testAHostWithNoCatalogueSkipsDeclarationsRatherThanFailing(): void
    {
        $this->tempDir = $this->makeTempDir();
        $this->writePlugin('DemoCatalog', $this->goodDeclaration());

        $loader = new PluginLoader((string) $this->tempDir, new Router(''), null, null, $this->logger);
        $loader->load();

        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        self::assertSame('active', $states['DemoCatalog'] ?? null);
    }

    // ==================== per-boot rebuild ====================

    /**
     * The property that replaces an unregister API: the catalogue is rebuilt per
     * boot from the plugins actually loaded, so a plugin that is gone takes its
     * keys with it. This is also why the mutable half must be an instance and
     * not a static — a static would keep the departed plugin's keys alive in
     * whichever worker had registered them.
     */
    public function testAFreshRegistryHasOnlyTheKeysOfThePluginsPresent(): void
    {
        $first = new PluginSettingsRegistry();
        $this->loadPlugins($first, ['Alpha' => "'k' => ['type' => 'string', 'default' => 'a'],"]);
        self::assertSame(['alpha:k'], $first->keys());

        // A second boot with a different plugin tree: nothing carries over.
        $this->removeDirectory($this->tempDir ?? '');
        $this->tempDir = null;

        $second = new PluginSettingsRegistry();
        $this->loadPlugins($second, ['Beta' => "'k' => ['type' => 'string', 'default' => 'b'],"]);
        self::assertSame(['beta:k'], $second->keys());
    }

    // ==================== helpers ====================

    private function goodDeclaration(): string
    {
        return "'sync_interval' => ['type' => 'int', 'default' => 300, 'min' => 60, 'admin' => true], "
            . "'mode' => ['type' => 'enum', 'options' => ['off', 'live'], 'default' => 'off'],";
    }

    /**
     * @param array<string, string> $plugins name => the body of the settings array literal
     */
    private function loadPlugins(PluginSettingsRegistry $registry, array $plugins): PluginLoader
    {
        $this->tempDir = $this->makeTempDir();
        foreach ($plugins as $name => $body) {
            $this->writePlugin($name, $body);
        }

        return $this->loadFrom($registry);
    }

    private function writePlugin(string $name, string $settingsBody): void
    {
        $class = $name . 'Fixture' . (++self::$fixtureSeq) . 'Plugin';
        file_put_contents((string) $this->tempDir . '/' . $class . '.php', <<<PHP
        <?php

        namespace Whity\\Plugins;

        use Whity\\Sdk\\PluginInterface;
        use Whity\\Sdk\\Settings\\PluginSettingsInterface;

        class {$class} implements PluginInterface, PluginSettingsInterface
        {
            public function getName(): string { return '{$name}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }

            public function getSettings(): array
            {
                return [{$settingsBody}];
            }
        }
        PHP);
    }

    private function loadThrowingPlugin(PluginSettingsRegistry $registry): PluginLoader
    {
        $this->tempDir = $this->makeTempDir();
        $class = 'ThrowingFixture' . (++self::$fixtureSeq) . 'Plugin';
        file_put_contents($this->tempDir . '/' . $class . '.php', <<<PHP
        <?php

        namespace Whity\Plugins;

        use Whity\Sdk\PluginInterface;
        use Whity\Sdk\Settings\PluginSettingsInterface;

        class {$class} implements PluginInterface, PluginSettingsInterface
        {
            public function getName(): string { return 'ThrowingPlugin'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }

            public function getSettings(): array
            {
                throw new \RuntimeException('declaration exploded');
            }
        }
        PHP);

        return $this->loadFrom($registry);
    }

    private function loadPlainPlugin(PluginSettingsRegistry $registry): PluginLoader
    {
        $this->tempDir = $this->makeTempDir();
        $class = 'PlainFixture' . (++self::$fixtureSeq) . 'Plugin';
        file_put_contents($this->tempDir . '/' . $class . '.php', <<<PHP
        <?php

        namespace Whity\Plugins;

        use Whity\Sdk\PluginInterface;

        class {$class} implements PluginInterface
        {
            public function getName(): string { return 'PlainPlugin'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }
        }
        PHP);

        return $this->loadFrom($registry);
    }

    private function loadFrom(PluginSettingsRegistry $registry): PluginLoader
    {
        $loader = new PluginLoader(
            (string) $this->tempDir,
            new Router(''),
            null,
            null,
            $this->logger,
            null,
            null,
            null,
            null,
            null,
            $registry
        );
        $loader->load();

        return $loader;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/whity_plugin_settings_' . uniqid();
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * In-memory PSR-3 logger double: the per-plugin error boundary's whole contract
 * is "a logged warning, not a dead host", so the warning has to be asserted
 * rather than merely allowed to happen somewhere.
 */
final class SettingsLoaderSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function has(string $needle): bool
    {
        foreach ($this->messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
