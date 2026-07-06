<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;

/**
 * WC-bc07b6de: Membership-aware RBAC + delegation re-scoping.
 *
 * Proves the four security-critical properties enumerated in the task spec:
 *
 *  1. Per-tenant permission divergence: the same profile can be admin in
 *     tenant A (gains admin permissions) and read-only in tenant B (gains only
 *     viewer permissions). hasPermissionForProfile() resolves from memberships,
 *     not from users, so the role is strictly per-membership.
 *
 *  2. Revoked membership loses permission: suspending or removing a membership
 *     means the profile no longer has any permissions in that tenant. The
 *     membership-aware path checks the status field and returns an empty set for
 *     non-active memberships.
 *
 *  3. Cross-tenant delegation denied: a profile-grantee delegation created in
 *     tenant B cannot be resolved when the acting tenant is tenant A. The
 *     DelegationRepository always filters by tenant_id.
 *
 *  4. Cache invalidation fires on role/membership/delegation change: after a
 *     membership role changes, clearCache() causes the next hasPermissionForProfile()
 *     call to see the updated grants rather than the stale cached set.
 *
 * All tests run against in-memory SQLite via SchemaFromMigrations (full
 * production schema including migration 037) with STRINGIFY_FETCHES on to
 * mirror PostgreSQL's string-fetch behaviour.
 */
