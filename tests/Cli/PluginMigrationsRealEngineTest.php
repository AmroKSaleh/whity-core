<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Database\Database;

/**
 * WC-164: the migration runner executes PLUGIN-declared migrations through the
 * SDK contract ({@see \Whity\Sdk\MigrationInterface}) — fixing the famously
 * dead getMigrations().
 *
 * Real-engine suite: a genuine SQLite database (NOW() UDF mirroring the other
 * RealEngine harnesses) and the REAL PluginLoader + MigrationsCommand drive
 * fixture plugins with portable SQL. The shipped HelloWorld migration uses
 * PostgreSQL-flavoured DDL (DEFAULT NOW()), so its end-to-end proof runs on
 * the real-Postgres dev stack and the postgres-integration CI job (which
 * executes `migrate run`); this suite proves the runner mechanics per the
 * contract: SDK-path execution, per-plugin namespace recording, idempotent
 * re-runs, reconciliation of hand-created schema, transactional rollback on
 * mid-migration failure, and plugin-aware `rollback`.
 */
final class PluginMigrationsRealEngineTest extends TestCase
{
    private static string $pluginsDir;
    private static string $explodingDir;
    private static string $emptyCoreDir;

    private PDO $pdo;
    private Database $db;

    private static string $hijackDir;
    private static string $mixedDir;
    private static string $restartUpDir;
    private static string $restartDownDir;

