<?php

declare(strict_types=1);

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use Whity\Core\RBAC\PermissionRegistry;
use PDOStatement;

/**
 * Tests for RoleChecker class
 */
class RoleCheckerTest extends TestCase
{
    private RoleChecker $roleChecker;
    private Database $mockDb;
    private PermissionRegistry $mockRegistry;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(Database::class);
        $this->mockRegistry = $this->createMock(PermissionRegistry::class);
        $this->roleChecker = new RoleChecker($this->mockDb, $this->mockRegistry);
        // Worker-level cache is static; reset it so cases never leak into one another.
        RoleChecker::clearCache();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    /**
     * Build a PDOStatement mock returning the given fetch / fetchAll values.
     *
     * @param mixed              $fetch    Value returned by fetch().
     * @param array<int, mixed>  $fetchAll Value returned by fetchAll().
     */
    private function statement(mixed $fetch = false, array $fetchAll = []): PDOStatement
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn($fetch);
        $statement->method('fetchAll')->willReturn($fetchAll);
        return $statement;
    }

    /**
     * Test getRoleForUser returns correct role
     */
    public function testGetRoleForUser(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(['name' => 'admin']);

        $this->mockDb->method('query')
            ->with('SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :userId', [':userId' => 1])
            ->willReturn($mockStatement);

        $role = $this->roleChecker->getRoleForUser(1);

        $this->assertSame('admin', $role);
    }

    /**
     * Test getRoleForUser returns null for nonexistent user
     */
    public function testGetRoleForNonexistentUser(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(false);

        $this->mockDb->method('query')
            ->with('SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :userId', [':userId' => 999])
            ->willReturn($mockStatement);

        $role = $this->roleChecker->getRoleForUser(999);

        $this->assertNull($role);
    }

    /**
     * Test hasRole returns true when user has the role
     */
    public function testHasRoleReturnsTrue(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(['name' => 'user']);

        $this->mockDb->method('query')
            ->with('SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :userId', [':userId' => 2])
            ->willReturn($mockStatement);

        $hasRole = $this->roleChecker->hasRole(2, 'user');

        $this->assertTrue($hasRole);
    }

    /**
     * Test hasRole returns false when user doesn't have the role
     */
    public function testHasRoleReturnsFalse(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(['name' => 'user']);

        $this->mockDb->method('query')
            ->with('SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :userId', [':userId' => 2])
            ->willReturn($mockStatement);

        $hasRole = $this->roleChecker->hasRole(2, 'admin');

        $this->assertFalse($hasRole);
    }

    /**
     * Test hasPermission returns false if permission doesn't exist in registry
     */
    public function testHasPermissionReturnsFalseIfPermissionDoesntExistInRegistry(): void
    {
        $this->mockRegistry->method('permissionExists')
            ->with('nonexistent.permission')
            ->willReturn(false);

        $hasPermission = $this->roleChecker->hasPermission(1, 'nonexistent.permission');

        $this->assertFalse($hasPermission);
    }

    /**
     * Test hasPermission returns true if user has permission
     */
    public function testHasPermissionReturnsTrueIfUserHasPermission(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(['permission_string' => 'users.read']);

        $this->mockRegistry->method('permissionExists')
            ->with('users.read')
            ->willReturn(true);

        $this->mockDb->method('query')
            ->with(
                'SELECT 1 FROM role_permissions rp JOIN users u ON u.role_id = rp.role_id WHERE u.id = :userId AND rp.permission_string = :permission',
                [':userId' => 1, ':permission' => 'users.read']
            )
            ->willReturn($mockStatement);

        $hasPermission = $this->roleChecker->hasPermission(1, 'users.read');

        $this->assertTrue($hasPermission);
    }

    /**
     * Test getPermissionsForUser returns all user permissions
     */
    public function testGetPermissionsForUserReturnsAllUserPermissions(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetchAll')->willReturn([
            ['permission_string' => 'users.read'],
            ['permission_string' => 'users.create'],
            ['permission_string' => 'roles.read'],
        ]);

        $this->mockDb->method('query')
            ->with(
                'SELECT DISTINCT rp.permission_string FROM role_permissions rp JOIN users u ON u.role_id = rp.role_id WHERE u.id = :userId',
                [':userId' => 1]
            )
            ->willReturn($mockStatement);

        $permissions = $this->roleChecker->getPermissionsForUser(1);

        $this->assertCount(3, $permissions);
        $this->assertContains('users.read', $permissions);
        $this->assertContains('users.create', $permissions);
        $this->assertContains('roles.read', $permissions);
    }

    /**
     * Test hasRole still works (backwards compatibility)
     */
    public function testHasRoleStillWorks(): void
    {
        $mockStatement = $this->createMock(PDOStatement::class);
        $mockStatement->method('fetch')->willReturn(['name' => 'admin']);

        $this->mockDb->method('query')
            ->with('SELECT r.name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :userId', [':userId' => 3])
            ->willReturn($mockStatement);

        $hasRole = $this->roleChecker->hasRole(3, 'admin');

        $this->assertTrue($hasRole);
    }

    // ---------------------------------------------------------------------
    // Role hierarchy & permission inheritance (WC-15)
    // ---------------------------------------------------------------------

    /**
     * Hierarchy fixture: super_admin(1) -> admin(2) -> editor(3) -> viewer(4).
     *
     * Routes the DB queries RoleChecker issues during hierarchy resolution to
     * canned results: parent chain lookups and per-role direct-permission
     * lookups. Direct grants per role:
     *   viewer(4)  => dashboard:read
     *   editor(3)  => posts:write
     *   admin(2)   => users:read
     *   super_admin(1) => tenants:manage
     *
     * @param array<int, int|null>            $parents      role_id => parent role_id (null = root)
     * @param array<int, array<int, string>>  $permsByRole  role_id => [permission strings]
     */
    private function wireHierarchy(array $parents, array $permsByRole): void
    {
        $this->mockDb->method('query')->willReturnCallback(
            function (string $sql, array $params) use ($parents, $permsByRole): PDOStatement {
                // users.role_id lookup for a user.
                if (str_contains($sql, 'SELECT role_id FROM users')) {
                    $userId = $params[':userId'];
                    // Convention used by the tests: user id maps to its own role id.
                    return $this->statement(['role_id' => $userId]);
                }

                // roles.parent_id lookup.
                if (str_contains($sql, 'SELECT parent_id FROM roles')) {
                    $roleId = $params[':roleId'];
                    return $this->statement(['parent_id' => $parents[$roleId] ?? null]);
                }

                // per-role direct permissions (permissions JOIN role_permissions).
                if (str_contains($sql, 'FROM permissions p')) {
                    $roleId = $params[':roleId'];
                    $rows = array_map(
                        static fn(string $name): array => ['name' => $name],
                        $permsByRole[$roleId] ?? []
                    );
                    return $this->statement(false, $rows);
                }

                // The legacy direct-grant probe in hasPermission: no direct match,
                // forcing resolution down the hierarchy path.
                return $this->statement(false);
            }
        );
    }

    /**
     * AC1: admin inherits editor + viewer permissions via the hierarchy chain.
     */
    public function testEffectivePermissionsForAdminIncludeInheritedRoles(): void
    {
        $this->wireHierarchy(
            parents: [1 => null, 2 => 1, 3 => 2, 4 => 3],
            permsByRole: [
                4 => ['dashboard:read'],
                3 => ['posts:write'],
                2 => ['users:read'],
                1 => ['tenants:manage'],
            ]
        );

        // admin is role id 2; walking up reaches super_admin(1), and down-chain
        // editor(3)/viewer(4) are inherited because admin's parent chain is
        // super_admin. Inheritance is "higher inherits lower", so we resolve from
        // the perspective of a higher role: editor(3) inherits viewer(4) etc.
        $editorEffective = $this->roleChecker->getEffectivePermissionsForRole(3);

        // editor inherits viewer's dashboard:read plus its own posts:write,
        // plus admin/super_admin up the chain.
        $this->assertContains('posts:write', $editorEffective);
        $this->assertContains('users:read', $editorEffective);
        $this->assertContains('tenants:manage', $editorEffective);
    }

    /**
     * AC1 (precise wording): checking whether 'admin' has the viewer-level
     * permission 'dashboard:read' grants access via inheritance.
     *
     * Hierarchy here models "higher inherits lower" with viewer at the TOP of the
     * parent chain so that admin -> editor -> viewer resolves viewer's grant.
     */
    public function testAdminInheritsViewerPermissionViaHierarchy(): void
    {
        // Parent chain expresses inheritance source: admin(2)'s parent is
        // editor(3), whose parent is viewer(4). Walking up from admin collects
        // editor's and viewer's permissions.
        $this->mockRegistry->method('permissionExists')
            ->with('dashboard:read')->willReturn(true);

        $this->wireHierarchy(
            parents: [2 => 3, 3 => 4, 4 => null],
            permsByRole: [
                2 => ['users:read'],
                3 => ['posts:write'],
                4 => ['dashboard:read'],
            ]
        );

        // user id 2 maps to role id 2 (admin) by the fixture convention.
        $this->assertTrue(
            $this->roleChecker->hasPermission(2, 'dashboard:read'),
            "admin should inherit viewer's dashboard:read via the hierarchy chain"
        );
    }

    /**
     * Effective set is the union of own + all ancestors' permissions.
     */
    public function testEffectivePermissionsAreUnionOfChain(): void
    {
        $this->wireHierarchy(
            parents: [2 => 3, 3 => 4, 4 => null],
            permsByRole: [
                2 => ['users:read'],
                3 => ['posts:write'],
                4 => ['dashboard:read'],
            ]
        );

        $effective = $this->roleChecker->getEffectivePermissionsForRole(2);

        sort($effective);
        $this->assertSame(['dashboard:read', 'posts:write', 'users:read'], $effective);
    }

    /**
     * AC2: a circular hierarchy (A -> B -> A) is detected, logged as a warning,
     * and resolution terminates without an infinite loop.
     */
    public function testCircularHierarchyIsDetectedAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Circular role hierarchy detected'),
                $this->callback(static function (array $context): bool {
                    return ($context['event'] ?? null) === 'rbac.role_hierarchy.cycle_detected'
                        && array_key_exists('tenant_id', $context);
                })
            );

        $checker = new RoleChecker($this->mockDb, $this->mockRegistry, $logger);

        // A(10) -> B(11) -> A(10): a two-node cycle.
        $this->wireHierarchy(
            parents: [10 => 11, 11 => 10],
            permsByRole: [
                10 => ['a:read'],
                11 => ['b:read'],
            ]
        );

        // Must terminate (no infinite loop) and still return the permissions it
        // managed to collect before the cycle closed.
        $effective = $checker->getEffectivePermissionsForRole(10, 7);

        $this->assertContains('a:read', $effective);
        $this->assertContains('b:read', $effective);
    }

    /**
     * A self-referential role (A -> A) is treated as a cycle and terminates.
     */
    public function testSelfReferentialRoleTerminates(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $checker = new RoleChecker($this->mockDb, $this->mockRegistry, $logger);

        $this->wireHierarchy(
            parents: [10 => 10],
            permsByRole: [10 => ['a:read']],
        );

        $effective = $checker->getEffectivePermissionsForRole(10);

        $this->assertSame(['a:read'], $effective);
    }

    /**
     * Resolved effective permissions are cached at the worker level so repeated
     * resolution does not re-walk the hierarchy.
     */
    public function testEffectivePermissionsAreCachedPerRole(): void
    {
        $callCount = 0;
        $this->mockDb->method('query')->willReturnCallback(
            function (string $sql, array $params) use (&$callCount): PDOStatement {
                $callCount++;
                if (str_contains($sql, 'SELECT parent_id FROM roles')) {
                    return $this->statement(['parent_id' => null]);
                }
                if (str_contains($sql, 'FROM permissions p')) {
                    return $this->statement(false, [['name' => 'dashboard:read']]);
                }
                return $this->statement(false);
            }
        );

        $first = $this->roleChecker->getEffectivePermissionsForRole(4);
        $callsAfterFirst = $callCount;
        $second = $this->roleChecker->getEffectivePermissionsForRole(4);

        $this->assertSame($first, $second);
        $this->assertSame(
            $callsAfterFirst,
            $callCount,
            'Second resolution must be served from cache without new DB queries'
        );
    }

    /**
     * clearCache() invalidates the worker-level cache so a subsequent resolution
     * re-walks the hierarchy (used by RolesApiHandler after assignment writes).
     */
    public function testClearCacheForcesReResolution(): void
    {
        $queryCalls = 0;
        $this->mockDb->method('query')->willReturnCallback(
            function (string $sql) use (&$queryCalls): PDOStatement {
                $queryCalls++;
                if (str_contains($sql, 'SELECT parent_id FROM roles')) {
                    return $this->statement(['parent_id' => null]);
                }
                if (str_contains($sql, 'FROM permissions p')) {
                    return $this->statement(false, [['name' => 'dashboard:read']]);
                }
                return $this->statement(false);
            }
        );

        $this->roleChecker->getEffectivePermissionsForRole(4);
        $afterFirst = $queryCalls;

        RoleChecker::clearCache();
        $this->roleChecker->getEffectivePermissionsForRole(4);

        $this->assertGreaterThan(
            $afterFirst,
            $queryCalls,
            'After clearCache() the hierarchy must be re-walked (more DB queries)'
        );
    }

    /**
     * hasPermission honours a direct grant without consulting the hierarchy,
     * preserving backward-compatible semantics for the RBAC middleware.
     */
    public function testHasPermissionDirectGrantShortCircuits(): void
    {
        $this->mockRegistry->method('permissionExists')
            ->with('users:read')->willReturn(true);

        $this->mockDb->method('query')->willReturnCallback(
            function (string $sql) : PDOStatement {
                // The legacy direct-grant probe returns a hit.
                if (str_contains($sql, 'rp.permission_string = :permission')) {
                    return $this->statement(['1' => 1]);
                }
                $this->fail('Direct grant should short-circuit before hierarchy resolution');
            }
        );

        $this->assertTrue($this->roleChecker->hasPermission(2, 'users:read'));
    }
}
