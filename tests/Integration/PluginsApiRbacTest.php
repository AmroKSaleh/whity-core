<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PluginsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Integration tests for the WC-10 plugin management API (issue #8).
 *
 * Drives the real {@see RbacMiddleware}, {@see RoleChecker}, {@see Router},
 * {@see PluginLoader}, and {@see PluginsApiHandler} together — resolving the
 * route's required permission exactly as the HTTP kernel does — to prove:
 *
 *  1. Each plugin endpoint is gated by its OWN per-action permission (WC-218):
 *     GET /api/plugins → plugins:read, enable/re-enable → plugins:enable,
 *     disable → plugins:disable, uninstall → plugins:uninstall, reload →
 *     plugins:reload. A token holding ONLY the matching permission reaches the
 *     handler, a token without it receives a structured 403, and an
 *     unauthenticated caller receives 401. The retired `plugins:manage`
 *     permission opens NO plugin route.
 *  2. End-to-end, a privileged disable request unregisters the plugin's routes
 *     and flips its lifecycle status to 'disabled' (AC #2).
 *
 * Role/permission data is seeded in COLON notation via {@see CorePermissions} and
 * stubbed at the database layer, mirroring {@see RbacRouteEnforcementTest}.
 */
class PluginsApiRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;
    private Router $router;
    private string $tempDir;
    private PluginLoader $loader;
    private PluginsApiHandler $handler;

    /**
     * Unique plugin class name for this test method.
     *
     * A fresh name per test avoids PHP "cannot redeclare class" fatals: each
     * test method re-runs setUp() and loads a plugin, but a class cannot be
     * redefined within a single PHP process.
     */
    private string $pluginName;

    /**
     * Route path served by the fixture plugin, unique per test method.
     */
    private string $pluginPath;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry = new PermissionRegistry();
        // Default to an empty-grant store; each test re-seeds via seedRolePermissions.
        $this->roleChecker = new RoleChecker($this->makeEmptyDb(), $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);
        $this->router = new Router('');

        $this->pluginName = 'RbacFixturePlugin' . str_replace('.', '', uniqid('', true));
        $this->pluginPath = '/api/rbacfixture/' . strtolower($this->pluginName);

        $this->tempDir = sys_get_temp_dir() . '/whity_plugins_rbac_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->writePlugin($this->pluginName, $this->pluginPath);

        $this->loader = new PluginLoader($this->tempDir, $this->router);
        $this->loader->load();

        $this->handler = new PluginsApiHandler($this->tempDir, $this->loader);

        // Register the admin plugin endpoints exactly as public/index.php does,
        // each gated by its OWN per-action permission (WC-218). enable and
        // re-enable share plugins:enable; the rest are 1:1.
        $this->router->register(
            'GET',
            '/api/plugins',
            [$this->handler, 'list'],
            null,
            null,
            CorePermissions::PLUGINS_READ
        );
        $this->router->register(
            'POST',
            '/api/plugins/{name}/enable',
            [$this->handler, 'enable'],
            null,
            null,
            CorePermissions::PLUGINS_ENABLE
        );
        $this->router->register(
            'POST',
            '/api/plugins/{name}/disable',
            [$this->handler, 'disable'],
            null,
            null,
            CorePermissions::PLUGINS_DISABLE
        );
        $this->router->register(
            'POST',
            '/api/plugins/{id}/re-enable',
            [$this->handler, 'reEnable'],
            null,
            null,
            CorePermissions::PLUGINS_ENABLE
        );
        $this->router->register(
            'POST',
            '/api/plugins/{id}/uninstall',
            [$this->handler, 'uninstall'],
            null,
            null,
            CorePermissions::PLUGINS_UNINSTALL
        );
        $this->router->register(
            'POST',
            '/api/plugins/reload',
            [$this->handler, 'reload'],
            null,
            null,
            CorePermissions::PLUGINS_RELOAD
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Resolve and enforce a request the way the HTTP kernel does: match the
     * route, then run the matched permission through the middleware.
     */
    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $handler = $match['handler'];
        $params = $match['params'];
        $next = static fn(Request $req): Response => $handler($req, $params);

        return $this->middleware->handle(
            $request,
            $next,
            $match['requiredRole'],
            $match['requiredPermission']
        );
    }

    /**
     * Seed the role_permissions store backing RoleChecker::hasPermission with a
     * real in-memory SQLite engine: the given user (tenant 1, no OU) holds a
     * dedicated role granting exactly $grantedPermissions. Rebuilds the checker
     * and middleware so the dispatch path uses the seeded store.
     *
     * @param array<int, string> $grantedPermissions Permissions the user's role grants.
     */
    private function seedRolePermissions(int $userId, array $grantedPermissions): void
    {
        $pdo = $this->makeSchema();

        $pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute(['role_' . $userId]);
        $roleId = (int) $pdo->lastInsertId();
        foreach ($grantedPermissions as $permission) {
            $pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                ->execute([$roleId, $permissionId]);
        }
        $pdo->prepare('INSERT INTO users (id, tenant_id, email, password, role_id, ou_id, created_at) VALUES (?, ?, ?, ?, ?, NULL, NOW())')
            ->execute([$userId, self::TENANT, "user{$userId}@example.com", 'x', $roleId]);

        $this->roleChecker = new RoleChecker($this->wrapSqlite($pdo), $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);
    }

    private function tokenFor(int $userId): string
    {
        return $this->jwtParser->create([
            'user_id' => $userId,
            'email' => "user{$userId}@example.com",
            'tenant_id' => self::TENANT,
        ]);
    }

    private function makeEmptyDb(): Database
    {
        return $this->wrapSqlite($this->makeSchema());
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function makeSchema(): PDO
    {
        return SchemaFromMigrations::make();
    }

    /**
     * AC: GET /api/plugins with plugins:read reaches the handler and returns
     * the metadata contract (name, version, status, routes_count,
     * permissions_count).
     */
    public function testListWithPluginsReadReturnsMetadata(): void
    {
        $userId = 20;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_READ]);

        $request = new Request('GET', '/api/plugins', ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]);
        $response = $this->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);

        $entry = null;
        foreach ($payload['data'] as $plugin) {
            if (($plugin['name'] ?? null) === $this->pluginName) {
                $entry = $plugin;
                break;
            }
        }

        $this->assertNotNull($entry);
        $this->assertSame('1.0.0', $entry['version']);
        $this->assertSame('active', $entry['status']);
        $this->assertSame(1, $entry['routes_count']);
        $this->assertSame(1, $entry['permissions_count']);
    }

    public function testListWithoutPluginsReadIsForbidden(): void
    {
        $userId = 21;
        $this->seedRolePermissions($userId, [CorePermissions::USERS_READ]);

        $request = new Request('GET', '/api/plugins', ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]);
        $response = $this->dispatch($request);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => 'plugins:read'],
            $body
        );
    }

    public function testListWithoutTokenIsUnauthorized(): void
    {
        $response = $this->dispatch(new Request('GET', '/api/plugins'));

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * AC #2 end-to-end: a privileged disable request (holding plugins:disable)
     * unregisters the plugin's routes and flips its lifecycle status to
     * 'disabled'.
     */
    public function testDisableWithPluginsDisableUnregistersRoutesAndDisables(): void
    {
        $userId = 22;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_DISABLE]);

        // Plugin route is live before disabling.
        $this->assertNotNull($this->router->match(new Request('GET', $this->pluginPath)));

        $request = new Request(
            'POST',
            '/api/plugins/' . $this->pluginName . '/disable',
            ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]
        );
        $response = $this->dispatch($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertSame('disabled', $payload['data']['state']);

        $this->assertNull(
            $this->router->match(new Request('GET', $this->pluginPath)),
            'Disabled plugin route must no longer match'
        );
        $this->assertSame(
            \Whity\Core\PluginState::Disabled,
            $this->loader->getLifecycle('Whity\\Plugins\\' . $this->pluginName)?->getState()
        );
    }

    /**
     * Every plugin write route refuses a principal that does not hold its
     * matching per-action permission. Driven through the real middleware: a 403
     * means the route's requiredPermission did not match, never that the handler
     * ran. We grant USERS_READ so the principal is authenticated and non-empty
     * but holds none of the plugin permissions.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function forbiddenWriteRouteProvider(): array
    {
        return [
            'enable'        => ['POST', '/api/plugins/PLUGIN/enable', 'plugins:enable'],
            'disable'       => ['POST', '/api/plugins/PLUGIN/disable', 'plugins:disable'],
            're-enable'     => ['POST', '/api/plugins/1/re-enable', 'plugins:enable'],
            'uninstall'     => ['POST', '/api/plugins/1/uninstall', 'plugins:uninstall'],
            'reload'        => ['POST', '/api/plugins/reload', 'plugins:reload'],
        ];
    }

    /**
     * @dataProvider forbiddenWriteRouteProvider
     */
    public function testWriteRouteWithoutItsPermissionIsForbidden(string $method, string $path, string $required): void
    {
        $userId = 30;
        // Holds an unrelated permission only — none of the plugin permissions.
        $this->seedRolePermissions($userId, [CorePermissions::USERS_READ]);

        $path = str_replace('PLUGIN', $this->pluginName, $path);
        $request = new Request(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]
        );
        $response = $this->dispatch($request);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => $required],
            $body
        );
    }

    /**
     * A principal holding ONLY plugins:read can list plugins but is forbidden on
     * every write route — read access must never imply mutate access (WC-218).
     *
     * @dataProvider forbiddenWriteRouteProvider
     */
    public function testReadOnlyPrincipalIsForbiddenOnWriteRoutes(string $method, string $path, string $required): void
    {
        $userId = 31;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_READ]);

        $path = str_replace('PLUGIN', $this->pluginName, $path);
        $request = new Request(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]
        );
        $response = $this->dispatch($request);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame($required, $body['required'] ?? null);
    }

    /**
     * The retired `plugins:manage` permission must open NO plugin route: a
     * principal granted only that string is forbidden everywhere, proving the
     * umbrella permission was removed and not silently re-accepted (WC-218).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function allPluginRouteProvider(): array
    {
        return [
            'list'      => ['GET', '/api/plugins'],
            'enable'    => ['POST', '/api/plugins/PLUGIN/enable'],
            'disable'   => ['POST', '/api/plugins/PLUGIN/disable'],
            're-enable' => ['POST', '/api/plugins/1/re-enable'],
            'uninstall' => ['POST', '/api/plugins/1/uninstall'],
            'reload'    => ['POST', '/api/plugins/reload'],
        ];
    }

    /**
     * @dataProvider allPluginRouteProvider
     */
    public function testPluginsManageOpensNoPluginRoute(string $method, string $path): void
    {
        $userId = 40;
        // 'plugins:manage' is no longer a known permission; seed it as a raw
        // role grant to prove it satisfies none of the new per-action gates.
        $this->seedRolePermissions($userId, ['plugins:manage']);

        $path = str_replace('PLUGIN', $this->pluginName, $path);
        $request = new Request(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]
        );
        $response = $this->dispatch($request);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "plugins:manage must not satisfy {$method} {$path}"
        );
        $body = json_decode($response->getBody(), true);
        $this->assertNotSame('plugins:manage', $body['required'] ?? null);
    }

    private function writePlugin(string $class, string $path): void
    {
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Sdk\\PluginInterface;
use Whity\\Core\\Request;
use Whity\\Core\\Response;

class {$class} implements PluginInterface
{
    public function getName(): string { return '{$class}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '{$path}',
            'handler' => [\$this, 'handle'],
            'requiredRole' => null,
        ]];
    }
    public function getPermissions(): array { return ['fixture:use']; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function handle(Request \$request): Response
    {
        return Response::json(['ok' => true]);
    }
}
PHP;
        file_put_contents($this->tempDir . '/' . $class . '.php', $code);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $childPath = $dir . '/' . $file;
            if (is_dir($childPath)) {
                $this->removeDirectory($childPath);
            } else {
                unlink($childPath);
            }
        }

        rmdir($dir);
    }
}
