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
        $this->domains->insert(self::TENANT_A, 'acme.com', 1, true);

        $this->service->applyToVerifiedEmail('alice@acme.com', self::ALICE_PROFILE_ID);

        $membership = $this->memberships->findByProfile(self::ALICE_PROFILE_ID, self::TENANT_A);
        self::assertNotNull($membership, 'A membership must be created for the profile.');
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $membership['status']);
        self::assertSame(1, $membership['role_id']);
    }

    public function testAutoProvisionUsesDefaultRoleIdFromDomainRegistration(): void
    {
        $this->domains->insert(self::TENANT_A, 'corp.io', 2, true);  // role 2 (user)

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

    // ── multi-tenant domain ───────────────────────────────────────────────────

    public function testAutoProvisionForBothTenantsWhenBothClaimSameDomain(): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (" . self::BOB_PROFILE_ID . ", 'Bob', '\$2y\$10\$fakehashb', false, 0, 0, datetime('now'), datetime('now'))"
        );

        $this->domains->insert(self::TENANT_A, 'shared.com', 1, true);
        $this->domains->insert(self::TENANT_B, 'shared.com', 2, true);

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
