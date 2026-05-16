<?php

namespace Tests\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use PDOStatement;

/**
 * Tests for RoleChecker class
 */
class RoleCheckerTest extends TestCase
{
    private RoleChecker $roleChecker;
    private Database $mockDb;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(Database::class);
        $this->roleChecker = new RoleChecker($this->mockDb);
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
}
