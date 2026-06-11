<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * WC-162: the standalone, semver'd plugin SDK contract package.
 *
 * Whity\Sdk is the keystone for cross-app feature sharing: plugins implement
 * the SDK contract (not Whity\Core types), the SDK carries its own
 * composer.json + autoload root with an independent 1.0.0 version, and it must
 * never depend on whity-core — that is what makes a plugin distributable to
 * KeyHub/Elmak without dragging the host framework along.
 */
final class SdkPackageContractTest extends TestCase
{
    private const SDK_DIR = __DIR__ . '/../../sdk';

    // ==================== package + version ====================

    public function testSdkHasItsOwnComposerJsonWithIndependentVersion(): void
    {
        $composerPath = self::SDK_DIR . '/composer.json';
        $this->assertFileExists($composerPath, 'The SDK must carry its own composer.json');

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $this->assertIsArray($composer);

        $this->assertSame('whity/plugin-sdk', $composer['name'] ?? null);
        $this->assertMatchesRegularExpression(
            '/^1\.\d+\.\d+$/',
            (string) ($composer['version'] ?? ''),
            'The SDK carries an independent 1.x semver (additive policy: minors add capabilities)'
        );
        $this->assertSame(
            \Whity\Sdk\Sdk::VERSION,
            $composer['version'] ?? null,
            'composer.json and Sdk::VERSION must agree'
        );
        $this->assertArrayHasKey('autoload', $composer);
        $this->assertSame(
            'src/',
            $composer['autoload']['psr-4']['Whity\\Sdk\\'] ?? null,
            'The SDK owns the Whity\Sdk autoload root'
        );
    }

    public function testSdkRequiresOnlyPhp(): void
    {
        $composer = json_decode((string) file_get_contents(self::SDK_DIR . '/composer.json'), true);
        $this->assertIsArray($composer);

        $this->assertSame(
            ['php'],
            array_keys($composer['require'] ?? []),
            'The SDK must require ONLY php — not whity-core, not any library'
        );
    }

    /**
     * Standalone proof at the source level: no SDK file may reference a
     * Whity\Core / Whity\Database / Whity\Http / Whity\Auth symbol.
     */
    public function testSdkSourcesReferenceNoCoreNamespaces(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SDK_DIR . '/src', \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $checked++;
            $source = (string) file_get_contents($file->getPathname());
            foreach (['Whity\\Core', 'Whity\\Database', 'Whity\\Http\\', 'Whity\\Auth', 'Whity\\Api'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$file->getPathname()} must not reference {$forbidden} — the SDK is standalone"
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'The SDK source tree must contain PHP files');
    }

    public function testCoreComposerRequiresTheSdkViaPathRepository(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertIsArray($composer);

        $this->assertArrayHasKey(
            'whity/plugin-sdk',
            $composer['require'] ?? [],
            'whity-core must depend on the SDK package'
        );

        $paths = array_column($composer['repositories'] ?? [], 'url');
        $this->assertContains('sdk', $paths, 'The SDK is consumed through a composer path repository');
    }

    // ==================== contract types ====================

