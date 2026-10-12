<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Plugin\PluginPublicRoutes;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Sdk\Http\Response;

/**
 * A plugin may declare ONE kind of public route: a POST webhook at
 * /api/<its own slug>/webhooks/<name>, with no permission and no role (WHIT-671).
 * Anything else that asks to be public is refused outright. And what is
 * admitted at request time is narrower than a path: the registered method, and
 * only when the plugin's own route is the one that would run.
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
        $boom = function (Request $r): Response { throw new \RuntimeException('unsigned input'); };
        return [
            // The accepted shape, and one that throws.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/billing', 'handler' => $ok, 'public' => true],
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/boom', 'handler' => $boom, 'public' => true],
            // Refused: not POST.
            ['method' => 'GET', 'path' => '/api/__SLUG__/webhooks/peek', 'handler' => $ok, 'public' => true],
            // Refused: not under /webhooks/.
            ['method' => 'POST', 'path' => '/api/__SLUG__/things', 'handler' => $ok, 'public' => true],
            // Refused: another plugin's slug.
            ['method' => 'POST', 'path' => '/api/other/webhooks/x', 'handler' => $ok, 'public' => true],
            // Refused: a public route cannot also demand a permission or a role.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/gated', 'handler' => $ok, 'public' => true, 'requiredPermission' => '__SLUG__:write'],
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/roled', 'handler' => $ok, 'public' => true, 'requiredRole' => 'admin'],
            // Refused: "public" must be the boolean true, nothing truthy.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/truthy', 'handler' => $ok, 'public' => 'yes'],
            // Refused: a bad name, a deeper path, a parameter, a trailing newline.
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/Bad_Name', 'handler' => $ok, 'public' => true],
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/a/b', 'handler' => $ok, 'public' => true],
            ['method' => 'POST', 'path' => '/api/__SLUG__/webhooks/{provider}', 'handler' => $ok, 'public' => true],
            ['method' => 'POST', 'path' => "/api/__SLUG__/webhooks/nl\n", 'handler' => $ok, 'public' => true],
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

    private function path(string $tail): string
    {
        return '/api/v1/' . $this->slug . '/webhooks/' . $tail;
    }

    private function admitted(string $method, string $path): bool
    {
        return PluginPublicRoutes::admits(new Request($method, $path, [], ''), $this->router, $path);
    }

    private function routed(string $method, string $path): bool
    {
        return $this->router->match(new Request($method, $path, [], '')) !== null;
    }

    public function testTheOneAcceptedShapeIsRoutedAndAdmitted(): void
    {
        $this->load();

        self::assertTrue($this->routed('POST', $this->path('billing')));
        self::assertTrue($this->admitted('POST', $this->path('billing')));
    }

    public function testItPassesTheRealMiddlewareWithNoTokenAndNothingElseDoes(): void
    {
        $this->load();
        $middleware = new EnforceTenantIsolation(new JwtParser(str_repeat('k', 40)), null, null, $this->router);
        $next = static fn (): Response => Response::json(['reached' => true]);

        $through = $middleware->handle(new Request('POST', $this->path('billing'), [], '{}'), $next);
        $private = $middleware->handle(new Request('POST', $this->path('private'), [], '{}'), $next);

        self::assertSame(200, $through->getStatusCode());
        self::assertSame(401, $private->getStatusCode());
        // Without a router to ask, no plugin webhook is admitted at all.
        $blind = new EnforceTenantIsolation(new JwtParser(str_repeat('k', 40)));
        self::assertSame(401, $blind->handle(new Request('POST', $this->path('billing'), [], '{}'), $next)->getStatusCode());
    }

    public function testEveryOtherRequestToBePublicIsRefusedAndNotRegistered(): void
    {
        $this->load();

        foreach ([
            ['GET', $this->path('peek')],
            ['POST', '/api/v1/' . $this->slug . '/things'],
            ['POST', '/api/v1/other/webhooks/x'],
            ['POST', $this->path('gated')],
            ['POST', $this->path('roled')],
            ['POST', $this->path('truthy')],
            ['POST', $this->path('Bad_Name')],
            ['POST', $this->path('a/b')],
            ['POST', $this->path('anything')],
        ] as [$method, $path]) {
            self::assertFalse($this->routed($method, $path), "{$method} {$path} must not be registered");
            self::assertFalse($this->admitted($method, $path), "{$method} {$path} must not be admitted");
        }
    }

    public function testAnOrdinaryRouteBesideItStaysPrivate(): void
    {
        $this->load();

        self::assertTrue($this->routed('POST', $this->path('private')));
        self::assertFalse($this->admitted('POST', $this->path('private')));
    }

    public function testOnlyTheServedPathAndMethodAreAdmitted(): void
    {
        $this->load();

        self::assertFalse($this->admitted('POST', '/api/' . $this->slug . '/webhooks/billing'));
        self::assertFalse($this->admitted('POST', $this->path('billing') . '/x'));
        self::assertFalse($this->admitted('POST', $this->path('')));
        foreach (['GET', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $method) {
            self::assertFalse($this->admitted($method, $this->path('billing')), "{$method} must not be admitted");
        }
    }

    public function testALaterRouteOnTheSamePathWithAnotherMethodIsNotPublic(): void
    {
        $this->load();
        $this->router->register('GET', '/api/' . $this->slug . '/webhooks/billing', static fn (): Response => Response::json([]), null, 'SomebodyElse');

        self::assertTrue($this->routed('GET', $this->path('billing')));
        self::assertFalse($this->admitted('GET', $this->path('billing')));
        self::assertTrue($this->admitted('POST', $this->path('billing')));
    }

    public function testACorePatternRouteThatCapturesThePathKeepsItPrivate(): void
    {
        // Core registers first and the first match wins: here a core pattern
        // would answer the plugin's literal path, so it must not become public.
        $this->router->register('POST', '/api/' . $this->slug . '/{id}/{action}', static fn (): Response => Response::json([]), null, null, 'core:write');
        $this->load();

        self::assertFalse($this->admitted('POST', $this->path('billing')));
    }

    public function testARegisteredPathIsAdmittedOnlyWhileItsOwnersRouteAnswersIt(): void
    {
        // The second layer: whatever the registry says, the request is admitted
        // only if the route that would RUN belongs to the registered owner, so
        // a route order that changes after registration cannot open someone
        // else's handler.
        $path = '/api/v1/elsewhere/webhooks/x';
        $this->router->register('POST', '/api/elsewhere/webhooks/x', static fn (): Response => Response::json([]), null, 'TheRealOwner');
        PluginPublicRoutes::add('SomebodyElse', 'POST', $path);

        self::assertFalse($this->admitted('POST', $path));

        PluginPublicRoutes::add('TheRealOwner', 'POST', $path);
        self::assertTrue($this->admitted('POST', $path));
    }

    public function testDisablingThePluginClosesItsPublicPath(): void
    {
        $loader = $this->load();

        self::assertTrue($loader->disablePlugin($this->name . '\\Plugin'));

        self::assertFalse($this->admitted('POST', $this->path('billing')));
    }

    public function testAReloadThatDropsThePluginClosesItsPublicPath(): void
    {
        $loader = $this->load();
        self::assertTrue($this->admitted('POST', $this->path('billing')));

        unlink($this->dir . '/' . $this->name . '/Plugin.php');
        rmdir($this->dir . '/' . $this->name);
        $loader->reload();

        self::assertFalse(PluginPublicRoutes::allows('POST', $this->path('billing')));
    }

    public function testAnonymousFailuresOnAPublicRouteCannotQuarantineThePlugin(): void
    {
        $this->load();
        $boom = $this->router->match(new Request('POST', $this->path('boom'), [], ''));
        self::assertNotNull($boom);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(500, ($boom['handler'])(new Request('POST', $this->path('boom'), [], ''), [])->getStatusCode());
        }

        $billing = $this->router->match(new Request('POST', $this->path('billing'), [], ''));
        self::assertNotNull($billing);
        self::assertSame(200, ($billing['handler'])(new Request('POST', $this->path('billing'), [], ''), [])->getStatusCode());
    }
}
