<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\SeedCommand;

/**
 * Tests for SeedCommand CLI handler
 *
 * Tests the CLI-based database seeding execution (no HTTP/API).
 * Focuses on command structure and error handling.
 */
class SeedCommandTest extends TestCase
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

            $pdo = new \PDO(
                "pgsql:host=$db_host;dbname=$db_name",
                $db_user,
                $db_password,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Test that SeedCommand can be instantiated
     */
    public function testCommandCanBeInstantiated(): void
    {
        $command = new SeedCommand();
        $this->assertInstanceOf(SeedCommand::class, $command);
    }

    /**
     * Test seed command with database available
     *
     * @requires extension pdo_pgsql
     */
    public function testSeedCommandWithDatabase(): void
    {
        if (!$this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database not available for testing');
        }

        $command = new SeedCommand();

        ob_start();
        $exitCode = $command->execute([]);
        $output = ob_get_clean();

        // Should succeed
        $this->assertSame(0, $exitCode);
        // Output should contain success message
        $this->assertStringContainsString('seeded', strtolower($output));
        // Output should mention default tenant and users
        $this->assertStringContainsString('Default Tenant', $output);
    }

    /**
     * Test that command fails gracefully without database
     */
    public function testCommandFailsGracefullyWithoutDatabase(): void
    {
        // If database is available, skip this test
        if ($this->isDatabaseAvailable()) {
            $this->markTestSkipped('Database is available, skipping no-database test');
        }

        $command = new SeedCommand();

        ob_start();
        $exitCode = $command->execute([]);
        $output = ob_get_clean();

        // Should fail with non-zero exit code
        $this->assertNotSame(0, $exitCode);
        // Should show error message
        $this->assertStringContainsString('failed', strtolower($output));
    }
}
