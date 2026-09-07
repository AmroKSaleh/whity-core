<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Tests\Support\RequiresConfiguredDatabase;
use Whity\Cli\Commands\SeedCommand;

/**
 * Tests for SeedCommand CLI handler
 *
 * Tests the CLI-based database seeding execution (no HTTP/API).
 * Focuses on command structure and error handling.
 */
class SeedCommandTest extends TestCase
{
    // The probe reads DB_PORT, and fails rather than skips when a database is
    // configured and unreachable — see the trait for why those are two different
    // situations and only one of them deserves a skip (#1013).
    use RequiresConfiguredDatabase;

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
        // Skips only when nothing says where a database is; fails loudly when
        // something does and it is not there.
        $this->connectToConfiguredDatabase();

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
        // The inverse question, and the only half of the probe answerable without
        // opening a socket: this test is about the no-database path, so a
        // configured database — reachable or not — means there is nothing here
        // to assert.
        if (self::aDatabaseIsConfigured()) {
            $this->markTestSkipped('A database is configured, so the no-database path is not what would run.');
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
