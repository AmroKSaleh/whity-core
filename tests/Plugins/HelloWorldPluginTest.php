<?php

declare(strict_types=1);

namespace Tests\Plugins;

use HelloWorld\HelloWorldPlugin;
use HelloWorld\Migrations\CreateHelloGreetingsTable;
use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/HelloWorldPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/Migrations/CreateHelloGreetingsTable.php';

/**
 * Tests for the HelloWorld reference plugin shipped with the
 * Plugin-Development tutorial.
 *
 * Verifies that the plugin satisfies the SDK plugin contract (WC-162), that
 * the PluginLoader discovers it and registers GET /api/hello, and that its
 * `user.creating` hook runs the documented custom logic.
 */
final class HelloWorldPluginTest extends TestCase
{
    public function testImplementsPluginInterface(): void
    {
        $plugin = new HelloWorldPlugin();

        $this->assertInstanceOf(PluginInterface::class, $plugin);
        $this->assertSame('HelloWorld', $plugin->getName());
        $this->assertSame('1.0.0', $plugin->getVersion());
    }

    public function testDeclaresPublicAndAdminRoutes(): void
    {
        $routes = (new HelloWorldPlugin())->getRoutes();

        $this->assertCount(2, $routes);

        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/api/hello', $routes[0]['path']);
        $this->assertIsCallable($routes[0]['handler']);
        $this->assertNull($routes[0]['requiredRole']);

        $this->assertSame('GET', $routes[1]['method']);
        $this->assertSame('/api/hello/admin', $routes[1]['path']);
        $this->assertSame('admin', $routes[1]['requiredRole']);
    }

    public function testDeclaresColonNotationPermissions(): void
    {
        $permissions = (new HelloWorldPlugin())->getPermissions();

        $this->assertContains('hello:view', $permissions);
        $this->assertContains('hello:manage', $permissions);

        // Every permission must satisfy the mandated resource:action pattern.
        foreach ($permissions as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/',
                $permission
            );
        }
    }

    public function testDeclaresUserCreatingHookAndMigration(): void
    {
        $plugin = new HelloWorldPlugin();

        $hooks = $plugin->getHooks();
        $this->assertArrayHasKey('user.creating', $hooks);

        $migrations = $plugin->getMigrations();
        $this->assertContains(CreateHelloGreetingsTable::class, $migrations);
    }

    public function testHelloHandlerReturnsJsonGreeting(): void
    {
        $response = (new HelloWorldPlugin())->hello(new Request('GET', '/api/hello'));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertSame('Hello, World!', $payload['message']);
        $this->assertSame('HelloWorld', $payload['plugin']);
    }

    public function testUserCreatingHookNormalisesEmailAndStampsPayload(): void
    {
        $plugin = new HelloWorldPlugin();

        $result = $plugin->onUserCreating(
            ['email' => '  Alice@Example.COM ', 'password' => 'secret', 'role_id' => 2],
            ['tenant_id' => 1, 'timestamp' => time()]
        );

        $this->assertSame('alice@example.com', $result['email']);
        $this->assertTrue($result['hello_world_greeted']);
        // Untouched fields are preserved.
        $this->assertSame('secret', $result['password']);
        $this->assertSame(2, $result['role_id']);
    }

    public function testHookRunsViaHookManagerDispatch(): void
    {
        $plugin = new HelloWorldPlugin();
        $hookManager = new HookManager();

        foreach ($plugin->getHooks() as $event => $subscription) {
            /** @var array{callback: callable, priority?: int} $subscription */
            $hookManager->listen($event, $subscription['callback'], $subscription['priority'] ?? 10);
        }

        $result = $hookManager->dispatch('user.creating', [
            'email' => 'BOB@EXAMPLE.COM',
            'password' => 'pw',
            'role_id' => 3,
        ]);

        $this->assertSame('bob@example.com', $result['email']);
        $this->assertTrue($result['hello_world_greeted']);
    }

    public function testPluginLoaderDiscoversHelloWorldAndRegistersRoute(): void
    {
        // Point the loader at the real plugins/ directory so we exercise the
        // exact discovery path used in production. The directory may contain
        // other plugins (e.g. ExamplePlugin); we only assert on HelloWorld so
        // the test tolerates additional plugins.
        $pluginDir = dirname(__DIR__, 2) . '/plugins';

        $router = new Router();
        $permissionRegistry = new PermissionRegistry();
        $hookManager = new HookManager();

        $loader = new PluginLoader($pluginDir, $router, $permissionRegistry, $hookManager);
        $loader->load();

        $names = array_map(
            static fn(PluginInterface $p): string => $p->getName(),
            $loader->getPlugins()
        );
        $this->assertContains('HelloWorld', $names);

        // The public greeting route is live.
        $match = $router->match(new Request('GET', '/api/hello'));
        $this->assertNotNull($match);
        $this->assertNull($match['requiredRole']);

        // The admin-only route requires the admin role.
        $adminMatch = $router->match(new Request('GET', '/api/hello/admin'));
        $this->assertNotNull($adminMatch);
        $this->assertSame('admin', $adminMatch['requiredRole']);

        // Permissions were registered for the plugin source.
        $this->assertTrue($permissionRegistry->exists('hello:view'));
        $this->assertTrue($permissionRegistry->exists('hello:manage'));

        // The user.creating hook is registered.
        $this->assertNotEmpty($hookManager->getListeners('user.creating'));
    }
}
