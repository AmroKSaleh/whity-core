<?php

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
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
}
