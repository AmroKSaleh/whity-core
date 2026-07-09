<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Real-engine tests for {@see FederatedIdentityLinker} (WC-f3b17bd2) — the
 * anti-takeover first-login policy, in both trust tiers.
 *
 * GLOBAL-TRUST (operator IdP, system tenant 0): global (issuer,subject) namespace,
 * verified↔verified email link, and passwordless provisioning.
 *
 * TENANT-TRUST (a tenant's bring-your-own IdP): per-provider (provider_id,subject)
 * namespace; links ONLY to an active member of the configuring tenant; never
 * provisions and never reaches a non-member — the cross-tenant-takeover guard.
 */
final class FederatedIdentityLinkerRealEngineTest extends TestCase
{
    private const ISS = 'https://accounts.google.com';
    private const TENANT_PROVIDER_ID = 100;

    private PDO $pdo;
    private FederatedIdentityLinker $linker;
    private ExternalIdentityRepository $identities;
    private ProfileEmailRepository $emails;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->identities = new ExternalIdentityRepository($this->pdo);
        $this->emails = new ProfileEmailRepository($this->pdo);
        $this->linker = new FederatedIdentityLinker(
            $this->pdo,
            $this->identities,
            $this->emails,
            new MembershipRepository($this->pdo),
        );
    }

    private function seedProfile(): int
    {
        $this->pdo->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('U', '" . password_hash('x', PASSWORD_BCRYPT) . "', false, NULL, 0, 0, NOW(), NOW())");
        return (int) $this->pdo->lastInsertId();
    }

    private function seedTenant(string $name): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO tenants (name, created_at) VALUES (:name, NOW())");
        $stmt->execute([':name' => $name]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedMembership(int $profileId, int $tenantId, string $status = 'active'): void
    {
        $roleId = (int) $this->col("SELECT id FROM roles WHERE name = 'user'");
        $stmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, ?, NOW())"
        );
        $stmt->execute([$profileId, $tenantId, $roleId, $status]);
    }

    private function identity(string $sub, ?string $email, bool $verified): ExternalIdentity
    {
        return new ExternalIdentity(self::ISS, $sub, $email, $verified, 'Name');
    }

    private function globalCtx(): FederatedProviderContext
    {
        return new FederatedProviderContext(0, 'google', 0);
    }

    private function tenantCtx(int $tenantId): FederatedProviderContext
    {
        return new FederatedProviderContext(self::TENANT_PROVIDER_ID, 'corp_okta', $tenantId);
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        return $stmt->fetchColumn();
    }

    // ── GLOBAL-TRUST (operator IdP) ─────────────────────────────────────────────

    public function testGlobalExistingLinkReturnsThatProfile(): void
    {
        $pid = $this->seedProfile();
        $this->identities->link($pid, 'google', self::ISS, 'sub-1', 'a@b.com', null);

        $r = $this->linker->resolveForLogin($this->identity('sub-1', 'a@b.com', true), $this->globalCtx());
        self::assertSame('existing', $r['status']);
        self::assertSame($pid, $r['profile_id'] ?? null);
    }

    public function testGlobalVerifiedEmailMatchLinksExistingProfile(): void
    {
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'alice@corp.com', true, true);

        $r = $this->linker->resolveForLogin($this->identity('sub-2', 'Alice@corp.com', true), $this->globalCtx());
        self::assertSame('linked', $r['status']);
        self::assertSame($pid, $r['profile_id'] ?? null);
        // A global-trust link carries a NULL provider_id (global namespace).
        $link = $this->identities->findByIssuerSubject(self::ISS, 'sub-2');
        self::assertNotNull($link);
        self::assertNull($link['provider_id']);
    }

    public function testGlobalUnverifiedIdpEmailIsRefused(): void
    {
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'alice@corp.com', true, true);

        $r = $this->linker->resolveForLogin($this->identity('sub-3', 'alice@corp.com', false), $this->globalCtx());
        self::assertSame('refused_unverified', $r['status']);
        self::assertNull($this->identities->findByIssuerSubject(self::ISS, 'sub-3'));
    }

    public function testGlobalUnverifiedLocalEmailConflictIsRefused(): void
    {
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'bob@corp.com', false, true); // UNVERIFIED local

        $r = $this->linker->resolveForLogin($this->identity('sub-4', 'bob@corp.com', true), $this->globalCtx());
        self::assertSame('refused_conflict', $r['status']);
        self::assertNull($this->identities->findByIssuerSubject(self::ISS, 'sub-4'));
    }

    public function testGlobalNewIdentityProvisionsPasswordlessProfile(): void
    {
        $r = $this->linker->resolveForLogin($this->identity('sub-5', 'new@fresh.com', true), $this->globalCtx());
        self::assertSame('provisioned', $r['status']);
        $pid = $r['profile_id'] ?? 0;
        self::assertGreaterThan(0, $pid);

        self::assertSame('', (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$pid}"));
        self::assertContains(
            (string) $this->col("SELECT verified FROM profile_emails WHERE email = 'new@fresh.com'"),
            ['1', 't', 'true']
        );
        $link = $this->identities->findByIssuerSubject(self::ISS, 'sub-5');
        self::assertNotNull($link);
        self::assertSame($pid, (int) $link['profile_id']);
        self::assertNull($link['provider_id'], 'a provisioned link is global (provider_id NULL)');
    }

    public function testGlobalProvisionSecondTimeResolvesToExisting(): void
    {
        $first = $this->linker->resolveForLogin($this->identity('sub-6', 'x@fresh.com', true), $this->globalCtx());
        self::assertSame('provisioned', $first['status']);

        $second = $this->linker->resolveForLogin($this->identity('sub-6', 'x@fresh.com', true), $this->globalCtx());
        self::assertSame('existing', $second['status']);
        self::assertSame($first['profile_id'] ?? null, $second['profile_id'] ?? null);
    }

    // ── TENANT-TRUST (bring-your-own IdP) ───────────────────────────────────────

    public function testTenantExistingProviderLinkReturnsThatProfile(): void
    {
        $tenant = $this->seedTenant('Acme');
        $pid = $this->seedProfile();
        $this->identities->link($pid, 'corp_okta', self::ISS, 'sub-t1', 'a@acme.com', self::TENANT_PROVIDER_ID);

        $r = $this->linker->resolveForLogin($this->identity('sub-t1', 'a@acme.com', true), $this->tenantCtx($tenant));
        self::assertSame('existing', $r['status']);
        self::assertSame($pid, $r['profile_id'] ?? null);
    }

    public function testTenantLinksVerifiedEmailOfActiveMember(): void
    {
        $tenant = $this->seedTenant('Acme');
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'carol@acme.com', true, true);
        $this->seedMembership($pid, $tenant);

        $r = $this->linker->resolveForLogin($this->identity('sub-t2', 'carol@acme.com', true), $this->tenantCtx($tenant));
        self::assertSame('linked', $r['status']);
        self::assertSame($pid, $r['profile_id'] ?? null);
        // Linked in the TENANT namespace (provider_id set, not the global namespace).
        $link = $this->identities->findByProviderSubject(self::TENANT_PROVIDER_ID, 'sub-t2');
        self::assertNotNull($link);
        self::assertSame(self::TENANT_PROVIDER_ID, $link['provider_id']);
        self::assertNull(
            $this->identities->findGlobalByIssuerSubject(self::ISS, 'sub-t2'),
            'a tenant-trust link must never appear in the global namespace'
        );
    }

    public function testTenantRefusesVerifiedEmailOfNonMember(): void
    {
        // The account exists (verified email) but is NOT a member of this tenant —
        // a tenant IdP must not be able to reach it (cross-tenant takeover guard).
        $tenant = $this->seedTenant('Acme');
        $other  = $this->seedTenant('Other');
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'victim@other.com', true, true);
        $this->seedMembership($pid, $other); // member of Other, not Acme

        $r = $this->linker->resolveForLogin($this->identity('sub-t3', 'victim@other.com', true), $this->tenantCtx($tenant));
        self::assertSame('refused_no_account', $r['status']);
        self::assertNull($this->identities->findByProviderSubject(self::TENANT_PROVIDER_ID, 'sub-t3'));
    }

    public function testTenantRefusesWhenNoLocalAccount(): void
    {
        $tenant = $this->seedTenant('Acme');
        $r = $this->linker->resolveForLogin($this->identity('sub-t4', 'nobody@acme.com', true), $this->tenantCtx($tenant));
        self::assertSame('refused_no_account', $r['status'], 'tenant-trust never provisions (deferred to A7)');
        self::assertNull($this->identities->findByProviderSubject(self::TENANT_PROVIDER_ID, 'sub-t4'));
    }

    public function testTenantRefusesUnverifiedIdpEmail(): void
    {
        $tenant = $this->seedTenant('Acme');
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'dan@acme.com', true, true);
        $this->seedMembership($pid, $tenant);

        $r = $this->linker->resolveForLogin($this->identity('sub-t5', 'dan@acme.com', false), $this->tenantCtx($tenant));
        self::assertSame('refused_unverified', $r['status']);
        self::assertNull($this->identities->findByProviderSubject(self::TENANT_PROVIDER_ID, 'sub-t5'));
    }

    public function testTenantRefusesUnverifiedMemberEmailConflict(): void
    {
        $tenant = $this->seedTenant('Acme');
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'eve@acme.com', false, true); // UNVERIFIED local
        $this->seedMembership($pid, $tenant);

        $r = $this->linker->resolveForLogin($this->identity('sub-t6', 'eve@acme.com', true), $this->tenantCtx($tenant));
        self::assertSame('refused_conflict', $r['status']);
        self::assertNull($this->identities->findByProviderSubject(self::TENANT_PROVIDER_ID, 'sub-t6'));
    }

    public function testTenantTrustCannotResolveGlobalLinkViaIssuerSpoof(): void
    {
        // A victim has a GLOBAL (operator-Google) link. An attacker's tenant IdP
        // reuses the SAME issuer + subject string. Because tenant-trust resolves by
        // (provider_id, subject) — never (issuer, subject) — the spoof cannot hit
        // the victim's global link, and the victim is not a member, so the whole
        // attempt is refused with no new link.
        $victim = $this->seedProfile();
        $this->identities->link($victim, 'google', self::ISS, 'shared-sub', 'victim@corp.com', null);
        $this->emails->insert($victim, 'victim@corp.com', true, true);

        $attackerTenant = $this->seedTenant('Attacker');

        $r = $this->linker->resolveForLogin(
            $this->identity('shared-sub', 'victim@corp.com', true),
            $this->tenantCtx($attackerTenant),
        );
        self::assertSame('refused_no_account', $r['status']);
        // The victim's global link is untouched and still points at the victim.
        $global = $this->identities->findGlobalByIssuerSubject(self::ISS, 'shared-sub');
        self::assertNotNull($global);
        self::assertSame($victim, (int) $global['profile_id']);
        // No tenant-trust link was created for the attacker's provider.
        self::assertNull($this->identities->findByProviderSubject(self::TENANT_PROVIDER_ID, 'shared-sub'));
    }
}
