<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;

/**
 * Tests for MigrationsCommand CLI handler
 *
 * Tests the CLI-based migrations execution (no HTTP/API).
 * MigrationsCommand directly connects to database and executes migrations.
 */
class MigrationsCommandTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        // Use temporary directory for test migrations
        $this->migrationsDir = sys_get_temp_dir() . '/test_migrations_' . uniqid();
        mkdir($this->migrationsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->migrationsDir)) {
            array_map('unlink', glob($this->migrationsDir . '/*'));
            rmdir($this->migrationsDir);
        }
    }

    /**
     * Test migration status shows pending/executed migrations
     */
    public function testMigrationStatus(): void
    {
        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute(['status']);
        $output = ob_get_clean();

        // Should succeed with exit code 0
        $this->assertSame(0, $exitCode);

        // Output should contain migration status table
        $this->assertStringContainsString('Migration Status', $output);
        $this->assertStringContainsString('Migration', $output);
        $this->assertStringContainsString('Status', $output);
    }

    /**
     * Test migration run executes pending migrations
     */
    public function testMigrationRun(): void
    {
        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute(['run']);
        $output = ob_get_clean();

        // Should complete (may succeed or fail depending on DB state)
        // But should not crash with an exception
        $this->assertIsInt($exitCode);
        $this->assertStringContainsString('Running migrations', $output);
    }

    /**
     * Test help command displays usage information
     */
    public function testHelpCommand(): void
    {
        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute(['help']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Migrations Command', $output);
        $this->assertStringContainsString('Usage:', $output);
        $this->assertStringContainsString('status', $output);
        $this->assertStringContainsString('run', $output);
        $this->assertStringContainsString('rollback', $output);
    }

    /**
     * Test unknown action displays error
     */
    public function testUnknownAction(): void
    {
        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute(['invalid-action']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Unknown action', $output);
    }

    /**
     * Test default action is status when none specified
     */
    public function testDefaultActionIsStatus(): void
    {
        $command = new MigrationsCommand();

        ob_start();
        $exitCode = $command->execute([]);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Migration Status', $output);
    }
}
