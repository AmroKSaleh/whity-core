<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UsersApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Integration test proving the users API endpoints are RBAC permission-gated
 * (WC-203) — driving the real {@see RbacMiddleware}, {@see RoleChecker}, and
 * {@see Router} together, exactly as the HTTP kernel does.
 *
 * Mirrors {@see RelationsApiRbacTest}: real in-memory SQLite store, colon-
 * notation permissions, dispatch through the matched route's required permission.
 *
 * Routes under test
 * -----------------
 *  GET    /api/users        → users:read
 *  POST   /api/users        → users:write
 *  PATCH  /api/users/{id}   → users:write
 *  DELETE /api/users/{id}   → users:delete
 */
final class UsersApiRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private Router $router;
    private PDO $pdo;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry  = new PermissionRegistry();
        $this->pdo       = self::makeSchema();
        $db              = self::wrapSqlite($this->pdo);

        $this->roleChecker = new RoleChecker($db, $this->registry);
        $this->middleware  = new RbacMiddleware($this->jwtParser, $this->roleChecker);

        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        $usersHandler = new UsersApiHandler($this->pdo, $hooks);

        $this->router = new Router();
        $this->router->register('GET',    '/api/users',       [$usersHandler, 'list'],   null, null, CorePermissions::USERS_READ);
        $this->router->register('POST',   '/api/users',       [$usersHandler, 'create'], null, null, CorePermissions::USERS_WRITE);
        $this->router->register('PATCH',  '/api/users/{id}',  [$usersHandler, 'update'], null, null, CorePermissions::USERS_WRITE);
        $this->router->register('DELETE', '/api/users/{id}',  [$usersHandler, 'delete'], null, null, CorePermissions::USERS_DELETE);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== Unauthenticated / no token ====================

    public function testListWithoutTokenIsUnauthorized(): void
    {
        TenantContext::setTenantId(1);
        $this->assertSame(401, $this->dispatch(new Request('GET', '/api/users'))->getStatusCode());
    }

    // ==================== users:read gating ====================

    public function testListWithoutUsersReadIsForbidden(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::USERS_WRITE]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request('GET', '/api/users', $this->auth($userId, 1)));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(CorePermissions::USERS_READ, json_decode($response->getBody(), true)['required']);
    }

    public function testListWithUsersReadReachesHandler(): void
    {
        $this->seedUserWithPermissions(1, [CorePermissions::USERS_READ]);
        $caller = $this->seedUserWithPermissions(1, [CorePermissions::USERS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request('GET', '/api/users', $this->auth($caller, 1)));

        $this->assertSame(200, $response->getStatusCode());
    }

    // ==================== users:write gating (POST) ====================

    public function testCreateWithoutUsersWriteIsForbidden(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::USERS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'POST',
            '/api/users',
            $this->auth($userId, 1),
            (string) json_encode(['email' => 'new@example.com', 'password' => 'secret123'])
        ));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(CorePermissions::USERS_WRITE, json_decode($response->getBody(), true)['required']);
    }

    public function testCreateWithUsersWriteReachesHandler(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::USERS_WRITE]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'POST',
            '/api/users',
            $this->auth($userId, 1),
            (string) json_encode(['email' => 'new@example.com', 'password' => 'secret123'])
        ));

        // 201 = created successfully; the RBAC check passed.
        $this->assertSame(201, $response->getStatusCode());
    }

    // ==================== users:write gating (PATCH) ====================

    public function testUpdateWithoutUsersWriteIsForbidden(): void
    {
        $target = $this->seedUserWithPermissions(1, []);
        $caller = $this->seedUserWithPermissions(1, [CorePermissions::USERS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'PATCH',
            "/api/users/{$target}",
            $this->auth($caller, 1),
            (string) json_encode(['email' => 'changed@example.com'])
        ));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(CorePermissions::USERS_WRITE, json_decode($response->getBody(), true)['required']);
    }

    public function testUpdateWithUsersWriteReachesHandler(): void
    {
        $target = $this->seedUserWithPermissions(1, []);
        $caller = $this->seedUserWithPermissions(1, [CorePermissions::USERS_WRITE]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'PATCH',
            "/api/users/{$target}",
            $this->auth($caller, 1),
            (string) json_encode(['email' => 'changed@example.com'])
        ));

        // The handler may return 409 (email exists) or 200; either way RBAC passed.
        $this->assertNotSame(403, $response->getStatusCode(), 'users:write must not be denied.');
        $this->assertNotSame(401, $response->getStatusCode());
    }

    // ==================== users:delete gating ====================

    public function testDeleteWithoutUsersDeleteIsForbidden(): void
    {
        $target = $this->seedUserWithPermissions(1, []);
        $caller = $this->seedUserWithPermissions(1, [CorePermissions::USERS_WRITE]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'DELETE',
            "/api/users/{$target}",
            $this->auth($caller, 1)
        ));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(CorePermissions::USERS_DELETE, json_decode($response->getBody(), true)['required']);
    }

    public function testDeleteWithUsersDeleteReachesHandler(): void
    {
        $target = $this->seedUserWithPermissions(1, []);
        $caller = $this->seedUserWithPermissions(1, [CorePermissions::USERS_DELETE]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'DELETE',
            "/api/users/{$target}",
            $this->auth($caller, 1)
        ));

        // 200 or 404 — either way the RBAC gate was passed.
        $this->assertNotSame(403, $response->getStatusCode(), 'users:delete must not be denied.');
        $this->assertNotSame(401, $response->getStatusCode());
    }

    // ==================== write with only read must fail ====================

    /**
     * A user who holds users:read but NOT users:write must be denied on POST.
     * This guards against accidental permission bundling.
     */
    public function testUsersReadDoesNotImplyWrite(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::USERS_READ]);
        TenantContext::setTenantId(1);

        $post = $this->dispatch(new Request(
            'POST',
            '/api/users',
            $this->auth($userId, 1),
            (string) json_encode(['email' => 'check@example.com', 'password' => 'secret123'])
        ));
        $this->assertSame(403, $post->getStatusCode(), 'users:read must not grant write access.');

        $delete = $this->dispatch(new Request(
            'DELETE',
            '/api/users/999',
            $this->auth($userId, 1)
        ));
        $this->assertSame(403, $delete->getStatusCode(), 'users:read must not grant delete access.');
    }

    // ==================== Harness ====================

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $handler  = $match['handler'];
        $params   = $match['params'];
        $next     = static fn (Request $req): Response => $handler($req, $params);

        return $this->middleware->handle($request, $next, $match['requiredRole'], $match['requiredPermission']);
    }

    /**
     * @return array<string, string>
     */
    private function auth(int $userId, int $tenantId): array
    {
        $token = $this->jwtParser->create([
            'user_id'   => $userId,
            'email'     => "user{$userId}@example.com",
            'tenant_id' => $tenantId,
        ]);

        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Seed a user whose dedicated role grants exactly the given permissions.
     *
     * @param array<int, string> $permissions
     */
    private function seedUserWithPermissions(int $tenantId, array $permissions): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')
            ->execute(['role_' . uniqid('', true)]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($permissions as $permission) {
            $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $this->pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                ->execute([$roleId, $permissionId]);
        }

        $this->pdo->prepare('INSERT INTO users (tenant_id, email, password, role_id, ou_id, created_at) VALUES (?, ?, ?, ?, NULL, NOW())')
            ->execute([$tenantId, 'u' . $roleId . '@example.com', 'x', $roleId]);

        return (int) $this->pdo->lastInsertId();
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * Build an in-memory SQLite connection seeded with the minimal schema needed
     * to exercise UsersApiHandler + RbacMiddleware end-to-end.
     */
    private static function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        // system tenant (id=0) and global roles (1=admin, 2=user) come from migrations.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0,'system'),(1,'tenant-a'),(2,'tenant-b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, created_at) VALUES (1,'admin',NOW()),(2,'user',NOW())");

        return $pdo;
    }
}
