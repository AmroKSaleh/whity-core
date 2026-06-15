<?php

declare(strict_types=1);

namespace Tests\Core\Delegation;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\Exception\PermissionNotDelegableException;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests for the WC-34 delegation core
 * ({@see DelegationService}, {@see DelegationRepository}) and its integration
 * into {@see RoleChecker}.
 *
 * The schema mirrors the production migrations closely enough to exercise the
 * real SQL: roles/permissions/role_permissions, users, organizational_units,
 * ou_role_assignments, and permission_delegations. A `NOW()` UDF is registered
 * because the production SQL uses PostgreSQL's NOW().
 *
 * Real-Postgres parity: `PDO::ATTR_STRINGIFY_FETCHES` is enabled so fetched
 * integers come back as STRINGS exactly as the Postgres PDO driver returns them
 * — this is what catches int-vs-string comparison bugs in resolution/scoping
 * that mocked PDO and native-SQLite ints would hide.
 *
 * Covers the core invariant end to end:
 *  - a grantor CANNOT delegate a permission they lack (rejected, 0 rows),
 *  - a grantor CAN delegate one they hold,
 *  - a delegated permission then makes hasPermission() true for the grantee,
 *  - revocation removes that access,
 *  - the whole flow is tenant-isolated, and
 *  - OU scoping applies only within the scoped subtree.
 */
