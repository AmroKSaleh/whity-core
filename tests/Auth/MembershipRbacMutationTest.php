<?php

declare(strict_types=1);

namespace Tests\Auth;

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
 * WC-bc07b6de: mutation-hardening tests for the membership-aware RBAC resolution
 * and delegation bounding introduced by this change.
 *
 * These are deliberately FINE-GRAINED, in the mutation-scope test set
 * (tests/Auth), and each asserts a precise behavioural boundary that a specific
 * escaped Infection mutant in the new security-critical code would break:
 *
 *   - RoleChecker::getEffectiveRoleIdsForProfile()  — the status == 'active' gate
 *     (active vs suspended vs invited), the direct-role inclusion, and the OU
 *     union branch (ou_id !== null).
 *   - RoleChecker::getOuChainRoleIds() / buildOuChainIds() / getParentOuId() —
 *     multi-level ancestor inclusion, the leaf-only case, and the tenant_id
 *     predicate on the parent walk (cross-tenant parent must NOT be followed).
 *   - RoleChecker::getRoleIdsAssignedToOu()          — the tenant_id predicate on
 *     OU-role assignment lookup.
 *   - DelegationService::delegate()                  — the subset-invariant gate:
 *     the held/unheld boundary AND the registry-existence half of the OR, plus
 *     one-row-per-permission and the partial-set (some held, some not) rejection.
 *   - DelegationRepository::livePermissionsForGrantee() — grantee_type + grantee_id
 *     exact-match and tenant_id predicate.
 *
 * Runs against the full production schema (SchemaFromMigrations) on SQLite with
 * STRINGIFY_FETCHES on (Postgres string-fetch parity).
 */
