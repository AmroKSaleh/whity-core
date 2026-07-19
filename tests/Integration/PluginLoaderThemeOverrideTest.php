<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * WC-242: PluginLoader::getThemeOverrideRoute() — the optional
 * PluginThemeInterface capability that lets a plugin contribute theme color
 * overrides the host applies at render time.
 *
 * Same ownership invariant as data-bound block sources (WC-230,
 * {@see PluginLoaderDataBoundBlocksTest}): the declared route must be a GET
 * route the SAME plugin actually registered, or it is dropped fail-closed and
 * the plugin still loads normally.
 */
final class PluginLoaderThemeOverrideTest extends TestCase
{
    private static string $ownedDir;
    private static string $foreignDir;
    private static string $noneDir;
    private static string $twoPluginsDir;

    public static function setUpBeforeClass(): void
    {
        self::$ownedDir = sys_get_temp_dir() . '/whity_theme_owned_' . uniqid();
        self::$foreignDir = sys_get_temp_dir() . '/whity_theme_foreign_' . uniqid();
        self::$noneDir = sys_get_temp_dir() . '/whity_theme_none_' . uniqid();
        self::$twoPluginsDir = sys_get_temp_dir() . '/whity_theme_two_' . uniqid();

        // Plugin that owns GET /api/theme-owner/current and declares it as
        // its theme-override route.
        self::writePlugin(self::$ownedDir, 'ThemeOwned', <<<'PHP'
    public function getPermissions(): array { return []; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/theme-owner/current',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => ['primary' => '#112233']]),
            'requiredRole' => null,
            'requiredPermission' => null,
        ]];
    }
    public function getThemeOverrideRoute(): string { return '/api/theme-owner/current'; }
PHP, true);

        // Plugin that does NOT register the path it claims as its theme route.
        self::writePlugin(self::$foreignDir, 'ThemeForeign', <<<'PHP'
    public function getPermissions(): array { return []; }
    public function getRoutes(): array { return []; }
    public function getThemeOverrideRoute(): string { return '/api/somebody/elses-route'; }
PHP, true);

        // Plain plugin implementing only PluginInterface (no theme capability).
        self::writePlugin(self::$noneDir, 'ThemeNone', <<<'PHP'
    public function getPermissions(): array { return []; }
    public function getRoutes(): array { return []; }
PHP, false);

        // Two plugins both implementing PluginThemeInterface — first
        // (alphabetical discovery order) should win.
        self::writePlugin(self::$twoPluginsDir . '/ThemeAaFirst', 'ThemeAaFirst', <<<'PHP'
    public function getPermissions(): array { return []; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/aa-first/current',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => ['primary' => '#111111']]),
            'requiredRole' => null,
            'requiredPermission' => null,
        ]];
    }
    public function getThemeOverrideRoute(): string { return '/api/aa-first/current'; }
PHP, true, subdir: true);
        self::writePlugin(self::$twoPluginsDir . '/ThemeZzSecond', 'ThemeZzSecond', <<<'PHP'
    public function getPermissions(): array { return []; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/zz-second/current',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => ['primary' => '#222222']]),
            'requiredRole' => null,
            'requiredPermission' => null,
        ]];
    }
    public function getThemeOverrideRoute(): string { return '/api/zz-second/current'; }
PHP, true, subdir: true);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$ownedDir, self::$foreignDir, self::$noneDir, self::$twoPluginsDir] as $dir) {
            self::removeDirectory($dir);
        }
    }

    public function testOwnedThemeRouteIsExposed(): void
    {
        [$loader] = $this->loadDir(self::$ownedDir);

        $descriptor = $loader->getThemeOverrideRoute();

        $this->assertNotNull($descriptor);
        $this->assertSame('/api/theme-owner/current', $descriptor['path']);
        $this->assertSame('ThemeOwned', $descriptor['plugin']);
        $this->assertNull($descriptor['requiredPermission']);
        $this->assertIsCallable($descriptor['handler']);
    }

    public function testOwnedThemeRouteHandlerIsInvocable(): void
    {
        [$loader] = $this->loadDir(self::$ownedDir);
        $descriptor = $loader->getThemeOverrideRoute();
        $this->assertNotNull($descriptor);

        $response = ($descriptor['handler'])(new \Whity\Sdk\Http\Request('GET', '/api/theme-owner/current'), []);
        $body = json_decode($response->getBody(), true);

        $this->assertSame(['primary' => '#112233'], $body['data']);
    }

    /**
     * A plugin declaring a theme-override route it did NOT itself register
     * is dropped fail-closed — same invariant as data-bound block sources.
     */
    public function testForeignThemeRouteIsRejected(): void
    {
        [$loader] = $this->loadDir(self::$foreignDir);

        $this->assertNull($loader->getThemeOverrideRoute());
    }

    public function testPluginWithoutTheInterfaceContributesNothing(): void
    {
        [$loader] = $this->loadDir(self::$noneDir);

        $this->assertNull($loader->getThemeOverrideRoute());
    }

    /**
     * Two plugins both implementing PluginThemeInterface: first-registration
     * (discovery order) wins, same convention as frontend-feature ids and
     * MCP prompt names.
     */
    public function testFirstRegisteredPluginWinsOnConflict(): void
    {
        [$loader] = $this->loadDir(self::$twoPluginsDir);

        $descriptor = $loader->getThemeOverrideRoute();

        $this->assertNotNull($descriptor);
        $this->assertSame('ThemeAaFirst', $descriptor['plugin']);
    }

    public function testNeverThrowsRegardlessOfDeclaration(): void
    {
        $this->expectNotToPerformAssertions();

        [$loader] = $this->loadDir(self::$foreignDir);
        $loader->getThemeOverrideRoute();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: PluginLoader}
     */
    private function loadDir(string $dir): array
    {
        $loader = new PluginLoader($dir, new Router(''), new PermissionRegistry(), new HookManager());
        $loader->load();

        return [$loader];
    }

    private static function writePlugin(
        string $baseDir,
        string $name,
        string $body,
        bool $implementsTheme,
        bool $subdir = false,
    ): void {
        $dir = $subdir ? $baseDir : $baseDir . '/' . $name;
        mkdir($dir, 0755, true);

        $interfaces = 'PluginInterface';
        $themeImport = '';
        if ($implementsTheme) {
            $interfaces .= ', PluginThemeInterface';
            $themeImport = "use Whity\\Sdk\\PluginThemeInterface;\n";
        }

        file_put_contents($dir . '/Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\Http\\Response;
use Whity\\Sdk\\PluginInterface;
{$themeImport}
final class Plugin implements {$interfaces}
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.7'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
{$body}
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
