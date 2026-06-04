<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Api\RolesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Database\Database;
use Whity\Core\RBAC\PermissionRegistry;

/**
 * Tests for RolesApiHandler.
 *
 * Focuses on the WC-15 contract that mutating role/permission writes invalidate
 * the worker-level effective-permission cache so RBAC checks never go stale.
 * These tests run entirely against mocked PDO/Database seams and require no live
 * database.
 */
class RolesApiHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        RoleChecker::clearCache();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    /**
     * Prime the worker-level cache for a role, then assert it is currently
     * populated by checking a re-resolution serves from cache (no new queries).
     *
     * @return Database The mock Database used to seed the cache.
     */
    private function seedEffectivePermissionCache(int $roleId): Database
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

        return $mockDb;
    }

    /**
     * Whether the worker-level cache currently holds an entry for $roleId.
     *
     * Re-resolves against a Database mock that fails the test if any query runs;
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
     * Build a HookManager stub whose dispatch() echoes back the payload (the
     * create() flow reads name/description/permissions from the returned array).
     */
    private function passthroughHookManager(): HookManager
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');
        return $hooks;
    }

    public function testCreateInvalidatesEffectivePermissionCache(): void
    {
        // Prime the cache so we can prove the write clears it.
        $this->seedEffectivePermissionCache(4);
        $this->assertTrue($this->cacheHasEntry(4), 'precondition: cache should be primed');

        $pdo = $this->createMock(PDO::class);
        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->method('execute')->willReturn(true);
        $checkStmt->method('fetch')->willReturn(false); // role does not already exist
        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturn($checkStmt, $insertStmt);
        $pdo->method('lastInsertId')->willReturn('42');

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = new Request('POST', '/api/roles', [], json_encode(['name' => 'managers']));

        $response = $handler->create($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertFalse(
            $this->cacheHasEntry(4),
            'create() must invalidate the worker-level effective-permission cache'
        );
    }

    public function testDeleteInvalidatesEffectivePermissionCache(): void
    {
        $this->seedEffectivePermissionCache(4);
        $this->assertTrue($this->cacheHasEntry(4), 'precondition: cache should be primed');

        $pdo = $this->createMock(PDO::class);

        $existsStmt = $this->createMock(PDOStatement::class);
        $existsStmt->method('execute')->willReturn(true);
        $existsStmt->method('fetch')->willReturn(['id' => 5]); // role exists

        $inUseStmt = $this->createMock(PDOStatement::class);
        $inUseStmt->method('execute')->willReturn(true);
        $inUseStmt->method('fetch')->willReturn(['count' => 0]); // not in use

        $delPermStmt = $this->createMock(PDOStatement::class);
        $delPermStmt->method('execute')->willReturn(true);

        $delRoleStmt = $this->createMock(PDOStatement::class);
        $delRoleStmt->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturn($existsStmt, $inUseStmt, $delPermStmt, $delRoleStmt);

        $handler = new RolesApiHandler($pdo, $this->passthroughHookManager());
        $request = new Request('DELETE', '/api/roles/5');

        $response = $handler->delete($request, ['id' => '5']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(
            $this->cacheHasEntry(4),
            'delete() must invalidate the worker-level effective-permission cache'
        );
    }
}