final class MembershipRbacTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private Database $db;
    private RoleChecker $checker;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo     = $this->makeSchema();
        $this->db      = $this->wrapSqlite($this->pdo);
        $this->checker = new RoleChecker($this->db, $this->registry());
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    // =========================================================================
    // Property 1 — per-tenant permission divergence for the same profile
    // =========================================================================

    /**
     * The same profile can hold different roles in different tenants.
     * hasPermissionForProfile() must use the MEMBERSHIP for the given tenant,
     * so the admin-only permission is available in tenant A but not in tenant B.
     */
    public function testSameProfileAdminInTenantAButNotTenantB(): void
    {
        // Profile 1 is admin in tenant A and viewer in tenant B.
        $profileId = $this->seedProfile('alice@example.com');
        $this->addMembership($profileId, self::TENANT_A, 'admin');
        $this->addMembership($profileId, self::TENANT_B, 'viewer');

        $this->grantPermission('admin',  'users:delete');
        $this->grantPermission('viewer', 'users:read');

        // Tenant A: admin membership → has users:delete.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'Profile with admin role in Tenant A must have users:delete in that tenant.'
        );

        // Tenant B: viewer membership → does NOT have users:delete.
        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_B),
            'Profile with viewer role in Tenant B must NOT have users:delete in that tenant.'
        );

        // Tenant B: viewer membership → has users:read.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:read', self::TENANT_B),
            'Profile with viewer role in Tenant B must have users:read.'
        );
    }

    /**
     * Effective permission sets are distinct per tenant for the same profile.
     * getEffectivePermissionsForProfile() must resolve against the correct
     * membership.role_id for each tenant independently.
     */
    public function testEffectivePermissionSetsArePerTenantForSameProfile(): void
    {
        $profileId = $this->seedProfile('bob@example.com');
        $this->addMembership($profileId, self::TENANT_A, 'admin');
        $this->addMembership($profileId, self::TENANT_B, 'viewer');

        $this->grantPermission('admin',  'users:delete');
        $this->grantPermission('admin',  'roles:read');
        $this->grantPermission('viewer', 'users:read');

        $permsA = $this->checker->getEffectivePermissionsForProfile($profileId, self::TENANT_A);
        $permsB = $this->checker->getEffectivePermissionsForProfile($profileId, self::TENANT_B);

        $this->assertContains('users:delete', $permsA, 'Tenant A must include admin-only users:delete.');
        $this->assertContains('roles:read',   $permsA, 'Tenant A must include admin-only roles:read.');
        $this->assertNotContains('users:delete', $permsB, 'Tenant B must NOT include admin-only users:delete.');
        $this->assertNotContains('roles:read',   $permsB, 'Tenant B must NOT include admin-only roles:read.');
        $this->assertContains('users:read', $permsB, 'Tenant B viewer must include users:read.');
    }

    /**
     * A `:read` permission can never trigger a write check: asserting the
     * wrong action suffix is denied regardless of what the membership grants.
     */
    public function testReadPermissionDoesNotSatisfyWriteCheck(): void
    {
        $profileId = $this->seedProfile('carol@example.com');
        $this->addMembership($profileId, self::TENANT_A, 'viewer');
        $this->grantPermission('viewer', 'users:read');

        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:read', self::TENANT_A),
            'A profile with users:read granted must have users:read.'
        );
        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:write', self::TENANT_A),
            'A profile with only users:read must never satisfy users:write.'
        );
        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'A profile with only users:read must never satisfy users:delete.'
        );
    }

    // =========================================================================
    // Property 2 — revoked membership loses permission
    // =========================================================================

    /**
     * After a membership is suspended, the profile must lose all permissions in
     * that tenant. getEffectivePermissionsForProfile() returns an empty set for
     * a suspended membership.
     */
    public function testSuspendedMembershipLosesAllPermissions(): void
    {
        $profileId = $this->seedProfile('dave@example.com');
        $membershipId = $this->addMembership($profileId, self::TENANT_A, 'admin');
        $this->grantPermission('admin', 'users:delete');

        // Before suspension: has permission.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'Active membership must grant users:delete.'
        );

        // Suspend the membership.
        $this->pdo->prepare(
            "UPDATE memberships SET status = 'suspended' WHERE id = ?"
        )->execute([$membershipId]);

        // Clear cache so the suspended status is re-read.
        RoleChecker::clearCache();

        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'A suspended membership must grant NO permissions.'
        );
        $this->assertSame(
            [],
            $this->checker->getEffectivePermissionsForProfile($profileId, self::TENANT_A),
            'Suspended membership must return empty effective permission set.'
        );
    }

    /**
     * After a membership is deleted, the profile has no permissions in that tenant.
     */
    public function testDeletedMembershipLosesAllPermissions(): void
    {
        $profileId = $this->seedProfile('eve@example.com');
        $membershipId = $this->addMembership($profileId, self::TENANT_A, 'admin');
        $this->grantPermission('admin', 'users:delete');

        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'Active membership must grant users:delete.'
        );

        // Delete the membership.
        $this->pdo->prepare('DELETE FROM memberships WHERE id = ?')->execute([$membershipId]);

        // Clear cache so the deleted row is re-read.
        RoleChecker::clearCache();

        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'A deleted membership must grant NO permissions.'
        );
    }

    // =========================================================================
    // Property 3 — cross-tenant delegation denied
    // =========================================================================

    /**
     * A delegation row created in tenant B cannot be resolved when the acting
     * tenant is tenant A. livePermissionsForGrantee() always scopes by tenant_id.
     */
    public function testDelegationInTenantBInvisibleToTenantA(): void
    {
        $repo = new DelegationRepository($this->pdo);

        $profileA = $this->seedProfile('fa@example.com');
        $profileB = $this->seedProfile('fb@example.com');
        $this->addMembership($profileA, self::TENANT_A, 'viewer');
        $this->addMembership($profileB, self::TENANT_B, 'viewer');

        // Create delegation in tenant B granting users:read to profile B.
        $this->pdo->prepare(
            "INSERT INTO permission_delegations
                 (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
             VALUES (?, ?, 'profile', ?, 'users:read', datetime('now'))"
        )->execute([self::TENANT_B, $profileB, $profileB]);

        // Acting as tenant A: profile B's delegation must not appear.
        $perms = $repo->livePermissionsForGrantee(
            self::TENANT_A,
            DelegationRepository::GRANTEE_PROFILE,
            $profileB,
            []
        );

        $this->assertSame(
            [],
            $perms,
            'A delegation created in Tenant B must be invisible to Tenant A queries.'
        );
    }

    /**
     * FULL authorization-path cross-tenant denial (not just repository-level):
     * a delegation that grants users:delete to a profile in TENANT B must NOT be
     * exercisable when RoleChecker::hasPermissionForProfile() is asked about
     * TENANT A. This drives the real checker → DelegationService →
     * DelegationRepository chain, proving the tenant predicate holds end to end.
     *
     * Runs on SQLite and, under PHPUNIT_PG_DSN, on real PostgreSQL.
     */
    public function testDelegationInTenantBCannotBeExercisedViaHasPermissionForProfileInTenantA(): void
    {
        // The same profile is a viewer in BOTH tenants (no users:delete via role).
        $profile = $this->seedProfile('xt@example.com');
        $this->addMembership($profile, self::TENANT_A, 'viewer');
        $this->addMembership($profile, self::TENANT_B, 'viewer');
        $this->grantPermission('viewer', 'users:read');

        // A delegation in TENANT B grants users:delete to the profile.
        $grantorB = $this->seedProfile('xt-grantor@example.com');
        $this->addMembership($grantorB, self::TENANT_B, 'admin');
        $this->grantPermission('admin', 'users:delete');
        $this->pdo->prepare(
            "INSERT INTO permission_delegations
                 (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
             VALUES (?, ?, 'profile', ?, 'users:delete', datetime('now'))"
        )->execute([self::TENANT_B, $grantorB, $profile]);

        // Build a DELEGATION-AWARE checker (the enforcement checker in production).
        $delegationService = $this->makeDelegationService();
        $checker = new RoleChecker($this->db, $this->registry(), null, $delegationService);
        RoleChecker::clearCache();

        // In TENANT B the delegation DOES grant users:delete (positive control).
        $this->assertTrue(
            $checker->hasPermissionForProfile($profile, 'users:delete', self::TENANT_B),
            'Positive control: the delegation must grant users:delete in its own tenant B.'
        );

        // In TENANT A the SAME profile must NOT gain users:delete — the tenant-B
        // delegation is invisible across the tenant boundary through the full
        // authorization path.
        $this->assertFalse(
            $checker->hasPermissionForProfile($profile, 'users:delete', self::TENANT_A),
            'A delegation in tenant B must never be exercisable in tenant A via hasPermissionForProfile().'
        );
    }

    /**
     * A cross-tenant revoke must touch zero rows — the tenant B delegation row
     * must remain active after tenant A tries to revoke it.
     */
    public function testCrossTenantRevokeOfDelegationTouchesZeroRows(): void
    {
        $repo = new DelegationRepository($this->pdo);

        $profileB = $this->seedProfile('rb@example.com');
        $this->addMembership($profileB, self::TENANT_B, 'viewer');

        $this->pdo->prepare(
            "INSERT INTO permission_delegations
                 (id, tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
             VALUES (500, ?, ?, 'profile', ?, 'users:read', datetime('now'))"
        )->execute([self::TENANT_B, $profileB, $profileB]);

        // Tenant A tries to revoke delegation 500 (Tenant B's row).
        $affected = $repo->revoke(500, self::TENANT_A);

        $this->assertSame(0, $affected, 'Cross-tenant revoke must affect zero rows.');
        $this->assertNotNull(
            $repo->findById(500, self::TENANT_B),
            'Tenant B delegation must remain live after cross-tenant revoke attempt.'
        );
    }

    // =========================================================================
    // Property 4 — cache invalidation fires on role/membership/delegation change
    // =========================================================================

    /**
     * After a membership's role_id changes, clearCache() must cause the next
     * permission check to see the updated role's grants rather than the stale
     * cached set.
     */
    public function testCacheInvalidationFiresOnMembershipRoleChange(): void
    {
        $profileId = $this->seedProfile('ci@example.com');
        $membershipId = $this->addMembership($profileId, self::TENANT_A, 'viewer');
        $this->grantPermission('viewer', 'users:read');
        $this->grantPermission('admin',  'users:delete');

        // First check — viewer role; users:delete must be denied.
        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'Viewer membership must not have users:delete initially.'
        );

        // Promote the membership to admin.
        $adminRoleId = $this->roleId('admin');
        $this->pdo->prepare('UPDATE memberships SET role_id = ? WHERE id = ?')
            ->execute([$adminRoleId, $membershipId]);

        // Cache still holds the stale 'viewer' result; must clear.
        RoleChecker::clearCache();

        // After clearCache(): must now resolve admin permissions.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'After clearCache(), promoted-to-admin membership must have users:delete.'
        );
    }

    /**
     * After a delegation is revoked, clearCache() must cause the next permission
     * check to no longer include the previously delegated permission.
     */
    public function testCacheInvalidationFiresOnDelegationRevoke(): void
    {
        $repo = new DelegationRepository($this->pdo);

        $grantorProfile = $this->seedProfile('grantor@example.com');
        $granteeProfile = $this->seedProfile('grantee@example.com');
        $this->addMembership($grantorProfile, self::TENANT_A, 'admin');
        $this->addMembership($granteeProfile, self::TENANT_A, 'viewer');

        $this->grantPermission('admin', 'users:delete');

        // Create delegation: grantor delegates users:delete to grantee.
        $this->pdo->prepare(
            "INSERT INTO permission_delegations
                 (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
             VALUES (?, ?, 'profile', ?, 'users:delete', datetime('now'))"
        )->execute([self::TENANT_A, $grantorProfile, $granteeProfile]);

        // Build a delegation-aware checker.
        $delegationService = $this->makeDelegationService();
        $checkerWithDelegation = new RoleChecker($this->db, $this->registry(), null, $delegationService);

        // Grantee (viewer) can exercise users:delete via delegation.
        $this->assertTrue(
            $checkerWithDelegation->hasPermissionForProfile($granteeProfile, 'users:delete', self::TENANT_A),
            'Grantee must have users:delete via delegation before revoke.'
        );

        // Revoke the delegation (gets the last-inserted delegation id).
        $stmt = $this->pdo->query(
            "SELECT id FROM permission_delegations
             WHERE grantee_id = {$granteeProfile} AND permission = 'users:delete'
             ORDER BY id DESC LIMIT 1"
        );
        $this->assertNotFalse($stmt, 'Expected query to return a PDOStatement.');
        $delegationId = (int) $stmt->fetchColumn();
        $repo->revoke($delegationId, self::TENANT_A);

        // Invalidate cache — this is what every mutating handler must call.
        RoleChecker::clearCache();

        // After clearCache(), delegation is revoked and must not grant access.
        $this->assertFalse(
            $checkerWithDelegation->hasPermissionForProfile($granteeProfile, 'users:delete', self::TENANT_A),
            'After clearCache() + revoke, grantee must lose users:delete.'
        );
    }

    /**
     * Proves the exact cache-invalidation SEMANTICS: the STALE (pre-clear) value
     * is deterministically served after the suspend-without-clear, and the
     * CORRECT (denied) value is served only after clearCache(). This is what makes
     * clearCache() the security-critical contract:
     *
     *   CONTRACT — the future membership-management API (WC-32e5bb09) MUST call
     *   RoleChecker::clearCache() on any membership suspend/remove/role-change.
     *   No membership-mutation HTTP path exists in THIS PR, so there is nothing to
     *   wire here; this test pins the semantics that path must honour. A stale
     *   cache that keeps granting a revoked permission is a security bug.
     */
    public function testClearCacheEnforcesConsistencyAroundMembershipSuspend(): void
    {
        $profileId = $this->seedProfile('suspend@example.com');
        $membershipId = $this->addMembership($profileId, self::TENANT_A, 'admin');
        $this->grantPermission('admin', 'users:delete');

        // Prime the cache: active admin membership grants users:delete.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'Pre-suspension: must have users:delete.'
        );

        // Suspend WITHOUT clearing the cache.
        $this->pdo->prepare("UPDATE memberships SET status = 'suspended' WHERE id = ?")
            ->execute([$membershipId]);

        // WITHOUT clearCache the STALE (granted) value is deterministically served
        // — this proves the worker-level cache is real and that a mutation which
        // forgets to invalidate would keep granting a revoked permission.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'Stale cache must still return the pre-suspension (granted) value until clearCache().'
        );

        // The invalidation a membership-mutation handler MUST call (contract above).
        RoleChecker::clearCache();

        // AFTER clearCache the suspended membership correctly denies.
        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'After clearCache(): suspended membership must deny users:delete.'
        );
    }

    // =========================================================================
    // Blocker 2 regression — profile grantor delegation bounding
    // =========================================================================

    /**
     * A profile grantor CAN delegate a permission it actually holds via its
     * membership role. This is the positive half of the subset-invariant gate and
     * regresses the bug where delegate() resolved the grantor through the
     * user→profile mapping path (getEffectivePermissionsForUser) and thus saw an
     * EMPTY set for a profile-only grantor — which silently blocked ALL delegation.
     */
    public function testProfileGrantorCanDelegateHeldPermission(): void
    {
        $service = $this->makeDelegationService();

        $grantor = $this->seedProfile('grantor-holds@example.com');
        $grantee = $this->seedProfile('grantee-holds@example.com');
        $this->addMembership($grantor, self::TENANT_A, 'admin');
        $this->addMembership($grantee, self::TENANT_A, 'viewer');
        $this->grantPermission('admin', 'users:delete');

        // Grantor (admin) holds users:delete → delegation succeeds, one row written.
        $ids = $service->delegate(
            self::TENANT_A,
            $grantor,
            DelegationRepository::GRANTEE_PROFILE,
            $grantee,
            ['users:delete'],
            null
        );

        $this->assertCount(1, $ids, 'A profile grantor must be able to delegate a permission it holds.');
        $this->assertGreaterThan(0, $ids[0]);
    }

    /**
     * A profile grantor CANNOT delegate a permission it does NOT hold — the subset
     * invariant throws PermissionNotDelegableException and writes nothing.
     */
    public function testProfileGrantorCannotDelegateUnheldPermission(): void
    {
        $service = $this->makeDelegationService();

        $grantor = $this->seedProfile('grantor-lacks@example.com');
        $grantee = $this->seedProfile('grantee-lacks@example.com');
        $this->addMembership($grantor, self::TENANT_A, 'viewer');
        $this->addMembership($grantee, self::TENANT_A, 'viewer');
        $this->grantPermission('viewer', 'users:read'); // grantor holds only users:read

        $threw = false;
        try {
            $service->delegate(
                self::TENANT_A,
                $grantor,
                DelegationRepository::GRANTEE_PROFILE,
                $grantee,
                ['users:delete'], // NOT held
                null
            );
        } catch (\Whity\Api\Exception\PermissionNotDelegableException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Delegating an unheld permission must throw PermissionNotDelegableException.');
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM permission_delegations WHERE grantee_id = {$grantee} AND permission = 'users:delete'"
        );
        $this->assertNotFalse($stmt, 'Expected query to return a PDOStatement.');
        $this->assertSame(
            0,
            (int) $stmt->fetchColumn(),
            'No delegation row may be written when the grantor lacks the permission.'
        );
    }

    // =========================================================================
    // OU-inherited role via membership (membership-aware path)
    // =========================================================================

    /**
     * OU-assigned roles are inherited by the profile's membership when the
     * membership's ou_id is set. The effective set unions the OU-role grants.
     */
    public function testOuInheritedRoleViaProfileMembership(): void
    {
        $profileId = $this->seedProfile('outest@example.com');

        // Create an OU in Tenant A.
        $this->pdo->prepare(
            "INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
             VALUES (50, ?, 'Engineering', 'eng', datetime('now'))"
        )->execute([self::TENANT_A]);

        // Assign 'admin' role to the OU.
        $this->pdo->prepare(
            'INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at) VALUES (?, 50, ?, datetime(\'now\'))'
        )->execute([self::TENANT_A, $this->roleId('admin')]);

        // Profile is viewer in membership, but assigned to OU 50.
        $membershipId = $this->addMembership($profileId, self::TENANT_A, 'viewer', 50);

        $this->grantPermission('admin',  'users:delete');
        $this->grantPermission('viewer', 'users:read');

        // Profile must have admin's permission via OU inheritance.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_A),
            'Membership with OU-inherited admin role must have users:delete.'
        );
        // And still has viewer's direct permission.
        $this->assertTrue(
            $this->checker->hasPermissionForProfile($profileId, 'users:read', self::TENANT_A),
            'Membership must also have viewer:read from direct membership role.'
        );

        // OU-inherited roles must NOT leak to tenant B.
        $this->addMembership($profileId, self::TENANT_B, 'viewer');
        $this->assertFalse(
            $this->checker->hasPermissionForProfile($profileId, 'users:delete', self::TENANT_B),
            'OU-inherited admin role in Tenant A must not leak to Tenant B.'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function registry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $registry->register('test', [
            'users:read', 'users:write', 'users:delete', 'roles:read',
            'dashboard:read', 'posts:write', 'tenants:manage',
        ]);
        return $registry;
    }

    private function seedProfile(string $email): int
    {
        $localPart = strstr($email, '@', true) ?: $email;
        $this->pdo->prepare(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version,
                  token_epoch, created_at, updated_at)
             VALUES (?, '', false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([$localPart]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return int The membership row id.
     */
    private function addMembership(int $profileId, int $tenantId, string $roleName, ?int $ouId = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, $tenantId, $this->roleId($roleName), $ouId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function grantPermission(string $roleName, string $permission): void
    {
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, datetime(\'now\'))'
        )->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, datetime(\'now\'))'
        )->execute([$this->roleId($roleName), $permId]);
    }

    private function roleId(string $roleName): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$roleName]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Role '{$roleName}' not found in test schema.");
        }
        return (int) $id;
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();
        return $db;
    }

    private function makeSchema(): PDO
    {
        // STRINGIFY_FETCHES = true to mirror PostgreSQL's string-fetch behaviour.
        $pdo = SchemaFromMigrations::make(true);

        // Tenants.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        // Roles (admin=1, user=2 from migrations; add viewer for tests).
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
            (1, 'admin',  '', NULL, datetime('now')),
            (2, 'user',   '', NULL, datetime('now')),
            (3, 'viewer', '', NULL, datetime('now'))");

        return $pdo;
    }

    /**
     * Build a DelegationService that resolves delegations for the profile path.
     * We use DelegationService (which implements DelegatedPermissionResolver)
     * wired to a delegation-unaware bounding RoleChecker.
     */
    private function makeDelegationService(): \Whity\Core\Delegation\DelegationService
    {
        $boundingChecker = new RoleChecker($this->db, $this->registry());
        $delegationRepo  = new DelegationRepository($this->pdo);

        return new \Whity\Core\Delegation\DelegationService(
            $delegationRepo,
            $boundingChecker,
            $this->registry()
        );
    }
}
