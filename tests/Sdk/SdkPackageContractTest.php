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

    public function testDeprecatedCorePluginInterfaceAliasIsRemoved(): void
    {
        $this->assertFalse(
            interface_exists(\Whity\Core\PluginInterface::class),
            'The deprecated Whity\Core\PluginInterface alias was removed in WC-215; '
            . 'implement \Whity\Sdk\PluginInterface directly.'
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

    /**
     * SDK 1.2 (WC-169): the OPTIONAL frontend feature descriptor capability.
     * Mirrors PluginRequirementsInterface — a sibling interface a plugin MAY
     * implement, keeping PluginInterface itself backend-only.
     */
    public function testPluginFrontendInterfaceLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginFrontendInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginFrontendInterface::class))->getMethods()
        );
        $this->assertSame(['getFrontendFeatures'], $methods);

        $return = (new \ReflectionMethod(\Whity\Sdk\PluginFrontendInterface::class, 'getFrontendFeatures'))
            ->getReturnType();
        $this->assertSame('array', (string) $return);
    }

    public function testSdkVersionIsOneEightForInteractiveBlocks(): void
    {
        $this->assertSame(
            '1.20.0',
            \Whity\Sdk\Sdk::VERSION,
            'SDK 1.20 adds the plugin-owned data-type contracts (WC-723 Door 2): '
            . 'Tenant\PluginTablesInterface, by which a plugin declares WHICH '
            . 'tables it owns while the host stamps WHO owns them; '
            . 'DataType\PluginDataTypesInterface, by which it declares a record\'s '
            . 'lifecycle and reference graph as DATA rather than code; and the '
            . 'read-only DataType\DataTypeGuard, so a plugin\'s own delete route '
            . 'enforces through the same evaluator the generated one uses. All '
            . 'three OPTIONAL; trashed (reversible, pending removal) and retired '
            . '(permanent, closed to new references) stay distinct states; '
            . 'SDK 1.19 adds PluginHealthProbesInterface — the OPTIONAL contract by '
            . 'which a plugin contributes a status-page probe for a dependency it '
            . 'owns, sampled and published beside core\'s database/queue/scheduler/'
            . 'render probes instead of on a private status surface nobody watches. '
            . 'Host-namespaced under the plugin name the loader supplies, so probes '
            . 'cannot collide or shadow a core one (WC-status-probes); '
            . 'SDK 1.18 adds PluginResourceTypesInterface — the OPTIONAL contract '
            . 'by which a plugin declares the resource types it owns. The host '
            . 'namespaces them under the plugin name it supplies, so two plugins '
            . 'cannot collide and none can shadow a core type (WC-712 §2); '
            . 'SDK 1.17 makes PermissionResolver RESOURCE-SCOPED: optional '
            . '$resourceType/$resourceId on hasPermission() and '
            . 'effectivePermissions(), honoured against resource_role_assignments '
            . 'so a plugin can ask "may this caller act on THIS record?" instead '
            . 'of keeping a private grant table (WC-712 §2). Additive — omitting '
            . 'them preserves 1.16 behaviour exactly; '
            . 'SDK 1.16 adds the read-only permission-resolution contract '
            . '(Rbac\PermissionResolver, WC-712) so a plugin asks the host the same '
            . 'authorization question the RBAC middleware answers instead of '
            . 're-deriving it in hand-written SQL; '
            . 'SDK 1.15 adds the hook VETO contract (HookVetoException — the one '
            . 'Throwable the host error boundary re-throws, so a plugin can refuse '
            . 'a deletion and have it rolled back, WC-713); '
            . '1.14 adds the notification transport contract (NotificationTransport '
            . '+ NotificationMessage/SendResult value objects, WC-notifications); '
            . '1.13 adds the embed frontend-feature screen and multipart '
            . 'action file uploads (WC-246/WC-247); 1.12 adds the optional '
            . 'theme-override contribution point '
            . '(PluginThemeInterface, WC-242); 1.11 adds inline sort/filter/pagination '
            . 'to dataTable/dataList (the dataColumnList prop-rule kind, WC-241); '
            . '1.10 adds the chart data-bound block type and the chartSeriesList '
            . 'prop-rule kind (WC-240); 1.9 adds the MCP prompt contribution point '
            . '(PluginMcpInterface, WC-7abb732f); 1.8 added interactive block types '
            . '(form, inputs, submitButton, actionButton) and the '
            . 'inputName/selectOptions/submitSpec prop-rule kinds (WC-233)'
        );
    }

    /**
     * SDK 1.15 (WC-712): the read-only permission-resolution contract. It must
     * live in the SDK — not in core — so an out-of-repo plugin, which depends on
     * whity/plugin-sdk alone, can type-hint the service it resolves from the
     * host container.
     */
    public function testPermissionResolverContractLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\Rbac\PermissionResolver::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\Rbac\PermissionResolver::class))->getMethods()
        );
        sort($methods);

        // Read-only by construction: three questions, and deliberately no
        // cache-invalidation or database surface (which is why the host
        // registers a narrow facade rather than RoleChecker itself).
        $this->assertSame(['effectivePermissions', 'hasPermission', 'hasRole'], $methods);

        $this->assertSame(
            'bool',
            (string) (new \ReflectionMethod(\Whity\Sdk\Rbac\PermissionResolver::class, 'hasPermission'))
                ->getReturnType()
        );
        $this->assertSame(
            'array',
            (string) (new \ReflectionMethod(\Whity\Sdk\Rbac\PermissionResolver::class, 'effectivePermissions'))
                ->getReturnType()
        );
    }

    /**
     * SDK 1.19 (WC-status-probes): the health-probe contribution point. It must
     * live in the SDK — not in core — so an out-of-repo plugin, which depends on
     * whity/plugin-sdk alone, can declare a probe and build its results without
     * referencing a single host type (core's HealthStatus enum notably included:
     * ProbeResult carries the state as a plain string the host maps).
     */
    public function testHealthProbeContributionPointLivesInTheSdk(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\Health\PluginHealthProbesInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\Health\PluginHealthProbesInterface::class))->getMethods()
        );
        $this->assertSame(['getHealthProbes'], $methods);
        $this->assertSame(
            'array',
            (string) (new \ReflectionMethod(
                \Whity\Sdk\Health\PluginHealthProbesInterface::class,
                'getHealthProbes'
            ))->getReturnType()
        );

        $this->assertTrue(class_exists(\Whity\Sdk\Health\HealthProbeDefinition::class));
        $this->assertTrue(class_exists(\Whity\Sdk\Health\ProbeResult::class));

        // A result can only be built through the three factories, so `status` is
        // always one of the three states the host knows how to render — a plugin
        // cannot mint a fourth.
        $this->assertFalse(
            (new \ReflectionClass(\Whity\Sdk\Health\ProbeResult::class))
                ->getConstructor()?->isPublic() ?? true,
            'ProbeResult must not be constructible with an arbitrary status string'
        );
        $this->assertSame(
            'operational',
            \Whity\Sdk\Health\ProbeResult::operational()->status
        );
        $this->assertSame('degraded', \Whity\Sdk\Health\ProbeResult::degraded('slow')->status);
        $this->assertSame('down', \Whity\Sdk\Health\ProbeResult::down('gone')->status);
    }

    public function testPluginMcpInterface_existsWithGetMcpPromptsMethod(): void
    {
        $this->assertTrue(interface_exists(\Whity\Sdk\PluginMcpInterface::class));

        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\PluginMcpInterface::class))->getMethods()
        );
        $this->assertSame(['getMcpPrompts'], $methods);

        $return = (new \ReflectionMethod(\Whity\Sdk\PluginMcpInterface::class, 'getMcpPrompts'))
            ->getReturnType();
        $this->assertSame('array', (string) $return);
    }

    /**
     * SDK 1.6 (WC-225): the platform-neutral plugin-UI block contract +
     * validator ship in the SDK so distributable plugins can declare a
     * server-driven `screen: 'blocks'` tree with only whity/plugin-sdk
     * installed.
     */
    public function testBlockContractAndValidatorLiveInTheSdk(): void
    {
        $this->assertTrue(
            class_exists(\Whity\Sdk\Frontend\Blocks\BlockContract::class),
            'The block whitelist/contract must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Frontend\Blocks\BlockValidator::class),
            'The block validator must live in the SDK'
        );

        $this->assertSame(32, \Whity\Sdk\Frontend\Blocks\BlockContract::MAX_DEPTH);
        $this->assertSame(500, \Whity\Sdk\Frontend\Blocks\BlockContract::MAX_NODES);

        $validate = new \ReflectionMethod(\Whity\Sdk\Frontend\Blocks\BlockValidator::class, 'validate');
        $this->assertTrue($validate->isStatic(), 'validate() is a pure static gate');
        $this->assertSame('array', (string) $validate->getReturnType());
    }

    /**
     * WC-194: the conformance kit ships in the SDK so out-of-repo plugins (which
     * depend only on whity/plugin-sdk) can run it. The scanner engine and the
     * shared base test case must live under the SDK autoload root.
     */
    public function testTenantConformanceKitLivesInTheSdk(): void
    {
        $this->assertTrue(
            class_exists(\Whity\Sdk\Tenant\TenantPredicateScanner::class),
            'The portable tenant-predicate scanner must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Tenant\MigrationTenantColumnLinter::class),
            'The migration linter must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Tenant\TenantTableRegistry::class),
            'The portable tenant-table registry must live in the SDK'
        );
        $this->assertTrue(
            class_exists(\Whity\Sdk\Testing\TenantIsolationConformanceTestCase::class),
            'The shared base conformance test case must live in the SDK'
        );
    }

    /**
     * The conformance kit is part of the standalone contract: it must not drag
     * in any host namespace, so an out-of-repo plugin can run it with only the
     * SDK (and phpunit) installed.
     */
    public function testTenantConformanceKitDependsOnlyOnTheSdkAndPhpunit(): void
    {
        $files = [
            self::SDK_DIR . '/src/Tenant/TenantPredicateScanner.php',
            self::SDK_DIR . '/src/Tenant/TenantTableRegistry.php',
            self::SDK_DIR . '/src/Tenant/MigrationTenantColumnLinter.php',
            self::SDK_DIR . '/src/Testing/TenantIsolationConformanceTestCase.php',
        ];

        foreach ($files as $path) {
            $this->assertFileExists($path);
            $source = (string) file_get_contents($path);
            foreach (['Whity\\Core', 'Whity\\Database', 'Whity\\Http\\', 'Whity\\Auth', 'Whity\\Api'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $source,
                    "{$path} must not reference {$forbidden} — the conformance kit is standalone"
                );
            }
        }
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
            // The deprecated core alias was removed in WC-215; no shipped
            // plugin should report it among its implemented interfaces. Use a
            // string literal — the class no longer exists to reference.
            $this->assertNotContains(
                'Whity\Core\PluginInterface',
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
        $router = new Router('');
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
            new Router(''),
            null,
            new HookManager()
        );
        $loader->load();

        $this->assertNotEmpty($loader->getPlugins(), 'The SDK-typed shipped plugins must load');

        $states = array_column($loader->getPluginStatuses(), 'state', 'name');
        $this->assertSame('active', $states['HelloWorld'] ?? null, 'HelloWorld must reach the active state');
    }
}
