<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TenantEmailDomainPolicyService;
use Whity\Core\Identity\TenantEmailDomainsRepository;

/**
 * Real-engine tests for {@see TenantEmailDomainPolicyService} (WC-9b87), now
 * activated by the email-verification confirm flow (WC-235). Covers all three
 * branches: auto-provision, accept-pending-invite, and the no-op cases.
 *
 * This is the "provision membership in another tenant from an email domain"
 * path — the cross-tenant-provisioning risk class — so each branch is asserted
 * on a real SQL engine.
 */
final class TenantEmailDomainPolicyRealEngineTest extends TestCase
{
    private PDO $pdo;
    private TenantEmailDomainPolicyService $policy;
    private TenantEmailDomainsRepository $domains;
    private MembershipRepository $memberships;
    private ProfileEmailRepository $emails;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->domains = new TenantEmailDomainsRepository($this->pdo);
        $this->memberships = new MembershipRepository($this->pdo);
        $this->emails = new ProfileEmailRepository($this->pdo);
        $this->policy = new TenantEmailDomainPolicyService($this->domains, $this->memberships);
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        return $stmt->fetchColumn();
    }

    private function seedTenant(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        if ($stmt === false) {
            self::fail('prepare failed');
        }
        $stmt->execute([':n' => $name, ':s' => strtolower($name)]);
        return (int) $this->pdo->lastInsertId();
    }

    private function baseRoleId(): int
    {
        return (int) $this->col('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
    }

    private function seedProfileWithEmail(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:dn, :ph, false, NULL, 0, 0, NOW(), NOW())'
        );
        if ($stmt === false) {
            self::fail('prepare failed');
        }
        $stmt->execute([':dn' => 'User', ':ph' => password_hash('x', PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();
        $this->emails->insert($profileId, $email, true, true);
        return $profileId;
    }

    public function testAutoProvisionCreatesActiveMembershipForVerifiedDomain(): void
    {
        $tenantId = $this->seedTenant('Acme');
        $domainId = $this->domains->insert($tenantId, 'acme.test', $this->baseRoleId(), true);
        $this->domains->markVerified($domainId, $tenantId); // ownership proven
        $profileId = $this->seedProfileWithEmail('alice@acme.test');

        $this->policy->applyToVerifiedEmail('alice@acme.test', $profileId);

        $row = $this->memberships->findByProfile($profileId, $tenantId);
        self::assertNotNull($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status']);
    }

    public function testUnverifiedDomainDoesNotAutoProvision(): void
    {
        // The cross-tenant harvesting guard (WC-628738f5): a tenant that has NOT
        // proven ownership of the domain must never auto-provision a membership,
        // even with auto_provision=true.
        $tenantId = $this->seedTenant('Squatter');
        $this->domains->insert($tenantId, 'notmine.test', $this->baseRoleId(), true); // unverified
        $profileId = $this->seedProfileWithEmail('victim@notmine.test');

        $this->policy->applyToVerifiedEmail('victim@notmine.test', $profileId);

        self::assertNull(
            $this->memberships->findByProfile($profileId, $tenantId),
            'an unverified domain claim must not harvest a membership'
        );
    }

    public function testAcceptsPendingInviteRegardlessOfAutoProvisionFlag(): void
    {
        $tenantId = $this->seedTenant('Beta');
        // auto_provision = false: the service must still accept an existing invite.
        $this->domains->insert($tenantId, 'beta.test', $this->baseRoleId(), false);
        $profileId = $this->seedProfileWithEmail('bob@beta.test');
        $this->memberships->invite($profileId, $tenantId, $this->baseRoleId());

        $this->policy->applyToVerifiedEmail('bob@beta.test', $profileId);

        $row = $this->memberships->findByProfile($profileId, $tenantId);
        self::assertNotNull($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status'], 'a pending invite is accepted on verification');
    }

    public function testNoAutoProvisionAndNoInviteIsANoOp(): void
    {
        $tenantId = $this->seedTenant('Gamma');
        $this->domains->insert($tenantId, 'gamma.test', $this->baseRoleId(), false);
        $profileId = $this->seedProfileWithEmail('carol@gamma.test');

        $this->policy->applyToVerifiedEmail('carol@gamma.test', $profileId);

        self::assertNull(
            $this->memberships->findByProfile($profileId, $tenantId),
            'auto_provision=false with no invite must not create a membership'
        );
    }

    public function testUnclaimedDomainIsANoOp(): void
    {
        $tenantId = $this->seedTenant('Delta');
        $profileId = $this->seedProfileWithEmail('dave@nobody.test');

        $this->policy->applyToVerifiedEmail('dave@nobody.test', $profileId);

        self::assertSame(0, (int) $this->col(
            "SELECT COUNT(*) FROM memberships WHERE profile_id = {$profileId}"
        ));
        // Tenant seeded only to prove nothing was provisioned into it.
        self::assertNull($this->memberships->findByProfile($profileId, $tenantId));
    }

    public function testAlreadyActiveMembershipIsNotDuplicated(): void
    {
        $tenantId = $this->seedTenant('Epsilon');
        $domainId = $this->domains->insert($tenantId, 'eps.test', $this->baseRoleId(), true);
        $this->domains->markVerified($domainId, $tenantId);
        $profileId = $this->seedProfileWithEmail('erin@eps.test');
        $this->memberships->insert($profileId, $tenantId, $this->baseRoleId());

        $this->policy->applyToVerifiedEmail('erin@eps.test', $profileId);

        self::assertSame(1, (int) $this->col(
            "SELECT COUNT(*) FROM memberships WHERE profile_id = {$profileId} AND tenant_id = {$tenantId}"
        ));
    }

    public function testMalformedEmailIsIgnored(): void
    {
        $profileId = $this->seedProfileWithEmail('frank@zeta.test');
        // No exception, no membership.
        $this->policy->applyToVerifiedEmail('not-an-email', $profileId);
        self::assertSame(0, (int) $this->col(
            "SELECT COUNT(*) FROM memberships WHERE profile_id = {$profileId}"
        ));
    }

    public function testSuspendedMembershipIsNotReactivated(): void
    {
        $tenantId = $this->seedTenant('Theta');
        $domainId = $this->domains->insert($tenantId, 'theta.test', $this->baseRoleId(), true);
        $this->domains->markVerified($domainId, $tenantId);
        $profileId = $this->seedProfileWithEmail('gina@theta.test');
        $this->memberships->insert(
            $profileId,
            $tenantId,
            $this->baseRoleId(),
            null,
            MembershipRepository::STATUS_SUSPENDED
        );

        $this->policy->applyToVerifiedEmail('gina@theta.test', $profileId);

        $row = $this->memberships->findByProfile($profileId, $tenantId);
        self::assertNotNull($row);
        self::assertSame(
            MembershipRepository::STATUS_SUSPENDED,
            $row['status'],
            'a deliberately-suspended membership must never be silently reactivated by the domain policy'
        );
    }

    public function testSystemTenantClaimIsNeverAutoProvisioned(): void
    {
        // A domain claim for the system tenant (id 0) with auto-provision on must
        // NOT grant a tenant-0 membership on verification — that would confer
        // platform-wide authority via an email-domain match.
        $sysDomainId = $this->domains->insert(0, 'sys.test', $this->baseRoleId(), true);
        $this->domains->markVerified($sysDomainId, 0); // even a verified tenant-0 claim must be skipped
        $profileId = $this->seedProfileWithEmail('intruder@sys.test');

        $this->policy->applyToVerifiedEmail('intruder@sys.test', $profileId);

        self::assertNull(
            $this->memberships->findByProfile($profileId, 0),
            'the domain policy must never provision a membership in the system tenant (0)'
        );
    }
}
