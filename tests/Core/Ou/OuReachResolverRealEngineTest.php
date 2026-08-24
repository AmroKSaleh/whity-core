<?php

declare(strict_types=1);

namespace Tests\Core\Ou;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;

/**
 * Real-engine tests for {@see OuReachResolver} — the narrowing half of
 * RBAC-scoped access to document templates and design blocks.
 *
 * The API-level consequences are asserted in
 * {@see \Tests\Api\DocumentTemplatesApiHandlerRealEngineTest}. These cover the
 * resolver's own edges, which that test cannot reach through HTTP: the tenant
 * boundary, several memberships at once, the everyone-grant exclusion, and the
 * empty answer for somebody with no standing at all.
 */
final class OuReachResolverRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    // The tree: Faculty (10) → Civil (11), Materials (12); Civil → Structures (13).
    private const FACULTY = 10;
    private const CIVIL = 11;
    private const MATERIALS = 12;
    private const STRUCTURES = 13;

    private const DEAN_SECRETARY = 100; // member of the Faculty
    private const DEPT_SECRETARY = 101; // member of Civil
    private const UNPLACED = 102;       // member of no unit
    private const DUAL = 103;           // member of Civil AND Materials
    private const SUSPENDED = 104;      // suspended member of Materials

    private const ROLE = 200;

    private PDO $pdo;
    private OuReachResolver $resolver;
    private ResourceRoleAssignmentRepository $grants;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->grants = new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry());
        $this->resolver = new OuReachResolver($this->pdo, $this->grants);
    }

    /** Standing at a unit reaches that unit and everything beneath it. */
    public function testReachIsTheSubtreeBeneathTheCallersOwnUnit(): void
    {
        self::assertSame(
            [self::FACULTY, self::CIVIL, self::MATERIALS, self::STRUCTURES],
            $this->reach(self::DEAN_SECRETARY),
            'the faculty and every unit under it'
        );
        self::assertSame(
            [self::CIVIL, self::STRUCTURES],
            $this->reach(self::DEPT_SECRETARY),
            'one department and what is under it — never the faculty above it'
        );
    }

    /**
     * The asymmetry the requirement asks for, stated as a set relation rather
     * than as two literal lists.
     */
    public function testTheHigherStandingIsAStrictSuperset(): void
    {
        $dean = $this->reach(self::DEAN_SECRETARY);
        $dept = $this->reach(self::DEPT_SECRETARY);

        self::assertSame($dept, array_values(array_intersect($dean, $dept)));
        self::assertGreaterThan(count($dept), count($dean));
    }

    /** Every active membership contributes, not only the primary one. */
    public function testEveryActiveMembershipContributesItsSubtree(): void
    {
        self::assertSame(
            [self::CIVIL, self::MATERIALS, self::STRUCTURES],
            $this->reach(self::DUAL),
            'both units and both subtrees, minus the faculty that is above them'
        );
    }

    /** A suspended membership is not standing. */
    public function testASuspendedMembershipConfersNoStanding(): void
    {
        self::assertSame([], $this->reach(self::SUSPENDED));
    }

    /** Nobody-in-particular reaches nothing: placed rows fail closed. */
    public function testAProfileWithNoStandingReachesNothing(): void
    {
        self::assertSame([], $this->reach(self::UNPLACED));
        self::assertFalse($this->resolver->reachFor(self::TENANT, self::UNPLACED)(self::FACULTY));
    }

    /**
     * A profile-addressed grant adds a unit with no membership there — the fact
     * about a PERSON that the tree cannot express.
     */
    public function testAProfileGrantAddsAUnitAndItsSubtree(): void
    {
        $this->grants->grant(
            self::TENANT,
            ResourceTypeRegistry::TYPE_OU,
            self::MATERIALS,
            self::ROLE,
            self::DEPT_SECRETARY
        );

        self::assertSame(
            [self::CIVIL, self::MATERIALS, self::STRUCTURES],
            $this->reach(self::DEPT_SECRETARY)
        );
    }

    /**
     * An everyone-grant (`profile_id IS NULL`) is not access.
     *
     * Migration 088 defines it as "everyone WITH ACCESS to this resource gets
     * role R here". Counting it as standing would give every profile in the
     * tenant authority at any unit carrying one.
     */
    public function testAnEveryoneGrantConfersNoStanding(): void
    {
        $this->grants->grant(self::TENANT, ResourceTypeRegistry::TYPE_OU, self::MATERIALS, self::ROLE, null);

        self::assertSame(
            [self::CIVIL, self::STRUCTURES],
            $this->reach(self::DEPT_SECRETARY),
            'unchanged: an everyone-grant modifies what reachable people may do'
        );
    }

    /** Reach cannot cross the tenant boundary, whatever is asked of it. */
    public function testReachIsTenantScoped(): void
    {
        self::assertSame(
            [],
            $this->reach(self::DEAN_SECRETARY, self::OTHER_TENANT),
            'the same profile has no standing in a tenant it is not a member of'
        );
        self::assertFalse(
            $this->resolver->existsInTenant(self::TENANT, 900),
            "another tenant's unit is not a placement target here"
        );
        self::assertTrue($this->resolver->existsInTenant(self::TENANT, self::FACULTY));
    }

    /** The closure is the same answer, pre-bound. */
    public function testTheClosureAgreesWithTheList(): void
    {
        $reaches = $this->resolver->reachFor(self::TENANT, self::DEPT_SECRETARY);

        self::assertTrue($reaches(self::CIVIL));
        self::assertTrue($reaches(self::STRUCTURES));
        self::assertFalse($reaches(self::FACULTY));
        self::assertFalse($reaches(self::MATERIALS));
    }

    /** @return list<int> sorted, so assertions are about the set */
    private function reach(int $profileId, int $tenantId = self::TENANT): array
    {
        $ids = $this->resolver->reachableOuIds($tenantId, $profileId);
        sort($ids);

        return array_values($ids);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");

        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at)
                    VALUES (200, 'secretary', '', 1, datetime('now'))");

        $pdo->exec("INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
            (10, 1, NULL, 'Faculty of Engineering', 'faculty', datetime('now')),
            (11, 1, 10,   'Civil Engineering',      'civil',   datetime('now')),
            (12, 1, 10,   'Materials Science',      'mats',    datetime('now')),
            (13, 1, 11,   'Structures',             'struct',  datetime('now')),
            (900, 2, NULL, 'Other Faculty',         'other',   datetime('now'))");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (100, 'dean sec',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (101, 'dept sec',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (102, 'unplaced',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (103, 'dual',      'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (104, 'suspended', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        // `is_primary` is explicit on the second of the dual memberships: migration
        // 094's partial unique index admits only ONE primary row per (profile,
        // tenant), and reach deliberately reads them all anyway.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (9001, 100, 1, 200, 10,   true,  'active',    datetime('now')),
                (9002, 101, 1, 200, 11,   true,  'active',    datetime('now')),
                (9003, 102, 1, 200, NULL, true,  'active',    datetime('now')),
                (9004, 103, 1, 200, 11,   true,  'active',    datetime('now')),
                (9005, 103, 1, 200, 12,   false, 'active',    datetime('now')),
                (9006, 104, 1, 200, 12,   true,  'suspended', datetime('now'))
        ");

        return $pdo;
    }
}
