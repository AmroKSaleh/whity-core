<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\DelegationsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests for {@see DelegationsApiHandler} (WC-34).
 *
 * Drives the create/list/revoke endpoints against a genuine SQL engine so the
 * real INSERT/SELECT/UPDATE semantics — and the typed-error → HTTP-status
 * translation — are exercised. `PDO::ATTR_STRINGIFY_FETCHES` mirrors Postgres so
 * int-vs-string bugs surface.
 *
 * Asserts the API contract around the core invariant: a subset violation returns
 * 422 (no row written), a held-permission delegation returns 201, listing is
 * tenant-scoped, an unknown grantee returns 404, and revocation is tenant-scoped.
 */
final class DelegationsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        $this->db = self::wrapSqlite($this->pdo);
        MockRequestFactory::setTestTenant(1);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $_GET = [];
    }

    /**
     * WC-167 review BLOCKER regression: at runtime FrankenPHP strips the query
     * string from the path, so filters MUST be read from $_GET (the path-query
     * form below only ever existed in tests). A revoked delegation appears
     * when includeRevoked arrives via the superglobal.
     */
    public function testFiltersAreReadFromTheGetSuperglobal(): void
    {
        $this->pdo->exec("
            INSERT INTO permission_delegations
                (tenant_id, grantor_user_id, grantee_type, grantee_id, permission, granted_at, revoked_at)
            VALUES (1, 10, 'user', 11, 'users:read', datetime('now'), datetime('now'))
        ");

        $_GET = ['includeRevoked' => '1'];
        $response = $this->handler()->list(new Request('GET', '/api/delegations'));

        $body = json_decode($response->getBody(), true);
        $this->assertCount(
            1,
            $body['data'] ?? [],
            'includeRevoked supplied via $_GET (the runtime shape) must surface the revoked delegation'
        );
    }

    /**
     * The documented 400 for an invalid granteeType must fire when the filter
     * arrives via $_GET, not silently return 200 with wrong data.
     */
    public function testInvalidGranteeTypeFromGetSuperglobalReturns400(): void
    {
        $_GET = ['granteeType' => 'bogus'];
        $response = $this->handler()->list(new Request('GET', '/api/delegations'));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateRejectsPermissionGrantorDoesNotHoldWith422(): void
    {
        // Grantor is a plain 'user' with no grants.
        $grantorId = $this->seedUser('grantor@example.com', 'user', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM permission_delegations')->fetchColumn(),
            'A 422 subset violation must write no rows.'
        );
    }

    public function testCreateSucceedsForHeldPermissionWith201(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(1, $data['count']);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM permission_delegations WHERE revoked_at IS NULL')->fetchColumn()
        );
    }

    public function testCreateReturns404ForUnknownGrantee(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => 9999,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateReturns404WhenGranteeUserBelongsToAnotherTenant(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        // Grantee user lives in tenant 2.
        $otherTenantUser = $this->seedUser('other@example.com', 'user', 2);

        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $otherTenantUser,
            'permissions' => ['users:read'],
        ]));

        $this->assertSame(404, $response->getStatusCode(), 'A cross-tenant grantee must not be visible.');
    }

    public function testListIsTenantScoped(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]));

        // Tenant 1 sees it.
        $listT1 = json_decode($this->handler()->list(new Request('GET', '/api/delegations'))->getBody(), true)['data'];
        $this->assertCount(1, $listT1);

        // Tenant 2 does not.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $listT2 = json_decode($this->handler()->list(new Request('GET', '/api/delegations'))->getBody(), true)['data'];
        $this->assertCount(0, $listT2, 'Tenant 2 must not see tenant 1 delegations.');
    }

    public function testRevokeMarksDelegationRevokedAndIsTenantScoped(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $created = json_decode($this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'user',
            'granteeId' => $granteeId,
            'permissions' => ['users:read'],
        ]))->getBody(), true)['data'];
        $delegationId = (int) $created['ids'][0];

        // Tenant 2 cannot revoke tenant 1's delegation.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $deniedRevoke = $this->handler()->revoke(new Request('DELETE', '/api/delegations/' . $delegationId), ['id' => (string) $delegationId]);
        $this->assertSame(404, $deniedRevoke->getStatusCode(), 'Cross-tenant revoke must 404.');
        $this->assertNull(
            $this->pdo->query('SELECT revoked_at FROM permission_delegations WHERE id = ' . $delegationId)->fetchColumn() ?: null
        );

        // Tenant 1 can.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(1);
        $ok = $this->handler()->revoke(new Request('DELETE', '/api/delegations/' . $delegationId), ['id' => (string) $delegationId]);
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertNotNull(
            $this->pdo->query('SELECT revoked_at FROM permission_delegations WHERE id = ' . $delegationId)->fetchColumn() ?: null,
            'Revoked delegation must carry a revoked_at timestamp.'
        );

        // Revoking again is a no-op 404 (already revoked).
        $again = $this->handler()->revoke(new Request('DELETE', '/api/delegations/' . $delegationId), ['id' => (string) $delegationId]);
        $this->assertSame(404, $again->getStatusCode());
    }

    public function testRevokeReturns404ForUnknownDelegation(): void
    {
        $response = $this->handler()->revoke(new Request('DELETE', '/api/delegations/424242'), ['id' => '424242']);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateValidatesGranteeType(): void
    {
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $response = $this->handler()->create($this->authedRequest($grantorId, 1, [
            'granteeType' => 'bogus',
            'granteeId' => 1,
            'permissions' => ['users:read'],
        ]));
        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== Helpers ====================

    private function handler(): DelegationsApiHandler
    {
        $repo = new DelegationRepository($this->pdo);
        $baseChecker = new RoleChecker($this->db, new PermissionRegistry());
        $service = new DelegationService($repo, $baseChecker, new PermissionRegistry());

        return new DelegationsApiHandler($this->pdo, $service);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function authedRequest(int $userId, int $tenantId, array $body): Request
    {
        $request = new Request('POST', '/api/delegations', [], (string) json_encode($body));
        $request->user = (object) ['user_id' => $userId, 'tenant_id' => $tenantId];

        return $request;
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

    private function seedUser(string $email, string $roleName, int $tenantId): int
    {
        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE name = '{$roleName}'")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$tenantId, $email, 'x', $roleId]);

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

    private static function makeSqliteSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
