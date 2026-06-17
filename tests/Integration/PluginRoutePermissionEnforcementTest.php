<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;
use Whity\Sdk\Http\Response;

/**
 * WC-169: a plugin route declaring `requiredPermission` in its route array is
 * actually ENFORCED by the host.
 *
 * Wires the real PluginLoader (loading a fixture plugin from disk), the real
 * Router, RbacMiddleware, RoleChecker, and PermissionRegistry against an
 * in-memory SQLite engine, dispatching exactly as the HTTP kernel does: a
 * caller whose role grants the plugin permission reaches the plugin handler
 * (200); a caller without it is refused with the structured 403 naming the
 * missing permission.
 */
final class PluginRoutePermissionEnforcementTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    private static string $pluginDir;

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private PDO $pdo;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;
    private Router $router;
    private PluginLoader $loader;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = sys_get_temp_dir() . '/whity_routeperm_' . uniqid();
        mkdir(self::$pluginDir . '/GuardedPlugin', 0755, true);

        file_put_contents(self::$pluginDir . '/GuardedPlugin/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace GuardedPlugin;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'GuardedPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/guarded/things',
            'handler' => static fn (Request $r): Response => Response::json(['data' => ['guarded thing']]),
            'requiredRole' => null,
            'requiredPermission' => 'guarded:view',
        ]];
    }
    public function getPermissions(): array { return ['guarded:view']; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$pluginDir . '/GuardedPlugin/Plugin.php');
        @rmdir(self::$pluginDir . '/GuardedPlugin');
        @rmdir(self::$pluginDir);
    }

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry = new PermissionRegistry();
        $this->pdo = $this->makeSchema();
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        $this->roleChecker = new RoleChecker($db, $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);

        // The plugin registers its route (with requiredPermission) AND its
        // permission catalogue entry through the loader, as in production.
        $this->router = new Router('');
        $this->loader = new PluginLoader(self::$pluginDir, $this->router, $this->registry, new HookManager());
        $this->loader->load();

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testCallerWithThePluginPermissionReachesThePluginHandler(): void
    {
        $this->seedUserWithPermissions(10, ['guarded:view']);

        $response = $this->dispatch($this->authedRequest(10));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertSame(['data' => ['guarded thing']], json_decode($response->getBody(), true));
    }

    public function testCallerWithoutThePluginPermissionGetsStructured403(): void
    {
        // The role grants nothing.
        $this->seedUserWithPermissions(11, []);

        $response = $this->dispatch($this->authedRequest(11));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => 'guarded:view'],
            json_decode($response->getBody(), true),
            'The denial must name the missing plugin permission'
        );
    }

    // ==================== helpers ====================

    /**
     * Dispatch exactly as the HTTP kernel does: match, then enforce the
     * matched role/permission through the middleware before the handler runs.
     */
    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        $this->assertNotNull($match, 'The plugin route must be registered');

        $params = $match['params'];
        $next = static fn (Request $req): Response => ($match['handler'])($req, $params);

        return $this->middleware->handle(
            $request,
            $next,
            $match['requiredRole'],
            $match['requiredPermission']
        );
    }

    private function authedRequest(int $userId): Request
    {
        $token = $this->jwtParser->create([
            'user_id' => $userId,
            'email' => "u{$userId}@example.com",
            'tenant_id' => self::TENANT,
        ]);

        return new Request('GET', '/api/guarded/things', ['Authorization' => "Bearer {$token}"]);
    }

    /**
     * Seed a user (tenant 1, no OU) whose direct role grants exactly the given
     * permissions.
     *
     * @param array<int, string> $grantedPermissions Permissions the user's role grants.
     */
    private function seedUserWithPermissions(int $userId, array $grantedPermissions): void
    {
        $roleName = 'role_' . $userId;
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute([$roleName]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($grantedPermissions as $permission) {
            $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
                ->execute([$permission]);
            $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
            )->execute([$roleId, $permissionId]);
        }

        $this->pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, password, role_id, ou_id, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, NOW())'
        )->execute([$userId, self::TENANT, "u{$userId}@example.com", 'x', $roleId]);
    }

    private function makeSchema(): PDO
    {
        return SchemaFromMigrations::make();
    }
}
