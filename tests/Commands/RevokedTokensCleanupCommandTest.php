<?php

namespace Whity\Tests\Commands;

use PHPUnit\Framework\TestCase;
use Whity\Commands\RevokedTokensCleanupCommand;
use PDO;

/**
 * Tests for RevokedTokensCleanupCommand
 *
 * Tests the cron command that deletes expired revocation entries.
 * Uses database mocking for unit tests and real database for integration tests.
 */
class RevokedTokensCleanupCommandTest extends TestCase
{
    /**
     * Check if database is available for testing
     */
    private function isDatabaseAvailable(): bool
    {
        try {
            $db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
            $db_user = $_ENV['DB_USER'] ?? getenv('DB_USER');
            $db_password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD');
            $db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME');

            if (!$db_user || !$db_password) {
                return false;
            }

            $pdo = new PDO(
                "pgsql:host=$db_host;dbname=$db_name",
                $db_user,
                $db_password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get a real database connection for testing
     */
    private function getDatabase(): PDO
    {
        $db_host = $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?? 'localhost';
        $db_user = $_ENV['DB_USER'] ?? getenv('DB_USER');
        $db_password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD');
        $db_name = $_ENV['DB_NAME'] ?? getenv('DB_NAME');

        return new PDO(
            "pgsql:host=$db_host;dbname=$db_name",
            $db_user,
            $db_password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Clear the revoked_tokens table before each test
     */
    protected function setUp(): void
    {
        if (!$this->isDatabaseAvailable()) {
            return;
        }

        $db = $this->getDatabase();
        try {
            $db->exec('DELETE FROM revoked_tokens');
        } catch (\PDOException $e) {
            // Table might not exist yet, that's OK
        }
    }

    /**
     * Clear the revoked_tokens table after each test
     */
    protected function tearDown(): void
    {
        if (!$this->isDatabaseAvailable()) {
            return;
        }

        $db = $this->getDatabase();
        try {
            $db->exec('DELETE FROM revoked_tokens');
        } catch (\PDOException $e) {
            // Table might not exist, that's OK
        }
    }

    /**
     * Test that RevokedTokensCleanupCommand can be instantiated with PDO
     */
    public function testCommandCanBeInstantiated(): void
    {
        $mockPdo = $this->createMock(PDO::class);
        $command = new RevokedTokensCleanupCommand($mockPdo);
        $this->assertInstanceOf(RevokedTokensCleanupCommand::class, $command);
    }

    /**
     * Test cleanup deletes only expired rows
     *
     * @requires extension pdo_pgsql
     */
    public function testCleanupDeletesOnlyExpiredRows(): void
    {
        if (!$this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database not available for testing');
        }

        $db = $this->getDatabase();

        // Insert expired token (1 hour ago)
        $expiredTime = date('Y-m-d H:i:s', time() - 3600);
        $stmt = $db->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)');
        $stmt->execute(['expired_token_1', $expiredTime]);

        // Insert non-expired token (1 hour in future)
        $futureTime = date('Y-m-d H:i:s', time() + 3600);
        $stmt = $db->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)');
        $stmt->execute(['future_token_1', $futureTime]);

        // Verify both rows exist
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$result['count']);

        // Run cleanup command
        $command = new RevokedTokensCleanupCommand($db);
        ob_start();
        $command->execute();
        $output = ob_get_clean();

        // Should have deleted 1 row
        $this->assertStringContainsString('Cleaned 1 expired revocation entries', $output);

        // Verify only non-expired row remains
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens WHERE jti = ?');
        $stmt->execute(['future_token_1']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$result['count']);

        // Verify expired row is gone
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens WHERE jti = ?');
        $stmt->execute(['expired_token_1']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$result['count']);
    }

    /**
     * Test cleanup preserves non-expired rows
     *
     * @requires extension pdo_pgsql
     */
    public function testCleanupPreservesNonExpiredRows(): void
    {
        if (!$this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database not available for testing');
        }

        $db = $this->getDatabase();

        // Insert multiple non-expired tokens
        $futureTime1 = date('Y-m-d H:i:s', time() + 7200);
        $futureTime2 = date('Y-m-d H:i:s', time() + 10800);

        $stmt = $db->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)');
        $stmt->execute(['future_token_1', $futureTime1]);
        $stmt->execute(['future_token_2', $futureTime2]);

        // Verify both rows exist
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$result['count']);

        // Run cleanup command
        $command = new RevokedTokensCleanupCommand($db);
        ob_start();
        $command->execute();
        $output = ob_get_clean();

        // Should have deleted 0 rows
        $this->assertStringContainsString('Cleaned 0 expired revocation entries', $output);

        // Verify both rows still exist
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$result['count']);
    }

    /**
     * Test cleanup with empty table
     *
     * @requires extension pdo_pgsql
     */
    public function testCleanupWithEmptyTable(): void
    {
        if (!$this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database not available for testing');
        }

        $db = $this->getDatabase();

        // Verify table is empty
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$result['count']);

        // Run cleanup command
        $command = new RevokedTokensCleanupCommand($db);
        ob_start();
        $command->execute();
        $output = ob_get_clean();

        // Should report 0 deleted rows
        $this->assertStringContainsString('Cleaned 0 expired revocation entries', $output);

        // Table should still be empty
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$result['count']);
    }

    /**
     * Test cleanup with all expired tokens
     *
     * @requires extension pdo_pgsql
     */
    public function testCleanupWithAllExpired(): void
    {
        if (!$this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database not available for testing');
        }

        $db = $this->getDatabase();

        // Insert multiple expired tokens
        $expiredTime1 = date('Y-m-d H:i:s', time() - 3600);
        $expiredTime2 = date('Y-m-d H:i:s', time() - 7200);
        $expiredTime3 = date('Y-m-d H:i:s', time() - 86400); // 1 day ago

        $stmt = $db->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)');
        $stmt->execute(['expired_token_1', $expiredTime1]);
        $stmt->execute(['expired_token_2', $expiredTime2]);
        $stmt->execute(['expired_token_3', $expiredTime3]);

        // Verify all rows exist
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(3, (int)$result['count']);

        // Run cleanup command
        $command = new RevokedTokensCleanupCommand($db);
        ob_start();
        $command->execute();
        $output = ob_get_clean();

        // Should have deleted all 3 rows
        $this->assertStringContainsString('Cleaned 3 expired revocation entries', $output);

        // Verify table is now empty
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$result['count']);
    }

    /**
     * Test cleanup with mixed expired and non-expired tokens
     *
     * @requires extension pdo_pgsql
     */
    public function testCleanupWithMixedTokens(): void
    {
        if (!$this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database not available for testing');
        }

        $db = $this->getDatabase();

        // Insert a mix of expired and non-expired tokens
        $expiredTime1 = date('Y-m-d H:i:s', time() - 3600);
        $expiredTime2 = date('Y-m-d H:i:s', time() - 7200);
        $futureTime1 = date('Y-m-d H:i:s', time() + 3600);
        $futureTime2 = date('Y-m-d H:i:s', time() + 7200);

        $stmt = $db->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)');
        $stmt->execute(['expired_1', $expiredTime1]);
        $stmt->execute(['expired_2', $expiredTime2]);
        $stmt->execute(['future_1', $futureTime1]);
        $stmt->execute(['future_2', $futureTime2]);

        // Verify all rows exist
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(4, (int)$result['count']);

        // Run cleanup command
        $command = new RevokedTokensCleanupCommand($db);
        ob_start();
        $command->execute();
        $output = ob_get_clean();

        // Should have deleted 2 expired rows
        $this->assertStringContainsString('Cleaned 2 expired revocation entries', $output);

        // Verify only 2 rows remain (the non-expired ones)
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$result['count']);

        // Verify the remaining rows are the future ones
        $stmt = $db->query('SELECT COUNT(*) as count FROM revoked_tokens WHERE jti IN (?, ?)');
        $stmt->execute(['future_1', 'future_2']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int)$result['count']);
    }

    /**
     * Test that command output format is correct
     *
     * @requires extension pdo_pgsql
     */
    public function testCommandOutputFormat(): void
    {
        if (!$this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database not available for testing');
        }

        $db = $this->getDatabase();

        // Insert one expired token
        $expiredTime = date('Y-m-d H:i:s', time() - 3600);
        $stmt = $db->prepare('INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)');
        $stmt->execute(['expired_token', $expiredTime]);

        // Run cleanup command and capture output
        $command = new RevokedTokensCleanupCommand($db);
        ob_start();
        $command->execute();
        $output = ob_get_clean();

        // Verify exact output format
        $this->assertSame("Cleaned 1 expired revocation entries\n", $output);
    }
}
