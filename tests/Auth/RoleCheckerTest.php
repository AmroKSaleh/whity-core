<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;

/**
 * Tests for {@see RoleChecker}.
 *
 * Authorization checks ({@see RoleChecker::hasRole()},
 * {@see RoleChecker::hasPermission()}) traverse several related tables (users,
 * roles, role_permissions, organizational_units, ou_role_assignments) and unfold
 * through both the role hierarchy and the OU parent chain. Mocking that graph by
 * matching exact SQL strings is brittle and hides real SQL semantics (the very
 * gap WC-54 flaw 1 slipped through), so these tests run the real resolver against
 * an in-memory SQLite engine seeded with the production schema. The two genuinely
 * static lookups ({@see RoleChecker::getRoleForUser()},
 * {@see RoleChecker::getPermissionsForUser()}) are still exercised the same way —
 * just through real rows rather than stubbed statements.
 */
class RoleCheckerTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
        $this->roleChecker = new RoleChecker($this->db, $this->registry());
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    // ---------------------------------------------------------------------
    // Primary role lookups
    // ---------------------------------------------------------------------

    public function testGetRoleForUser(): void
    {
        $userId = $this->seedUser('admin@example.com', 'admin');

        $this->assertSame('admin', $this->roleChecker->getRoleForUser($userId));
    }

    public function testGetRoleForNonexistentUser(): void
    {
        $this->assertNull($this->roleChecker->getRoleForUser(999));
    }

    // ---------------------------------------------------------------------
    // hasRole (effective role set)
    // ---------------------------------------------------------------------

    public function testHasRoleReturnsTrue(): void
    {
        $userId = $this->seedUser('user@example.com', 'user');

        $this->assertTrue($this->roleChecker->hasRole($userId, 'user', self::TENANT));
    }

    public function testHasRoleReturnsFalse(): void
    {
        $userId = $this->seedUser('user@example.com', 'user');

        $this->assertFalse($this->roleChecker->hasRole($userId, 'admin', self::TENANT));
    }

    public function testHasRoleWorksForAdmin(): void
    {
        $userId = $this->seedUser('admin@example.com', 'admin');

        $this->assertTrue($this->roleChecker->hasRole($userId, 'admin', self::TENANT));
    }

    // ---------------------------------------------------------------------
    // hasPermission / getPermissionsForUser
    // ---------------------------------------------------------------------

    public function testHasPermissionReturnsFalseIfPermissionDoesntExistInRegistry(): void
    {
        $userId = $this->seedUser('admin@example.com', 'admin');

        // An unregistered permission can never be granted, regardless of grants.
        $this->assertFalse(
            $this->roleChecker->hasPermission($userId, 'nonexistent:permission', self::TENANT)
        );
    }

    public function testHasPermissionReturnsTrueIfUserRoleHoldsItDirectly(): void
    {
        $this->grant('user', 'users:read');
        $userId = $this->seedUser('user@example.com', 'user');

        $this->assertTrue($this->roleChecker->hasPermission($userId, 'users:read', self::TENANT));
    }

    public function testGetPermissionsForUserReturnsAllUserPermissions(): void
    {
        // Use a test-only role (viewer) that migrations never grant permissions to,
        // so the assertSame can enumerate exactly the grants made here.
        $this->grant('viewer', 'users:read');
        $this->grant('viewer', 'users:write');
        $this->grant('viewer', 'roles:read');
        $userId = $this->seedUser('viewer@example.com', 'viewer');

        $permissions = $this->roleChecker->getPermissionsForUser($userId);

        sort($permissions);
        $this->assertSame(['roles:read', 'users:read', 'users:write'], $permissions);
    }

    // ---------------------------------------------------------------------
    // Role hierarchy & permission inheritance (WC-15)
    // ---------------------------------------------------------------------

    /**
     * Effective permissions for a role include everything inherited up the
     * parent chain: admin -> editor -> viewer.
     */
    public function testEffectivePermissionsForAdminIncludeInheritedRoles(): void
    {
        // editor(3)'s parent is admin(2), whose parent is super_admin(1): walking
        // up from editor collects editor + admin + super_admin grants.
        $this->setParent('editor', 'admin');
        $this->setParent('admin', 'super_admin');
        $this->grant('viewer', 'dashboard:read');
        $this->grant('editor', 'posts:write');
        $this->grant('admin', 'users:read');
        $this->grant('super_admin', 'tenants:manage');

        $editorEffective = $this->roleChecker->getEffectivePermissionsForRole($this->roleId('editor'));

        $this->assertContains('posts:write', $editorEffective);
        $this->assertContains('users:read', $editorEffective);
        $this->assertContains('tenants:manage', $editorEffective);
    }

    /**
     * Checking whether an admin user has a viewer-level permission resolves via
     * inheritance: admin -> editor -> viewer.
     */
    public function testAdminInheritsViewerPermissionViaHierarchy(): void
    {
        $this->setParent('admin', 'editor');
        $this->setParent('editor', 'viewer');
        $this->grant('admin', 'users:read');
        $this->grant('editor', 'posts:write');
        $this->grant('viewer', 'dashboard:read');

        $userId = $this->seedUser('admin@example.com', 'admin');

        $this->assertTrue(
            $this->roleChecker->hasPermission($userId, 'dashboard:read', self::TENANT),
            "admin should inherit viewer's dashboard:read via the hierarchy chain"
        );
    }

    public function testEffectivePermissionsAreUnionOfChain(): void
    {
        // Use test-only roles (super_admin, editor, viewer) that migrations never
        // grant permissions to, so the assertSame can enumerate exactly what's granted.
        $this->setParent('super_admin', 'editor');
        $this->setParent('editor', 'viewer');
        $this->grant('super_admin', 'users:read');
        $this->grant('editor', 'posts:write');
        $this->grant('viewer', 'dashboard:read');

        $effective = $this->roleChecker->getEffectivePermissionsForRole($this->roleId('super_admin'));

        sort($effective);
        $this->assertSame(['dashboard:read', 'posts:write', 'users:read'], $effective);
    }

    /**
     * A circular role hierarchy (A -> B -> A) is detected, logged, and resolution
     * terminates without an infinite loop.
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

        $this->grant('editor', 'a:read');
        $this->grant('viewer', 'b:read');
        // editor <-> viewer two-node cycle.
        $this->setParent('editor', 'viewer');
        $this->setParent('viewer', 'editor');

        $checker = new RoleChecker($this->db, $this->registry(), $logger);

        $effective = $checker->getEffectivePermissionsForRole($this->roleId('editor'), 7);

        $this->assertContains('a:read', $effective);
        $this->assertContains('b:read', $effective);
    }

    public function testSelfReferentialRoleTerminates(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $this->grant('editor', 'a:read');
        // Direct self-reference (parent_id = id) violates the production CHECK
        // constraint. A two-node cycle (editor→viewer→editor) exercises the same
        // cycle-detection path in RoleChecker without violating the constraint.
        $this->setParent('editor', 'viewer');
        $this->setParent('viewer', 'editor');

        $checker = new RoleChecker($this->db, $this->registry(), $logger);

        $effective = $checker->getEffectivePermissionsForRole($this->roleId('editor'));

        $this->assertSame(['a:read'], $effective);
    }

    /**
     * Resolved effective permissions are cached at the worker level so repeated
     * role resolution does not re-walk the hierarchy.
     */
    public function testEffectivePermissionsAreCachedPerRole(): void
    {
        $this->grant('viewer', 'dashboard:read');
        $roleId = $this->roleId('viewer');

        $first = $this->roleChecker->getEffectivePermissionsForRole($roleId);

        // Mutate the underlying grant AFTER caching; without invalidation the
        // cached set must still be served unchanged.
        $this->grant('viewer', 'posts:write');
        $second = $this->roleChecker->getEffectivePermissionsForRole($roleId);

        $this->assertSame($first, $second, 'A cached role set must be served without re-reading grants.');
        $this->assertNotContains('posts:write', $second);
    }

    /**
     * clearCache() invalidates the worker-level cache so a subsequent resolution
     * reflects new grants.
     */
    public function testClearCacheForcesReResolution(): void
    {
        $this->grant('viewer', 'dashboard:read');
        $roleId = $this->roleId('viewer');

        $this->roleChecker->getEffectivePermissionsForRole($roleId);

        $this->grant('viewer', 'posts:write');
        RoleChecker::clearCache();
        $second = $this->roleChecker->getEffectivePermissionsForRole($roleId);

        $this->assertContains('posts:write', $second, 'After clearCache() the new grant must be visible.');
    }

    // ==================== Helpers ====================

    private function registry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        // Register the colon-notation permissions these tests grant so the step-1
        // registry gate never short-circuits them.
        $registry->register('test', [
            'users:read', 'users:write', 'roles:read', 'dashboard:read',
            'posts:write', 'tenants:manage', 'a:read', 'b:read',
        ]);

        return $registry;
    }

    private function grant(string $roleName, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
            ->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$this->roleId($roleName), $permissionId]);
    }

    private function setParent(string $roleName, string $parentRoleName): void
    {
        $this->pdo->prepare('UPDATE roles SET parent_id = ? WHERE id = ?')
            ->execute([$this->roleId($parentRoleName), $this->roleId($roleName)]);
    }

    private function seedUser(string $email, string $roleName): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, ou_id, created_at)
             VALUES (?, ?, ?, ?, NULL, NOW())'
        );
        $stmt->execute([self::TENANT, $email, 'x', $this->roleId($roleName)]);

        return (int) $this->pdo->lastInsertId();
    }

    private function roleId(string $roleName): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$roleName]);

        return (int) $stmt->fetchColumn();
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        // Migrations seed admin(id=1) and user(id=2).  Add the extra roles that
        // this test suite exercises via name-based lookups (roleId() resolves by
        // name, so the auto-assigned ids don't matter).
        $pdo->exec("INSERT OR IGNORE INTO roles (name, created_at) VALUES
            ('super_admin', datetime('now')),
            ('editor',      datetime('now')),
            ('viewer',      datetime('now'))");

        // Migration 001 seeds only the System tenant (id=0).  seedUser() inserts
        // rows with tenant_id=1 (TENANT constant), so add a test tenant.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'test-tenant')");

        return $pdo;
    }
}
