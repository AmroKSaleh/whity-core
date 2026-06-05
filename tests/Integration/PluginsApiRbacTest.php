<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Api\PluginsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
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
 *  1. Every plugin endpoint (list/enable/disable) is gated by
 *     {@see CorePermissions::PLUGINS_MANAGE}; a user granted it reaches the
 *     handler, a user without it receives a structured 403, and an unauthenticated
 *     caller receives 401.
 *  2. End-to-end, a privileged disable request unregisters the plugin's routes
 *     and flips its lifecycle status to 'disabled' (AC #2).
 *
 * Role/permission data is seeded in COLON notation via {@see CorePermissions} and
 * stubbed at the database layer, mirroring {@see RbacRouteEnforcementTest}.
 */
class PluginsApiRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key';
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
        $this->router = new Router();

        $this->pluginName = 'RbacFixturePlugin' . str_replace('.', '', uniqid('', true));
        $this->pluginPath = '/api/rbacfixture/' . strtolower($this->pluginName);

        $this->tempDir = sys_get_temp_dir() . '/whity_plugins_rbac_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->writePlugin($this->pluginName, $this->pluginPath);

        $this->loader = new PluginLoader($this->tempDir, $this->router);
        $this->loader->load();

        $this->handler = new PluginsApiHandler($this->tempDir, $this->loader);

        // Register the admin plugin endpoints exactly as the documented handoff
        // does in public/index.php, gated by plugins:manage.
        $this->router->register(
            'GET',
            '/api/plugins',
            [$this->handler, 'list'],
            null,
            null,
            CorePermissions::PLUGINS_MANAGE
        );
        $this->router->register(
            'POST',
            '/api/plugins/{name}/enable',
            [$this->handler, 'enable'],
            null,
            null,
            CorePermissions::PLUGINS_MANAGE
        );
        $this->router->register(
            'POST',
            '/api/plugins/{name}/disable',
            [$this->handler, 'disable'],
            null,
            null,
            CorePermissions::PLUGINS_MANAGE
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
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, parent_id INTEGER, tenant_id INTEGER, created_at TEXT)');
        $pdo->exec('CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE, description TEXT, created_at TEXT)');
        $pdo->exec('CREATE TABLE role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL, permission_id INTEGER NOT NULL, created_at TEXT, UNIQUE(role_id, permission_id))');
        $pdo->exec('CREATE TABLE organizational_units (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, parent_id INTEGER, name TEXT NOT NULL, slug TEXT NOT NULL, created_at TEXT)');
        $pdo->exec('CREATE TABLE ou_role_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, ou_id INTEGER NOT NULL, role_id INTEGER NOT NULL, created_at TEXT, UNIQUE(ou_id, role_id))');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, email TEXT NOT NULL, password TEXT NOT NULL, role_id INTEGER, ou_id INTEGER, created_at TEXT)');

        return $pdo;
    }

    /**
     * AC: GET /api/plugins with plugins:manage reaches the handler and returns
     * the metadata contract (name, version, status, routes_count,
     * permissions_count).
     */
    public function testListWithPluginsManageReturnsMetadata(): void
    {
        $userId = 20;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_MANAGE]);

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

    public function testListWithoutPluginsManageIsForbidden(): void
    {
        $userId = 21;
        $this->seedRolePermissions($userId, [CorePermissions::USERS_READ]);

        $request = new Request('GET', '/api/plugins', ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]);
        $response = $this->dispatch($request);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => 'plugins:manage'],
            $body
        );
    }

    public function testListWithoutTokenIsUnauthorized(): void
    {
        $response = $this->dispatch(new Request('GET', '/api/plugins'));

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * AC #2 end-to-end: a privileged disable request unregisters the plugin's
     * routes and flips its lifecycle status to 'disabled'.
     */
    public function testDisableWithPluginsManageUnregistersRoutesAndDisables(): void
    {
        $userId = 22;
        $this->seedRolePermissions($userId, [CorePermissions::PLUGINS_MANAGE]);

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

    public function testDisableWithoutPluginsManageIsForbidden(): void
    {
        $userId = 23;
        $this->seedRolePermissions($userId, [CorePermissions::ROLES_READ]);

        $request = new Request(
            'POST',
            '/api/plugins/' . $this->pluginName . '/disable',
            ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]
        );
        $response = $this->dispatch($request);

        $this->assertSame(403, $response->getStatusCode());
        // The handler must not have run: the route is still live.
        $this->assertNotNull($this->router->match(new Request('GET', $this->pluginPath)));
    }

    public function testEnableWithoutPluginsManageIsForbidden(): void
    {
        $userId = 24;
        $this->seedRolePermissions($userId, [CorePermissions::USERS_READ]);

        $request = new Request(
            'POST',
            '/api/plugins/' . $this->pluginName . '/enable',
            ['Authorization' => 'Bearer ' . $this->tokenFor($userId)]
        );
        $response = $this->dispatch($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    private function writePlugin(string $class, string $path): void
    {
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Core\\PluginInterface;
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
