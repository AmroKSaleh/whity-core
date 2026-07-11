<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Identity\TenantEmailDomainPolicyService;

/**
 * WC-9b87: integration tests for TenantEmailDomainPolicyService.
 *
 * The policy service is the single point that translates a "profile just
 * verified an email" event into membership side-effects:
 *   - auto_provision = true  → insert an 'active' membership if none exists
 *   - invite exists          → accept it (invited → active) regardless of auto_provision
 *   - membership already active → no-op
 *   - no tenant claims the domain → no-op
 */
final class TenantEmailDomainPolicyServiceTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    /**
     * Fixture profile ids: use high values to avoid collisions with the system
     * admin profile (id=1) seeded by migration 036.
     */
    private const ALICE_PROFILE_ID = 101;
    private const BOB_PROFILE_ID   = 102;

    private PDO $pdo;
    private TenantEmailDomainsRepository $domains;
    private MembershipRepository $memberships;
    private TenantEmailDomainPolicyService $service;

    protected function setUp(): void
    {
        $this->pdo         = SchemaFromMigrations::make(true);
        $this->domains     = new TenantEmailDomainsRepository($this->pdo);
        $this->memberships = new MembershipRepository($this->pdo);
        $this->service     = new TenantEmailDomainPolicyService($this->domains, $this->memberships);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec(
            "INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1, 'admin', '', NULL, datetime('now')),
                (2, 'user',  '', NULL, datetime('now'))"
        );
        // Global identity anchor for the test profile.
        // Use id=101 to avoid collision with the system admin profile (id=1)
        // seeded by migration 036.
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (" . self::ALICE_PROFILE_ID . ", 'Alice', '\$2y\$10\$fakehash', false, 0, 0, datetime('now'), datetime('now'))"
        );
    }

    // ── auto-provision ────────────────────────────────────────────────────────

    public function testAutoProvisionCreatesMembershipWhenDomainIsRegistered(): void
    {
        $this->domains->markVerified($this->domains->insert(self::TENANT_A, 'acme.com', 1, true), self::TENANT_A);

        $this->service->applyToVerifiedEmail('alice@acme.com', self::ALICE_PROFILE_ID);

        $membership = $this->memberships->findByProfile(self::ALICE_PROFILE_ID, self::TENANT_A);
        self::assertNotNull($membership, 'A membership must be created for the profile.');
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $membership['status']);
        self::assertSame(1, $membership['role_id']);
    }

    public function testAutoProvisionUsesDefaultRoleIdFromDomainRegistration(): void
    {
        $this->domains->markVerified($this->domains->insert(self::TENANT_A, 'corp.io', 2, true), self::TENANT_A);  // role 2 (user)

        $this->service->applyToVerifiedEmail('alice@corp.io', self::ALICE_PROFILE_ID);

        $membership = $this->memberships->findByProfile(self::ALICE_PROFILE_ID, self::TENANT_A);
        self::assertNotNull($membership);
        self::assertSame(2, $membership['role_id']);
    }

    public function testNoAutoProvisionSkipsMembershipCreationWhenFlagIsFalse(): void
    {
        $this->domains->insert(self::TENANT_A, 'noprovisioning.com', 1, false);

        $this->service->applyToVerifiedEmail('alice@noprovisioning.com', self::ALICE_PROFILE_ID);

        self::assertNull(
            $this->memberships->findByProfile(self::ALICE_PROFILE_ID, self::TENANT_A),
            'No membership must be created when auto_provision is false and no invite exists.'
        );
    }

    // ── auto-accept invite ────────────────────────────────────────────────────

    public function testPendingInviteIsAcceptedWhenDomainMatches(): void
    {
        // Domain registered with auto_provision = false; an invite already exists.
        $this->domains->insert(self::TENANT_A, 'invited.com', 1, false);
        $this->memberships->invite(self::ALICE_PROFILE_ID, self::TENANT_A, 1);

        $this->service->applyToVerifiedEmail('alice@invited.com', self::ALICE_PROFILE_ID);

        $membership = $this->memberships->findByProfile(self::ALICE_PROFILE_ID, self::TENANT_A);
        self::assertNotNull($membership);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $membership['status']);
    }

    public function testPendingInviteIsAcceptedEvenWhenAutoProvisionIsTrue(): void
    {
        $this->domains->insert(self::TENANT_A, 'acme.com', 1, true);
        $this->memberships->invite(self::ALICE_PROFILE_ID, self::TENANT_A, 1);

        $this->service->applyToVerifiedEmail('alice@acme.com', self::ALICE_PROFILE_ID);

        $membership = $this->memberships->findByProfile(self::ALICE_PROFILE_ID, self::TENANT_A);
        self::assertNotNull($membership);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $membership['status']);
    }

    // ── idempotency ───────────────────────────────────────────────────────────

    public function testNoOpWhenMembershipAlreadyActive(): void
    {
        $this->domains->insert(self::TENANT_A, 'acme.com', 1, true);
        $this->memberships->insert(self::ALICE_PROFILE_ID, self::TENANT_A, 1);

        // Must not throw or create a duplicate.
        $this->service->applyToVerifiedEmail('alice@acme.com', self::ALICE_PROFILE_ID);

        $rows = $this->memberships->listForTenant(self::TENANT_A);
        self::assertCount(1, $rows, 'Only one membership must exist — no duplicate must be created.');
    }

    // ── no matching domain ────────────────────────────────────────────────────

    public function testNoOpWhenNoTenantClaimsDomain(): void
    {
        $this->service->applyToVerifiedEmail('alice@unknown.com', self::ALICE_PROFILE_ID);

        self::assertSame(
            [],
            $this->memberships->listForTenant(self::TENANT_A),
            'No membership must be created when no tenant owns the domain.'
        );
    }

    // ── check-then-insert race ────────────────────────────────────────────────

    /**
     * The window between findByProfile() (sees no membership) and insert() can be
     * lost to a concurrent racer — a second verification of the same email, or a
     * federated/JIT provision — that inserts the membership first. The loser hits
     * UNIQUE(profile_id, tenant_id). That is the desired end state (the member row
     * exists), so applyToVerifiedEmail must swallow it and NOT surface a 500.
     */
    public function testAutoProvisionSwallowsUniqueViolationFromConcurrentInsert(): void
    {
        // Real domains repo on the real engine advertises a verified,
        // auto-provisioning claim; the memberships repo is backed by a PDO that
        // sees no existing row (lost the race) but throws 23505 on INSERT (the
        // racer already created it). Both repos are final, so they cannot be
        // doubled directly — we inject the failure at the PDO seam instead.
        $this->domains->markVerified($this->domains->insert(self::TENANT_A, 'acme.com', 1, true), self::TENANT_A);
        $memberships = new MembershipRepository($this->pdoThatThrowsOnInsert('23505'));
        $service     = new TenantEmailDomainPolicyService($this->domains, $memberships);

        // Must NOT throw — the concurrent insert already achieved the end state.
        $service->applyToVerifiedEmail('alice@acme.com', self::ALICE_PROFILE_ID);
        $this->addToAssertionCount(1);
    }

    /**
     * A non-unique DB failure on insert (bad role FK, connection loss, etc.) is a
     * real error and MUST surface — only the benign duplicate is swallowed.
     */
    public function testAutoProvisionRethrowsNonUniqueInsertFailure(): void
    {
        $this->domains->markVerified($this->domains->insert(self::TENANT_A, 'acme.com', 1, true), self::TENANT_A);
        // 23503 = foreign-key violation — NOT a benign duplicate.
        $memberships = new MembershipRepository($this->pdoThatThrowsOnInsert('23503'));
        $service     = new TenantEmailDomainPolicyService($this->domains, $memberships);

        $this->expectException(\PDOException::class);
        $service->applyToVerifiedEmail('alice@acme.com', self::ALICE_PROFILE_ID);
    }

    /**
     * A mock PDO for MembershipRepository whose findByProfile SELECT sees no row
     * (the racer's insert isn't visible to this connection's check) but whose
     * INSERT INTO memberships fails with the given SQLSTATE.
     */
    private function pdoThatThrowsOnInsert(string $sqlState): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($sqlState) {
            $stmt = $this->createMock(\PDOStatement::class);
            if (str_contains($sql, 'INSERT INTO memberships')) {
                $stmt->method('execute')->willThrowException(
                    new \PDOException("SQLSTATE[$sqlState]: constraint violation", (int) $sqlState)
                );
            } else {
                // findByProfile SELECT → no existing membership on this connection.
                $stmt->method('execute')->willReturn(true);
                $stmt->method('fetch')->willReturn(false);
            }
            return $stmt;
        });
        return $pdo;
    }

    // ── multi-tenant domain ───────────────────────────────────────────────────

    public function testAutoProvisionForBothTenantsWhenBothClaimSameDomain(): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (" . self::BOB_PROFILE_ID . ", 'Bob', '\$2y\$10\$fakehashb', false, 0, 0, datetime('now'), datetime('now'))"
        );

        $this->domains->markVerified($this->domains->insert(self::TENANT_A, 'shared.com', 1, true), self::TENANT_A);
        $this->domains->markVerified($this->domains->insert(self::TENANT_B, 'shared.com', 2, true), self::TENANT_B);

        $this->service->applyToVerifiedEmail('bob@shared.com', self::BOB_PROFILE_ID);

        self::assertNotNull(
            $this->memberships->findByProfile(self::BOB_PROFILE_ID, self::TENANT_A),
            'Profile ' . self::BOB_PROFILE_ID . ' must get a membership in Tenant A.'
        );
        self::assertNotNull(
            $this->memberships->findByProfile(self::BOB_PROFILE_ID, self::TENANT_B),
            'Profile ' . self::BOB_PROFILE_ID . ' must get a membership in Tenant B.'
        );
    }
}
