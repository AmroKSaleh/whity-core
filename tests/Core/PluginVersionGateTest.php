<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Router;

/**
 * WC-165: SDK/version compatibility gate and dependency-ordered loading.
 *
 * Plugins may declare a required SDK constraint and inter-plugin dependencies
 * (with version ranges) via {@see \Whity\Sdk\PluginRequirementsInterface};
 * the loader evaluates them with composer/semver against
 * {@see \Whity\Sdk\Sdk::VERSION} and the other plugins' getVersion(), loads
 * satisfied plugins in topological dependency order, and routes unsatisfied
 * ones to PluginState::Failed with an admin-visible reason — registering NONE
 * of their capabilities.
 */
final class PluginVersionGateTest extends TestCase
{
    private static string $mainDir;
    private static string $ghostDir;
    private static string $mismatchDir;
    private static string $cycleDir;
    private static string $cascadeDir;

    public static function setUpBeforeClass(): void
    {
        self::$mainDir = sys_get_temp_dir() . '/whity_vgate_main_' . uniqid();
        self::$ghostDir = sys_get_temp_dir() . '/whity_vgate_ghost_' . uniqid();
        self::$mismatchDir = sys_get_temp_dir() . '/whity_vgate_mismatch_' . uniqid();
        self::$cycleDir = sys_get_temp_dir() . '/whity_vgate_cycle_' . uniqid();
        self::$cascadeDir = sys_get_temp_dir() . '/whity_vgate_cascade_' . uniqid();

        // mainDir: scandir yields AaDependent BEFORE ZzBase, so topological
        // ordering (not directory order) must put ZzBase first.
        self::writePlugin(self::$mainDir, 'AaDependent', '1.0.0', null, ['ZzBase' => '^1.0'], '/api/vgate/aa');
        self::writePlugin(self::$mainDir, 'MmIncompatible', '1.0.0', '^99.0', [], '/api/vgate/mm');
        self::writePlainPlugin(self::$mainDir, 'NnPlain', '/api/vgate/nn');
        self::writePlugin(self::$mainDir, 'ZzBase', '1.2.3', '^1.0', [], '/api/vgate/zz');

        self::writePlugin(self::$ghostDir, 'DependsOnGhost', '1.0.0', null, ['GhostPlugin' => '^1.0'], '/api/vgate/ghostdep');

        self::writePlugin(self::$mismatchDir, 'MvBase', '1.0.0', null, [], '/api/vgate/mvbase');
        self::writePlugin(self::$mismatchDir, 'MvNeedsTwo', '1.0.0', null, ['MvBase' => '^2.0'], '/api/vgate/mvneeds');

        self::writePlugin(self::$cycleDir, 'CycA', '1.0.0', null, ['CycB' => '^1.0'], '/api/vgate/cyca');
        self::writePlugin(self::$cycleDir, 'CycB', '1.0.0', null, ['CycA' => '^1.0'], '/api/vgate/cycb');

        self::writePlugin(self::$cascadeDir, 'CascRoot', '1.0.0', '^99.0', [], '/api/vgate/cascroot');
        self::writePlugin(self::$cascadeDir, 'CascLeaf', '1.0.0', null, ['CascRoot' => '^1.0'], '/api/vgate/cascleaf');
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$mainDir, self::$ghostDir, self::$mismatchDir, self::$cycleDir, self::$cascadeDir] as $dir) {
            self::removeDirectory($dir);
        }
    }

    // ==================== ordering ====================

    public function testDependentLoadsAfterItsDependency(): void
    {
        [$loader] = $this->loadDir(self::$mainDir);

        $names = array_map(static fn ($p): string => $p->getName(), $loader->getPlugins());
        $base = array_search('ZzBase', $names, true);
        $dependent = array_search('AaDependent', $names, true);

        $this->assertNotFalse($base, 'ZzBase must load');
        $this->assertNotFalse($dependent, 'AaDependent must load');
        $this->assertLessThan(
            $dependent,
            $base,
            'The dependency (ZzBase) must register before its dependent (AaDependent) despite scandir order'
        );
    }

    // ==================== SDK constraint gate ====================

    public function testSdkIncompatiblePluginIsQuarantinedWithReason(): void
    {
        [$loader, $router] = $this->loadDir(self::$mainDir);

        $states = $this->statesByName($loader);
        $this->assertSame('failed', $states['MmIncompatible']['state'] ?? null, 'An SDK-incompatible plugin must be Failed');

        $reason = $states['MmIncompatible']['last_error']['message'] ?? '';
        $this->assertStringContainsString('^99.0', $reason, 'The reason must name the unsatisfied constraint');
        $this->assertStringContainsString(\Whity\Sdk\Sdk::VERSION, $reason, 'The reason must name the host SDK version');

        $names = array_map(static fn ($p): string => $p->getName(), $loader->getPlugins());
        $this->assertNotContains('MmIncompatible', $names, 'A quarantined plugin must not be among the loaded plugins');
        $this->assertNull(
            $router->match(new Request('GET', '/api/vgate/mm')),
            'A quarantined plugin must register NO routes'
        );
    }

    public function testPluginWithoutRequirementsStillLoads(): void
    {
        [$loader, $router] = $this->loadDir(self::$mainDir);

        $states = $this->statesByName($loader);
        $this->assertSame('active', $states['NnPlain']['state'] ?? null, 'Plugins without declared requirements keep loading (BC)');
        $this->assertNotNull($router->match(new Request('GET', '/api/vgate/nn')));
    }

    // ==================== dependency gates ====================

    public function testMissingDependencyQuarantines(): void
    {
        [$loader, $router] = $this->loadDir(self::$ghostDir);

        $states = $this->statesByName($loader);
        $this->assertSame('failed', $states['DependsOnGhost']['state'] ?? null);
        $this->assertStringContainsString('GhostPlugin', $states['DependsOnGhost']['last_error']['message'] ?? '');
        $this->assertNull($router->match(new Request('GET', '/api/vgate/ghostdep')));
    }

    public function testVersionMismatchedDependencyQuarantines(): void
    {
        [$loader] = $this->loadDir(self::$mismatchDir);

        $states = $this->statesByName($loader);
        $this->assertSame('active', $states['MvBase']['state'] ?? null, 'The dependency itself is healthy');
        $this->assertSame('failed', $states['MvNeedsTwo']['state'] ?? null);

        $reason = $states['MvNeedsTwo']['last_error']['message'] ?? '';
        $this->assertStringContainsString('^2.0', $reason);
        $this->assertStringContainsString('1.0.0', $reason, 'The reason must name the version actually found');
    }

    public function testDependencyCycleQuarantinesAllMembers(): void
    {
        [$loader] = $this->loadDir(self::$cycleDir);

        $states = $this->statesByName($loader);
        $this->assertSame('failed', $states['CycA']['state'] ?? null);
        $this->assertSame('failed', $states['CycB']['state'] ?? null);
        $this->assertStringContainsString('cycle', strtolower($states['CycA']['last_error']['message'] ?? ''));
        $this->assertSame([], $loader->getPlugins(), 'No member of a dependency cycle may load');
    }

    public function testQuarantineCascadesToDependents(): void
    {
        [$loader] = $this->loadDir(self::$cascadeDir);

        $states = $this->statesByName($loader);
        $this->assertSame('failed', $states['CascRoot']['state'] ?? null, 'The SDK-incompatible root fails');
        $this->assertSame('failed', $states['CascLeaf']['state'] ?? null, 'Its dependent must fail too');
        $this->assertStringContainsString('CascRoot', $states['CascLeaf']['last_error']['message'] ?? '');
    }

    // ==================== evaluator + SDK version contract ====================

    public function testComposerSemverIsTheEvaluatorNotHandRolledCode(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
        $this->assertIsArray($composer);
        $this->assertArrayHasKey('composer/semver', $composer['require'] ?? [], 'composer/semver must be a runtime dependency');

        $loaderSource = (string) file_get_contents(__DIR__ . '/../../src/Core/PluginLoader.php');
        $this->assertStringContainsString('Composer\\Semver', $loaderSource, 'The loader must evaluate constraints via composer/semver');
        $this->assertStringNotContainsString('version_compare', $loaderSource, 'No hand-rolled version comparison');

        $this->assertFalse(
            \Composer\InstalledVersions::isInstalled('composer/composer'),
            'The full composer/composer package must NOT be a runtime dependency'
        );
    }

    public function testSdkVersionConstantMatchesTheSdkPackage(): void
    {
        $sdkComposer = json_decode((string) file_get_contents(__DIR__ . '/../../sdk/composer.json'), true);
        $this->assertIsArray($sdkComposer);

        $this->assertSame(
            $sdkComposer['version'] ?? null,
            \Whity\Sdk\Sdk::VERSION,
            'Sdk::VERSION must match sdk/composer.json (single source of truth, drift-tested)'
        );
        $this->assertMatchesRegularExpression(
            '/^1\.[1-9]\d*\.\d+$/',
            \Whity\Sdk\Sdk::VERSION,
            'WC-165 ships in an additive 1.1+ SDK version'
        );
    }

    // ==================== helpers ====================

    /**
     * @return array{0: PluginLoader, 1: Router}
     */
    private function loadDir(string $dir): array
    {
        $router = new Router();
        $loader = new PluginLoader($dir, $router, null, new HookManager());
        $loader->load();

        return [$loader, $router];
    }

    /**
     * @return array<string, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}>
     */
    private function statesByName(PluginLoader $loader): array
    {
        $byName = [];
        foreach ($loader->getPluginStatuses() as $status) {
            $byName[$status['name']] = $status;
        }

        return $byName;
    }

    private static function writePlugin(
        string $baseDir,
        string $name,
        string $version,
        ?string $sdkConstraint,
        array $dependencies,
        string $routePath
    ): void {
        mkdir($baseDir . '/' . $name, 0755, true);
        $sdkConstraintCode = $sdkConstraint === null ? "''" : var_export($sdkConstraint, true);
        $depsCode = var_export($dependencies, true);

        file_put_contents($baseDir . '/' . $name . '/Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\Http\\Request;
use Whity\\Sdk\\Http\\Response;
use Whity\\Sdk\\PluginInterface;
use Whity\\Sdk\\PluginRequirementsInterface;

final class Plugin implements PluginInterface, PluginRequirementsInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '{$version}'; }
    public function getSdkConstraint(): string { return {$sdkConstraintCode}; }
    public function getPluginDependencies(): array { return {$depsCode}; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '{$routePath}',
            'handler' => static fn (Request \$r): Response => Response::json(['plugin' => '{$name}']),
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
    }

    private static function writePlainPlugin(string $baseDir, string $name, string $routePath): void
    {
        mkdir($baseDir . '/' . $name, 0755, true);

        file_put_contents($baseDir . '/' . $name . '/Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\Http\\Request;
use Whity\\Sdk\\Http\\Response;
use Whity\\Sdk\\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '{$routePath}',
            'handler' => static fn (Request \$r): Response => Response::json(['plugin' => '{$name}']),
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
