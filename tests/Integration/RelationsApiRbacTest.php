<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RelationsSchema;
use Whity\Api\PersonsApiHandler;
use Whity\Api\RelationsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Relations\RelationResolver;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * Integration test proving the WC-65 family-relations endpoints are RBAC
 * route-gated AND tenant-isolated end-to-end â€” driving the real
 * {@see RbacMiddleware}, {@see RoleChecker}, {@see Router}, the resolver and both
 * handlers together, exactly as the HTTP kernel does.
 *
 * Mirrors {@see DelegationsApiRbacTest}: real in-memory SQLite store
 * (`PDO::ATTR_STRINGIFY_FETCHES` on), colon-notation permissions, dispatch
 * through the matched route's required permission.
 */
final class RelationsApiRbacTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private Router $router;
    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry = new PermissionRegistry();
        $this->pdo = RelationsSchema::make();
        $this->db = self::wrapSqlite($this->pdo);

        $this->roleChecker = new RoleChecker($this->db, $this->registry);
        $this->middleware = new RbacMiddleware($this->jwtParser, $this->roleChecker);

        $personRepo = new PersonRepository($this->pdo);
        $relationRepo = new RelationRepository($this->pdo);
        $resolver = new RelationResolver($this->pdo, $personRepo, $relationRepo);
        $personsHandler = new PersonsApiHandler($personRepo, $relationRepo);
        $relationsHandler = new RelationsApiHandler($personRepo, $relationRepo, $resolver);

        $this->router = new Router();
        $this->router->register('GET', '/api/relationship-types', [$relationsHandler, 'listTypes'], null, null, CorePermissions::RELATIONS_READ);
        $this->router->register('GET', '/api/persons', [$personsHandler, 'list'], null, null, CorePermissions::RELATIONS_READ);
        $this->router->register('POST', '/api/persons', [$personsHandler, 'create'], null, null, CorePermissions::RELATIONS_MANAGE);
        $this->router->register('GET', '/api/persons/{id}', [$personsHandler, 'get'], null, null, CorePermissions::RELATIONS_READ);
        $this->router->register('DELETE', '/api/persons/{id}', [$personsHandler, 'delete'], null, null, CorePermissions::RELATIONS_MANAGE);
        $this->router->register('POST', '/api/relations', [$relationsHandler, 'create'], null, null, CorePermissions::RELATIONS_MANAGE);
        $this->router->register('DELETE', '/api/relations/{id}', [$relationsHandler, 'delete'], null, null, CorePermissions::RELATIONS_MANAGE);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== RBAC route protection ====================

    public function testReadWithoutTokenIsUnauthorized(): void
    {
        TenantContext::setTenantId(1);
        $this->assertSame(401, $this->dispatch(new Request('GET', '/api/persons'))->getStatusCode());
    }

    public function testReadWithoutRelationsReadIsForbidden(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::USERS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request('GET', '/api/persons', $this->auth($userId, 1)));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('relations:read', json_decode($response->getBody(), true)['required']);
    }

    public function testReadWithRelationsReadReachesHandler(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::RELATIONS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request('GET', '/api/persons', $this->auth($userId, 1)));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testWriteWithOnlyReadIsForbidden(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::RELATIONS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'POST',
            '/api/persons',
            $this->auth($userId, 1),
            (string) json_encode(['displayName' => 'New Relative'])
        ));
        $this->assertSame(403, $response->getStatusCode(), 'relations:read must not allow a write.');
        $this->assertSame('relations:manage', json_decode($response->getBody(), true)['required']);
    }

    public function testWriteWithRelationsManageReachesHandler(): void
    {
        $userId = $this->seedUserWithPermissions(1, [CorePermissions::RELATIONS_MANAGE]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request(
            'POST',
            '/api/persons',
            $this->auth($userId, 1),
            (string) json_encode(['displayName' => 'New Relative'])
        ));
        $this->assertSame(201, $response->getStatusCode());
    }

    // ==================== Tenant isolation end-to-end ====================

    public function testTenantAdminSeesOnlyOwnPersons(): void
    {
        RelationsSchema::seedPerson($this->pdo, 1, 'Alice-A');
        RelationsSchema::seedPerson($this->pdo, 2, 'Carol-B');

        $userA = $this->seedUserWithPermissions(1, [CorePermissions::RELATIONS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request('GET', '/api/persons', $this->auth($userA, 1)));
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(1, $data, 'Tenant 1 admin sees only tenant 1 persons.');
        $this->assertSame('Alice-A', $data[0]['displayName']);
    }

    public function testTenantCannotReadAnotherTenantsPersonById(): void
    {
        $carol = RelationsSchema::seedPerson($this->pdo, 2, 'Carol-B');
        $userA = $this->seedUserWithPermissions(1, [CorePermissions::RELATIONS_READ]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request('GET', "/api/persons/{$carol}", $this->auth($userA, 1)));
        $this->assertSame(404, $response->getStatusCode(), 'Cross-tenant person read must be 404.');
    }

    public function testSystemTenantSeesAllPersons(): void
    {
        RelationsSchema::seedPerson($this->pdo, 1, 'Alice-A');
        RelationsSchema::seedPerson($this->pdo, 2, 'Carol-B');

        // System admin in tenant 0.
        $sysAdmin = $this->seedUserWithPermissions(0, [CorePermissions::RELATIONS_READ]);
        TenantContext::setTenantId(0);

        $response = $this->dispatch(new Request('GET', '/api/persons', $this->auth($sysAdmin, 0)));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, json_decode($response->getBody(), true)['data'], 'System tenant sees all persons.');
    }

    public function testTenantCannotDeleteAnotherTenantsRelation(): void
    {
        $a2 = RelationsSchema::seedPerson($this->pdo, 2, 'A2');
        $b2 = RelationsSchema::seedPerson($this->pdo, 2, 'B2');
        $relationRepo = new RelationRepository($this->pdo);
        $id = $relationRepo->insert(2, $a2, $b2, RelationsSchema::TYPE_PARENT);

        $userA = $this->seedUserWithPermissions(1, [CorePermissions::RELATIONS_MANAGE]);
        TenantContext::setTenantId(1);

        $response = $this->dispatch(new Request('DELETE', "/api/relations/{$id}", $this->auth($userA, 1)));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull($relationRepo->findById($id, 2), 'The foreign-tenant edge must survive.');
    }

    // ==================== Harness ====================

    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $handler = $match['handler'];
        $params = $match['params'];
        $next = static fn (Request $req): Response => $handler($req, $params);

        return $this->middleware->handle($request, $next, $match['requiredRole'], $match['requiredPermission']);
    }

    /**
     * @return array<string, string>
     */
    private function auth(int $userId, int $tenantId): array
    {
        $token = $this->jwtParser->create([
            'user_id' => $userId,
            'email' => "user{$userId}@example.com",
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
}
