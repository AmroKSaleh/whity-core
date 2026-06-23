<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * Unit tests for {@see RolesApiHandler} (WC-16, issue #9; WC-110).
 *
 * Covers the full CRUD surface, permission assignment from numeric ids and/or
 * colon-notation name strings resolved to ids (WC-110), tenant scoping by the
 * owning `roles.tenant_id` column (WC-110), the "cannot delete a role with
 * active user assignments" guard, and the WC-15 contract that every mutating
 * write invalidates the worker-level effective-permission cache. All tests here
 * run against mocked PDO/Database seams; the WC-110 defects (numeric ids dropped,
 * created roles undeletable) are additionally covered against a real SQL engine
 * in {@see RolesApiHandlerRealEngineTest}, since mocked PDO masked both.
 */
class RolesApiHandlerTest extends TestCase
{
    private int $testTenantId = 1;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        MockRequestFactory::setTestTenant($this->testTenantId);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== WC-15 cache-invalidation helpers ====================

    /**
     * Prime the worker-level cache for a role so a write can be proven to clear it.
     */
    private function seedEffectivePermissionCache(int $roleId): void
    {
        $mockDb = $this->createMock(Database::class);
        $mockDb->method('query')->willReturnCallback(
            function (string $sql): PDOStatement {
                $statement = $this->createMock(PDOStatement::class);
                if (str_contains($sql, 'SELECT parent_id FROM roles')) {
                    $statement->method('fetch')->willReturn(['parent_id' => null]);
                    $statement->method('fetchAll')->willReturn([]);
                } elseif (str_contains($sql, 'FROM permissions p')) {
                    $statement->method('fetch')->willReturn(false);
                    $statement->method('fetchAll')->willReturn([['name' => 'dashboard:read']]);
                } else {
                    $statement->method('fetch')->willReturn(false);
                    $statement->method('fetchAll')->willReturn([]);
                }
                return $statement;
            }
        );

        $checker = new RoleChecker($mockDb, $this->createMock(PermissionRegistry::class));
        $checker->getEffectivePermissionsForRole($roleId);
    }

    /**
     * Whether the worker-level cache currently holds an entry for $roleId.
     *
     * Re-resolves against a Database mock that records whether any query runs;
     * a cache hit performs zero queries, a miss triggers one.
     */
    private function cacheHasEntry(int $roleId): bool
    {
        $probeDb = $this->createMock(Database::class);
        $queried = false;
        $probeDb->method('query')->willReturnCallback(
            function () use (&$queried): PDOStatement {
                $queried = true;
                $statement = $this->createMock(PDOStatement::class);
                $statement->method('fetch')->willReturn(['parent_id' => null]);
                $statement->method('fetchAll')->willReturn([]);
                return $statement;
            }
        );

        $checker = new RoleChecker($probeDb, $this->createMock(PermissionRegistry::class));
        $checker->getEffectivePermissionsForRole($roleId);

        return $queried === false;
    }

    /**
     * HookManager stub whose dispatch() echoes back the payload (create()/update()
     * read name/description/permissions from the returned array).
     */
    private function passthroughHookManager(): HookManager
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');
        return $hooks;
    }

    /**
     * Build a PDOStatement mock for a single result row / row set.
     *
     * @param array<string, mixed>|false              $fetch    fetch() return value.
     * @param array<int, array<string, mixed>>|null    $fetchAll fetchAll() return value.
     */
    private function statement(array|false $fetch = false, ?array $fetchAll = null): PDOStatement
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn($fetch);
        $stmt->method('fetchAll')->willReturn($fetchAll ?? []);
        return $stmt;
    }

    /**
     * Request carrying an authenticated acting user (so user_roles linking runs).
     */
    private function authedRequest(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string)json_encode($body) : '');
        $request->user = (object)['user_id' => 99, 'tenant_id' => $this->testTenantId];
        return $request;
    }

    // ==================== CREATE ====================

    /**
     * AC1: POST creates the role and links the supplied colon-notation
     * permissions through role_permissions after resolving them to ids.
     */
    public function testCreateLinksColonNotationPermissions(): void
    {
        $nameCheck = $this->statement(false);            // role name free
        $insertRole = $this->statement();                // INSERT roles (with tenant_id)
        $resolveByName = $this->statement(false, [       // SELECT id, name FROM permissions WHERE name IN
            ['id' => 7, 'name' => 'posts:read'],
            ['id' => 8, 'name' => 'posts:write'],
        ]);
        $insertPerms = $this->statement();               // INSERT role_permissions

        $pdo = $this->createMock(PDO::class);
        // WC-110: no acting-user user_roles seed insert anymore; the role is
        // tenant-stamped on the INSERT itself. Colon-notation names resolve via a
        // single name lookup (no numeric-id pass).
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $nameCheck,
            $insertRole,
            $resolveByName,
            $insertPerms
        );
        $pdo->method('lastInsertId')->willReturn('42');

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = $this->authedRequest('POST', '/api/roles', [
            'name' => 'Editor',
            'permissions' => ['posts:read', 'posts:write'],
        ]);

        $response = $handler->create($request);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame(42, $data['id']);
        $this->assertSame('Editor', $data['name']);
        $this->assertSame(2, $data['permissionCount']);
    }

    /**
     * WC-110 (mocked): POST with numeric permission ids validates them against
     * the catalogue and links the matching ids (the web UI sends ids). The real
     * SQL semantics are exercised in RolesApiHandlerRealEngineTest.
     */
    public function testCreateLinksNumericPermissionIds(): void
    {
        $nameCheck = $this->statement(false);            // role name free
        $insertRole = $this->statement();                // INSERT roles (with tenant_id)
        $validateIds = $this->statement(false, [         // SELECT id FROM permissions WHERE id IN
            ['id' => 7], ['id' => 8],
        ]);
        $insertPerms = $this->statement();               // INSERT role_permissions

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $nameCheck,
            $insertRole,
            $validateIds,
            $insertPerms
        );
        $pdo->method('lastInsertId')->willReturn('43');

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = $this->authedRequest('POST', '/api/roles', [
            'name' => 'Editor',
            'permissions' => [7, 8],
        ]);

        $response = $handler->create($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(2, json_decode($response->getBody(), true)['data']['permissionCount']);
    }

    public function testCreateWithoutNameReturns400(): void
    {
        $pdo = $this->createMock(PDO::class);
        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());

        $response = $handler->create($this->authedRequest('POST', '/api/roles', ['description' => 'x']));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateDuplicateNameReturns409(): void
    {
        $nameCheck = $this->statement(['id' => 5]); // name already taken

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($nameCheck);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'admin']));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testCreateWithoutTenantContextReturns400(): void
    {
        TenantContext::reset(); // no tenant resolved

        $pdo = $this->createMock(PDO::class);
        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());

        // Plain request (no acting user) and no tenant -> rejected before any write.
        $request = new Request('POST', '/api/roles', [], (string)json_encode(['name' => 'X']));
        $response = $handler->create($request);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testCreateInvalidatesEffectivePermissionCache(): void
    {
        $this->seedEffectivePermissionCache(4);
        $this->assertTrue($this->cacheHasEntry(4), 'precondition: cache should be primed');

        $nameCheck = $this->statement(false);
        $insertRole = $this->statement();

        // WC-110: create no longer seeds a user_roles row; the role is
        // tenant-stamped on the INSERT (and this role carries no permissions).
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($nameCheck, $insertRole);
        $pdo->method('lastInsertId')->willReturn('42');

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->create($this->authedRequest('POST', '/api/roles', ['name' => 'managers']));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertFalse(
            $this->cacheHasEntry(4),
            'create() must invalidate the worker-level effective-permission cache'
        );
    }

    /**
     * `permissions` is the sole accepted key: a legacy `permissionIds` payload is
     * ignored, so the role is created with zero permissions and no permission
     * resolution/insert runs (no fallback to the dropped compat key).
     */
    public function testCreateIgnoresLegacyPermissionIdsKey(): void
    {
        $nameCheck = $this->statement(false);   // role name free
        $insertRole = $this->statement();        // INSERT roles (with tenant_id)

        // Only the name check and role insert may run: with `permissions` absent,
        // extractPermissionList() yields an empty list, so no resolve/insert of
        // role_permissions occurs. willReturn (not consecutive) keeps the test from
        // asserting on a permission-resolution call that must never happen.
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($nameCheck, $insertRole);
        $pdo->method('lastInsertId')->willReturn('51');

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = $this->authedRequest('POST', '/api/roles', [
            'name' => 'Viewer',
            'permissionIds' => ['posts:read'],
        ]);

        $response = $handler->create($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(0, json_decode($response->getBody(), true)['data']['permissionCount']);
    }

    // ==================== LIST (tenant scoping) ====================

    public function testListReturnsTenantScopedRoles(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Custom Admin', 'description' => '', 'parent_id' => null, 'created_at' => 'now', 'permission_count' => 3],
        ];
        $stmt = $this->statement(false, $rows);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->list(new Request('GET', '/api/roles'));

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertCount(1, $data);
        $this->assertSame('Custom Admin', $data[0]['name']);
        $this->assertSame(3, $data[0]['permissionCount']);
        $this->assertArrayNotHasKey('permission_count', $data[0]);
    }

    /**
     * AC2: a role belonging only to another tenant is not returned for this
     * tenant (the tenant-scoped query yields no rows).
     */
    public function testListIsolatesRolesAcrossTenants(): void
    {
        // Tenant 2 has no user_roles links to Tenant 1's 'Custom Admin' role, so
        // the scoped query returns an empty set.
        $stmt = $this->statement(false, []);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->list(new Request('GET', '/api/roles'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], json_decode($response->getBody(), true)['data']);
    }

    public function testListAsSystemTenantSeesAllRoles(): void
    {
        MockRequestFactory::setTestTenant(0); // SYSTEM tenant

        $rows = [
            ['id' => 1, 'name' => 'Tenant A Role', 'description' => '', 'parent_id' => null, 'created_at' => 'now', 'permission_count' => 0],
            ['id' => 2, 'name' => 'Tenant B Role', 'description' => '', 'parent_id' => null, 'created_at' => 'now', 'permission_count' => 0],
        ];
        // Pagination adds a COUNT query before the SELECT — provide two stmts.
        $countStmt = $this->statement(['cnt' => 2]);
        $listStmt  = $this->statement(false, $rows);

        $pdo = $this->createMock(PDO::class);
        // SYSTEM path uses queries with no bound tenant parameter; both avoid user_roles.
        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->with($this->logicalNot($this->stringContains('user_roles')))
            ->willReturnOnConsecutiveCalls($countStmt, $listStmt);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->list(new Request('GET', '/api/roles'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, json_decode($response->getBody(), true)['data']);
    }

    // ==================== GET ====================

    public function testGetReturnsRoleWithPermissions(): void
    {
        $visibility = $this->statement(['1' => 1]);       // role visible to tenant
        $roleRow = $this->statement(['id' => 5, 'name' => 'Editor', 'description' => '', 'parent_id' => null, 'created_at' => 'now']);
        $perms = $this->statement(false, [['id' => 7, 'name' => 'posts:read', 'description' => null]]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($visibility, $roleRow, $perms);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->get(new Request('GET', '/api/roles/5'), ['id' => '5']);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame('Editor', $data['name']);
        $this->assertCount(1, $data['permissions']);
        $this->assertSame('posts:read', $data['permissions'][0]['name']);
    }

    /**
     * AC2: fetching a role that belongs to another tenant returns 404, never the
     * other tenant's data.
     */
    public function testGetRoleFromOtherTenantReturns404(): void
    {
        $visibility = $this->statement(false); // not visible to this tenant

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($visibility);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->get(new Request('GET', '/api/roles/999'), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ==================== UPDATE ====================

    public function testUpdateReplacesPermissions(): void
    {
        $visibility = $this->statement(['1' => 1]);
        $roleRow = $this->statement(['id' => 5, 'name' => 'Editor', 'description' => '']);
        $delPerms = $this->statement();
        $resolveByName = $this->statement(false, [['id' => 9, 'name' => 'posts:delete']]);
        $insertPerms = $this->statement();

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $visibility,
            $roleRow,
            $delPerms,
            $resolveByName,
            $insertPerms
        );

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = new Request('PATCH', '/api/roles/5', [], (string)json_encode([
            'permissions' => ['posts:delete'],
        ]));
        $response = $handler->update($request, ['id' => '5']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateRoleFromOtherTenantReturns404(): void
    {
        $visibility = $this->statement(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($visibility);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = new Request('PATCH', '/api/roles/999', [], (string)json_encode(['name' => 'x']));
        $response = $handler->update($request, ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateInvalidatesEffectivePermissionCache(): void
    {
        $this->seedEffectivePermissionCache(4);
        $this->assertTrue($this->cacheHasEntry(4), 'precondition: cache should be primed');

        $visibility = $this->statement(['1' => 1]);
        $roleRow = $this->statement(['id' => 5, 'name' => 'Editor', 'description' => '']);
        $updateStmt = $this->statement();

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($visibility, $roleRow, $updateStmt);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = new Request('PATCH', '/api/roles/5', [], (string)json_encode(['description' => 'changed']));
        $response = $handler->update($request, ['id' => '5']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(
            $this->cacheHasEntry(4),
            'update() must invalidate the worker-level effective-permission cache'
        );
    }

    // ==================== DELETE ====================

    /**
     * AC3: deleting a role that still has assigned users returns 409 with the
     * exact documented message.
     */
    public function testDeleteRoleWithActiveUsersReturns409(): void
    {
        $visibility = $this->statement(['1' => 1]);
        $userCount = $this->statement(['cnt' => 2]); // 2 users still assigned

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($visibility, $userCount);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->delete(new Request('DELETE', '/api/roles/5'), ['id' => '5']);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame(
            'Cannot delete role with active user assignments',
            json_decode($response->getBody(), true)['error']
        );
    }

    public function testDeleteRoleFromOtherTenantReturns404(): void
    {
        $visibility = $this->statement(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($visibility);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->delete(new Request('DELETE', '/api/roles/999'), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteSucceedsWhenNoUsersAssigned(): void
    {
        $visibility = $this->statement(['1' => 1]);
        $userCount = $this->statement(['cnt' => 0]);
        $delPerms = $this->statement();
        $delAssign = $this->statement();
        $delRole = $this->statement();

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $visibility,
            $userCount,
            $delPerms,
            $delAssign,
            $delRole
        );

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->delete(new Request('DELETE', '/api/roles/5'), ['id' => '5']);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeleteInvalidatesEffectivePermissionCache(): void
    {
        $this->seedEffectivePermissionCache(4);
        $this->assertTrue($this->cacheHasEntry(4), 'precondition: cache should be primed');

        $visibility = $this->statement(['1' => 1]);
        $userCount = $this->statement(['cnt' => 0]);
        $delPerms = $this->statement();
        $delAssign = $this->statement();
        $delRole = $this->statement();

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $visibility,
            $userCount,
            $delPerms,
            $delAssign,
            $delRole
        );

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->delete(new Request('DELETE', '/api/roles/5'), ['id' => '5']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(
            $this->cacheHasEntry(4),
            'delete() must invalidate the worker-level effective-permission cache'
        );
    }

    // ==================== getPermissions ====================

    public function testGetPermissionsReturnsRolePermissions(): void
    {
        $visibility = $this->statement(['1' => 1]);
        $perms = $this->statement(false, [
            ['id' => 7, 'name' => 'posts:read', 'description' => null],
            ['id' => 8, 'name' => 'posts:write', 'description' => null],
        ]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($visibility, $perms);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $response = $handler->getPermissions(new Request('GET', '/api/roles/5/permissions'), ['id' => '5']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, json_decode($response->getBody(), true)['data']);
    }
}
