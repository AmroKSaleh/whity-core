<?php

declare(strict_types=1);

namespace Whity\Tests\Commands;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Commands\RevokedTokensCleanupCommand;

/**
 * Real-engine tests for RevokedTokensCleanupCommand (WC-188).
 *
 * The cron command prunes EXPIRED revocation entries from the sanctioned GLOBAL
 * revoked_tokens table so it cannot grow unbounded once access-token jtis are
 * recorded on logout/password-change (WC-185).
 *
 * These run against a real SQL engine — in-memory SQLite locally AND real
 * PostgreSQL in CI — so the delete is genuinely executed, not mocked or skipped.
 * That matters because the command's `WHERE expires_at < CURRENT_TIMESTAMP`
 * comparison is portable SQL that must behave identically on both engines: the
 * earlier `NOW()` form parsed on PostgreSQL but is not a SQLite function, so the
 * previous suite could only ever run (and was skipped) against PostgreSQL,
 * leaving the cleanup behaviour unverified locally. CURRENT_TIMESTAMP is
 * standard SQL evaluated by both engines, and expires_at is written as a UTC
 * 'Y-m-d H:i:s' literal that compares correctly against SQLite's same-format
 * CURRENT_TIMESTAMP. The schema mirrors production migration 011 (jti UNIQUE +
 * the two supporting indexes). The pattern mirrors
 * {@see \Tests\Auth\AccessTokenRevocationRealEngineTest}.
 */
final class RevokedTokensCleanupCommandTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
    }

    public function testCommandCanBeInstantiated(): void
    {
        $command = new RevokedTokensCleanupCommand($this->pdo);
        $this->assertInstanceOf(RevokedTokensCleanupCommand::class, $command);
    }

    /**
     * The core contract: expired rows are deleted, non-expired rows are
     * retained, and the deleted count is reported.
     */
    public function testCleanupDeletesExpiredRetainsNonExpiredAndReportsCount(): void
    {
        $this->insertRevocation('expired_1', time() - 3600);   // 1h ago
        $this->insertRevocation('expired_2', time() - 86400);  // 1d ago
        $this->insertRevocation('future_1', time() + 3600);    // 1h ahead
        $this->insertRevocation('future_2', time() + 86400);   // 1d ahead

        $this->assertSame(4, $this->countRows(), 'Sanity: all four rows seeded.');

        $output = $this->runCleanup();

        // Deleted count reported.
        $this->assertSame("Cleaned 2 expired revocation entries\n", $output);

        // Only the two non-expired rows survive.
        $this->assertSame(2, $this->countRows());
        $this->assertFalse($this->exists('expired_1'), 'An expired revocation must be pruned.');
        $this->assertFalse($this->exists('expired_2'), 'An expired revocation must be pruned.');
        $this->assertTrue($this->exists('future_1'), 'A not-yet-expired revocation must be retained.');
        $this->assertTrue($this->exists('future_2'), 'A not-yet-expired revocation must be retained.');
    }

    public function testCleanupRetainsAllRowsWhenNoneExpired(): void
    {
        $this->insertRevocation('future_1', time() + 7200);
        $this->insertRevocation('future_2', time() + 10800);

        $output = $this->runCleanup();

        $this->assertSame("Cleaned 0 expired revocation entries\n", $output);
        $this->assertSame(2, $this->countRows(), 'No rows expired, so none are deleted.');
    }

    public function testCleanupDeletesAllRowsWhenAllExpired(): void
    {
        $this->insertRevocation('expired_1', time() - 3600);
        $this->insertRevocation('expired_2', time() - 7200);
        $this->insertRevocation('expired_3', time() - 86400);

        $output = $this->runCleanup();

        $this->assertSame("Cleaned 3 expired revocation entries\n", $output);
        $this->assertSame(0, $this->countRows(), 'All rows expired, so the table is emptied.');
    }

    public function testCleanupOnEmptyTableReportsZero(): void
    {
        $this->assertSame(0, $this->countRows());

        $output = $this->runCleanup();

        $this->assertSame("Cleaned 0 expired revocation entries\n", $output);
        $this->assertSame(0, $this->countRows());
    }

    // ==================== helpers ====================

    private function runCleanup(): string
    {
        $command = new RevokedTokensCleanupCommand($this->pdo);
        ob_start();
        $command->execute();

        return (string) ob_get_clean();
    }

    private function insertRevocation(string $jti, int $expiresAtUnix): void
    {
        // Mirror the production write path (AuthHandler::revokeJti): a portable
        // UTC 'Y-m-d H:i:s' literal derived from the token's exp.
        $stmt = $this->pdo->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)');
        $stmt->execute([$jti, gmdate('Y-m-d H:i:s', $expiresAtUnix)]);
    }

    private function countRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM revoked_tokens')->fetchColumn();
    }

    private function exists(string $jti): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
        $stmt->execute([$jti]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * In-memory SQLite mirroring production migration 011: the sanctioned GLOBAL
     * revoked_tokens table with the UNIQUE jti key and the two supporting indexes
     * (lookup + cleanup). expires_at is TEXT here because SQLite has no native
     * TIMESTAMP type, but the stored 'Y-m-d H:i:s' literals compare lexically the
     * same way they compare as timestamps on PostgreSQL.
     */
    private static function makeSqliteSchema(): PDO
    {
        return SchemaFromMigrations::make();
    }
}