    public function testPluginInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginInterface::class))->getMethods()
        );
        sort($methods);
        $this->assertSame(
            ['getHooks', 'getMigrations', 'getName', 'getPermissions', 'getRoutes', 'getVersion'],
            $methods
        );
    }

    public function testCorePluginInterfaceIsAnAliasOfTheSdkContract(): void
    {
        $reflection = new \ReflectionClass(\Whity\Core\PluginInterface::class);
        $this->assertTrue(
            $reflection->isSubclassOf(\Whity\Sdk\PluginInterface::class),
            'The deprecated core interface must extend the SDK contract so legacy fixtures keep loading'
        );
    }

    public function testMigrationInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\MigrationInterface::class));

        $up = new \ReflectionMethod(\Whity\Sdk\MigrationInterface::class, 'up');
        $down = new \ReflectionMethod(\Whity\Sdk\MigrationInterface::class, 'down');
        $this->assertSame('PDO', (string) $up->getParameters()[0]->getType());
        $this->assertSame('PDO', (string) $down->getParameters()[0]->getType());
    }

    public function testHookEventConstantsLiveInTheSdk(): void
    {
        $this->assertSame('user.creating', \Whity\Sdk\Hooks\Events::USER_CREATING);
        $this->assertSame('user.created', \Whity\Sdk\Hooks\Events::USER_CREATED);
        $this->assertSame('tenant.deleted', \Whity\Sdk\Hooks\Events::TENANT_DELETED);
        $this->assertSame('navigation.register', \Whity\Sdk\Hooks\Events::NAVIGATION_REGISTER);
        $this->assertSame('worker.request.start', \Whity\Sdk\Hooks\Events::WORKER_REQUEST_START);
    }

    // ==================== HTTP shapes ====================

    public function testCoreRequestAndResponseAreSdkShapes(): void
    {
        $request = new \Whity\Core\Request('GET', '/api/x');
        $this->assertInstanceOf(\Whity\Sdk\Http\Request::class, $request);

        $response = new \Whity\Core\Response(200, 'ok');
        $this->assertInstanceOf(\Whity\Sdk\Http\Response::class, $response);
    }

    /**
     * Late static binding: the static factories must return the CALLED class,
     * or every core handler using Whity\Core\Response::json() would silently
     * start returning the SDK base type and break core-typed signatures.
     */
    public function testResponseFactoriesHonourLateStaticBinding(): void
    {
        $json = \Whity\Core\Response::json(['a' => 1]);
        $this->assertInstanceOf(\Whity\Core\Response::class, $json);

        $error = \Whity\Core\Response::error('nope', 400);
        $this->assertInstanceOf(\Whity\Core\Response::class, $error);

        $sdkJson = \Whity\Sdk\Http\Response::json(['a' => 1]);
        $this->assertSame(\Whity\Sdk\Http\Response::class, $sdkJson::class);
    }

    /**
     * The single-decode contract (WC-159) travels with the SDK Request shape:
     * the attribute bag and the well-known claims key are part of the contract
     * plugins may read.
     */
    public function testSdkRequestCarriesTheAttributeBag(): void
    {
        $request = new \Whity\Sdk\Http\Request('GET', '/api/x');
        $request->setAttribute(\Whity\Sdk\Http\Request::ATTR_JWT_CLAIMS, ['user_id' => 7]);

        $this->assertTrue($request->hasAttribute('jwt.claims'));
        $this->assertSame(['user_id' => 7], $request->getAttribute('jwt.claims'));
    }

    // ==================== shipped plugins use the SDK ====================

    public function testShippedPluginsImplementTheSdkContractDirectly(): void
    {
        require_once __DIR__ . '/../../plugins/HelloWorld/HelloWorldPlugin.php';

        foreach ([\HelloWorld\HelloWorldPlugin::class, \Whity\Plugins\ExamplePlugin::class] as $pluginClass) {
            $reflection = new \ReflectionClass($pluginClass);
            $this->assertContains(
                \Whity\Sdk\PluginInterface::class,
                $reflection->getInterfaceNames(),
                "{$pluginClass} must implement the SDK contract"
            );
            $this->assertNotContains(
                \Whity\Core\PluginInterface::class,
                $reflection->getInterfaceNames(),
                "{$pluginClass} must implement the SDK contract directly, not the deprecated core alias"
            );
        }
    }

    public function testHelloWorldMigrationImplementsTheSdkMigrationContract(): void
    {
        require_once __DIR__ . '/../../plugins/HelloWorld/Migrations/CreateHelloGreetingsTable.php';

        $reflection = new \ReflectionClass(\HelloWorld\Migrations\CreateHelloGreetingsTable::class);
        $this->assertTrue($reflection->implementsInterface(\Whity\Sdk\MigrationInterface::class));
    }

    /**
     * Production path regression: the loader wraps every plugin handler in an
     * error boundary, and that boundary must accept the SDK Response the
     * SDK-typed handler returns — not demand the host subclass. Caught live on
     * the dev stack as a 500 "Internal plugin error" before this test existed.
     */
    public function testWrappedSdkHandlerServesItsRouteThroughTheRouter(): void
    {
        $router = new Router();
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            $router,
            null,
            new HookManager()
        );
        $loader->load();

        $match = $router->match(new \Whity\Core\Request('GET', '/api/hello'));
        $this->assertNotNull($match, 'The HelloWorld route must be registered');

        $response = ($match['handler'])(new \Whity\Core\Request('GET', '/api/hello'), $match['params']);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('Hello, World!', $payload['message'] ?? null);
    }

    /**
     * End to end through the loader: an SDK-typed plugin is discovered, loads,
     * and reaches the ACTIVE lifecycle state.
     */
    public function testSdkTypedPluginLoadsAndReachesActiveState(): void
    {
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            new Router(),
            null,
            new HookManager()
        );
        $loader->load();

        $this->assertNotEmpty($loader->getPlugins(), 'The SDK-typed shipped plugins must load');

        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        $this->assertSame('active', $states['HelloWorld'] ?? null, 'HelloWorld must reach the active state');
    }
}
