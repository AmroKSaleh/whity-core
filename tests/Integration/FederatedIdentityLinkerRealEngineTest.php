<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Real-engine tests for {@see FederatedIdentityLinker} (WC-f3b17bd2) — the
 * anti-takeover first-login policy. Verifies each branch: existing link,
 * link-by-verified-email, provision-new, and the two refusals.
 */
final class FederatedIdentityLinkerRealEngineTest extends TestCase
{
    private const ISS = 'https://accounts.google.com';

    private PDO $pdo;
    private FederatedIdentityLinker $linker;
    private ExternalIdentityRepository $identities;
    private ProfileEmailRepository $emails;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->identities = new ExternalIdentityRepository($this->pdo);
        $this->emails = new ProfileEmailRepository($this->pdo);
        $this->linker = new FederatedIdentityLinker($this->pdo, $this->identities, $this->emails);
    }

    private function seedProfile(): int
    {
        $this->pdo->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('U', '" . password_hash('x', PASSWORD_BCRYPT) . "', false, NULL, 0, 0, NOW(), NOW())");
        return (int) $this->pdo->lastInsertId();
    }

    private function identity(string $sub, ?string $email, bool $verified): ExternalIdentity
    {
        return new ExternalIdentity(self::ISS, $sub, $email, $verified, 'Name');
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        return $stmt->fetchColumn();
    }

    public function testExistingLinkReturnsThatProfile(): void
    {
        $pid = $this->seedProfile();
        $this->identities->link($pid, 'google', self::ISS, 'sub-1', 'a@b.com');

        $r = $this->linker->resolveForLogin($this->identity('sub-1', 'a@b.com', true), 'google');
        self::assertSame('existing', $r['status']);
        self::assertSame($pid, $r['profile_id'] ?? null);
    }

    public function testVerifiedEmailMatchLinksExistingProfile(): void
    {
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'alice@corp.com', true, true);

        $r = $this->linker->resolveForLogin($this->identity('sub-2', 'Alice@corp.com', true), 'google');
        self::assertSame('linked', $r['status']);
        self::assertSame($pid, $r['profile_id'] ?? null);
        // The link now resolves.
        self::assertNotNull($this->identities->findByIssuerSubject(self::ISS, 'sub-2'));
    }

    public function testUnverifiedIdpEmailIsRefused(): void
    {
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'alice@corp.com', true, true);

        // Even though a verified local email matches, the IdP email is unverified.
        $r = $this->linker->resolveForLogin($this->identity('sub-3', 'alice@corp.com', false), 'google');
        self::assertSame('refused_unverified', $r['status']);
        self::assertNull($this->identities->findByIssuerSubject(self::ISS, 'sub-3'));
    }

    public function testUnverifiedLocalEmailConflictIsRefused(): void
    {
        $pid = $this->seedProfile();
        $this->emails->insert($pid, 'bob@corp.com', false, true); // UNVERIFIED local

        $r = $this->linker->resolveForLogin($this->identity('sub-4', 'bob@corp.com', true), 'google');
        self::assertSame('refused_conflict', $r['status']);
        self::assertNull($this->identities->findByIssuerSubject(self::ISS, 'sub-4'));
    }

    public function testNewIdentityProvisionsPasswordlessProfile(): void
    {
        $r = $this->linker->resolveForLogin($this->identity('sub-5', 'new@fresh.com', true), 'google');
        self::assertSame('provisioned', $r['status']);
        $pid = $r['profile_id'] ?? 0;
        self::assertGreaterThan(0, $pid);

        // Passwordless profile.
        self::assertSame('', (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$pid}"));
        // Verified primary email.
        self::assertContains(
            (string) $this->col("SELECT verified FROM profile_emails WHERE email = 'new@fresh.com'"),
            ['1', 't', 'true']
        );
        // Linked.
        $link = $this->identities->findByIssuerSubject(self::ISS, 'sub-5');
        self::assertNotNull($link);
        self::assertSame($pid, (int) $link['profile_id']);
    }

    public function testProvisionSecondTimeResolvesToExisting(): void
    {
        $first = $this->linker->resolveForLogin($this->identity('sub-6', 'x@fresh.com', true), 'google');
        self::assertSame('provisioned', $first['status']);

        // Same (issuer, subject) again → the existing link, not a duplicate profile.
        $second = $this->linker->resolveForLogin($this->identity('sub-6', 'x@fresh.com', true), 'google');
        self::assertSame('existing', $second['status']);
        self::assertSame($first['profile_id'] ?? null, $second['profile_id'] ?? null);
    }
}
