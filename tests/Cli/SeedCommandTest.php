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
     * The document demo dataset is seeded ONLY when asked for by name.
     *
     * No database, deliberately — this pins the GATE, and the gate is the part
     * that already went wrong. The demo first rode `--with-fixtures`, which the
     * E2E suite passes because it needs `admin@example.com` to log in as; the
     * demo's eight memberships then pushed that account off the first page of a
     * users table that paginates at ten, and two specs with nothing to do with
     * documents failed on the missing cell.
     *
     * Nothing in the language or the type system catches a flag being widened
     * back into another flag's meaning, and nothing in the PHP suite noticed the
     * first time. So the four cases below are exhaustive on purpose: neither an
     * empty argv nor `--with-fixtures` may imply the demo, and it must be
     * recognised whatever else is on the line.
     */
    public function testTheDocumentDemoIsSeededOnlyWhenAskedForByName(): void
    {
        $this->assertFalse(
            SeedCommand::wantsDocumentDemo([]),
            'A bare `seed` must not lay down demo content.'
        );
        $this->assertFalse(
            SeedCommand::wantsDocumentDemo(['--with-fixtures']),
            'The demo ACCOUNTS flag must never imply the demo CONTENT — that coupling broke E2E.'
        );
        $this->assertTrue(
            SeedCommand::wantsDocumentDemo([SeedCommand::DOCUMENT_DEMO_FLAG])
        );
        $this->assertTrue(
            SeedCommand::wantsDocumentDemo(['seed', '--with-fixtures', SeedCommand::DOCUMENT_DEMO_FLAG]),
            'The two flags are independent and must compose.'
        );
    }

    /**
     * APP_ENV never implies the demo, unlike the `*@example.com` accounts.
     *
     * Asserted rather than assumed because "development" is not one audience: it
     * is a person clicking through a UI and it is also a CI job booting a stack
     * for Playwright, and only the first wants demo content.
     */
    public function testDevelopmentEnvironmentDoesNotImplyTheDocumentDemo(): void
    {
        $previous = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        $_ENV['APP_ENV'] = 'development';
        putenv('APP_ENV=development');

        try {
            $this->assertFalse(SeedCommand::wantsDocumentDemo([]));
            $this->assertFalse(SeedCommand::wantsDocumentDemo(['--with-fixtures']));
        } finally {
            if (is_string($previous)) {
                $_ENV['APP_ENV'] = $previous;
                putenv('APP_ENV=' . $previous);
            } else {
                unset($_ENV['APP_ENV']);
                putenv('APP_ENV');
            }
        }
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
