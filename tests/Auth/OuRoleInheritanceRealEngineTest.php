<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests pinning WC-54 flaw 2: OU role inheritance
 * wired into authorization.
 *
 * Pattern mirrors {@see RoleCheckerRealEngineTest}: the code under test runs
 * against a genuine SQL engine seeded with the REAL production OU schema
 * (`organizational_units(id, parent_id, tenant_id)`, `memberships.ou_id`,
 * `ou_role_assignments(ou_id, role_id, tenant_id)`), so real SQL semantics —
 * tenant predicates, parent-chain joins — are enforced rather than masked by
 * mocked PDO. Identity is on profiles + memberships; the `users` table was
 * retired by the identity hard cutover (migration 042).
 *
 * The central regression these tests pin: on main, permission resolution
 * considered ONLY the member's direct `memberships.role_id` (+ role hierarchy)
 * and IGNORED roles assigned via the member's organizational unit, so an OU role
 * assignment granted nothing. After the fix, the effective role set unions the
 * direct role with every tenant-scoped OU/ancestor-OU role.
 *
 * Coverage:
 *  - direct role still grants (no regression);
 *  - an OU-assigned role grants a permission the user's direct role lacks;
 *  - the OU PARENT CHAIN is walked (a child-OU user inherits an ancestor OU's role);
 *  - tenant isolation (an OU role in tenant A never leaks to tenant B);
 *  - cache correctness across a tenant switch and after clearCache().
 */
final class OuRoleInheritanceRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    // ==================== No regression: direct role ====================

    public function testDirectRoleStillGrantsPermission(): void
    {
        // editor role (3) holds posts:write directly; user has it as their direct
        // role and belongs to NO OU.
        $this->grant('editor', 'posts:write');
        $userId = $this->seedUser('editor@a.example', 'editor', self::TENANT_A, ouId: null);

        $this->assertTrue(
            $this->checker()->hasPermissionForProfile($userId, 'posts:write', self::TENANT_A),
            'A direct role grant must still satisfy hasPermission().'
        );
        $this->assertTrue(
            $this->checker()->hasRoleForProfile($userId, 'editor', self::TENANT_A),
            'The direct role must be reported by hasRole().'
        );
    }

    // ==================== The fix: OU role grants ====================

    public function testOuAssignedRoleGrantsPermissionTheDirectRoleLacks(): void
    {
        // The user's DIRECT role is plain `user` with no posts:write grant.
        // Their OU has `editor` assigned, and editor holds posts:write.
        $this->grant('editor', 'posts:write');
        $ouId = $this->seedOu('engineering', self::TENANT_A, parentId: null);
        $this->assignRoleToOu($ouId, 'editor', self::TENANT_A);
        $userId = $this->seedUser('member@a.example', 'user', self::TENANT_A, ouId: $ouId);

        // PRE-FIX BEHAVIOUR PROOF: with OU roles ignored the direct `user` role has
        // no posts:write, so this would be false on main. The assertion documents
        // exactly the gap WC-54 flaw 2 closes.
        $this->assertTrue(
            $this->checker()->hasPermissionForProfile($userId, 'posts:write', self::TENANT_A),
            'A role assigned to the user\'s OU must grant its permissions (OU inheritance).'
        );
        $this->assertTrue(
            $this->checker()->hasRoleForProfile($userId, 'editor', self::TENANT_A),
            'hasRole() must include the OU-assigned role in the effective set.'
        );
        $this->assertContains(
            'editor',
            $this->checker()->getEffectiveRolesForProfile($userId, self::TENANT_A),
            'Effective roles must union direct + OU roles.'
        );
        $this->assertContains(
            'user',
            $this->checker()->getEffectiveRolesForProfile($userId, self::TENANT_A),
            'Effective roles must retain the direct role too.'
        );
    }

    public function testUserWithoutOuRoleIsStillDenied(): void
    {
        // OU exists but has NO role assignment that grants posts:write; the user's
        // direct `user` role lacks it too. Must be denied — proving the OU path is
        // an additive grant, not a blanket allow.
        $ouId = $this->seedOu('sales', self::TENANT_A, parentId: null);
        $userId = $this->seedUser('member@a.example', 'user', self::TENANT_A, ouId: $ouId);

        $this->assertFalse(
            $this->checker()->hasPermissionForProfile($userId, 'posts:write', self::TENANT_A),
            'With no granting role (direct or via OU) the user must be denied.'
        );
    }

    // ==================== OU parent-chain inheritance ====================

    public function testOuParentChainIsInherited(): void
    {
        // Hierarchy: root(engineering) -> child(backend). `editor` (holds
        // posts:write) is assigned to the ROOT OU only. A user in the CHILD OU must
        // still inherit it by walking the parent chain up.
        $this->grant('editor', 'posts:write');
        $rootOu = $this->seedOu('engineering', self::TENANT_A, parentId: null);
        $childOu = $this->seedOu('backend', self::TENANT_A, parentId: $rootOu);
        $this->assignRoleToOu($rootOu, 'editor', self::TENANT_A);
        $userId = $this->seedUser('child@a.example', 'user', self::TENANT_A, ouId: $childOu);

        $this->assertTrue(
            $this->checker()->hasPermissionForProfile($userId, 'posts:write', self::TENANT_A),
            'A user in a child OU must inherit roles assigned to an ancestor OU.'
        );
    }

    public function testCyclicOuChainTerminatesGracefully(): void
    {
        // A corrupted parent chain (A -> B -> A). Resolution must terminate, not
        // hang, and still return the grants it collected before the cycle closed.
        $this->grant('editor', 'posts:write');
        $ouA = $this->seedOu('a', self::TENANT_A, parentId: null);
        $ouB = $this->seedOu('b', self::TENANT_A, parentId: $ouA);
        // Close the cycle: make A's parent B.
        $this->pdo->prepare('UPDATE organizational_units SET parent_id = ? WHERE id = ?')
            ->execute([$ouB, $ouA]);
        $this->assignRoleToOu($ouA, 'editor', self::TENANT_A);
        $userId = $this->seedUser('cyc@a.example', 'user', self::TENANT_A, ouId: $ouB);

        $this->assertTrue(
            $this->checker()->hasPermissionForProfile($userId, 'posts:write', self::TENANT_A),
            'A cyclic OU chain must terminate and still surface collected grants.'
        );
    }

    // ==================== Tenant isolation ====================

    public function testOuRoleDoesNotLeakAcrossTenants(): void
    {
        // Same OU id space, but the granting assignment lives in tenant B. A
        // tenant-A user in that OU id must NOT inherit it, because the OU-role
        // lookup is filtered by tenant. (We seed the OU + user in tenant A but the
        // assignment row carries tenant_id = B.)
        $this->grant('admin', 'tenants:delete');
        $ouId = $this->seedOu('shared', self::TENANT_A, parentId: null);

        // A cross-tenant assignment row (as if tenant B assigned admin to the same
        // OU id). The seedOu above gave us an id; create the leaking row under B.
        $this->assignRoleToOuRaw($ouId, 'admin', self::TENANT_B);

        $userId = $this->seedUser('a@a.example', 'user', self::TENANT_A, ouId: $ouId);

        $this->assertFalse(
            $this->checker()->hasPermissionForProfile($userId, 'tenants:delete', self::TENANT_A),
            'An OU role assigned under another tenant must never grant in this tenant.'
        );
        $this->assertNotContains(
            'admin',
            $this->checker()->getEffectiveRolesForProfile($userId, self::TENANT_A),
            'Cross-tenant OU roles must be filtered out of the effective set.'
        );
    }

    public function testUserResolvedUnderWrongTenantGainsNoOuRoles(): void
    {
        // The user and OU live in tenant A. Resolving the SAME user under tenant B
        // yields no OU (the tenant predicate on users excludes the row), so no OU
        // roles — only what the direct role grants (here: nothing for posts:write).
        $this->grant('editor', 'posts:write');
        $ouId = $this->seedOu('engineering', self::TENANT_A, parentId: null);
        $this->assignRoleToOu($ouId, 'editor', self::TENANT_A);
        $userId = $this->seedUser('member@a.example', 'user', self::TENANT_A, ouId: $ouId);

        $this->assertFalse(
            $this->checker()->hasPermissionForProfile($userId, 'posts:write', self::TENANT_B),
            'Resolving a user under the wrong tenant must not surface their OU roles.'
        );
    }

    // ==================== Cache correctness ====================

    public function testCacheIsTenantAwareAcrossASwitch(): void
    {
        // Two users in the SAME OU id but different tenants, different OU roles.
        // Resolving user A (tenant A) then user B (tenant B) back-to-back must not
        // let A's cached set bleed into B.
        $this->grant('editor', 'posts:write');   // tenant A's OU role
        $this->grant('admin', 'tenants:delete');  // tenant B's OU role

        $ouA = $this->seedOu('ou-a', self::TENANT_A, parentId: null);
        $this->assignRoleToOu($ouA, 'editor', self::TENANT_A);
        $userA = $this->seedUser('a@a.example', 'user', self::TENANT_A, ouId: $ouA);

        $ouB = $this->seedOu('ou-b', self::TENANT_B, parentId: null);
        $this->assignRoleToOu($ouB, 'admin', self::TENANT_B);
        $userB = $this->seedUser('b@b.example', 'user', self::TENANT_B, ouId: $ouB);

        $checker = $this->checker();

        // Warm A's cache, then resolve B.
        $this->assertTrue($checker->hasPermissionForProfile($userA, 'posts:write', self::TENANT_A));
        $this->assertFalse(
            $checker->hasPermissionForProfile($userA, 'tenants:delete', self::TENANT_A),
            'User A must not have tenant B\'s OU grant.'
        );

        $this->assertTrue($checker->hasPermissionForProfile($userB, 'tenants:delete', self::TENANT_B));
        $this->assertFalse(
            $checker->hasPermissionForProfile($userB, 'posts:write', self::TENANT_B),
            'User B (different tenant key) must not be served user A\'s cached set.'
        );
    }

    public function testClearCacheReflectsNewOuAssignment(): void
    {
        // Resolve once with NO granting OU role (denied), then assign the role and
        // clearCache(): the next resolution must see the new grant.
        $this->grant('editor', 'posts:write');
        $ouId = $this->seedOu('engineering', self::TENANT_A, parentId: null);
        $userId = $this->seedUser('member@a.example', 'user', self::TENANT_A, ouId: $ouId);

        $checker = $this->checker();
        $this->assertFalse(
            $checker->hasPermissionForProfile($userId, 'posts:write', self::TENANT_A),
            'Before the OU assignment the user must be denied.'
        );

        $this->assignRoleToOu($ouId, 'editor', self::TENANT_A);
        RoleChecker::clearCache();

        $this->assertTrue(
            $checker->hasPermissionForProfile($userId, 'posts:write', self::TENANT_A),
            'After clearCache() the new OU assignment must take effect.'
        );
    }

    // ==================== Helpers ====================

    private function checker(): RoleChecker
    {
        $registry = new PermissionRegistry();
        // Register the permissions these tests grant so the step-1 registry gate
        // in hasPermission() never short-circuits them (an unregistered permission
        // can never be granted, by design).
        $registry->register('test', ['posts:write', 'tenants:delete']);

        return new RoleChecker($this->db, $registry);
    }

    /**
     * Grant a permission to a role by name through the real junction schema.
     */
    private function grant(string $roleName, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
            ->execute([$permission]);
        $roleId = $this->roleId($roleName);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$roleId, $permissionId]);
    }

    private function seedOu(string $name, int $tenantId, ?int $parentId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$tenantId, $parentId, $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Assign a role (by name) to an OU within a tenant, validating the OU belongs
     * to that tenant — the normal, well-formed path.
     */
    private function assignRoleToOu(int $ouId, string $roleName, int $tenantId): void
    {
        $this->assignRoleToOuRaw($ouId, $roleName, $tenantId);
    }

    /**
     * Insert an ou_role_assignments row with an explicit tenant id, allowing the
     * tenant on the assignment to differ from the OU's owning tenant (used to
     * model a cross-tenant leak attempt in the isolation test).
     */
    private function assignRoleToOuRaw(int $ouId, string $roleName, int $tenantId): void
    {
        $this->pdo->prepare(
            'INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([$tenantId, $ouId, $this->roleId($roleName)]);
    }

    /**
     * Seed a profile + primary verified email + one membership (carrying the
     * role and optional OU) in the given tenant; returns the profile id — the
     * canonical caller identity RoleChecker resolves against (ADR 0005).
     */
    private function seedUser(string $email, string $roleName, int $tenantId, ?int $ouId): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', 0, 0, 0, datetime('now'), datetime('now'))"
        )->execute([explode('@', $email)[0]]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, 1, 1, datetime('now'))"
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, $tenantId, $this->roleId($roleName), $ouId]);

        return $profileId;
    }

    private function roleId(string $roleName): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$roleName]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Wrap a live SQLite PDO in the production {@see Database} so the real
     * RoleChecker runs unmodified against it; the same handle is reused for the
     * whole test (no recycle/ping that would drop the in-memory schema).
     */
    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * Build an in-memory SQLite schema mirroring the OU migrations (007/008/009)
     * plus the roles/permissions/users base (001/002) and role hierarchy (017).
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        // Seed the tenants referenced by seeded users' tenant_id FK (real PG
        // enforces the constraint; SQLite does not). TENANT_A=1, TENANT_B=2.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        // Migrations seed admin(1) and user(2); seed the extra roles used by these tests.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, created_at) VALUES
            (3, 'editor', NOW()), (4, 'viewer', NOW())");
        return $pdo;
    }
}
