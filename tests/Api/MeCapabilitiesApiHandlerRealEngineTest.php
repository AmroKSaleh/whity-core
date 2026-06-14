<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Api\MeCapabilitiesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * WC-176 (#205): GET /api/me/capabilities against a REAL SQL engine.
 *
 * Pattern mirrors {@see \Tests\Core\Delegation\DelegationServiceRealEngineTest}:
 * the production {@see RoleChecker} (delegation-aware) runs unmodified against an
 * in-memory SQLite engine seeded with the real roles/permissions/role_permissions
 * /users/permission_delegations schema, so the handler's permission set is what
 * the authoritative store actually resolves — not a mocked stub.
 *
 * Proves the role contract the consumer (#205) relies on:
 *
 *  - an `admin` caller (role `admin`, granted both relations permissions exactly
 *    as migration 020 does) gets BOTH `relations:manage` AND `relations:read`;
 *  - a `delegate` caller (plain `user` role + a LIVE delegation of
 *    `relations:read` only) gets `relations:read` but NOT `relations:manage`;
 *  - revoking that delegation removes `relations:read` for the delegate.
 *
 * `PDO::ATTR_STRINGIFY_FETCHES` mirrors the Postgres driver (integers come back
 * as strings), catching int-vs-string resolution bugs native-SQLite ints hide.
 */
final class MeCapabilitiesApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_ID = 1;

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_ID);

        $this->pdo = self::makeSqliteSchema();
        $this->db = self::wrapSqlite($this->pdo);

        // Seed the relations:* catalogue and grant BOTH to admin, exactly as
        // migration 020_create_relations does in production.
        $this->grant('admin', CorePermissions::RELATIONS_READ);
        $this->grant('admin', CorePermissions::RELATIONS_MANAGE);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== admin: holds both relations permissions ====================

    public function testAdminCallerHoldsBothRelationsReadAndManage(): void
    {
        $adminUserId = $this->seedUser('admin@example.com', 'admin');

        $response = $this->handler()->list($this->authedRequest($adminUserId));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $permissions = $this->permissions($response);

        $this->assertContains(CorePermissions::RELATIONS_MANAGE, $permissions);
        $this->assertContains(CorePermissions::RELATIONS_READ, $permissions);
    }

    // ==================== delegate: read-only via a live delegation ====================

    public function testDelegateCallerHoldsRelationsReadOnlyNotManage(): void
    {
        $adminUserId = $this->seedUser('admin@example.com', 'admin');
        $delegateUserId = $this->seedUser('delegate@example.com', 'user');

        // Live delegation: admin (holds both) delegates ONLY relations:read.
        $this->service()->delegate(
            self::TENANT_ID,
            $adminUserId,
            DelegationRepository::GRANTEE_USER,
            $delegateUserId,
            [CorePermissions::RELATIONS_READ],
            null
        );
        RoleChecker::clearCache();

        $response = $this->handler()->list($this->authedRequest($delegateUserId));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $permissions = $this->permissions($response);

        $this->assertContains(
            CorePermissions::RELATIONS_READ,
            $permissions,
            'The live delegation must grant relations:read to the delegate.'
        );
        $this->assertNotContains(
            CorePermissions::RELATIONS_MANAGE,
            $permissions,
            'A relations:read-only delegation must NOT leak relations:manage.'
        );
    }

    public function testRevokingTheDelegationRemovesRelationsReadForTheDelegate(): void
    {
        $adminUserId = $this->seedUser('admin@example.com', 'admin');
        $delegateUserId = $this->seedUser('delegate@example.com', 'user');

        $service = $this->service();
        $ids = $service->delegate(
            self::TENANT_ID,
            $adminUserId,
            DelegationRepository::GRANTEE_USER,
            $delegateUserId,
            [CorePermissions::RELATIONS_READ],
            null
        );
        RoleChecker::clearCache();

        $this->assertContains(
            CorePermissions::RELATIONS_READ,
            $this->permissions($this->handler()->list($this->authedRequest($delegateUserId)))
        );

        $this->assertTrue($service->revoke($ids[0], self::TENANT_ID));
        RoleChecker::clearCache();

        $this->assertNotContains(
            CorePermissions::RELATIONS_READ,
            $this->permissions($this->handler()->list($this->authedRequest($delegateUserId))),
            'Revoking the delegation must remove relations:read from the delegate.'
        );
    }

    // ==================== response shape ====================

    public function testPermissionsAreSortedDeterministically(): void
    {
        $adminUserId = $this->seedUser('admin@example.com', 'admin');

        $permissions = $this->permissions($this->handler()->list($this->authedRequest($adminUserId)));

        $sorted = $permissions;
        sort($sorted);
        $this->assertSame($sorted, $permissions, 'permissions must be returned in sorted order.');
    }

    // ==================== Helpers ====================

    /**
     * The handler wired with the production delegation-aware RoleChecker, exactly
     * as public/index.php constructs it.
     */
    private function handler(): MeCapabilitiesApiHandler
    {
        return new MeCapabilitiesApiHandler($this->delegationAwareChecker());
    }

    private function delegationAwareChecker(): RoleChecker
    {
        $repo = new DelegationRepository($this->pdo);
        $baseChecker = new RoleChecker($this->db, new PermissionRegistry());
        $service = new DelegationService($repo, $baseChecker, new PermissionRegistry());

        return new RoleChecker($this->db, new PermissionRegistry(), null, $service);
    }

    private function service(): DelegationService
    {
        $repo = new DelegationRepository($this->pdo);
        $baseChecker = new RoleChecker($this->db, new PermissionRegistry());

        return new DelegationService($repo, $baseChecker, new PermissionRegistry());
    }

    private function grant(string $roleName, string $permission): void
    {
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())'
        )->execute([$permission, null]);

        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE name = '{$roleName}'")->fetchColumn();
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$roleId, $permissionId]);
    }

    private function seedUser(string $email, string $roleName): int
    {
        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE name = '{$roleName}'")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([self::TENANT_ID, $email, 'x', $roleId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function authedRequest(int $userId): Request
    {
        $request = new Request('GET', '/api/me/capabilities');
        $request->user = (object) ['user_id' => $userId];

        return $request;
    }

    /**
     * @return array<int, string> The permissions slugs from the response body.
     */
    private function permissions(Response $response): array
    {
        $body = json_decode($response->getBody(), true);

        return $body['data']['permissions'];
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Mirror PostgreSQL's PDO behaviour of returning integers as strings so
        // int-vs-string bugs in resolution/scoping are caught (WC-34).
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (0, 'system'), (1, 'tenant-a'), (2, 'tenant-b')");

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                parent_id INTEGER,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec("INSERT INTO roles (id, name, created_at) VALUES (1, 'admin', NOW()), (2, 'user', NOW())");

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                created_at TEXT
            )
        ');

        $pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at TEXT,
                UNIQUE(role_id, permission_id)
            )
        ');

        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER,
                ou_id INTEGER,
                created_at TEXT
            )
        ');

        $pdo->exec('
            CREATE TABLE organizational_units (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                parent_id INTEGER,
                name TEXT NOT NULL,
                slug TEXT,
                description TEXT,
                created_at TEXT
            )
        ');

        $pdo->exec('
            CREATE TABLE ou_role_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                ou_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT,
                UNIQUE(ou_id, role_id)
            )
        ');

        $pdo->exec('
            CREATE TABLE permission_delegations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                grantor_user_id INTEGER NOT NULL,
                grantee_type TEXT NOT NULL,
                grantee_id INTEGER NOT NULL,
                permission TEXT NOT NULL,
                ou_id INTEGER,
                granted_at TEXT,
                revoked_at TEXT,
                CHECK (grantee_type IN (\'role\', \'user\'))
            )
        ');

        return $pdo;
    }
}
