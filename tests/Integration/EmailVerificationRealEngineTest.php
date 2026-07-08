<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\EmailVerificationService;
use Whity\Core\Identity\ProfileEmailRepository;

/**
 * Real-engine tests for the WC-235 verification token lifecycle
 * ({@see EmailVerificationService}), against the full migration-built schema
 * (in-memory SQLite locally; real PostgreSQL on the postgres-integration job).
 *
 * Proves: issue persists only the HASH (never the raw token) with a future
 * expiry and supersedes prior outstanding tokens; confirm verifies the email
 * and is single-use, and rejects expired / unknown / replayed tokens.
 */
final class EmailVerificationRealEngineTest extends TestCase
{
    private PDO $pdo;
    private EmailVerificationService $service;
    private ProfileEmailRepository $emails;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->service = new EmailVerificationService($this->pdo);
        $this->emails = new ProfileEmailRepository($this->pdo);
    }

    /**
     * Insert a profile + one unverified profile_email.
     *
     * @return array{0: int, 1: int, 2: string} [profileId, profileEmailId, email]
     */
    private function seedUnverifiedEmail(string $email): array
    {
        $this->pdo->prepare(
            'INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:dn, :ph, false, NULL, 0, 0, NOW(), NOW())'
        )->execute([':dn' => 'Test User', ':ph' => password_hash('x', PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();

        $profileEmailId = $this->emails->insert($profileId, $email, false, true);

        return [$profileId, $profileEmailId, $email];
    }

    private function verifiedFlag(int $profileEmailId): string
    {
        return (string) $this->col("SELECT verified FROM profile_emails WHERE id = {$profileEmailId}");
    }

    /** Run a scalar query with the result type narrowed away from `false`. */
    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        return $stmt->fetchColumn();
    }

    /**
     * Run a row query with the result type narrowed away from `false`.
     *
     * @return array<string, mixed>
     */
    private function row(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    public function testIssuePersistsOnlyTheHashWithAFutureExpiry(): void
    {
        [, $peid] = $this->seedUnverifiedEmail('issue@acme.test');

        $raw = $this->service->issue($peid);

        // A 256-bit token → 64 hex chars.
        self::assertSame(64, strlen($raw));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);

        $row = $this->row(
            "SELECT token_hash, expires_at, consumed_at FROM email_verifications WHERE profile_email_id = {$peid}"
        );

        // The RAW token is never stored — only its SHA-256 hash.
        self::assertSame(hash('sha256', $raw), $row['token_hash']);
        self::assertNotSame($raw, $row['token_hash']);
        // Outstanding (unconsumed) and not yet expired.
        self::assertNull($row['consumed_at']);
        self::assertGreaterThan(gmdate('Y-m-d H:i:s'), (string) $row['expires_at']);
    }

    public function testIssueSupersedesPriorOutstandingToken(): void
    {
        [, $peid] = $this->seedUnverifiedEmail('supersede@acme.test');

        $first = $this->service->issue($peid);
        $second = $this->service->issue($peid);

        // Only one outstanding token remains for the email.
        self::assertSame(1, (int) $this->col(
            "SELECT COUNT(*) FROM email_verifications WHERE profile_email_id = {$peid}"
        ));

        // The old link no longer works; the new one does.
        self::assertNull($this->service->confirm($first));
        self::assertNotNull($this->service->confirm($second));
    }

    public function testConfirmVerifiesTheEmailAndReturnsOwnerContext(): void
    {
        [$profileId, $peid] = $this->seedUnverifiedEmail('confirm@acme.test');
        self::assertNotContains($this->verifiedFlag($peid), ['1', 't', 'true']);

        $raw = $this->service->issue($peid);
        $result = $this->service->confirm($raw);

        self::assertNotNull($result);
        self::assertSame($peid, $result['profile_email_id']);
        self::assertSame($profileId, $result['profile_id']);
        self::assertSame('confirm@acme.test', $result['email']);

        // The email is now verified …
        self::assertContains($this->verifiedFlag($peid), ['1', 't', 'true']);
    }

    public function testConfirmIsSingleUse(): void
    {
        [, $peid] = $this->seedUnverifiedEmail('once@acme.test');
        $raw = $this->service->issue($peid);

        self::assertNotNull($this->service->confirm($raw));
        // Replaying the same token is rejected (consumed).
        self::assertNull($this->service->confirm($raw));
    }

    public function testConfirmRejectsAnExpiredToken(): void
    {
        [, $peid] = $this->seedUnverifiedEmail('expired@acme.test');

        // Issue with a already-elapsed TTL.
        $raw = $this->service->issue($peid, -10);

        self::assertNull($this->service->confirm($raw));
        // The email stays unverified.
        self::assertNotContains($this->verifiedFlag($peid), ['1', 't', 'true']);
    }

    public function testConfirmRejectsUnknownAndEmptyTokens(): void
    {
        self::assertNull($this->service->confirm('deadbeef'));
        self::assertNull($this->service->confirm(''));
    }
}
