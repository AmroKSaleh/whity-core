<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see RolesApiHandler} (WC-110).
 *
 * The original WC-16 roles tests use mocked PDO, which does not enforce real SQL
 * semantics. That masked two production defects against PostgreSQL:
 *
 *  1. {@see RolesApiHandler::resolvePermissionIds()} resolved the `permissions`
 *     payload ONLY by `permissions.name`, so the numeric permission ids the web
 *     UI actually sends linked zero permissions.
 *  2. Create inserted a `user_roles` provisioning row for the acting user, which
 *     the deletion guard then counted — making every API-created role
 *     undeletable.
 *
 * These tests drive the handler against a genuine SQL engine so the real
 * INSERT/SELECT/DELETE semantics are exercised. SQLite is used because CI has no
 * live PostgreSQL; the shared `:name` placeholder grammar and a registered
 * `NOW()` UDF make the handler's statements run unmodified.
 *
 * A later WC-110 regression is also covered here: migration 018 leaves the
 * seeded base roles (`admin`, `user`) with `tenant_id IS NULL`, and the strict
 * `WHERE tenant_id = ?` scoping introduced on this branch then hid those base
 * roles from every non-system tenant — emptying the Roles page. The
 * NULL-tenant-as-GLOBAL cases below assert a tenant sees global roles plus its
 * own (read) while never being able to mutate a global base role (write), and
 * fail on the pre-change strict scoping.
 */
