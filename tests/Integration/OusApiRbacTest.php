<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;
use Whity\Sdk\Http\Response;

/**
 * The OU routes must be gated on the seeded `ous:*` PERMISSIONS, not on the bare
 * `admin` ROLE, so a downstream plugin that aliases OU management can reuse the
 * platform's authority model instead of inventing a slug of its own. An invented
 * slug is the hazard this pins: it would let a caller holding only that plugin's
 * permissions mutate platform-wide OUs while holding no OU permission at all.
 *
 * The wiring under test is PARSED OUT OF public/index.php rather than re-declared
 * here. Re-declaring it would prove only that RbacMiddleware works — the tests
 * below would pass against the role-gated wiring they exist to reject.
 *
 * Both directions are covered per route: a caller holding exactly the declared
 * permission reaches the handler, and a caller holding everything except it is
 * refused with that permission named in the 403 body.
 */
final class OusApiRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    /**
     * The permission each live OU route must declare, keyed by "METHOD path" as
     * written in public/index.php.
     *
     * ous:read covers the OU resource and its sub-resource reads; ous:write covers
     * create/update (the slug the admin UI's capability check already uses); and
     * ous:assign covers the two routes that ASSIGN roles to an OU — verbatim what
     * migration 005 seeded it for. ous:create/ous:update are deliberately absent:
     * see testEveryWiredPermissionIsRegistryBacked().
     *
     * @var array<string, string>
     */
    private const EXPECTED_WIRING = [
        'GET /api/ous' => CorePermissions::OUS_READ,
        'POST /api/ous' => CorePermissions::OUS_WRITE,
        'GET /api/ous/{id:\d+}' => CorePermissions::OUS_READ,
        'PATCH /api/ous/{id:\d+}' => CorePermissions::OUS_WRITE,
        'DELETE /api/ous/{id:\d+}' => CorePermissions::OUS_DELETE,
        'GET /api/ous/{id:\d+}/roles' => CorePermissions::OUS_READ,
        'GET /api/ous/{id:\d+}/members' => CorePermissions::OUS_READ,
        'POST /api/ous/{id:\d+}/roles' => CorePermissions::OUS_ASSIGN,
        'DELETE /api/ous/{ouId:\d+}/roles/{roleId:\d+}' => CorePermissions::OUS_ASSIGN,
    ];

    private JwtParser $jwtParser;
    private PDO $pdo;
    private Database $db;
    private RbacMiddleware $middleware;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->jwtParser = new JwtParser(self::SECRET);
        $this->pdo = self::makeSchema();
        $this->db = self::wrapDatabase($this->pdo);

        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();

        $this->middleware = new RbacMiddleware(
            $this->jwtParser,
            new RoleChecker($this->db, $registry)
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== Declared wiring ====================

    public function testEveryOuRouteIsGatedOnItsPermissionAndNotOnTheAdminRole(): void
    {
        $live = self::liveOuRoutes();

        $this->assertSame(
            array_keys(self::EXPECTED_WIRING),
            array_keys($live),
            'The set of OU routes in public/index.php changed; update EXPECTED_WIRING.'
        );

        foreach (self::EXPECTED_WIRING as $route => $permission) {
            $this->assertNull(
                $live[$route]['requiredRole'],
                "{$route} must not carry a requiredRole: the permission has to be the WHOLE truth of the gate. "
                . 'Leaving the role alongside it makes core stricter than a plugin aliasing the same slug, '
                . 'which is the mirror image of the hazard this wiring exists to close.'
            );
            $this->assertSame(
                $permission,
                $live[$route]['requiredPermission'],
                "{$route} must declare {$permission}."
            );
        }
    }

    public function testEveryWiredPermissionIsRegistryBacked(): void
    {
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();

        foreach (self::liveOuRoutes() as $route => $wiring) {
            $permission = $wiring['requiredPermission'];
            $this->assertNotNull($permission, "{$route} declares no permission.");

            // RoleChecker::hasPermissionForProfile() returns false outright for a
            // permission the registry does not know, so an unregistered slug on a
            // route 403s EVERY caller, admin included. `ous:create` and `ous:update`
            // are seeded by migration 005 but were never added to CorePermissions,
            // which is exactly why they must not be wired here.
            $this->assertTrue(
                $registry->exists($permission),
                "{$route} declares {$permission}, which is not in the PermissionRegistry. "
                . 'An unregistered permission locks out every caller including admin.'
            );
        }
    }

    // ==================== Enforcement, both directions ====================

    /**
     * @dataProvider liveOuRouteProvider
     */
    public function testHolderOfTheDeclaredPermissionReachesTheHandler(
        string $method,
        string $path,
        ?string $requiredRole,
        ?string $requiredPermission
    ): void {
        $this->assertNotNull($requiredPermission, 'Route declares no permission to hold.');

        // The caller holds the route's permission and NOTHING else — in particular
        // not the `admin` role. Under the old role gate this is a 403.
        $profileId = $this->seedProfileHolding([$requiredPermission]);

        $response = $this->dispatch($method, $path, $requiredRole, $requiredPermission, $profileId);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            "{$method} {$path}: holding {$requiredPermission} must reach the handler without the admin role."
        );
    }

    /**
     * @dataProvider liveOuRouteProvider
     */
    public function testCallerLackingTheDeclaredPermissionIsRefused(
        string $method,
        string $path,
        ?string $requiredRole,
        ?string $requiredPermission
    ): void {
        $this->assertNotNull($requiredPermission, 'Route declares no permission to withhold.');

        // The caller holds every OTHER core OU permission but not this route's, so
        // the refusal can only be attributed to the permission under test.
        $others = array_values(array_diff(
            [CorePermissions::OUS_READ, CorePermissions::OUS_WRITE, CorePermissions::OUS_DELETE, CorePermissions::OUS_ASSIGN],
            [$requiredPermission]
        ));
        $profileId = $this->seedProfileHolding($others);

        $response = $this->dispatch($method, $path, $requiredRole, $requiredPermission, $profileId);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            "{$method} {$path}: lacking {$requiredPermission} must be refused."
        );

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame(
            $requiredPermission,
            $body['required'] ?? null,
            'The 403 must name the missing permission so a caller can see which capability it needs.'
        );
    }

    public function testUnauthenticatedCallerIsRejected(): void
    {
        $router = new Router('');
        $router->register('GET', '/api/ous', [$this, 'probe'], null, null, CorePermissions::OUS_READ);
        $match = $router->match(new Request('GET', '/api/ous'));
        $this->assertNotNull($match);

        $response = $this->middleware->handle(
            new Request('GET', '/api/ous'),
            static fn (Request $r): Response => Response::json(['ok' => true], 200),
            $match['requiredRole'],
            $match['requiredPermission']
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== Seeded grants ====================

    public function testAdminRoleHoldsEveryWiredOuPermission(): void
    {
        // The failure mode to rule out before wiring anything: if the seeded admin
        // role did NOT hold these, declaring them would lock admins out of OU
        // management on every existing deployment.
        $held = $this->permissionsHeldByRole('admin');

        foreach (array_unique(array_values(self::EXPECTED_WIRING)) as $permission) {
            $this->assertContains(
                $permission,
                $held,
                "The seeded admin role must hold {$permission}, or wiring it locks admins out of OU management."
            );
        }
    }

    public function testUserRoleHoldsNoOuPermission(): void
    {
        // Migration 009 granted `ous:read` to the base `user` role, but the routes
        // never consulted permissions, so the grant was inert. Wiring ous:read while
        // it stands would silently hand every plain user the OU tree, the OU role
        // assignments, and — through GET /api/ous/{id}/members — the login email and
        // display name of every active member. The revoke migration keeps this
        // change behaviour-preserving for the seeded roles.
        $held = $this->permissionsHeldByRole('user');

        $ouPermissions = array_values(array_filter(
            $held,
            static fn (string $permission): bool => str_starts_with($permission, 'ous:')
        ));

        $this->assertSame(
            [],
            $ouPermissions,
            'The base `user` role must hold no ous:* permission, otherwise wiring the OU routes widens read access.'
        );
    }

    // ==================== Harness ====================

    /**
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    public static function liveOuRouteProvider(): array
    {
        $cases = [];
        foreach (self::liveOuRoutes() as $route => $wiring) {
            $cases[$route] = [
                $wiring['method'],
                $wiring['path'],
                $wiring['requiredRole'],
                $wiring['requiredPermission'],
            ];
        }

        return $cases;
    }

    /**
     * Parse the live OU route registrations out of public/index.php.
     *
     * @return array<string, array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string}>
     */
    private static function liveOuRoutes(): array
    {
        $source = file_get_contents(__DIR__ . '/../../public/index.php');
        if ($source === false) {
            throw new \RuntimeException('Could not read public/index.php');
        }

        preg_match_all(
            '/\$router->register\(\s*\'(GET|POST|PATCH|DELETE|PUT)\'\s*,\s*\'(\/api\/ous[^\']*)\'\s*,\s*\[\$ousHandler\s*,\s*\'\w+\'\]\s*,([^;]*?)\);/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $routes = [];
        foreach ($matches as $match) {
            $args = array_map('trim', explode(',', $match[3]));

            $routes[$match[1] . ' ' . $match[2]] = [
                'method' => $match[1],
                'path' => $match[2],
                'requiredRole' => self::literal($args[0] ?? 'null'),
                'requiredPermission' => self::literal($args[2] ?? 'null'),
            ];
        }

        return $routes;
    }

    /**
     * Resolve a PHP argument as written in index.php — `null`, a quoted string, or
     * a CorePermissions:: constant reference — to its runtime value.
     */
    private static function literal(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === 'null') {
            return null;
        }
        if (preg_match('/^\'(.*)\'$/', $raw, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(?:\\\\?Whity\\\\Core\\\\RBAC\\\\)?CorePermissions::(\w+)$/', $raw, $m) === 1) {
            /** @var string $value */
            $value = constant(CorePermissions::class . '::' . $m[1]);
            return $value;
        }

        throw new \RuntimeException("Unrecognised route argument in index.php: {$raw}");
    }

    /**
     * @param array<string, string> $params
     */
    public function probe(Request $request, array $params = []): Response
    {
        return Response::json(['ok' => true], 200);
    }

    private function dispatch(
        string $method,
        string $path,
        ?string $requiredRole,
        ?string $requiredPermission,
        int $profileId
    ): Response {
        $router = new Router('');
        $router->register($method, $path, [$this, 'probe'], $requiredRole, null, $requiredPermission);

        // Substitute a concrete id for every {param} so the request matches.
        $requestPath = (string) preg_replace('/\{[^}]+\}/', '1', $path);
        $request = new Request($method, $requestPath, $this->auth($profileId));

        $match = $router->match($request);
        $this->assertNotNull($match, "Route {$method} {$path} did not match {$requestPath}.");

        $handler = $match['handler'];
        $params = $match['params'];

        return $this->middleware->handle(
            $request,
            static fn (Request $req): Response => $handler($req, $params),
            $match['requiredRole'],
            $match['requiredPermission']
        );
    }

    /**
     * @return array<string, string>
     */
    private function auth(int $profileId): array
    {
        $token = $this->jwtParser->create([
            'profile_id' => $profileId,
            'email' => "profile{$profileId}@example.com",
            'active_tenant_id' => self::TENANT,
            'tenant_id' => self::TENANT,
        ]);

        return ['Authorization' => 'Bearer ' . $token];
    }

    /**
     * Seed a profile whose dedicated role grants exactly the given permissions and
     * nothing else — no `admin` role anywhere in its membership.
     *
     * @param array<int, string> $permissions
     */
    private function seedProfileHolding(array $permissions): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')
            ->execute(['role_' . uniqid('', true)]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($permissions as $permission) {
            $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();

            $this->pdo->prepare(
                'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
            )->execute([$roleId, $permissionId]);
        }

        $this->pdo->prepare(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version,
                  token_epoch, created_at, updated_at)
             VALUES ('', '', false, 0, 0, NOW(), NOW())"
        )->execute();
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, 'active', NOW())"
        )->execute([$profileId, self::TENANT, $roleId]);

        RoleChecker::clearCache();

        return $profileId;
    }

    /**
     * @return array<int, string>
     */
    private function permissionsHeldByRole(string $roleName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.name = ?'
        );
        $stmt->execute([$roleName]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function wrapDatabase(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private static function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0,'system'),(1,'tenant-a')");

        return $pdo;
    }
}
