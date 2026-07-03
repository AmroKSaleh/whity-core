<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\RoleChecker;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;

/**
 * WC-d115b62c: Cross-tenant isolation tests for the identity tables
 *              (profile_emails, tenant_email_domains) + membership coverage
 *              gaps from steps 5/6 + #181 regression via the RoleChecker path.
 *
 * WHAT THIS SUITE ADDS vs. steps 5/6 (CrossTenantRejectionRealEngineTest):
 * ─────────────────────────────────────────────────────────────────────────
 *  - profile_emails: steps 5/6 do NOT directly cover this table as a
 *    cross-isolation subject — they only seed rows into it as JOIN fixtures
 *    for the OU-members tests. This suite adds:
 *      • UNIQUE(email) structural constraint — a second INSERT with the same
 *        email raises a PDOException, making two profiles with the same email
 *        physically impossible at the DB engine level (the core fix for #181).
 *      • Cross-profile isolation via findByProfileId() / findPrimaryForProfile():
 *        repo calls for profile A never return profile B's emails.
 *      • findByEmail() global-unique semantic: returns the owning profile_id so
 *        the login flow always resolves to a single unambiguous profile.
 *      • A tenant-scoped membership+profile_emails JOIN: only the profile whose
 *        membership is in tenant A is reachable from a tenant-A-scoped query,
 *        even though profile_emails itself has no tenant_id.
 *
 *  - tenant_email_domains: steps 5/6 cover listForTenant, findById, delete.
 *    This suite adds:
 *      • findByDomain() cross-tenant isolation: Tenant A cannot find Tenant B's
 *        domain row by looking up that domain name with Tenant A's id.
 *      • findTenantsByDomain() intentional no-scope: the method is unscoped BY
 *        DESIGN (policy service needs all tenants claiming a domain); we prove
 *        it returns the right row for the right domain and returns nothing for
 *        an unregistered domain, and that each row carries the correct tenant_id.
 *
 *  - memberships: steps 5/6 cover findById, listForTenant, suspend, delete,
 *    findForProfile. This suite adds:
 *      • accept() cross-tenant isolation: Tenant A cannot accept Tenant B's
 *        invitation and the status stays 'invited'.
 *      • reactivate() cross-tenant isolation: Tenant A cannot reactivate Tenant B's
 *        suspended row; the status stays 'suspended'.
 *      • findByProfile() cross-tenant isolation: a profile's membership in Tenant B
 *        is invisible to a Tenant-A-scoped findByProfile() call.
 *
 *  - profiles (global table): steps 5/6 do not test profiles directly as an
 *    isolation subject because it has no tenant_id. This suite asserts the
 *    correct model: a tenant-scoped memberships query can only reach profiles
 *    that have a membership in the acting tenant — a profile that belongs only
 *    to Tenant B does not appear in a Tenant-A-scoped memberships query.
 *
 *  - #181 REGRESSION (complementary): ProfileLoginRealEngineTest already covers
 *    the core cross-tenant duplicate-email cross-login scenario via the login
 *    handler. This suite adds the complementary RoleChecker path: a profile with
 *    NO membership in Tenant B cannot have permissions resolved for Tenant B,
 *    proving that two profiles with no shared membership cannot cross-access.
 *
 * Does NOT duplicate anything already in CrossTenantRejectionRealEngineTest:
 *   • memberships listForTenant, findById, suspend, delete, findForProfile
 *   • tenant_email_domains listForTenant, findById, delete
 *   • login flow cross-login (#181 core scenario in ProfileLoginRealEngineTest)
 *   • ActiveTenantMembershipGuard happy/sad paths (ProfileLoginRealEngineTest)
 *
 * Runs on SQLite locally and real PostgreSQL in CI (PHPUNIT_PG_DSN).
 */
final class IdentityTablesCrossTenantIsolationTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    /**
     * Profile ids — resolved from lastInsertId() so these are robust to
     * migration 036 seeding a system profile as id=1 before our fixtures.
     */
    private int $profileAliceId;
    private int $profileBobId;

    private PDO $pdo;
    private ProfileEmailRepository $emailRepo;
    private MembershipRepository $membershipRepo;
    private TenantEmailDomainsRepository $domainRepo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = SchemaFromMigrations::make(true);
        $this->emailRepo      = new ProfileEmailRepository($this->pdo);
        $this->membershipRepo = new MembershipRepository($this->pdo);
        $this->domainRepo     = new TenantEmailDomainsRepository($this->pdo);

        // Tenants
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))"
        );

        // One global role (needed for memberships FK)
        $this->pdo->exec(
            "INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'member')"
        );

        // Alice: profile in Tenant A only
        $this->pdo->exec(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Alice', '\$2y\$10\$fakehash1', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->profileAliceId = (int) $this->pdo->lastInsertId();

        // Bob: profile in Tenant B only
        $this->pdo->exec(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Bob', '\$2y\$10\$fakehash2', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->profileBobId = (int) $this->pdo->lastInsertId();

        // Alice has a verified primary email
        $this->emailRepo->insert($this->profileAliceId, 'alice@corp.com', verified: true, isPrimary: true);

        // Bob has a verified primary email on a DIFFERENT address
        $this->emailRepo->insert($this->profileBobId, 'bob@corp.com', verified: true, isPrimary: true);

        // Memberships: Alice in Tenant A, Bob in Tenant B
        $this->membershipRepo->insert($this->profileAliceId, self::TENANT_A, 1);
        $this->membershipRepo->insert($this->profileBobId, self::TENANT_B, 1);

        // Tenant email domain registrations
        $this->domainRepo->insert(self::TENANT_A, 'acme.com', 1, true);
        $this->domainRepo->insert(self::TENANT_B, 'example.org', 1, true);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ============================================================
    // profile_emails — UNIQUE(email) structural constraint
    // ============================================================

    /**
     * #181 REGRESSION (structural): profile_emails has UNIQUE(email), so
     * inserting the same email address a second time (for any profile, in any
     * tenant context) raises a PDOException at the database engine level.
     *
     * This proves that two profiles sharing the same email is PHYSICALLY
     * IMPOSSIBLE — not just logically prevented — on both SQLite and PostgreSQL.
     * ProfileLoginRealEngineTest already proves the login handler always
     * resolves a unique profile; this test proves the schema enforces it.
     */
    public function testProfileEmailsUniqueConstraintPreventsDuplicateEmail(): void
    {
        $this->expectException(PDOException::class);

        // alice@corp.com is already inserted for Alice in setUp().
        // Attempting to register the same address for Bob must fail at the DB layer.
        $this->emailRepo->insert($this->profileBobId, 'alice@corp.com', verified: true);
    }

    /**
     * #181 REGRESSION (structural, positive control): two DIFFERENT profiles
     * CAN each hold their own distinct email — the UNIQUE constraint only
     * prevents the same email appearing twice, not multiple profiles having emails.
     */
    public function testTwoProfilesCanHaveDistinctEmails(): void
    {
        // alice@corp.com and bob@corp.com were inserted in setUp() — no exception.
        $aliceRow = $this->emailRepo->findByEmail('alice@corp.com');
        $bobRow   = $this->emailRepo->findByEmail('bob@corp.com');

        self::assertIsArray($aliceRow, 'Alice must have a profile_email row.');
        self::assertIsArray($bobRow,   'Bob must have a profile_email row.');
        self::assertSame($this->profileAliceId, $aliceRow['profile_id']);
        self::assertSame($this->profileBobId,   $bobRow['profile_id']);
    }

    // ============================================================
    // profile_emails — global-unique lookup semantic
    // ============================================================

    /**
     * findByEmail() resolves alice@corp.com to Alice's profile_id, never to Bob's.
     * This is the login-resolution semantic: the UNIQUE(email) constraint
     * guarantees at most one row; the returned profile_id is unambiguous.
     */
    public function testFindByEmailResolvesUnambiguouslyToOwningProfile(): void
    {
        $row = $this->emailRepo->findByEmail('alice@corp.com');

        self::assertIsArray($row, 'findByEmail must return Alice\'s row.');
        self::assertSame(
            $this->profileAliceId,
            $row['profile_id'],
            '#181: email lookup must always resolve to the single owning profile.'
        );
        self::assertNotSame(
            $this->profileBobId,
            $row['profile_id'],
            '#181: alice@corp.com must never resolve to Bob\'s profile_id.'
        );
    }

    /**
     * findByEmail() for Bob's email resolves to Bob only, never to Alice.
     */
    public function testFindByEmailForBobResolvesToBobOnly(): void
    {
        $row = $this->emailRepo->findByEmail('bob@corp.com');

        self::assertIsArray($row);
        self::assertSame($this->profileBobId, $row['profile_id']);
        self::assertNotSame($this->profileAliceId, $row['profile_id']);
    }

    // ============================================================
    // profile_emails — cross-profile isolation (no tenant_id)
    // ============================================================

    /**
     * findByProfileId() for Alice returns only Alice's email(s) — Bob's email
     * never appears in the result even though profile_emails has no tenant_id.
     * Isolation here is by profile_id (the table's natural partition key), not
     * by tenant_id.
     */
    public function testFindByProfileIdReturnsOnlyEmailsForThatProfile(): void
    {
        $aliceEmails = $this->emailRepo->findByProfileId($this->profileAliceId);
        $bobEmails   = $this->emailRepo->findByProfileId($this->profileBobId);

        $aliceAddresses = array_column($aliceEmails, 'email');
        $bobAddresses   = array_column($bobEmails, 'email');

        self::assertContains('alice@corp.com', $aliceAddresses);
        self::assertNotContains(
            'bob@corp.com',
            $aliceAddresses,
            'Bob\'s email must never appear when querying Alice\'s profile emails.'
        );
        self::assertContains('bob@corp.com', $bobAddresses);
        self::assertNotContains(
            'alice@corp.com',
            $bobAddresses,
            'Alice\'s email must never appear when querying Bob\'s profile emails.'
        );
        foreach ($aliceEmails as $row) {
            self::assertSame($this->profileAliceId, $row['profile_id']);
        }
        foreach ($bobEmails as $row) {
            self::assertSame($this->profileBobId, $row['profile_id']);
        }
    }

    /**
     * findPrimaryForProfile() for Alice returns only Alice's primary email.
     * Calling it with Bob's profile_id returns Bob's primary email, never Alice's.
     */
    public function testFindPrimaryForProfileReturnsPrimaryForCorrectProfileOnly(): void
    {
        $alicePrimary = $this->emailRepo->findPrimaryForProfile($this->profileAliceId);
        $bobPrimary   = $this->emailRepo->findPrimaryForProfile($this->profileBobId);

        self::assertIsArray($alicePrimary);
        self::assertSame('alice@corp.com', $alicePrimary['email']);
        self::assertSame($this->profileAliceId, $alicePrimary['profile_id']);

        self::assertIsArray($bobPrimary);
        self::assertSame('bob@corp.com', $bobPrimary['email']);
        self::assertSame($this->profileBobId, $bobPrimary['profile_id']);

        // Crucially: Alice's primary is never returned when querying Bob's profile.
        self::assertNotSame(
            $this->profileAliceId,
            $bobPrimary['profile_id'],
            'findPrimaryForProfile(bob) must never return Alice\'s email row.'
        );
    }

    // ============================================================
    // profile_emails + memberships JOIN: tenant-scoped resolution
    // ============================================================

    /**
     * A tenant-scoped JOIN of memberships + profile_emails — the pattern used
     * by the OU-members endpoint — must only surface profiles whose membership
     * is in the acting tenant.
     *
     * Bob has a membership in Tenant B but NOT Tenant A. Even though
     * profile_emails has no tenant_id, a JOIN via memberships.tenant_id = 1
     * must return zero rows for Bob because no membership row links him to
     * Tenant A.
     *
     * This proves that tenant isolation of profile data is correctly enforced
     * through the memberships predicate, not by a tenant_id on the profile
     * table itself.
     */
    public function testTenantScopedMembershipsJoinDoesNotLeakCrossTenantProfileEmails(): void
    {
        // Tenant-A-scoped query: only profiles with an active Tenant-A membership.
        $stmt = $this->pdo->prepare(
            "SELECT pe.email, pe.profile_id
             FROM memberships m
             JOIN profile_emails pe ON pe.profile_id = m.profile_id
             WHERE m.tenant_id = ? AND m.status = 'active'"
        );
        $stmt->execute([self::TENANT_A]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $emails     = array_column($rows, 'email');
        $profileIds = array_column($rows, 'profile_id');

        self::assertContains('alice@corp.com', $emails, 'Alice is in Tenant A — her email must appear.');
        self::assertNotContains(
            'bob@corp.com',
            $emails,
            'Bob is in Tenant B only — his email must not appear in a Tenant-A-scoped query.'
        );
        foreach ($profileIds as $pid) {
            self::assertSame(
                (string) $this->profileAliceId,
                (string) $pid,
                'Only Alice\'s profile_id may appear in the Tenant-A-scoped result.'
            );
        }
    }

    /**
     * (Positive control) The same JOIN for Tenant B returns Bob's email only.
     */
    public function testTenantScopedMembershipsJoinReturnsOwnTenantProfileEmailsOnly(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT pe.email, pe.profile_id
             FROM memberships m
             JOIN profile_emails pe ON pe.profile_id = m.profile_id
             WHERE m.tenant_id = ? AND m.status = 'active'"
        );
        $stmt->execute([self::TENANT_B]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $emails = array_column($rows, 'email');

        self::assertContains('bob@corp.com', $emails);
        self::assertNotContains(
            'alice@corp.com',
            $emails,
            'Alice is in Tenant A only — her email must not appear in a Tenant-B-scoped query.'
        );
    }

    // ============================================================
    // profiles — global table: tenant isolation via memberships
    // ============================================================

    /**
     * profiles has no tenant_id (it is a GLOBAL identity table). Cross-tenant
     * isolation of profile data is enforced ENTIRELY by the memberships table.
     *
     * Prove the model: a SELECT on profiles alone returns all rows regardless
     * of tenant context — there is nothing to filter on. The isolation is at
     * the memberships level (as shown in the JOIN tests above).
     *
     * This is an explicit documentation test: any reviewer reading this test
     * knows profiles does not carry tenant_id and that this is BY DESIGN.
     */
    public function testProfilesTableHasNoTenantIdColumn(): void
    {
        // PRAGMA table_info returns one row per column. We check that no column
        // is named 'tenant_id'. SchemaFromMigrations translates this to
        // information_schema.columns on PostgreSQL.
        $stmt = $this->pdo->query("PRAGMA table_info(profiles)");
        self::assertNotFalse($stmt);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_column($columns, 'name');

        self::assertNotContains(
            'tenant_id',
            $columnNames,
            'profiles is a GLOBAL identity table — it intentionally has no tenant_id column. ' .
            'Tenant isolation is enforced via the memberships table.'
        );
    }

    /**
     * Because profiles has no tenant_id, a bare SELECT on the table returns
     * BOTH Alice and Bob. This is expected and correct — callers must go
     * through the memberships predicate to achieve tenant isolation.
     */
    public function testProfilesTableReturnsAllProfilesRegardlessOfTenant(): void
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM profiles WHERE display_name IN (\'Alice\', \'Bob\')');
        self::assertNotFalse($stmt);
        $count = (int) $stmt->fetchColumn();

        // Both Alice and Bob profiles exist — the global table is unfiltered.
        self::assertGreaterThanOrEqual(
            2,
            $count,
            'profiles is global: both Alice and Bob must be visible without a tenant predicate.'
        );
    }

    // ============================================================
    // memberships — accept() cross-tenant isolation
    // ============================================================

    /**
     * Tenant A cannot accept Tenant B's invitation — the accept() call touches
     * zero rows and Carol's membership remains 'invited'.
     *
     * NOT covered in steps 5/6 (which only test suspend() and delete()).
     *
     * We use a third profile (Carol) to avoid UNIQUE(profile_id, tenant_id)
     * conflicts with the active memberships seeded in setUp for Alice and Bob.
     */
    public function testTenantCannotAcceptForeignInvitedMembershipAndStatusStaysInvited(): void
    {
        // Create Carol and invite her into Tenant B (distinct from Bob who is active).
        $carolId = $this->seedAuxProfile('Carol');
        $inviteId = $this->membershipRepo->invite($carolId, self::TENANT_B, 1);

        // Tenant A tries to accept Tenant B's invitation row.
        $affected = $this->membershipRepo->accept($inviteId, self::TENANT_A);

        self::assertSame(0, $affected, 'Cross-tenant accept() must touch zero rows.');

        $row = $this->membershipRepo->findById($inviteId, self::TENANT_B);
        self::assertIsArray($row);
        self::assertSame(
            MembershipRepository::STATUS_INVITED,
            $row['status'],
            'Carol\'s invited membership must remain \'invited\' after the rejected cross-tenant accept.'
        );
    }

    /**
     * (Positive control) Tenant B can accept its own invitation — own-tenant
     * accept() changes the status to 'active'.
     */
    public function testTenantCanAcceptOwnInvitedMembership(): void
    {
        $carolId  = $this->seedAuxProfile('Carol');
        $inviteId = $this->membershipRepo->invite($carolId, self::TENANT_B, 1);

        $affected = $this->membershipRepo->accept($inviteId, self::TENANT_B);

        self::assertSame(1, $affected);
        $row = $this->membershipRepo->findById($inviteId, self::TENANT_B);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status']);
    }

    // ============================================================
    // memberships — reactivate() cross-tenant isolation
    // ============================================================

    /**
     * Tenant A cannot reactivate Tenant B's suspended membership — the
     * reactivate() call touches zero rows and Carol's membership remains 'suspended'.
     *
     * NOT covered in steps 5/6 (which only test suspend() and delete()).
     *
     * We use Carol (a fresh profile) to avoid UNIQUE constraint conflicts.
     */
    public function testTenantCannotReactivateForeignSuspendedMembershipAndStatusStaysSuspended(): void
    {
        $carolId      = $this->seedAuxProfile('Carol');
        $membershipId = $this->membershipRepo->insert($carolId, self::TENANT_B, 1);
        $this->membershipRepo->suspend($membershipId, self::TENANT_B);

        // Tenant A tries to reactivate Tenant B's suspended row.
        $affected = $this->membershipRepo->reactivate($membershipId, self::TENANT_A);

        self::assertSame(0, $affected, 'Cross-tenant reactivate() must touch zero rows.');

        $row = $this->membershipRepo->findById($membershipId, self::TENANT_B);
        self::assertIsArray($row);
        self::assertSame(
            MembershipRepository::STATUS_SUSPENDED,
            $row['status'],
            'Carol\'s suspended membership must remain \'suspended\' after the rejected cross-tenant reactivate.'
        );
    }

    /**
     * (Positive control) Tenant B can reactivate its own suspended membership.
     */
    public function testTenantCanReactivateOwnSuspendedMembership(): void
    {
        $carolId      = $this->seedAuxProfile('Carol');
        $membershipId = $this->membershipRepo->insert($carolId, self::TENANT_B, 1);
        $this->membershipRepo->suspend($membershipId, self::TENANT_B);

        $affected = $this->membershipRepo->reactivate($membershipId, self::TENANT_B);

        self::assertSame(1, $affected);
        $row = $this->membershipRepo->findById($membershipId, self::TENANT_B);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status']);
    }

    // ============================================================
    // memberships — findByProfile() cross-tenant isolation
    // ============================================================

    /**
     * findByProfile(profileId, tenantId) is scoped: Alice's Tenant-A membership
     * is invisible when the query is issued for Tenant B, and Bob's Tenant-B
     * membership is invisible for Tenant A.
     *
     * NOT explicitly covered in steps 5/6 (findForProfile() is covered, but
     * that is intentionally unscoped; findByProfile() is the scoped variant).
     */
    public function testFindByProfileRespectsTheTenantPredicate(): void
    {
        // Alice has a membership in Tenant A only.
        $foundInA = $this->membershipRepo->findByProfile($this->profileAliceId, self::TENANT_A);
        $foundInB = $this->membershipRepo->findByProfile($this->profileAliceId, self::TENANT_B);

        self::assertIsArray($foundInA, 'Alice\'s membership must be found when queried for Tenant A.');
        self::assertSame(self::TENANT_A, $foundInA['tenant_id']);
        self::assertNull(
            $foundInB,
            'Alice has no membership in Tenant B — findByProfile must return null for Tenant B.'
        );

        // Bob has a membership in Tenant B only.
        $bobInB = $this->membershipRepo->findByProfile($this->profileBobId, self::TENANT_B);
        $bobInA = $this->membershipRepo->findByProfile($this->profileBobId, self::TENANT_A);

        self::assertIsArray($bobInB);
        self::assertSame(self::TENANT_B, $bobInB['tenant_id']);
        self::assertNull(
            $bobInA,
            'Bob has no membership in Tenant A — findByProfile must return null for Tenant A.'
        );
    }

    // ============================================================
    // tenant_email_domains — findByDomain() cross-tenant isolation
    // ============================================================

    /**
     * findByDomain() is tenant-scoped: looking up 'example.org' (Tenant B's
     * domain) with Tenant A's id returns null — Tenant A cannot discover
     * which domains Tenant B has registered.
     *
     * NOT covered in steps 5/6 (which only test findById() and delete()).
     */
    public function testFindByDomainIsTenantScoped(): void
    {
        // Tenant A can find its own domain.
        $found = $this->domainRepo->findByDomain('acme.com', self::TENANT_A);
        self::assertIsArray($found, 'Tenant A must find its own domain registration.');
        self::assertSame(self::TENANT_A, $found['tenant_id']);

        // Tenant A cannot find Tenant B's domain.
        $crossTenantFind = $this->domainRepo->findByDomain('example.org', self::TENANT_A);
        self::assertNull(
            $crossTenantFind,
            'Tenant A must not find Tenant B\'s domain via findByDomain — tenant predicate must filter it out.'
        );
    }

    /**
     * (Positive control) Each tenant can find its own domain by name.
     */
    public function testFindByDomainReturnsOwnTenantDomainOnly(): void
    {
        $tenantBRow = $this->domainRepo->findByDomain('example.org', self::TENANT_B);
        self::assertIsArray($tenantBRow);
        self::assertSame(self::TENANT_B, $tenantBRow['tenant_id']);
        self::assertSame('example.org', $tenantBRow['domain']);
    }

    // ============================================================
    // tenant_email_domains — findTenantsByDomain() intentional unscoped
    // ============================================================

    /**
     * findTenantsByDomain() is intentionally unscoped (used by the policy
     * service to enumerate ALL tenants that registered a domain for JIT
     * membership provisioning). This test proves the method returns:
     *  1. The correct tenant(s) for a registered domain.
     *  2. An empty list for an unregistered domain.
     *  3. The correct tenant_id in every returned row (no row leaks
     *     a different tenant's id).
     *
     * NOT directly covered in steps 5/6.
     */
    public function testFindTenantsByDomainReturnsAllTenantsClaimingThatDomain(): void
    {
        // 'acme.com' is claimed by Tenant A only.
        $rows = $this->domainRepo->findTenantsByDomain('acme.com');

        self::assertCount(1, $rows, 'Only Tenant A has registered acme.com.');
        self::assertSame(
            self::TENANT_A,
            $rows[0]['tenant_id'],
            'The returned row must belong to Tenant A.'
        );
    }

    /**
     * findTenantsByDomain() for an unregistered domain returns an empty list.
     */
    public function testFindTenantsByDomainReturnsEmptyListForUnregisteredDomain(): void
    {
        $rows = $this->domainRepo->findTenantsByDomain('nobody.example');

        self::assertSame([], $rows, 'An unregistered domain must yield an empty result set.');
    }

    /**
     * When two tenants both register the SAME domain (a valid configuration),
     * findTenantsByDomain() returns both rows with their correct tenant_ids.
     * Each row's tenant_id is consistent with the inserting tenant.
     */
    public function testFindTenantsByDomainReturnsBothTenantsWhenBothClaimTheDomain(): void
    {
        // Both tenants register 'shared.example'.
        $this->domainRepo->insert(self::TENANT_A, 'shared.example', 1, true);
        $this->domainRepo->insert(self::TENANT_B, 'shared.example', 1, false);

        $rows = $this->domainRepo->findTenantsByDomain('shared.example');

        self::assertCount(2, $rows, 'Both tenants claiming shared.example must appear.');
        $tenantIds = array_column($rows, 'tenant_id');
        self::assertContains(self::TENANT_A, $tenantIds, 'Tenant A must be in the result.');
        self::assertContains(self::TENANT_B, $tenantIds, 'Tenant B must be in the result.');

        // Verify each row carries the correct tenant_id (no cross-pollution).
        foreach ($rows as $row) {
            self::assertContains(
                $row['tenant_id'],
                [self::TENANT_A, self::TENANT_B],
                'Every returned row must belong to a known tenant, never a phantom.'
            );
        }
    }

    // ============================================================
    // #181 REGRESSION — RoleChecker membership-aware path
    // ============================================================

    /**
     * #181 REGRESSION (complementary to ProfileLoginRealEngineTest):
     *
     * Two profiles with no shared membership cannot resolve each other's
     * permissions across the tenant boundary.
     *
     * Alice has a membership (and therefore a role) in Tenant A only.
     * Bob has a membership in Tenant B only.
     *
     * hasPermissionForProfile(alice, 'users:read', TENANT_B) must return false
     * because the memberships query finds no active row for Alice in Tenant B
     * — there is no role to resolve permissions from.
     *
     * Likewise, hasPermissionForProfile(bob, 'users:read', TENANT_A) must
     * return false for the same reason.
     *
     * This proves the entire permission chain — not just the login handler —
     * is cross-tenant-safe: a profile can only hold permissions in a tenant
     * where it has an active membership.
     *
     * ProfileLoginRealEngineTest (step 4) proves the login path + the
     * ActiveTenantMembershipGuard; this test proves the RoleChecker /
     * permission resolution path is equally isolated.
     */
    public function testProfileWithNoMembershipInTenantCannotResolvePermissionsForThatTenant(): void
    {
        // Give Alice's Tenant-A membership a concrete role with 'users:read'.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO permissions (name, description) VALUES ('users:read', 'Read users')"
        );
        // Get the permission id.
        $permStmt = $this->pdo->prepare("SELECT id FROM permissions WHERE name = 'users:read'");
        $permStmt->execute();
        $permId = (int) $permStmt->fetchColumn();

        // Assign users:read to role 1.
        $this->pdo->exec(
            "INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at)
             VALUES (1, {$permId}, datetime('now'))"
        );

        RoleChecker::clearCache();
        $pdo = $this->pdo;
        $db = Database::withFactory(static fn(): PDO => $pdo, 86400, 86400);
        $db->forceConnect();
        $checker = new RoleChecker($db, new PermissionRegistry());

        // Alice DOES have 'users:read' in Tenant A (her membership's role has it).
        self::assertTrue(
            $checker->hasPermissionForProfile($this->profileAliceId, 'users:read', self::TENANT_A),
            '#181: Alice must have users:read in Tenant A (her home tenant).'
        );

        // Alice does NOT have any permission in Tenant B (no membership there).
        self::assertFalse(
            $checker->hasPermissionForProfile($this->profileAliceId, 'users:read', self::TENANT_B),
            '#181: Alice must NOT have users:read in Tenant B — she has no membership there.'
        );

        // Bob DOES have 'users:read' in Tenant B.
        self::assertTrue(
            $checker->hasPermissionForProfile($this->profileBobId, 'users:read', self::TENANT_B),
            '#181: Bob must have users:read in Tenant B (his home tenant).'
        );

        // Bob does NOT have any permission in Tenant A (no membership there).
        self::assertFalse(
            $checker->hasPermissionForProfile($this->profileBobId, 'users:read', self::TENANT_A),
            '#181: Bob must NOT have users:read in Tenant A — he has no membership there.'
        );
    }

    /**
     * #181 REGRESSION (ActiveTenantMembershipGuard, complementary):
     *
     * A JWT claiming Alice's profile_id but with active_tenant_id pointing at
     * Tenant B (where Alice has no membership) must be refused by the guard.
     * This is a direct structural proof that "no shared membership = cannot
     * cross-login" holds at the token-validation layer.
     *
     * The core guard scenario is already in ProfileLoginRealEngineTest. This
     * assertion adds the "cross-profile" angle: the guard verifies the
     * (profile_id, active_tenant_id) pair, not just the tenant alone — so Bob's
     * profile_id with Tenant A is equally refused.
     */
    public function testActiveTenantMembershipGuardRefusesProfileInNonMemberTenant(): void
    {
        $guard = new ActiveTenantMembershipGuard($this->pdo);

        // Alice's profile_id with Tenant B (she has no membership there) → refused.
        $aliceClaimsForB = [
            'profile_id'       => $this->profileAliceId,
            'active_tenant_id' => self::TENANT_B,
        ];
        self::assertFalse(
            $guard->allows($aliceClaimsForB),
            '#181: Alice\'s profile with active_tenant_id=Tenant_B must be refused (no membership).'
        );

        // Bob's profile_id with Tenant A (he has no membership there) → refused.
        $bobClaimsForA = [
            'profile_id'       => $this->profileBobId,
            'active_tenant_id' => self::TENANT_A,
        ];
        self::assertFalse(
            $guard->allows($bobClaimsForA),
            '#181: Bob\'s profile with active_tenant_id=Tenant_A must be refused (no membership).'
        );

        // Alice's profile_id with Tenant A (her own tenant) → allowed.
        $aliceClaimsForA = [
            'profile_id'       => $this->profileAliceId,
            'active_tenant_id' => self::TENANT_A,
        ];
        self::assertTrue(
            $guard->allows($aliceClaimsForA),
            '#181: Alice\'s profile with active_tenant_id=Tenant_A must be allowed (active membership).'
        );

        // Bob's profile_id with Tenant B (his own tenant) → allowed.
        $bobClaimsForB = [
            'profile_id'       => $this->profileBobId,
            'active_tenant_id' => self::TENANT_B,
        ];
        self::assertTrue(
            $guard->allows($bobClaimsForB),
            '#181: Bob\'s profile with active_tenant_id=Tenant_B must be allowed (active membership).'
        );
    }

    // ============================================================
    // Private helpers
    // ============================================================

    /**
     * Seed an auxiliary profile (no membership, no email) and return its id.
     *
     * Used in membership tests that need a fresh profile to avoid colliding
     * with the UNIQUE(profile_id, tenant_id) constraint on the Alice/Bob rows
     * already inserted in setUp().
     */
    private function seedAuxProfile(string $displayName): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('{$displayName}', '\$2y\$10\$fakehashaux', false, 0, 0, datetime('now'), datetime('now'))"
        );
        return (int) $this->pdo->lastInsertId();
    }
}