final class RolesApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        // Seed tenant 1: migration 010 only seeds system tenant id=0.
        // On PostgreSQL (real-engine PG mode via PHPUNIT_PG_DSN) the FK on
        // roles.tenant_id is enforced, so any create under tenant 1 fails
        // unless the tenant row exists. INSERT OR IGNORE is a no-op on SQLite
        // and translates to ON CONFLICT DO NOTHING on PG via the SchemaFromMigrations
        // wrapper.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'test-tenant', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'test-tenant-b', datetime('now'))"
        );
        MockRequestFactory::setTestTenant(1);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== Defect 1: id | name resolution ====================

    public function testCreateWithNumericPermissionIdsLinksThePermissions(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Editor',
            // The web UI sends numeric permission ids from GET /api/permissions.
            'permissions' => [1, 3],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(2, $data['permissionCount'], 'Numeric ids must link the matching permissions.');
        $this->assertSame([1, 3], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testCreateWithPermissionNamesLinksThePermissions(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Viewer',
            'permissions' => ['users:read', 'roles:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(2, $data['permissionCount']);
        // Resolve the expected ids from the migrated permissions table (migrations seed
        // users:read first, then several users:* columns, then roles:read — so their ids
        // are not necessarily 1 and 2 in the production schema).
        $this->assertSame(
            [$this->permIdFor('users:read'), $this->permIdFor('roles:read')],
            $this->linkedPermissionIds((int) $data['id'])
        );
    }

    public function testCreateWithMixedIdsAndNamesLinksAllAndDeduplicates(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Mixed',
            // id 1 == users:read (duplicate when 'users:read' name also given),
            // 'roles:read' resolves by name to its migrated id, id 3 == users:update.
            'permissions' => [1, 'users:read', 'roles:read', 3],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(3, $data['permissionCount'], 'Mixed array must de-duplicate id/name overlap.');
        $this->assertSame([1, 3, $this->permIdFor('roles:read')], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testCreateDropsUnknownIdsAndNames(): void
    {
        $handler = $this->handler();

        $response = $handler->create($this->authedRequest('POST', '/api/roles', [
            'name' => 'Partial',
            // 999 / nope:perm do not exist and must be dropped, not fabricated.
            'permissions' => [1, 999, 'nope:perm', 'users:read'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(1, $data['permissionCount']);
        $this->assertSame([1], $this->linkedPermissionIds((int) $data['id']));
    }

    public function testUpdateWithNumericPermissionIdsReplacesPermissions(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'ToEdit',
                'permissions' => ['users:read'],
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/' . $roleId, ['permissions' => [2, 3]]),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([2, 3], $this->linkedPermissionIds($roleId));
    }

    // ==================== Defect 2: created roles are deletable ====================

    public function testFreshlyCreatedRoleIsDeletable(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'Disposable',
                'permissions' => [1],
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        $response = $handler->delete(
            $this->authedRequest('DELETE', '/api/roles/' . $roleId),
            ['id' => (string) $roleId]
        );

        $this->assertSame(200, $response->getStatusCode(), 'A freshly created role must be deletable.');
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = ' . $roleId)->fetchColumn()
        );
    }

    public function testRoleWithGenuineUserAssignmentStillReturns409(): void
    {
        $handler = $this->handler();

        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', [
                'name' => 'InUse',
            ]))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        // A genuine (other) user is assigned the role within the tenant via
        // users.role_id; this is sufficient for the active-assignment guard now
        // that user_roles has been dropped by migration 039.
        // Seed tenant 1 first (migration 010 only seeds system tenant id=0;
        // on PostgreSQL FK enforcement requires the tenant row to exist).
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at)
             VALUES (1, 'test-tenant', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at)
             VALUES (50, 1, 'real@example.com', 'x', {$roleId}, datetime('now'))"
        );

        $response = $handler->delete(
            $this->authedRequest('DELETE', '/api/roles/' . $roleId),
            ['id' => (string) $roleId]
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    // ==================== AC3: tenant isolation ====================

    public function testRoleCreatedUnderTenantAIsInvisibleToTenantB(): void
    {
        // Tenant A creates a role.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAOnly']))->getBody(),
            true
        )['data'];
        $roleId = (int) $created['id'];

        // Tenant A sees it.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(1);
        $listA = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $namesA = array_column($listA, 'name');
        $this->assertContains('TenantAOnly', $namesA);

        // Tenant B does NOT.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $listB = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $namesB = array_column($listB, 'name');
        $this->assertNotContains('TenantAOnly', $namesB, "Tenant B must not see tenant A's role.");

        // And cannot fetch/delete it.
        $this->assertSame(
            404,
            $handler->get(new Request('GET', '/api/roles/' . $roleId), ['id' => (string) $roleId])->getStatusCode()
        );
    }

    public function testSystemTenantSeesRolesAcrossTenants(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAScoped']));

        TenantContext::reset();
        MockRequestFactory::setTestTenant(0); // SYSTEM tenant
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');

        $this->assertContains('TenantAScoped', $names);
    }

    // ============ WC-110 global-role regression: NULL tenant_id = global ============

    public function testGlobalRoleIsListedForRegularTenantAlongsideOwnRoles(): void
    {
        // Seeded base roles are global (NULL tenant_id) — the production state
        // after migration 018 with NO backfill. They must be visible to EVERY
        // tenant. This assertion FAILS on the pre-change strict `WHERE tenant_id = ?`.
        $this->seedGlobalRole(1, 'admin');
        $this->seedGlobalRole(2, 'user');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOneCustom']));

        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');

        $this->assertContains('admin', $names, 'Global base role must be visible to tenant 1.');
        $this->assertContains('user', $names, 'Global base role must be visible to tenant 1.');
        $this->assertContains('TenantOneCustom', $names, "Tenant's own role must also be visible.");
    }

    public function testGlobalRoleIsGettableByRegularTenant(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $response = $handler->get(new Request('GET', '/api/roles/1'), ['id' => '1']);

        $this->assertSame(200, $response->getStatusCode(), 'A global role must be gettable by any tenant.');
        $this->assertSame('admin', json_decode($response->getBody(), true)['data']['name']);
    }

    public function testRegularTenantCannotUpdateGlobalRole(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $response = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/1', ['description' => 'hijacked']),
            ['id' => '1']
        );

        $this->assertSame(404, $response->getStatusCode(), 'A tenant must not update a global base role.');
        // The role is untouched.
        $this->assertNotSame(
            'hijacked',
            $this->pdo->query('SELECT description FROM roles WHERE id = 1')->fetchColumn()
        );
    }

    public function testRegularTenantCannotDeleteGlobalRole(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $response = $handler->delete(new Request('DELETE', '/api/roles/1'), ['id' => '1']);

        $this->assertSame(404, $response->getStatusCode(), 'A tenant must not delete a global base role.');
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM roles WHERE id = 1')->fetchColumn(),
            'The global role must survive a tenant delete attempt.'
        );
    }

    public function testRegularTenantCanDeleteOwnRoleButNotGlobalOne(): void
    {
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        // Its own freshly-created role IS deletable.
        $created = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'OwnRole']))->getBody(),
            true
        )['data'];
        $ownId = (int) $created['id'];

        $deleteOwn = $handler->delete(new Request('DELETE', '/api/roles/' . $ownId), ['id' => (string) $ownId]);
        $this->assertSame(200, $deleteOwn->getStatusCode(), "A tenant must be able to delete its own role.");

        // The global role is NOT.
        $deleteGlobal = $handler->delete(new Request('DELETE', '/api/roles/1'), ['id' => '1']);
        $this->assertSame(404, $deleteGlobal->getStatusCode());
    }

    public function testSystemTenantCanManageGlobalAndEveryTenantRole(): void
    {
        $this->seedGlobalRole(1, 'admin');

        // Tenant 1 owns a custom role.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $tenantRole = json_decode(
            $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOwned']))->getBody(),
            true
        )['data'];
        $tenantRoleId = (int) $tenantRole['id'];

        // SYSTEM tenant sees both the global and the tenant-owned role.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(0);
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');
        $this->assertContains('admin', $names);
        $this->assertContains('TenantOwned', $names);

        // SYSTEM can update the GLOBAL role.
        $updateGlobal = $handler->update(
            $this->authedRequest('PATCH', '/api/roles/1', ['description' => 'system-edited']),
            ['id' => '1']
        );
        $this->assertSame(200, $updateGlobal->getStatusCode(), 'SYSTEM may manage a global role.');

        // SYSTEM can delete the tenant-owned role.
        $deleteTenant = $handler->delete(
            new Request('DELETE', '/api/roles/' . $tenantRoleId),
            ['id' => (string) $tenantRoleId]
        );
        $this->assertSame(200, $deleteTenant->getStatusCode(), 'SYSTEM may manage any tenant role.');
    }

    // ============ WC-222: per-row manageability surfaced in list() ============

    public function testListMarksGlobalRoleNotManageableForRegularTenantButOwnRoleManageable(): void
    {
        // A global (NULL-tenant) base role plus a tenant-owned role.
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOneCustom']));

        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $byName = [];
        foreach ($list as $role) {
            $byName[$role['name']] = $role;
        }

        $this->assertArrayHasKey('admin', $byName, 'Global role must still be visible to the tenant.');
        $this->assertArrayHasKey('TenantOneCustom', $byName);

        // Global NULL-tenant role: NOT manageable by a regular tenant (write 404).
        $this->assertArrayHasKey('manageable', $byName['admin']);
        $this->assertFalse(
            $byName['admin']['manageable'],
            'A regular tenant must see a global base role as not manageable.'
        );
        // The tenant's OWN role: manageable.
        $this->assertTrue(
            $byName['TenantOneCustom']['manageable'],
            "A tenant's own role must be manageable."
        );
    }

    public function testListMarksEveryRoleManageableForSystemTenant(): void
    {
        $this->seedGlobalRole(1, 'admin');

        // Tenant 1 owns a custom role.
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantOwned']));

        // SYSTEM tenant (id 0) sees and may manage every role.
        TenantContext::reset();
        MockRequestFactory::setTestTenant(0);
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];

        $this->assertNotEmpty($list);
        foreach ($list as $role) {
            $this->assertArrayHasKey('manageable', $role);
            $this->assertTrue(
                $role['manageable'],
                "SYSTEM tenant must see every role as manageable (role: {$role['name']})."
            );
        }
    }

    public function testTenantBStillCannotSeeTenantAOwnedRoleEvenWithGlobalRolesPresent(): void
    {
        // A global role is present, but tenant isolation for OWNED roles still holds.
        $this->seedGlobalRole(1, 'admin');

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'TenantAPrivate']));

        TenantContext::reset();
        MockRequestFactory::setTestTenant(2);
        $list = json_decode($handler->list(new Request('GET', '/api/roles'))->getBody(), true)['data'];
        $names = array_column($list, 'name');

        $this->assertContains('admin', $names, 'Global role visible to tenant B.');
        $this->assertNotContains('TenantAPrivate', $names, "Tenant B must not see tenant A's owned role.");
    }

    // ==================== Helpers ====================

    private function handler(): RolesApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new RolesApiHandler($this->pdo, $hooks);
    }

    /**
     * Request carrying an authenticated acting user (user id 99, tenant 1).
     *
     * @param array<string, mixed>|null $body
     */
    private function authedRequest(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => 99, 'tenant_id' => 1];
        return $request;
    }

    /**
     * Seed a GLOBAL/system role (NULL tenant_id) — the production state of the
     * seeded base roles after migration 018 with no backfill.
     *
     * Uses INSERT OR IGNORE because migrations already seed admin (id 1) and
     * user (id 2); this is a no-op for those rows and still inserts any new ones.
     */
    private function seedGlobalRole(int $id, string $name): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at)
             VALUES (?, ?, '', NULL, datetime('now'))"
        );
        $stmt->execute([$id, $name]);
    }

    private function permIdFor(string $name): int
    {
        return (int) $this->pdo->query(
            'SELECT id FROM permissions WHERE name = ' . $this->pdo->quote($name)
        )->fetchColumn();
    }

    /**
     * @return array<int, int> Linked permission ids for a role, ascending.
     */
    private function linkedPermissionIds(int $roleId): array
    {
        $stmt = $this->pdo->query(
            'SELECT permission_id FROM role_permissions WHERE role_id = ' . $roleId . ' ORDER BY permission_id'
        );
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Build an in-memory SQLite connection seeded with the full migration schema.
     */
    private static function makeSqliteSchema(): PDO
    {
        return SchemaFromMigrations::make();
    }
}
