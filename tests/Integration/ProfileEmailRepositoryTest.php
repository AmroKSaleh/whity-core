<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\ProfileEmailRepository;

/**
 * WC-99: integration tests for ProfileEmailRepository.
 *
 * Runs against in-memory SQLite via SchemaFromMigrations::make(), which
 * includes all migrations (028 profiles, 029 profile_emails). The repository
 * is verified to correctly manage the global profile_emails table: inserting,
 * looking up by email or profile, setting primary, verifying, and deleting.
 */
final class ProfileEmailRepositoryTest extends TestCase
{
    private PDO $pdo;
    private ProfileEmailRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->repo = new ProfileEmailRepository($this->pdo);

        // Seed two profiles for use across tests.
        // Use false for the BOOLEAN two_factor_enabled column so the INSERT is
        // accepted by both PostgreSQL (strict boolean) and SQLite (stores as 0).
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Alice', '\$2y\$10\$hash1', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Bob', '\$2y\$10\$hash2', false, 0, 0, datetime('now'), datetime('now'))"
        );
    }

    // ── insert ───────────────────────────────────────────────────────────────

    public function testInsertReturnsNewId(): void
    {
        $id = $this->repo->insert(1, 'alice@corp.com');
        self::assertGreaterThan(0, $id);
    }

    public function testInsertDefaultsToUnverifiedNonPrimary(): void
    {
        $id = $this->repo->insert(1, 'alice@corp.com');
        $row = $this->repo->findById($id);
        self::assertIsArray($row);
        self::assertFalse($row['verified']);
        self::assertFalse($row['is_primary']);
    }

    public function testInsertWithVerifiedAndPrimaryFlags(): void
    {
        $id = $this->repo->insert(1, 'alice@corp.com', verified: true, isPrimary: true);
        $row = $this->repo->findById($id);
        self::assertIsArray($row);
        self::assertTrue($row['verified']);
        self::assertTrue($row['is_primary']);
    }

    // ── findById ─────────────────────────────────────────────────────────────

    public function testFindByIdReturnsNullForMissing(): void
    {
        self::assertNull($this->repo->findById(99999));
    }

    public function testFindByIdReturnsCorrectRow(): void
    {
        $id = $this->repo->insert(1, 'alice@corp.com', verified: true);
        $row = $this->repo->findById($id);
        self::assertIsArray($row);
        self::assertSame($id, $row['id']);
        self::assertSame(1, $row['profile_id']);
        self::assertSame('alice@corp.com', $row['email']);
        self::assertTrue($row['verified']);
    }

    // ── findByEmail ──────────────────────────────────────────────────────────

    public function testFindByEmailReturnsNullForMissing(): void
    {
        self::assertNull($this->repo->findByEmail('nobody@nowhere.com'));
    }

    public function testFindByEmailReturnsMatchingRow(): void
    {
        $id = $this->repo->insert(1, 'alice@corp.com', verified: true);
        $row = $this->repo->findByEmail('alice@corp.com');
        self::assertIsArray($row);
        self::assertSame($id, $row['id']);
        self::assertSame(1, $row['profile_id']);
        self::assertTrue($row['verified']);
    }

    // ── findByProfileId ──────────────────────────────────────────────────────

    public function testFindByProfileIdReturnsEmptyForNoEmails(): void
    {
        self::assertSame([], $this->repo->findByProfileId(1));
    }

    public function testFindByProfileIdReturnsAllEmailsForProfile(): void
    {
        $this->repo->insert(1, 'alice@work.com', verified: true, isPrimary: true);
        $this->repo->insert(1, 'alice@home.com');
        $this->repo->insert(2, 'bob@corp.com', verified: true);

        $aliceEmails = $this->repo->findByProfileId(1);
        self::assertCount(2, $aliceEmails);

        $emails = array_column($aliceEmails, 'email');
        self::assertContains('alice@work.com', $emails);
        self::assertContains('alice@home.com', $emails);
    }

    // ── findPrimaryForProfile ────────────────────────────────────────────────

    public function testFindPrimaryForProfileReturnsNullWhenNoPrimary(): void
    {
        $this->repo->insert(1, 'alice@corp.com', verified: true, isPrimary: false);
        self::assertNull($this->repo->findPrimaryForProfile(1));
    }

    public function testFindPrimaryForProfileReturnsPrimaryRow(): void
    {
        $this->repo->insert(1, 'alice@old.com', verified: true, isPrimary: false);
        $primaryId = $this->repo->insert(1, 'alice@new.com', verified: true, isPrimary: true);

        $primary = $this->repo->findPrimaryForProfile(1);
        self::assertIsArray($primary);
        self::assertSame($primaryId, $primary['id']);
        self::assertSame('alice@new.com', $primary['email']);
    }

    // ── setVerified ──────────────────────────────────────────────────────────

    public function testSetVerifiedUpdatesVerifiedFlag(): void
    {
        $id = $this->repo->insert(1, 'alice@corp.com', verified: false);
        $affected = $this->repo->setVerified($id, true);
        self::assertSame(1, $affected);

        $row = $this->repo->findById($id);
        self::assertIsArray($row);
        self::assertTrue($row['verified']);
    }

    public function testSetVerifiedReturnZeroForMissingId(): void
    {
        $affected = $this->repo->setVerified(99999, true);
        self::assertSame(0, $affected);
    }

    // ── setPrimary ───────────────────────────────────────────────────────────

    public function testSetPrimaryClearsOldPrimaryForSameProfile(): void
    {
        $oldId = $this->repo->insert(1, 'alice@old.com', verified: true, isPrimary: true);
        $newId = $this->repo->insert(1, 'alice@new.com', verified: true, isPrimary: false);

        $this->repo->setPrimary(1, $newId);

        $oldRow = $this->repo->findById($oldId);
        $newRow = $this->repo->findById($newId);
        self::assertIsArray($oldRow);
        self::assertIsArray($newRow);
        self::assertFalse($oldRow['is_primary'], 'Old primary must be cleared.');
        self::assertTrue($newRow['is_primary'], 'New primary must be set.');
    }

    public function testSetPrimaryDoesNotAffectOtherProfilesEmails(): void
    {
        $aliceId  = $this->repo->insert(1, 'alice@corp.com', verified: true, isPrimary: true);
        $bobId    = $this->repo->insert(2, 'bob@corp.com',   verified: true, isPrimary: true);

        // Promote Alice's secondary to primary.
        $alice2Id = $this->repo->insert(1, 'alice@home.com', verified: true, isPrimary: false);
        $this->repo->setPrimary(1, $alice2Id);

        // Bob's primary must not be touched.
        $bobRow = $this->repo->findById($bobId);
        self::assertIsArray($bobRow);
        self::assertTrue($bobRow['is_primary'], "setPrimary on profile 1 must not affect profile 2's emails.");
    }

    // ── delete ───────────────────────────────────────────────────────────────

    public function testDeleteRemovesEmailRow(): void
    {
        $id = $this->repo->insert(1, 'alice@corp.com');
        $affected = $this->repo->delete($id);
        self::assertSame(1, $affected);
        self::assertNull($this->repo->findById($id));
    }

    public function testDeleteReturnsZeroForMissingId(): void
    {
        self::assertSame(0, $this->repo->delete(99999));
    }

    // ── countForProfile ───────────────────────────────────────────────────────

    public function testCountForProfileReturnsZeroInitially(): void
    {
        self::assertSame(0, $this->repo->countForProfile(1));
    }

    public function testCountForProfileReturnsCorrectCount(): void
    {
        $this->repo->insert(1, 'alice@work.com');
        $this->repo->insert(1, 'alice@home.com');
        $this->repo->insert(2, 'bob@corp.com');

        self::assertSame(2, $this->repo->countForProfile(1));
        self::assertSame(1, $this->repo->countForProfile(2));
    }
}