final class DelegationServiceRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = self::makeSqliteSchema();
        $this->db = self::wrapSqlite($this->pdo);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    // ==================== Subset invariant ====================

    public function testGrantorCannotDelegatePermissionTheyDoNotHold(): void
    {
        // Grantor (user 10) is a plain 'user' with NO grants.
        $grantorId = $this->seedUser('grantor@example.com', 'user', 1);

        $service = $this->service();

        $this->expectException(PermissionNotDelegableException::class);
        try {
            $service->delegate(1, $grantorId, DelegationRepository::GRANTEE_USER, $this->seedUser('g@e.com', 'user', 1), ['users:read'], null);
        } finally {
            // Nothing was written.
            $this->assertSame(
                0,
                (int) $this->pdo->query('SELECT COUNT(*) FROM permission_delegations')->fetchColumn(),
                'A rejected delegation must write no rows.'
            );
        }
    }

    public function testRejectsWhenAnyPermissionInTheSetIsNotHeld(): void
    {
        // Grantor holds users:read but NOT roles:read.
        $this->grant('user', 'users:read');
        $grantorId = $this->seedUser('grantor@example.com', 'user', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $service = $this->service();

        $this->expectException(PermissionNotDelegableException::class);
        $service->delegate(
            1,
            $grantorId,
            DelegationRepository::GRANTEE_USER,
            $granteeId,
            ['users:read', 'roles:read'],
            null
        );
    }

    public function testGrantorCanDelegatePermissionTheyHold(): void
    {
        $this->grant('user', 'users:read');
        $grantorId = $this->seedUser('grantor@example.com', 'user', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $ids = $this->service()->delegate(
            1,
            $grantorId,
            DelegationRepository::GRANTEE_USER,
            $granteeId,
            ['users:read'],
            null
        );

        $this->assertCount(1, $ids);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM permission_delegations WHERE revoked_at IS NULL')->fetchColumn()
        );
    }

    // ==================== Resolution: delegation grants access ====================

    public function testDelegatedPermissionMakesHasPermissionTrueForUserGrantee(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $checker = $this->delegationAwareChecker();

        // Before: the grantee (plain user) lacks the permission.
        $this->assertFalse($checker->hasPermission($granteeId, 'users:read', 1));

        $this->service()->delegate(1, $grantorId, DelegationRepository::GRANTEE_USER, $granteeId, ['users:read'], null);
        RoleChecker::clearCache();

        // After: the live delegation grants it.
        $this->assertTrue(
            $checker->hasPermission($granteeId, 'users:read', 1),
            'A live delegation must make hasPermission() return true for the grantee.'
        );
    }

    public function testDelegatedPermissionToRoleGranteeReachesUsersWithThatRole(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        // A user whose direct role is 'user' (role id 2).
        $userWithRole = $this->seedUser('member@example.com', 'user', 1);

        $checker = $this->delegationAwareChecker();
        $this->assertFalse($checker->hasPermission($userWithRole, 'users:read', 1));

        // Delegate to the 'user' role (id 2), not to the user directly.
        $this->service()->delegate(1, $grantorId, DelegationRepository::GRANTEE_ROLE, 2, ['users:read'], null);
        RoleChecker::clearCache();

        $this->assertTrue(
            $checker->hasPermission($userWithRole, 'users:read', 1),
            'A role-targeted delegation must reach every user holding that role.'
        );
    }

    public function testRevocationRemovesDelegatedAccess(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $service = $this->service();
        $checker = $this->delegationAwareChecker();

        $ids = $service->delegate(1, $grantorId, DelegationRepository::GRANTEE_USER, $granteeId, ['users:read'], null);
        RoleChecker::clearCache();
        $this->assertTrue($checker->hasPermission($granteeId, 'users:read', 1));

        $this->assertTrue($service->revoke($ids[0], 1));
        RoleChecker::clearCache();

        $this->assertFalse(
            $checker->hasPermission($granteeId, 'users:read', 1),
            'Revoking the delegation must remove the delegated access.'
        );
    }

    // ==================== Tenant isolation ====================

    public function testDelegationIsTenantIsolated(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        // Same grantee user id exists conceptually only in tenant 1; seed a
        // tenant-2 user too so we can probe cross-tenant.
        $granteeT1 = $this->seedUser('grantee1@example.com', 'user', 1);
        $granteeT2 = $this->seedUser('grantee2@example.com', 'user', 2);

        $this->service()->delegate(1, $grantorId, DelegationRepository::GRANTEE_USER, $granteeT1, ['users:read'], null);
        RoleChecker::clearCache();

        $checker = $this->delegationAwareChecker();

        // Tenant 1 grantee gets it.
        $this->assertTrue($checker->hasPermission($granteeT1, 'users:read', 1));
        // The tenant-2 user never gets a tenant-1 delegation.
        $this->assertFalse(
            $checker->hasPermission($granteeT2, 'users:read', 2),
            'A delegation in tenant 1 must not grant anything in tenant 2.'
        );
    }

    public function testSameGranteeIdInDifferentTenantDoesNotInherit(): void
    {
        // A delegation to user id X in tenant 1 must not leak to tenant 2 even if
        // we ask about the SAME numeric grantee id under tenant 2.
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $granteeId = $this->seedUser('grantee@example.com', 'user', 1);

        $this->service()->delegate(1, $grantorId, DelegationRepository::GRANTEE_USER, $granteeId, ['users:read'], null);

        $repo = new DelegationRepository($this->pdo);
        $t1 = $repo->livePermissionsForGrantee(1, DelegationRepository::GRANTEE_USER, $granteeId, []);
        $t2 = $repo->livePermissionsForGrantee(2, DelegationRepository::GRANTEE_USER, $granteeId, []);

        $this->assertSame(['users:read'], $t1);
        $this->assertSame([], $t2, 'Resolution under another tenant must return nothing.');
    }

    // ==================== OU scoping ====================

    public function testOuScopedDelegationAppliesWithinSubtreeOnly(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);

        // OU tree (tenant 1): parent(100) -> child(101); sibling(200) is separate.
        $this->seedOu(100, 1, null, 'parent');
        $this->seedOu(101, 1, 100, 'child');
        $this->seedOu(200, 1, null, 'sibling');

        // Grantees: one in the child OU (in subtree of 100), one in the sibling OU.
        $inSubtree = $this->seedUser('child@example.com', 'user', 1, 101);
        $outSubtree = $this->seedUser('sibling@example.com', 'user', 1, 200);

        // Delegate users:read to the 'user' role, scoped to OU 100.
        $this->service()->delegate(1, $grantorId, DelegationRepository::GRANTEE_ROLE, 2, ['users:read'], 100);
        RoleChecker::clearCache();

        $checker = $this->delegationAwareChecker();

        $this->assertTrue(
            $checker->hasPermission($inSubtree, 'users:read', 1),
            'A user within the scoped OU subtree must receive the OU-scoped delegation.'
        );
        $this->assertFalse(
            $checker->hasPermission($outSubtree, 'users:read', 1),
            'A user outside the scoped OU subtree must NOT receive the OU-scoped delegation.'
        );
    }

    public function testTenantWideDelegationAppliesRegardlessOfOu(): void
    {
        $this->grant('admin', 'users:read');
        $grantorId = $this->seedUser('admin@example.com', 'admin', 1);
        $this->seedOu(200, 1, null, 'sibling');
        $anyUser = $this->seedUser('any@example.com', 'user', 1, 200);

        // No OU scope -> tenant-wide.
        $this->service()->delegate(1, $grantorId, DelegationRepository::GRANTEE_ROLE, 2, ['users:read'], null);
        RoleChecker::clearCache();

        $this->assertTrue(
            $this->delegationAwareChecker()->hasPermission($anyUser, 'users:read', 1),
            'A tenant-wide delegation applies to any user in the tenant.'
        );
    }

    // ==================== Helpers ====================

    private function service(): DelegationService
    {
        $repo = new DelegationRepository($this->pdo);
        $baseChecker = new RoleChecker($this->db, new PermissionRegistry());
        return new DelegationService($repo, $baseChecker, new PermissionRegistry());
    }

    private function delegationAwareChecker(): RoleChecker
    {
        $repo = new DelegationRepository($this->pdo);
        $baseChecker = new RoleChecker($this->db, new PermissionRegistry());
        $service = new DelegationService($repo, $baseChecker, new PermissionRegistry());

        return new RoleChecker($this->db, new PermissionRegistry(), null, $service);
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

    private function seedUser(string $email, string $roleName, int $tenantId, ?int $ouId = null): int
    {
        $roleId = (int) $this->pdo->query("SELECT id FROM roles WHERE name = '{$roleName}'")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, ou_id, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$tenantId, $email, 'x', $roleId, $ouId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedOu(int $id, int $tenantId, ?int $parentId, string $name): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$id, $tenantId, $parentId, $name, $name]);
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
        return SchemaFromMigrations::make(true);
    }
}
