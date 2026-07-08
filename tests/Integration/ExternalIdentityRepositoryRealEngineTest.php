<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\ExternalIdentityRepository;

/**
 * Real-engine tests for {@see ExternalIdentityRepository} (WC-7ad4), against the
 * migration-built schema (in-memory SQLite locally; real PostgreSQL on the
 * postgres-integration CI job).
 *
 * Proves the federated-login lookup, the UNIQUE(issuer, subject) anti-takeover
 * guard, last-login touch, and profile-scoped unlink.
 */
final class ExternalIdentityRepositoryRealEngineTest extends TestCase
{
    private PDO $pdo;
    private ExternalIdentityRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->repo = new ExternalIdentityRepository($this->pdo);
    }

    private function seedProfile(string $displayName): int
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
        $stmt->execute([':dn' => $displayName, ':ph' => password_hash('x', PASSWORD_BCRYPT)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testLinkAndFindByIssuerSubject(): void
    {
        $profileId = $this->seedProfile('Alice');
        $id = $this->repo->link($profileId, 'google', 'https://accounts.google.com', 'sub-123', 'alice@example.com');
        self::assertGreaterThan(0, $id);

        $row = $this->repo->findByIssuerSubject('https://accounts.google.com', 'sub-123');
        self::assertNotNull($row);
        self::assertSame($profileId, $row['profile_id']);
        self::assertSame('google', $row['provider_key']);
        self::assertSame('alice@example.com', $row['email']);
        self::assertNull($row['last_login_at']);

        // Unknown account → null.
        self::assertNull($this->repo->findByIssuerSubject('https://accounts.google.com', 'nope'));
    }

    public function testDuplicateIssuerSubjectIsRejected(): void
    {
        $a = $this->seedProfile('Alice');
        $b = $this->seedProfile('Bob');
        $this->repo->link($a, 'google', 'https://accounts.google.com', 'shared-sub', 'a@example.com');

        // The same external account cannot be linked to a second profile — the
        // UNIQUE(issuer, subject) guard is the structural anti-takeover control.
        $this->expectException(\PDOException::class);
        $this->repo->link($b, 'google', 'https://accounts.google.com', 'shared-sub', 'b@example.com');
    }

    public function testFindByProfileIdListsAllLinks(): void
    {
        $profileId = $this->seedProfile('Alice');
        $this->repo->link($profileId, 'google', 'https://accounts.google.com', 'g-1', 'a@example.com');
        $this->repo->link($profileId, 'microsoft', 'https://login.microsoftonline.com/x', 'm-1', 'a@corp.com');

        $links = $this->repo->findByProfileId($profileId);
        self::assertCount(2, $links);
        $providers = array_map(static fn(array $r): string => (string) $r['provider_key'], $links);
        self::assertContains('google', $providers);
        self::assertContains('microsoft', $providers);
    }

    public function testTouchLastLoginStampsTheRow(): void
    {
        $profileId = $this->seedProfile('Alice');
        $this->repo->link($profileId, 'google', 'iss', 'sub', 'a@example.com');

        $before = $this->repo->findByIssuerSubject('iss', 'sub');
        self::assertNotNull($before);
        self::assertNull($before['last_login_at']);

        self::assertSame(1, $this->repo->touchLastLogin($before['id']));

        $after = $this->repo->findByIssuerSubject('iss', 'sub');
        self::assertNotNull($after);
        self::assertNotNull($after['last_login_at'], 'last_login_at is stamped after touch');
    }

    public function testUnlinkIsScopedToOwningProfile(): void
    {
        $owner = $this->seedProfile('Owner');
        $other = $this->seedProfile('Other');
        $id = $this->repo->link($owner, 'google', 'iss', 'sub', 'o@example.com');

        // A different profile cannot unlink it.
        self::assertSame(0, $this->repo->unlink($id, $other));
        self::assertNotNull($this->repo->findByIssuerSubject('iss', 'sub'), 'cross-profile unlink must not remove the link');

        // The owner can.
        self::assertSame(1, $this->repo->unlink($id, $owner));
        self::assertNull($this->repo->findByIssuerSubject('iss', 'sub'));
    }
}
