<?php

declare(strict_types=1);

namespace Whity\Tests\Commands;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;
use Whity\Database\Database;

/**
 * TDD tests for WC-205: targeted rollback modes in MigrationsCommand.
 *
 * Tests three scenarios:
 *  1. `migrate rollback <MigrationClassName>` — rolls back exactly one named
 *     core migration by file-stem name (e.g. "001_create_alpha"), refusing
 *     when a later executed migration depends on the target's table.
 *  2. `migrate rollback --plugin <PluginName>` — rolls back all executed
 *     migrations that belong to the given plugin, in reverse execution order.
 *  3. The existing no-argument rollback continues to work (LIFO, single step).
 *
 * All tests run against an in-memory SQLite database with the tracking table
 * bootstrapped directly — no real migration files are loaded so the suite is
 * fast and self-contained.  The command under test is given an injected
 * Database wrapper and a custom migration directory containing a small set of
 * stub migration files.
 *
 * The stub migration directory is created ONCE per test class (setUpBeforeClass)
 * and cleaned up ONCE (tearDownAfterClass) to avoid PHP's "cannot redeclare
 * class" fatal error that would occur if require_once loaded the same class
 * name from a different temp path in a second setUp() call within the same
 * process.
 */
final class MigrationsCommandTargetedRollbackTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    /** Shared migration directory created once per test class. */
    private static string $sharedMigrationDir;

    // =========================================================================
    // Test-class lifecycle
    // =========================================================================

    public static function setUpBeforeClass(): void
    {
        self::$sharedMigrationDir = self::buildSharedMigrationDir();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$sharedMigrationDir) && is_dir(self::$sharedMigrationDir)) {
            foreach (glob(self::$sharedMigrationDir . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir(self::$sharedMigrationDir);
        }
    }

    protected function setUp(): void
    {
        $this->pdo = $this->buildSqlitePdo();
        $this->db  = Database::withFactory(fn(): PDO => $this->pdo, 86400, 86400);
        $this->db->forceConnect();
        $this->bootstrapTrackingTable();
    }

    // =========================================================================
    // Named-migration rollback
    // =========================================================================

    public function testRollbackByNameSucceedsWhenNoDependents(): void
    {
        // Record two migrations in order; the second one does NOT depend on
        // tables created by the first.
        $this->recordMigration('001_create_alpha', '2026-01-01 00:00:01', 1);
        $this->recordMigration('002_create_beta',  '2026-01-01 00:00:02', 2);

        $exitCode = $this->runCommand(['rollback', '001_create_alpha', '--force']);
        $this->assertSame(0, $exitCode, 'Named rollback with --force should succeed');
        $this->assertMigrationNotRecorded('001_create_alpha');
        $this->assertMigrationRecorded('002_create_beta');
    }

    public function testRollbackByNameRefusesWhenDependentExists(): void
    {
        // 001_create_alpha creates table "alpha_items".
        // 002_create_beta_depends_alpha references "alpha_items" in its up() SQL.
        // Rolling back 001 without --force must be refused.
        $this->recordMigration('001_create_alpha',              '2026-01-01 00:00:01', 1);
        $this->recordMigration('002_create_beta_depends_alpha', '2026-01-01 00:00:02', 2);

        $output   = '';
        $exitCode = $this->runCommand(['rollback', '001_create_alpha'], $output);
        $this->assertSame(1, $exitCode, 'Should refuse when a dependent migration exists');
        $this->assertStringContainsString('002_create_beta_depends_alpha', $output);
        $this->assertMigrationRecorded('001_create_alpha', 'Target must NOT be rolled back on refusal');
        $this->assertMigrationRecorded('002_create_beta_depends_alpha');
    }

    public function testRollbackByNameWithForceBypasesBlockingCheck(): void
    {
        $this->recordMigration('001_create_alpha',              '2026-01-01 00:00:01', 1);
        $this->recordMigration('002_create_beta_depends_alpha', '2026-01-01 00:00:02', 2);

        $exitCode = $this->runCommand(['rollback', '001_create_alpha', '--force']);
        $this->assertSame(0, $exitCode, '--force must bypass the dependency check');
        $this->assertMigrationNotRecorded('001_create_alpha');
        $this->assertMigrationRecorded('002_create_beta_depends_alpha');
    }

    public function testRollbackByNameFailsWhenMigrationNotRecorded(): void
    {
        $output   = '';
        $exitCode = $this->runCommand(['rollback', '001_create_alpha'], $output);
        $this->assertSame(1, $exitCode, 'Should fail when named migration was never run');
        $this->assertStringContainsString('001_create_alpha', $output);
    }

    public function testRollbackByNameFailsWhenFileNotFound(): void
    {
        $this->recordMigration('999_nonexistent', '2026-01-01 00:00:01');

        $output   = '';
        $exitCode = $this->runCommand(['rollback', '999_nonexistent'], $output);
        $this->assertSame(1, $exitCode, 'Should fail when the migration file does not exist');
        $this->assertStringContainsString('999_nonexistent', $output);
    }

    public function testRollbackByNameOnlyMigrationSucceeds(): void
    {
        // Single migration, no dependents — straight rollback.
        $this->recordMigration('001_create_alpha', '2026-01-01 00:00:01');

        $exitCode = $this->runCommand(['rollback', '001_create_alpha']);
        $this->assertSame(0, $exitCode, 'Single migration with no dependents should roll back cleanly');
        $this->assertMigrationNotRecorded('001_create_alpha');
    }

    // =========================================================================
    // Plugin rollback
    // =========================================================================

    public function testRollbackPluginRollsBackAllPluginMigrationsInReverseOrder(): void
    {
        // Two plugin migrations executed in order, one core migration.
        $this->recordMigration('plugin:SamplePlugin:MigrationA', '2026-01-01 00:00:01', 1);
        $this->recordMigration('plugin:SamplePlugin:MigrationB', '2026-01-01 00:00:02', 2);
        $this->recordMigration('001_create_alpha',               '2026-01-01 00:00:03', 3);

        $output   = '';
        $exitCode = $this->runCommand(['rollback', '--plugin', 'SamplePlugin'], $output);

        $this->assertSame(0, $exitCode, 'Plugin rollback should succeed');
        $this->assertMigrationNotRecorded('plugin:SamplePlugin:MigrationA');
        $this->assertMigrationNotRecorded('plugin:SamplePlugin:MigrationB');
        $this->assertMigrationRecorded('001_create_alpha', 'Core migration must be untouched');
    }

    public function testRollbackPluginReverseOrderRespected(): void
    {
        // Verify that MigrationB is rolled back BEFORE MigrationA (reverse
        // execution order).
        $this->recordMigration('plugin:SamplePlugin:MigrationA', '2026-01-01 00:00:01', 1);
        $this->recordMigration('plugin:SamplePlugin:MigrationB', '2026-01-01 00:00:02', 2);

        $output   = '';
        $exitCode = $this->runCommand(['rollback', '--plugin', 'SamplePlugin'], $output);

        $this->assertSame(0, $exitCode);
        // MigrationB should appear before MigrationA in the output
        $posB = strpos($output, 'MigrationB');
        $posA = strpos($output, 'MigrationA');
        $this->assertNotFalse($posB, 'MigrationB should be mentioned in output');
        $this->assertNotFalse($posA, 'MigrationA should be mentioned in output');
        $this->assertLessThan($posA, $posB, 'MigrationB must be rolled back before MigrationA (reverse order)');
    }

    public function testRollbackPluginWithNoExecutedMigrationsSucceedsWithWarning(): void
    {
        $output   = '';
        $exitCode = $this->runCommand(['rollback', '--plugin', 'SamplePlugin'], $output);

        $this->assertSame(0, $exitCode, 'Plugin with no executed migrations should succeed (no-op)');
        $this->assertStringContainsString('SamplePlugin', $output);
    }

    public function testRollbackPluginIgnoresOtherPlugins(): void
    {
        $this->recordMigration('plugin:SamplePlugin:MigrationA', '2026-01-01 00:00:01', 1);
        $this->recordMigration('plugin:OtherPlugin:MigrationX',  '2026-01-01 00:00:02', 2);

        $exitCode = $this->runCommand(['rollback', '--plugin', 'SamplePlugin']);

        $this->assertSame(0, $exitCode);
        $this->assertMigrationNotRecorded('plugin:SamplePlugin:MigrationA');
        $this->assertMigrationRecorded('plugin:OtherPlugin:MigrationX', 'Other plugin must be untouched');
    }

    public function testRollbackPluginMissingPluginNameReturnsError(): void
    {
        $output   = '';
        $exitCode = $this->runCommand(['rollback', '--plugin'], $output);

        $this->assertSame(1, $exitCode, 'Missing plugin name argument should error');
        $this->assertStringContainsString('--plugin', $output);
    }

    // =========================================================================
    // Backward-compatibility: no-argument LIFO rollback
    // =========================================================================

    public function testNoArgRollbackRollsBackLastMigration(): void
    {
        $this->recordMigration('001_create_alpha', '2026-01-01 00:00:01', 1);
        $this->recordMigration('002_create_beta',  '2026-01-01 00:00:02', 2);

        $exitCode = $this->runCommand(['rollback']);

        $this->assertSame(0, $exitCode, 'LIFO rollback should succeed');
        $this->assertMigrationRecorded('001_create_alpha', 'First migration must be untouched');
        $this->assertMigrationNotRecorded('002_create_beta', 'Last migration should be rolled back');
    }

    public function testNoArgRollbackOnEmptyTableSucceeds(): void
    {
        $output   = '';
        $exitCode = $this->runCommand(['rollback'], $output);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No migrations to rollback', $output);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Run the command and return the exit code.  Captures stdout into $output
     * if the reference parameter is provided.
     *
     * @param list<string> $argv
     */
    private function runCommand(array $argv, string &$output = ''): int
    {
        $command  = new MigrationsCommand(
            injectedDb: $this->db,
            pluginLoader: null,
            injectedMigrationDir: self::$sharedMigrationDir
        );
        ob_start();
        $exitCode = $command->execute($argv);
        $output   = (string) ob_get_clean();
        return $exitCode;
    }

    private function buildSqlitePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        return $pdo;
    }

    private function bootstrapTrackingTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS core_schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration_name TEXT NOT NULL UNIQUE,
                executed_at TEXT NOT NULL DEFAULT (datetime('now')),
                execution_time_ms INTEGER
            )
        ");
    }

    private function recordMigration(string $name, string $executedAt = '2026-01-01 00:00:00', ?int $id = null): void
    {
        if ($id !== null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO core_schema_migrations (id, migration_name, executed_at) VALUES (?, ?, ?)'
            );
            $stmt->execute([$id, $name, $executedAt]);
        } else {
            $stmt = $this->pdo->prepare(
                'INSERT INTO core_schema_migrations (migration_name, executed_at) VALUES (?, ?)'
            );
            $stmt->execute([$name, $executedAt]);
        }
    }

    private function assertMigrationRecorded(string $name, string $message = ''): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM core_schema_migrations WHERE migration_name = ?');
        $stmt->execute([$name]);
        $this->assertNotFalse($stmt->fetch(), $message ?: "Migration '{$name}' should be recorded");
    }

    private function assertMigrationNotRecorded(string $name, string $message = ''): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM core_schema_migrations WHERE migration_name = ?');
        $stmt->execute([$name]);
        $this->assertFalse($stmt->fetch(), $message ?: "Migration '{$name}' should NOT be recorded");
    }

    /**
     * Build a shared temporary directory with stub migration files the command
     * can load and execute.  Class names are globally unique so they can only be
     * declared once per PHP process.
     */
    private static function buildSharedMigrationDir(): string
    {
        $dir = sys_get_temp_dir() . '/wc205_migrations_shared';

        // Re-use if already created by a previous parallel run; the files are
        // idempotent since they use IF NOT EXISTS on the underlying tables.
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // 001_create_alpha: creates table alpha_items
        file_put_contents($dir . '/001_create_alpha.php', <<<'PHP'
<?php
namespace Database\Migrations;
use Whity\Database\Database;
class CreateAlpha {
    public static function up(Database $db): void  { $db->exec('CREATE TABLE IF NOT EXISTS alpha_items (id INTEGER PRIMARY KEY)'); }
    public static function down(Database $db): void { $db->exec('DROP TABLE IF EXISTS alpha_items'); }
}
PHP);

        // 002_create_beta: no dependency on alpha_items
        file_put_contents($dir . '/002_create_beta.php', <<<'PHP'
<?php
namespace Database\Migrations;
use Whity\Database\Database;
class CreateBeta {
    public static function up(Database $db): void  { $db->exec('CREATE TABLE IF NOT EXISTS beta_items (id INTEGER PRIMARY KEY)'); }
    public static function down(Database $db): void { $db->exec('DROP TABLE IF EXISTS beta_items'); }
}
PHP);

        // 002_create_beta_depends_alpha: up() SQL references "alpha_items" so
        // it depends on 001_create_alpha.
        file_put_contents($dir . '/002_create_beta_depends_alpha.php', <<<'PHP'
<?php
namespace Database\Migrations;
use Whity\Database\Database;
class CreateBetaDependsAlpha {
    public static function up(Database $db): void  {
        // References alpha_items — dependency the rollback check must detect.
        $db->exec('CREATE TABLE IF NOT EXISTS beta_dep (id INTEGER PRIMARY KEY, alpha_ref INTEGER REFERENCES alpha_items(id))');
    }
    public static function down(Database $db): void { $db->exec('DROP TABLE IF EXISTS beta_dep'); }
}
PHP);

        return $dir;
    }
}
