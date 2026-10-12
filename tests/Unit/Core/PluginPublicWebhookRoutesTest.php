<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\PluginPublicRoutes;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Http\Middleware\EnforceTenantIsolation;

/**
 * A plugin may declare ONE kind of public route: a POST webhook at
 * /api/<its own slug>/webhooks/<name>, with no permission and no role (WHIT-671).
 * Anything else that asks to be public is refused outright, the same
 * fail-closed treatment as a malformed requiredPermission. Public means only
 * "reachable without tenant resolution"; the handler still authenticates its
 * caller, by signature.
 */
final class PluginPublicWebhookRoutesTest extends TestCase
{
    private string $dir;
    private Router $router;
    /** A fresh plugin per test: PHP cannot redeclare one class twice. */
    private string $name;
    private string $slug;

    protected function setUp(): void
    {
        PluginPublicRoutes::reset();
        $this->name = 'Hook' . bin2hex(random_bytes(4));
        $this->slug = strtolower($this->name);
        $this->dir = sys_get_temp_dir() . '/whity_' . $this->slug;
        mkdir($this->dir . '/' . $this->name, 0755, true);
        $source = <<<'PHP'
<?php
declare(strict_types=1);
namespace __NAME__;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return '__NAME__'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        $ok = fn (Request $r): Response => Response::json(['ok' => true]);
        return [
            // The one accepted shape.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/billing', 'handler' => $ok, 'public' => true],
            // Refused: not POST.
            ['method' => 'GET', 'path' => '/api/__SLUG__/webhooks/peek', 'handler' => $ok, 'public' => true],
            // Refused: not under /webhooks/.
            ['method' => 'POST', 'path' => '/api/__SLUG__/things', 'handler' => $ok, 'public' => true],
            // Refused: another plugin's slug.
            ['method' => 'POST', 'path' => '/api/other/webhooks/x', 'handler' => $ok, 'public' => true],
            // Refused: a public route cannot also demand a permission.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/gated', 'handler' => $ok, 'public' => true, 'requiredPermission' => '__SLUG__:write'],
            // Refused: a role likewise.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/roled', 'handler' => $ok, 'public' => true, 'requiredRole' => 'admin'],
            // Refused: "public" must be the boolean true, nothing truthy.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/truthy', 'handler' => $ok, 'public' => 'yes'],
            // Refused: a name outside [a-z0-9-], or a deeper path.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/Bad_Name', 'handler' => $ok, 'public' => true],
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/a/b', 'handler' => $ok, 'public' => true],
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/{provider}', 'handler' => $ok, 'public' => true],
            // An ordinary route: registered, never public.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/private', 'handler' => $ok],
        ];
    }
    public function getPermissions(): array { return ['__SLUG__:write']; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP;
        file_put_contents(
            $this->dir . '/' . $this->name . '/Plugin.php',
            str_replace(['__NAME__', '__SLUG__'], [$this->name, $this->slug], $source)
        );
        $this->router = new Router();
    }

    protected function tearDown(): void
    {
        PluginPublicRoutes::reset();
        @unlink($this->dir . '/' . $this->name . '/Plugin.php');
        @rmdir($this->dir . '/' . $this->name);
        @rmdir($this->dir);
    }

    private function load(): PluginLoader
    {
        $loader = new PluginLoader($this->dir, $this->router, new PermissionRegistry(), new HookManager());
        $loader->load();
        return $loader;
    }

    private function routed(string $method, string $path): bool
    {
        return $this->router->match(new Request($method, $path, [], '')) !== null;
    }

    public function testTheOneAcceptedShapeIsRoutedAndPublic(): void
    {
        $this->load();

        self::assertTrue($this->routed('POST', '/api/v1/' . $this->slug . '/webhooks/billing'));
        self::assertTrue(EnforceTenantIsolation::pathBypassesTenantResolution('/api/v1/' . $this->slug . '/webhooks/billing'));
    }

    public function testEveryOtherRequestToBePublicIsRefusedAndNotRegistered(): void
    {
        $this->load();

        foreach ([
            ['GET', '/api/v1/' . $this->slug . '/webhooks/peek'],
            ['POST', '/api/v1/' . $this->slug . '/things'],
            ['POST', '/api/v1/other/webhooks/x'],
            ['POST', '/api/v1/' . $this->slug . '/webhooks/gated'],
            ['POST', '/api/v1/' . $this->slug . '/webhooks/roled'],
            ['POST', '/api/v1/' . $this->slug . '/webhooks/truthy'],
            ['POST', '/api/v1/' . $this->slug . '/webhooks/Bad_Name'],
            ['POST', '/api/v1/' . $this->slug . '/webhooks/a/b'],
        ] as [$method, $path]) {
            self::assertFalse($this->routed($method, $path), "{$method} {$path} must not be registered");
            self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution($path), "{$path} must not be public");
        }
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/api/v1/' . $this->slug . '/webhooks/anything'));
    }

    public function testAnOrdinaryRouteBesideItStaysPrivate(): void
    {
        $this->load();

        self::assertTrue($this->routed('POST', '/api/v1/' . $this->slug . '/webhooks/private'));
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/api/v1/' . $this->slug . '/webhooks/private'));
    }

    public function testOnlyTheServedPathIsPublicNotTheDeclaredOne(): void
    {
        $this->load();

        // The declared form is never served, so it must not be public either.
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/api/__SLUG__/webhooks/billing'));
        // Nor any prefix or extension of the public path.
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/api/v1/' . $this->slug . '/webhooks/billing/x'));
        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/api/v1/' . $this->slug . '/webhooks/'));
    }

    public function testDisablingThePluginClosesItsPublicPath(): void
    {
        $loader = $this->load();

        self::assertTrue($loader->disablePlugin($this->name . '\\Plugin'));

        self::assertFalse(EnforceTenantIsolation::pathBypassesTenantResolution('/api/v1/' . $this->slug . '/webhooks/billing'));
    }
}