final class MembershipRbacMutationTest extends TestCase
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
    // status == 'active' gate — active / suspended / invited boundaries
    // =========================================================================

    /**
     * ACTIVE membership contributes its role's permissions (positive control for
     * the status gate; kills a mutant that inverts the status comparison).
     */
    public function testActiveMembershipGrantsRolePermissions(): void
    {
        $profile = $this->seedProfile('active@example.com');
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'admin', 'active');
        $this->grantPermission('admin', 'users:delete');

        self::assertSame(
            ['admin'],
            $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A),
            'An active membership must resolve exactly its direct role.'
        );
        self::assertTrue(
            $this->checker->hasPermissionForProfile($profile, 'users:delete', self::TENANT_A)
        );
    }

    /**
     * SUSPENDED membership contributes nothing. Kills a mutant that removes/inverts
     * the `status !== 'active'` early return.
     */
    public function testSuspendedMembershipContributesNoRolesOrPermissions(): void
    {
        $profile = $this->seedProfile('suspended@example.com');
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'admin', 'suspended');
        $this->grantPermission('admin', 'users:delete');

        self::assertSame(
            [],
            $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A),
            'A suspended membership must resolve NO roles.'
        );
        self::assertFalse(
            $this->checker->hasPermissionForProfile($profile, 'users:delete', self::TENANT_A),
            'A suspended membership must grant NO permissions.'
        );
    }

    /**
     * INVITED membership (a DIFFERENT non-active status than suspended) also
     * contributes nothing. This kills the mutant that would replace the string
     * literal 'active' with another value, or narrow the check to only exclude
     * 'suspended' — because 'invited' must be rejected by the SAME gate.
     */
    public function testInvitedMembershipContributesNoRolesOrPermissions(): void
    {
        $profile = $this->seedProfile('invited@example.com');
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'admin', 'invited');
        $this->grantPermission('admin', 'users:delete');

        self::assertSame(
            [],
            $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A),
            'An invited membership must resolve NO roles.'
        );
        self::assertFalse(
            $this->checker->hasPermissionForProfile($profile, 'users:delete', self::TENANT_A),
            'An invited membership must grant NO permissions.'
        );
    }

    /**
     * No membership at all → empty set (kills a mutant that drops the
     * null-membership early return).
     */
    public function testNoMembershipContributesNoRoles(): void
    {
        $profile = $this->seedProfile('nomember@example.com');
        // No membership seeded in Tenant A.
        self::assertSame(
            [],
            $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A),
            'A profile with no membership in the tenant must resolve NO roles.'
        );
    }

    /**
     * hasRoleForProfile() returns true ONLY for a role the profile effectively
     * holds and false otherwise — the strict membership of the required role in
     * the resolved set. Kills mutants on the in_array strict flag / the boolean
     * result of hasRoleForProfile.
     */
    public function testHasRoleForProfileMatchesEffectiveRoleExactly(): void
    {
        $profile = $this->seedProfile('hasrole@example.com');
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'viewer', 'active');

        self::assertTrue(
            $this->checker->hasRoleForProfile($profile, 'viewer', self::TENANT_A),
            'A viewer membership must satisfy hasRoleForProfile("viewer").'
        );
        self::assertFalse(
            $this->checker->hasRoleForProfile($profile, 'admin', self::TENANT_A),
            'A viewer membership must NOT satisfy hasRoleForProfile("admin").'
        );
        // A suspended membership satisfies NO role check.
        $suspended = $this->seedProfile('hasrole-susp@example.com');
        $this->addMembershipWithStatus($suspended, self::TENANT_A, 'viewer', 'suspended');
        self::assertFalse(
            $this->checker->hasRoleForProfile($suspended, 'viewer', self::TENANT_A),
            'A suspended membership must satisfy no role check.'
        );
    }

    // =========================================================================
    // Direct-role inclusion vs OU-union branch
    // =========================================================================

    /**
     * A membership with NO OU (ou_id IS NULL) resolves ONLY its direct role — the
     * OU union branch must be skipped. Kills a mutant that always enters the OU
     * branch or that drops the direct-role inclusion.
     */
    public function testMembershipWithoutOuResolvesOnlyDirectRole(): void
    {
        $profile = $this->seedProfile('direct@example.com');
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'viewer', 'active', null);
        $this->grantPermission('viewer', 'users:read');

        self::assertSame(
            ['viewer'],
            $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A),
            'Membership without an OU must resolve exactly its direct role.'
        );
    }

    /**
     * A membership WITH an OU unions the OU's assigned role with the direct role.
     * Kills a mutant that skips the OU-union branch (ou_id !== null) or drops the
     * OU-role merge.
     */
    public function testMembershipWithOuUnionsDirectAndOuRoles(): void
    {
        $profile = $this->seedProfile('ouunion@example.com');
        $ou = $this->seedOu(self::TENANT_A, null, 'engineering');
        $this->assignRoleToOu(self::TENANT_A, $ou, 'admin');
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'viewer', 'active', $ou);

        $roles = $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A);
        sort($roles);
        self::assertSame(
            ['admin', 'viewer'],
            $roles,
            'Membership with an OU must union the OU-assigned role (admin) with the direct role (viewer).'
        );
    }

    // =========================================================================
    // OU ancestor-chain walk — multi-level inclusion, leaf-only, tenant predicate
    // =========================================================================

    /**
     * OU roles are inherited from EVERY ancestor, not just the immediate OU.
     * Grandparent-assigned roles must reach a membership on the leaf OU. Kills a
     * mutant that stops the parent walk after one hop or drops ancestor role union.
     */
    public function testOuRolesInheritedFromMultiLevelAncestors(): void
    {
        $profile = $this->seedProfile('ancestor@example.com');
        $root  = $this->seedOu(self::TENANT_A, null, 'root');
        $mid   = $this->seedOu(self::TENANT_A, $root, 'mid');
        $leaf  = $this->seedOu(self::TENANT_A, $mid, 'leaf');

        // 'admin' is assigned to the ROOT (grandparent of leaf).
        $this->assignRoleToOu(self::TENANT_A, $root, 'admin');
        $this->grantPermission('admin', 'users:delete');

        // Membership is a viewer on the LEAF ou.
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'viewer', 'active', $leaf);

        $roles = $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A);
        sort($roles);
        self::assertSame(
            ['admin', 'viewer'],
            $roles,
            'A role assigned to a grandparent OU must be inherited by a membership on the leaf OU.'
        );
        self::assertTrue(
            $this->checker->hasPermissionForProfile($profile, 'users:delete', self::TENANT_A),
            'The grandparent-inherited admin role must grant users:delete.'
        );
    }

    /**
     * The parent walk is tenant-scoped: getParentOuId() filters by tenant_id, so a
     * parent pointer that references an OU in ANOTHER tenant must NOT be followed.
     * Kills a mutant that drops the `AND tenant_id` predicate on the parent lookup.
     *
     * Setup: leaf OU in Tenant A whose parent_id points at an OU id that only
     * exists in Tenant B (cross-tenant pointer). The Tenant-B parent carries
     * 'admin'; it must be invisible when resolving in Tenant A.
     */
    public function testParentWalkDoesNotCrossTenantBoundary(): void
    {
        $profile = $this->seedProfile('xtparent@example.com');

        // Tenant B OU (id captured) carrying admin.
        $bParent = $this->seedOu(self::TENANT_B, null, 'b-parent');
        $this->assignRoleToOu(self::TENANT_B, $bParent, 'admin');
        $this->grantPermission('admin', 'users:delete');

        // Tenant A leaf OU whose parent_id references the Tenant-B parent id.
        $aLeaf = $this->seedOu(self::TENANT_A, $bParent, 'a-leaf');
        $this->addMembershipWithStatus($profile, self::TENANT_A, 'viewer', 'active', $aLeaf);

        $roles = $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A);
        self::assertSame(
            ['viewer'],
            $roles,
            'A cross-tenant parent pointer must not be followed: only the direct viewer role resolves.'
        );
        self::assertFalse(
            $this->checker->hasPermissionForProfile($profile, 'users:delete', self::TENANT_A),
            "Tenant B's OU-admin must never leak into Tenant A via a cross-tenant parent pointer."
        );
    }

    /**
     * getRoleIdsAssignedToOu() is tenant-scoped: an ou_role_assignment row for the
     * same ou_id but a DIFFERENT tenant_id must not contribute. Kills a mutant that
     * drops the tenant_id predicate on the assignment lookup.
     */
    public function testOuRoleAssignmentLookupIsTenantScoped(): void
    {
        $profile = $this->seedProfile('outenant@example.com');
        $ou = $this->seedOu(self::TENANT_A, null, 'shared-id-ou');

        // Assign 'admin' to this ou_id but under Tenant B (wrong tenant).
        $this->pdo->prepare(
            "INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
             VALUES (?, ?, ?, datetime('now'))"
        )->execute([self::TENANT_B, $ou, $this->roleId('admin')]);
        $this->grantPermission('admin', 'users:delete');

        $this->addMembershipWithStatus($profile, self::TENANT_A, 'viewer', 'active', $ou);

        self::assertSame(
            ['viewer'],
            $this->checker->getEffectiveRolesForProfile($profile, self::TENANT_A),
            'An ou_role_assignment under a different tenant_id must not contribute in Tenant A.'
        );
    }

    // =========================================================================
    // DelegationService.delegate() — subset-invariant gate boundaries
    // =========================================================================

    /**
     * A held permission is delegable → exactly one row written. Kills mutants that
     * invert the held/unheld branch or the one-row-per-permission loop.
     */
    public function testDelegateWritesExactlyOneRowPerHeldPermission(): void
    {
        $service = $this->makeDelegationService();
        $grantor = $this->seedProfile('g-holds@example.com');
        $grantee = $this->seedProfile('gr-holds@example.com');
        $this->addMembershipWithStatus($grantor, self::TENANT_A, 'admin', 'active');
        $this->addMembershipWithStatus($grantee, self::TENANT_A, 'viewer', 'active');
        $this->grantPermission('admin', 'users:read');
        $this->grantPermission('admin', 'users:delete');

        $ids = $service->delegate(
            self::TENANT_A,
            $grantor,
            DelegationRepository::GRANTEE_PROFILE,
            $grantee,
            ['users:read', 'users:delete'],
            null
        );

        self::assertCount(2, $ids, 'Two held permissions must yield exactly two delegation rows.');
        self::assertSame(
            2,
            $this->countDelegations($grantee),
            'Exactly two rows must be persisted for the grantee.'
        );
    }

    /**
     * An unheld permission is rejected and NOTHING is written. Kills a mutant that
     * flips the subset comparison so an unheld permission would pass.
     */
    public function testDelegateRejectsUnheldPermissionAndWritesNothing(): void
    {
        $service = $this->makeDelegationService();
        $grantor = $this->seedProfile('g-lacks@example.com');
        $grantee = $this->seedProfile('gr-lacks@example.com');
        $this->addMembershipWithStatus($grantor, self::TENANT_A, 'viewer', 'active');
        $this->addMembershipWithStatus($grantee, self::TENANT_A, 'viewer', 'active');
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
        } catch (PermissionNotDelegableException $e) {
            $threw = true;
        }

        self::assertTrue($threw, 'Delegating an unheld permission must throw.');
        self::assertSame(0, $this->countDelegations($grantee), 'A rejected delegation must write no rows.');
    }

    /**
     * A partial set — one held, one unheld — is rejected ATOMICALLY: the whole
     * call throws and NO rows (not even for the held permission) are written.
     * Kills a mutant that turns the `||` in the subset check into `&&` (which would
     * only reject a permission that is BOTH unheld AND unregistered), and a mutant
     * that would persist the held subset before validating the rest.
     */
    public function testDelegatePartialSetIsRejectedAtomically(): void
    {
        $service = $this->makeDelegationService();
        $grantor = $this->seedProfile('g-partial@example.com');
        $grantee = $this->seedProfile('gr-partial@example.com');
        $this->addMembershipWithStatus($grantor, self::TENANT_A, 'viewer', 'active');
        $this->addMembershipWithStatus($grantee, self::TENANT_A, 'viewer', 'active');
        $this->grantPermission('viewer', 'users:read'); // holds users:read, NOT users:delete

        $threw = false;
        try {
            $service->delegate(
                self::TENANT_A,
                $grantor,
                DelegationRepository::GRANTEE_PROFILE,
                $grantee,
                ['users:read', 'users:delete'], // one held, one unheld
                null
            );
        } catch (PermissionNotDelegableException $e) {
            $threw = true;
        }

        self::assertTrue($threw, 'A set containing any unheld permission must throw.');
        self::assertSame(
            0,
            $this->countDelegations($grantee),
            'No rows — not even the held users:read — may be written when the set is partially unheld.'
        );
    }

    /**
     * An UNREGISTERED permission is non-delegable even if it somehow appears in the
     * grantor's set — this exercises the registry-existence half of the OR in the
     * subset gate. Kills a mutant that drops `!$this->registry->exists(...)`.
     */
    public function testDelegateRejectsUnregisteredPermission(): void
    {
        $service = $this->makeDelegationService();
        $grantor = $this->seedProfile('g-unreg@example.com');
        $grantee = $this->seedProfile('gr-unreg@example.com');
        $this->addMembershipWithStatus($grantor, self::TENANT_A, 'admin', 'active');
        $this->addMembershipWithStatus($grantee, self::TENANT_A, 'viewer', 'active');

        // Grant the grantor's role a permission that is NOT in the registry, so it
        // is in the DB-resolved effective set but must still be non-delegable.
        $this->grantPermission('admin', 'ghost:permission');

        $threw = false;
        try {
            $service->delegate(
                self::TENANT_A,
                $grantor,
                DelegationRepository::GRANTEE_PROFILE,
                $grantee,
                ['ghost:permission'],
                null
            );
        } catch (PermissionNotDelegableException $e) {
            $threw = true;
        }

        self::assertTrue($threw, 'An unregistered permission must be non-delegable even if DB-held.');
        self::assertSame(0, $this->countDelegations($grantee));
    }

    // =========================================================================
    // DelegationRepository::livePermissionsForGrantee — match + tenant predicate
    // =========================================================================

    /**
     * livePermissionsForGrantee matches EXACTLY the (tenant, grantee_type,
     * grantee_id) triple. A row for a different grantee_id must not surface. Kills
     * a mutant that drops the grantee_id predicate.
     */
    public function testLivePermissionsMatchGranteeIdExactly(): void
    {
        $repo = new DelegationRepository($this->pdo);
        $grantor = $this->seedProfile('lp-grantor@example.com');
        $granteeA = $this->seedProfile('lp-a@example.com');
        $granteeB = $this->seedProfile('lp-b@example.com');

        $this->insertDelegation(self::TENANT_A, $grantor, 'profile', $granteeA, 'users:read');

        self::assertSame(
            ['users:read'],
            $repo->livePermissionsForGrantee(self::TENANT_A, DelegationRepository::GRANTEE_PROFILE, $granteeA, []),
            'The delegation must surface for its exact grantee.'
        );
        self::assertSame(
            [],
            $repo->livePermissionsForGrantee(self::TENANT_A, DelegationRepository::GRANTEE_PROFILE, $granteeB, []),
            'A different grantee_id must surface no permissions.'
        );
    }

    /**
     * livePermissionsForGrantee is tenant-scoped. Kills a mutant that drops the
     * tenant_id predicate.
     */
    public function testLivePermissionsAreTenantScoped(): void
    {
        $repo = new DelegationRepository($this->pdo);
        $grantor = $this->seedProfile('lpt-grantor@example.com');
        $grantee = $this->seedProfile('lpt-grantee@example.com');

        $this->insertDelegation(self::TENANT_A, $grantor, 'profile', $grantee, 'users:read');

        self::assertSame(
            ['users:read'],
            $repo->livePermissionsForGrantee(self::TENANT_A, DelegationRepository::GRANTEE_PROFILE, $grantee, []),
            'Visible in the owning tenant.'
        );
        self::assertSame(
            [],
            $repo->livePermissionsForGrantee(self::TENANT_B, DelegationRepository::GRANTEE_PROFILE, $grantee, []),
            'A delegation in Tenant A must be invisible to Tenant B.'
        );
    }

    /**
     * A revoked delegation (revoked_at set) is NOT live. Kills a mutant that drops
     * the `revoked_at IS NULL` predicate.
     */
    public function testRevokedDelegationIsNotLive(): void
    {
        $repo = new DelegationRepository($this->pdo);
        $grantor = $this->seedProfile('rv-grantor@example.com');
        $grantee = $this->seedProfile('rv-grantee@example.com');

        $this->pdo->prepare(
            "INSERT INTO permission_delegations
                 (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at, revoked_at)
             VALUES (?, ?, 'profile', ?, 'users:read', datetime('now'), datetime('now'))"
        )->execute([self::TENANT_A, $grantor, $grantee]);

        self::assertSame(
            [],
            $repo->livePermissionsForGrantee(self::TENANT_A, DelegationRepository::GRANTEE_PROFILE, $grantee, []),
            'A revoked delegation must not be live.'
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
     * Seed a membership with an explicit status (active | suspended | invited) and
     * optional OU.
     */
    private function addMembershipWithStatus(
        int $profileId,
        int $tenantId,
        string $roleName,
        string $status,
        ?int $ouId = null
    ): int {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))"
        )->execute([$profileId, $tenantId, $this->roleId($roleName), $ouId, $status]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Seed an OU and return its id. parent_id may reference any OU id (including a
     * cross-tenant one, for the tenant-predicate test).
     */
    private function seedOu(int $tenantId, ?int $parentId, string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at)
             VALUES (?, ?, ?, ?, datetime('now'))"
        )->execute([$tenantId, $parentId, $name, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function assignRoleToOu(int $tenantId, int $ouId, string $roleName): void
    {
        $this->pdo->prepare(
            "INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
             VALUES (?, ?, ?, datetime('now'))"
        )->execute([$tenantId, $ouId, $this->roleId($roleName)]);
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

    private function insertDelegation(int $tenantId, int $grantorProfileId, string $granteeType, int $granteeId, string $permission): void
    {
        $this->pdo->prepare(
            "INSERT INTO permission_delegations
                 (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))"
        )->execute([$tenantId, $grantorProfileId, $granteeType, $granteeId, $permission]);
    }

    private function countDelegations(int $granteeId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM permission_delegations WHERE grantee_id = ?'
        );
        $stmt->execute([$granteeId]);
        return (int) $stmt->fetchColumn();
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
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
            (1, 'admin',  '', NULL, datetime('now')),
            (2, 'user',   '', NULL, datetime('now')),
            (3, 'viewer', '', NULL, datetime('now'))");

        return $pdo;
    }

    private function makeDelegationService(): DelegationService
    {
        $boundingChecker = new RoleChecker($this->db, $this->registry());
        $delegationRepo  = new DelegationRepository($this->pdo);

        return new DelegationService(
            $delegationRepo,
            $boundingChecker,
            $this->registry()
        );
    }
}
