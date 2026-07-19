<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\ThemeApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;

/**
 * WC-242: ThemeApiHandler — GET /api/v1/theme.
 *
 * Defense in depth: even though PluginLoader already verifies route
 * ownership (see PluginLoaderThemeOverrideTest), this handler additionally
 * revalidates every key against the known design-token names and every value
 * against a strict '#rrggbb' hex pattern before it is ever returned — these
 * tests exercise THAT sanitization layer directly, plus the "never breaks
 * page render" degradation guarantees.
 */
final class ThemeApiHandlerTest extends TestCase
{
    public function testNoPluginReturnsEmptyOverrides(): void
    {
        $dir = $this->makeEmptyPluginDir();
        $loader = $this->loadedLoader($dir);
        $handler = new ThemeApiHandler($loader);

        $response = $handler->get(new Request('GET', '/api/theme'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => []], json_decode($response->getBody(), true));

        $this->removeDirectory($dir);
    }

    public function testKnownKeyWithValidHexPassesThrough(): void
    {
        $dir = $this->makePluginDir(
            'ThemeOk',
            "return \\Whity\\Sdk\\Http\\Response::json(['data' => ['primary' => '#ABCDEF']]);"
        );
        $loader = $this->loadedLoader($dir);
        $handler = new ThemeApiHandler($loader);

        $response = $handler->get(new Request('GET', '/api/theme'));
        $body = json_decode($response->getBody(), true);

        $this->assertSame(['primary' => '#ABCDEF'], $body['data']);

        $this->removeDirectory($dir);
    }

    public function testUnknownTokenNameIsDropped(): void
    {
        $dir = $this->makePluginDir(
            'ThemeUnknown',
            "return \\Whity\\Sdk\\Http\\Response::json(['data' => ['not-a-real-token' => '#ABCDEF']]);"
        );
        $loader = $this->loadedLoader($dir);
        $handler = new ThemeApiHandler($loader);

        $response = $handler->get(new Request('GET', '/api/theme'));
        $body = json_decode($response->getBody(), true);

        $this->assertSame([], $body['data']);

        $this->removeDirectory($dir);
    }

    /**
     * @dataProvider malformedHexValues
     */
    public function testMalformedHexValueIsDropped(string $value): void
    {
        $dir = $this->makePluginDir(
            'ThemeBadHex' . str_replace('.', '', uniqid('', true)),
            "return \\Whity\\Sdk\\Http\\Response::json(['data' => ['primary' => " . var_export($value, true) . "]]);"
        );
        $loader = $this->loadedLoader($dir);
        $handler = new ThemeApiHandler($loader);

        $response = $handler->get(new Request('GET', '/api/theme'));
        $body = json_decode($response->getBody(), true);

        $this->assertSame([], $body['data'], "value '{$value}' must be rejected");

        $this->removeDirectory($dir);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedHexValues(): iterable
    {
        yield 'no hash' => ['ABCDEF'];
        yield 'too short' => ['#ABC'];
        yield 'too long' => ['#ABCDEFAB'];
        yield 'not hex chars' => ['#GGGGGG'];
        yield 'css injection attempt' => ['#fff;}body{display:none'];
        yield 'javascript-ish' => ['red'];
    }

    public function testThrowingPluginHandlerDegradesToEmpty(): void
    {
        $dir = $this->makePluginDir('ThemeThrows', "throw new \\RuntimeException('boom');");
        $loader = $this->loadedLoader($dir);
        $handler = new ThemeApiHandler($loader);

        $response = $handler->get(new Request('GET', '/api/theme'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => []], json_decode($response->getBody(), true));

        $this->removeDirectory($dir);
    }

    public function testNonOkPluginResponseDegradesToEmpty(): void
    {
        $dir = $this->makePluginDir(
            'ThemeFails',
            "return \\Whity\\Sdk\\Http\\Response::error('nope', 500);"
        );
        $loader = $this->loadedLoader($dir);
        $handler = new ThemeApiHandler($loader);

        $response = $handler->get(new Request('GET', '/api/theme'));

        $this->assertSame(['data' => []], json_decode($response->getBody(), true));

        $this->removeDirectory($dir);
    }

    /**
     * A theme route with a requiredPermission and no RoleChecker wired
     * degrades to empty rather than crashing or exposing the data.
     */
    public function testPermissionGatedRouteWithNoRoleCheckerDegradesToEmpty(): void
    {
        $dir = sys_get_temp_dir() . '/whity_theme_api_gated_' . uniqid();
        mkdir($dir . '/ThemeGated', 0755, true);
        file_put_contents($dir . '/ThemeGated/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace ThemeGated;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginThemeInterface;
final class Plugin implements PluginInterface, PluginThemeInterface
{
    public function getName(): string { return 'ThemeGated'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.7'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/theme-gated/current',
            'handler' => static fn ($r) => Response::json(['data' => ['primary' => '#123456']]),
            'requiredRole' => null,
            'requiredPermission' => 'theme:manage',
        ]];
    }
    public function getThemeOverrideRoute(): string { return '/api/theme-gated/current'; }
}
PHP);
        $loader = $this->loadedLoader($dir);
        $handler = new ThemeApiHandler($loader);

        $response = $handler->get(new Request('GET', '/api/theme'));

        $this->assertSame(['data' => []], json_decode($response->getBody(), true));

        $this->removeDirectory($dir);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function loadedLoader(string $dir): PluginLoader
    {
        $loader = new PluginLoader($dir, new Router(''), new PermissionRegistry(), new HookManager());
        $loader->load();

        return $loader;
    }

    private function makeEmptyPluginDir(): string
    {
        $dir = sys_get_temp_dir() . '/whity_theme_api_empty_' . uniqid();
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function makePluginDir(string $name, string $handlerBody): string
    {
        $dir = sys_get_temp_dir() . '/whity_theme_api_' . uniqid();
        mkdir($dir . '/' . $name, 0755, true);
        file_put_contents($dir . '/' . $name . '/Plugin.php', <<<PHP
<?php
declare(strict_types=1);
namespace {$name};
use Whity\\Sdk\\Http\\Request;
use Whity\\Sdk\\Http\\Response;
use Whity\\Sdk\\PluginInterface;
use Whity\\Sdk\\PluginThemeInterface;
final class Plugin implements PluginInterface, PluginThemeInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.7'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/{$name}/current',
            'handler' => static function (Request \$r) {
                {$handlerBody}
            },
            'requiredRole' => null,
            'requiredPermission' => null,
        ]];
    }
    public function getThemeOverrideRoute(): string { return '/api/{$name}/current'; }
}
PHP);

        return $dir;
    }

    private function removeDirectory(string $dir): void
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