    public static function setUpBeforeClass(): void
    {
        // One shared fixture tree per process: plugin classes can only be
        // require'd once, so every test reuses the same namespaces/paths.
        self::$pluginsDir = sys_get_temp_dir() . '/whity_migrunner_' . uniqid();
        self::$explodingDir = sys_get_temp_dir() . '/whity_migexploder_' . uniqid();
        self::$hijackDir = sys_get_temp_dir() . '/whity_mighijack_' . uniqid();
        self::$mixedDir = sys_get_temp_dir() . '/whity_migmixed_' . uniqid();
        self::$restartUpDir = sys_get_temp_dir() . '/whity_migrestartup_' . uniqid();
        self::$restartDownDir = sys_get_temp_dir() . '/whity_migrestartdown_' . uniqid();
        self::$emptyCoreDir = sys_get_temp_dir() . '/whity_migcore_' . uniqid();
        mkdir(self::$pluginsDir . '/MigRunnerPlugin/Migrations', 0755, true);
        mkdir(self::$explodingDir . '/MigRunnerExploder/Migrations', 0755, true);
        mkdir(self::$hijackDir . '/MigRunnerHijacker/Migrations', 0755, true);
        mkdir(self::$mixedDir . '/AAThrowingDecl', 0755, true);
        mkdir(self::$mixedDir . '/ZzGoodDecl/Migrations', 0755, true);
        mkdir(self::$restartUpDir . '/MigRestartUp/Migrations', 0755, true);
        mkdir(self::$restartDownDir . '/MigRestartDown/Migrations', 0755, true);
        mkdir(self::$emptyCoreDir, 0755, true);

        file_put_contents(self::$restartUpDir . '/MigRestartUp/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRestartUp;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'MigRestartUp'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\RestartsTransaction::class];
    }
}
PHP);

        file_put_contents(self::$restartUpDir . '/MigRestartUp/Migrations/RestartsTransaction.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRestartUp\Migrations;

use Whity\Sdk\MigrationInterface;

final class RestartsTransaction implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE lost_tbl (id INTEGER PRIMARY KEY)');
        // The guard-bypass shape: end the host transaction AND start a fresh
        // one, so a naive inTransaction() check is satisfied again.
        $pdo->rollBack();
        $pdo->beginTransaction();
    }

    public function down(\PDO $pdo): void
    {
    }
}
PHP);

        file_put_contents(self::$restartDownDir . '/MigRestartDown/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRestartDown;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'MigRestartDown'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\DownRestartsTransaction::class];
    }
}
PHP);

        file_put_contents(self::$restartDownDir . '/MigRestartDown/Migrations/DownRestartsTransaction.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRestartDown\Migrations;

use Whity\Sdk\MigrationInterface;

final class DownRestartsTransaction implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS stay_tbl (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->rollBack();
        $pdo->beginTransaction();
    }
}
PHP);

        file_put_contents(self::$hijackDir . '/MigRunnerHijacker/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRunnerHijacker;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'MigRunnerHijacker'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\HijacksTransaction::class];
    }
}
PHP);

        file_put_contents(self::$hijackDir . '/MigRunnerHijacker/Migrations/HijacksTransaction.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRunnerHijacker\Migrations;

use Whity\Sdk\MigrationInterface;

final class HijacksTransaction implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE first_half (id INTEGER PRIMARY KEY)');
        // Misbehaving migration: ends the HOST's transaction itself.
        $pdo->rollBack();
        $pdo->exec('CREATE TABLE second_half (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
    }
}
PHP);

        file_put_contents(self::$mixedDir . '/AAThrowingDecl/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace AAThrowingDecl;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'AAThrowingDecl'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        throw new \RuntimeException('intentional getMigrations() explosion');
    }
}
PHP);

        file_put_contents(self::$mixedDir . '/ZzGoodDecl/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace ZzGoodDecl;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'ZzGoodDecl'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\CreateGoodTable::class];
    }
}
PHP);

        file_put_contents(self::$mixedDir . '/ZzGoodDecl/Migrations/CreateGoodTable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace ZzGoodDecl\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateGoodTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS good_tbl (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS good_tbl');
    }
}
PHP);

        file_put_contents(self::$pluginsDir . '/MigRunnerPlugin/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRunnerPlugin;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'MigRunnerPlugin'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\CreateProbeTable::class];
    }
}
PHP);

        file_put_contents(self::$pluginsDir . '/MigRunnerPlugin/Migrations/CreateProbeTable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRunnerPlugin\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateProbeTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS probe_items (
            id INTEGER PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            label VARCHAR(255)
        )');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS probe_items');
    }
}
PHP);

        file_put_contents(self::$explodingDir . '/MigRunnerExploder/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRunnerExploder;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'MigRunnerExploder'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\ExplodesMidway::class];
    }
}
PHP);

        file_put_contents(self::$explodingDir . '/MigRunnerExploder/Migrations/ExplodesMidway.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace MigRunnerExploder\Migrations;

use Whity\Sdk\MigrationInterface;

final class ExplodesMidway implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE txn_probe (id INTEGER PRIMARY KEY)');
        throw new \RuntimeException('intentional mid-migration failure');
    }

    public function down(\PDO $pdo): void
    {
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        $dirs = [
            self::$pluginsDir,
            self::$explodingDir,
            self::$hijackDir,
            self::$mixedDir,
            self::$restartUpDir,
            self::$restartDownDir,
            self::$emptyCoreDir,
        ];
        foreach ($dirs as $dir) {
            self::removeDirectory($dir);
        }
    }

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        // Pre-create the tracking table with SQLite-compatible DDL: the
        // runner's own ensureMigrationTable() uses PostgreSQL-flavoured
        // `DEFAULT NOW()` and is a no-op here thanks to IF NOT EXISTS.
        $this->pdo->exec('
            CREATE TABLE core_schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                execution_time_ms INTEGER
            )
        ');

        $pdo = $this->pdo;
        $this->db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
    }

    // ==================== run: SDK-path execution + namespace ====================

    public function testRunExecutesPluginMigrationThroughSdkPath(): void
    {
        $exit = $this->runQuiet($this->command(), ['run']);

        $this->assertSame(0, $exit, 'migrate run must succeed');
        $this->assertTrue($this->tableExists('probe_items'), 'The plugin-declared table must be created');

        $recorded = $this->recordedNames();
        $this->assertContains(
            'plugin:MigRunnerPlugin:CreateProbeTable',
            $recorded,
            'The plugin migration must be recorded under the per-plugin namespace'
        );
        // Every plugin record carries the plugin: prefix — never a bare name.
        foreach ($recorded as $name) {
            $this->assertMatchesRegularExpression('/^plugin:[A-Za-z0-9_]+:/', $name);
        }
    }

    public function testRerunIsIdempotent(): void
    {
        $command = $this->command();
        $this->assertSame(0, $this->runQuiet($command, ['run']));
        $this->assertSame(0, $this->runQuiet($command, ['run']), 'A re-run must succeed');

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM core_schema_migrations WHERE migration_name = 'plugin:MigRunnerPlugin:CreateProbeTable'")
            ->fetchColumn();
        $this->assertSame(1, $count, 'A re-run must not duplicate the tracking row');
        $this->assertTrue($this->tableExists('probe_items'));
    }

    /**
     * Never-run reconcile: the table already exists (hand-created or created by
     * pre-SDK code without tracking). Running the idempotent migration must
     * succeed and record it — adopting the existing state.
     */
    public function testHandCreatedSchemaIsReconciled(): void
    {
        $this->pdo->exec('CREATE TABLE probe_items (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, label VARCHAR(255))');

        $exit = $this->runQuiet($this->command(), ['run']);

        $this->assertSame(0, $exit, 'Hand-created schema must not break the run');
        $this->assertContains('plugin:MigRunnerPlugin:CreateProbeTable', $this->recordedNames());
    }

    // ==================== transactionality ====================

    /**
     * A migration that fails midway must leave NOTHING behind: the DDL it
     * already executed is rolled back with the transaction, and no tracking
     * row is recorded.
     */
    public function testFailedMigrationRollsBackAtomically(): void
    {
        $loader = new PluginLoader(self::$explodingDir, new Router(), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(1, $exit, 'A failing migration must fail the run');
        $this->assertFalse(
            $this->tableExists('txn_probe'),
            'DDL executed before the failure must be rolled back with the transaction'
        );
        $this->assertNotContains('plugin:MigRunnerExploder:ExplodesMidway', $this->recordedNames());
    }

    /**
     * Review BLOCKER regression: a migration that ends the HOST's transaction
     * itself (rollBack/commit inside up()) must NOT leave a phantom tracking
     * row behind — a recorded-but-unapplied migration would be masked from
     * every future re-run, silently diverging schema from the record.
     */
    public function testTransactionHijackingMigrationLeavesNoPhantomRecord(): void
    {
        $loader = new PluginLoader(self::$hijackDir, new Router(), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(1, $exit, 'A transaction-hijacking migration must fail the run');
        $this->assertNotContains(
            'plugin:MigRunnerHijacker:HijacksTransaction',
            $this->recordedNames(),
            'No tracking row may be recorded when the migration broke the host transaction'
        );
        // NOTE: statements the hijacker ran AFTER its own rollBack() executed
        // in autocommit and cannot be undone by the runner — that damage is the
        // forbidden behaviour's own doing. The invariant the runner protects is
        // the RECORD: never a phantom "executed" row masking unapplied schema.
    }

    /**
     * Re-review BLOCKER regression: rollBack() FOLLOWED BY beginTransaction()
     * inside up() defeats a naive inTransaction() guard — the fresh transaction
     * satisfies the boolean while the pre-rollback DDL is gone. The tracking
     * row written inside the runner's ORIGINAL transaction acts as a sentinel:
     * it vanishes with the hijacked rollback, so the runner detects the swap,
     * fails the migration, and records nothing.
     */
    public function testRollbackThenBeginHijackInUpLeavesNoPhantomRecord(): void
    {
        $loader = new PluginLoader(self::$restartUpDir, new Router(), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(1, $exit, 'A rollback-then-begin hijack must fail the run');
        $this->assertNotContains(
            'plugin:MigRestartUp:RestartsTransaction',
            $this->recordedNames(),
            'The sentinel must prevent a phantom record when the transaction was swapped'
        );
    }

    /**
     * Down-direction variant: a hijacking down() must not DE-record a
     * migration whose rollback never actually applied.
     */
    public function testRollbackThenBeginHijackInDownKeepsRecord(): void
    {
        $loader = new PluginLoader(self::$restartDownDir, new Router(), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        $this->assertSame(0, $this->runQuiet($command, ['run']), 'The well-behaved up() must apply');
        $this->assertContains('plugin:MigRestartDown:DownRestartsTransaction', $this->recordedNames());

        $exit = $this->runQuiet($command, ['rollback']);

        $this->assertSame(1, $exit, 'A hijacking down() must fail the rollback');
        $this->assertContains(
            'plugin:MigRestartDown:DownRestartsTransaction',
            $this->recordedNames(),
            'The migration must stay recorded when its down() never genuinely applied'
        );
    }

    /**
     * Review MAJOR regression: one plugin whose getMigrations() throws must be
     * warned about and SKIPPED — core migrations and every other plugin's
     * migrations still run (AAThrowingDecl loads before ZzGoodDecl).
     */
    public function testThrowingGetMigrationsIsSkippedAndOthersStillRun(): void
    {
        $loader = new PluginLoader(self::$mixedDir, new Router(), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(0, $exit, 'One broken declaration must not fail the whole run');
        $this->assertTrue($this->tableExists('good_tbl'), "The well-behaved plugin's migration must still run");
        $this->assertContains('plugin:ZzGoodDecl:CreateGoodTable', $this->recordedNames());
    }

    // ==================== rollback ====================

    /**
     * Review MAJOR regression: with identical executed_at timestamps (one
     * batch run), rollback must pick the genuinely LAST migration — the
     * highest id — not whichever row the engine happens to return first.
     */
    public function testRollbackBreaksTimestampTiesByIdDescending(): void
    {
        // Same executed_at for both rows; the plugin row is the later insert.
        $this->pdo->exec("INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
            VALUES ('000_fake_core', '2030-01-01 00:00:00', 1)");
        $this->pdo->exec("INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
            VALUES ('plugin:MigRunnerPlugin:CreateProbeTable', '2030-01-01 00:00:00', 1)");
        $this->pdo->exec('CREATE TABLE probe_items (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, label VARCHAR(255))');

        $exit = $this->runQuiet($this->command(), ['rollback']);

        $this->assertSame(0, $exit, 'Rollback must target the later row (the plugin migration), not the tied core row');
        $this->assertFalse($this->tableExists('probe_items'), 'The plugin migration must be the one rolled back');
        $this->assertContains('000_fake_core', $this->recordedNames(), 'The tied earlier row must remain untouched');
    }

    public function testRollbackRunsPluginDownAndRemovesRecord(): void
    {
        $command = $this->command();
        $this->assertSame(0, $this->runQuiet($command, ['run']));
        $this->assertTrue($this->tableExists('probe_items'));

        $exit = $this->runQuiet($command, ['rollback']);

        $this->assertSame(0, $exit, 'Rolling back a plugin migration must succeed');
        $this->assertFalse($this->tableExists('probe_items'), 'down() must drop the plugin table');
        $this->assertNotContains('plugin:MigRunnerPlugin:CreateProbeTable', $this->recordedNames());
    }

    // ==================== status ====================

    public function testStatusListsPluginMigrations(): void
    {
        $command = $this->command();

        ob_start();
        $exit = $command->execute(['status']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('plugin:MigRunnerPlugin:CreateProbeTable', $output);
        $this->assertStringContainsString('Pending', $output);
    }

    // ==================== helpers ====================

    private function command(): MigrationsCommand
    {
        $loader = new PluginLoader(self::$pluginsDir, new Router(), null, new HookManager());

        return new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);
    }

    /**
     * @param array<string> $argv
     */
    private function runQuiet(MigrationsCommand $command, array $argv): int
    {
        ob_start();
        try {
            return $command->execute($argv);
        } finally {
            ob_end_clean();
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetch() !== false;
    }

    /**
     * @return list<string>
     */
    private function recordedNames(): array
    {
        try {
            /** @var list<string> */
            return $this->pdo
                ->query('SELECT migration_name FROM core_schema_migrations')
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            return [];
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
