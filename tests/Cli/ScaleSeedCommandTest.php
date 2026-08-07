<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\ScaleSeedCommand;
use Whity\Database\Database;

/**
 * Tests for {@see ScaleSeedCommand} (WC-35): CLI argument parsing, `--help`,
 * `--dry-run` (no DB required), and a full execute() against an injected
 * in-memory SQLite {@see Database} so the command's happy path — including
 * `--reset` — is exercised without a real PostgreSQL connection.
 */
final class ScaleSeedCommandTest extends TestCase
{
    private const PASSWORD_ENV_VAR = 'SCALE_SEED_PASSWORD';
    private const PASSWORD_VALUE = 'wc35-cli-fixture-password-0123456789ABCDE';

    protected function setUp(): void
    {
        $_ENV[self::PASSWORD_ENV_VAR] = self::PASSWORD_VALUE;
        putenv(self::PASSWORD_ENV_VAR . '=' . self::PASSWORD_VALUE);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::PASSWORD_ENV_VAR]);
        putenv(self::PASSWORD_ENV_VAR);
    }

    /**
     * Close the output buffer and return what the command printed.
     *
     * ob_get_clean() is declared string|false — false only when no buffer is
     * active, which never happens here since every caller opens one first.
     * Asserting that narrows the type for the assertions downstream.
     */
    private static function endCapture(): string
    {
        $output = ob_get_clean();
        self::assertIsString($output, 'An output buffer must be active.');

        return $output;
    }

    private function inMemoryDatabase(): Database
    {
        $pdo = SchemaFromMigrations::make();
        $db = Database::withFactory(fn(): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    public function testCommandCanBeInstantiated(): void
    {
        self::assertInstanceOf(ScaleSeedCommand::class, new ScaleSeedCommand());
    }

    public function testHelpFlagPrintsUsageAndReturnsZero(): void
    {
        $command = new ScaleSeedCommand();

        ob_start();
        $exitCode = $command->execute(['--help']);
        $output = self::endCapture();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('scale:seed', $output);
        self::assertStringContainsString('--seed', $output);
        self::assertStringContainsString('--scale', $output);
    }

    public function testDryRunPrintsThePlanAndTouchesNoDatabase(): void
    {
        // No injected Database at all: if the command tried to touch a real DB
        // in --dry-run mode this would throw (Database::connect() requires
        // DB_USER/DB_PASSWORD), so a clean exit 0 proves no DB access occurred.
        $command = new ScaleSeedCommand();

        ob_start();
        $exitCode = $command->execute(['--dry-run', '--tenants=3', '--users-per-tenant=10']);
        $output = self::endCapture();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Dry run', $output);
        self::assertStringContainsString('tenants:', $output);
        self::assertStringContainsString('3', $output);
    }

    public function testInvalidOptionValueReturnsExitCodeOneWithoutTouchingTheDatabase(): void
    {
        $command = new ScaleSeedCommand();

        ob_start();
        $exitCode = $command->execute(['--tenants=not-a-number']);
        $output = self::endCapture();

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Error', $output);
    }

    public function testExecuteAgainstAnInjectedDatabaseSeedsAndReportsCounts(): void
    {
        $command = new ScaleSeedCommand($this->inMemoryDatabase());

        ob_start();
        $exitCode = $command->execute([
            '--seed=321',
            '--tenants=2',
            '--users-per-tenant=3',
            '--ou-depth=2',
            '--ou-breadth=2',
            '--custom-roles-per-tenant=1',
            '--relations-per-person=1',
        ]);
        $output = self::endCapture();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Scale-seed complete', $output);
        self::assertStringContainsString('Result (created / reused)', $output);
        self::assertStringContainsString('tenant 1/2 done', $output);
        self::assertStringContainsString('tenant 2/2 done', $output);
    }

    public function testRerunningAgainstTheSameDatabaseReportsReusedNotCreated(): void
    {
        $db = $this->inMemoryDatabase();
        $args = ['--seed=654', '--tenants=1', '--users-per-tenant=2', '--ou-depth=1', '--custom-roles-per-tenant=0'];

        ob_start();
        (new ScaleSeedCommand($db))->execute($args);
        ob_get_clean();

        ob_start();
        $exitCode = (new ScaleSeedCommand($db))->execute($args);
        $output = self::endCapture();

        self::assertSame(0, $exitCode);
        // "tenants:      0 / 1" -> zero created, one reused.
        self::assertMatchesRegularExpression('/tenants:\s+0 \/ 1/', $output);
    }

    public function testResetFlagReportsRemovedRowsBeforeReseeding(): void
    {
        $db = $this->inMemoryDatabase();
        $args = ['--seed=987', '--tenants=1', '--users-per-tenant=2', '--ou-depth=1', '--custom-roles-per-tenant=0'];

        ob_start();
        (new ScaleSeedCommand($db))->execute($args);
        ob_get_clean();

        ob_start();
        $exitCode = (new ScaleSeedCommand($db))->execute([...$args, '--reset']);
        $output = self::endCapture();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Resetting prior scale-seeded data', $output);
        self::assertStringContainsString('Removed 1 tenant(s), 2 profile(s)', $output);
        // After reset + reseed, the fresh run must have created (not reused) everything again.
        self::assertMatchesRegularExpression('/tenants:\s+1 \/ 0/', $output);
    }
}
