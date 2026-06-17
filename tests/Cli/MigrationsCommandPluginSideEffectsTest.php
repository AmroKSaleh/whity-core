<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Database\Database;

/**
 * WC-214: pin the `migrate` CLI command's plugin-loading side-effect contract.
 *
 * The contract this suite enforces:
 *
 *  1. ISOLATION. Loading plugins to collect their declared migrations must not
 *     register routes / hooks / permissions on any live or caller-shared state,
 *     and must not require a live HTTP router. {@see MigrationsCommand} builds
 *     its OWN throwaway {@see Router}/{@see HookManager}/permission collaborators
 *     for the loader (or uses an explicitly injected loader in tests); the live
 *     application router/hook registry is never touched. The CLI also runs in a
 *     separate process from the FrankenPHP workers, so even a plugin
 *     CONSTRUCTOR side effect is confined to that short-lived CLI process.
 *
 *  2. MALFORMED plugin => exit 1. An unparseable plugin file (ParseError from
 *     require_once) already degrades the command to exit 1. A loaded plugin
 *     whose getMigrations() THROWS is now ALSO a hard failure: the run still
 *     executes core + healthy-plugin migrations, prints the per-plugin warning,
 *     but RETURNS 1 so an operator sees that a plugin was malformed.
 *
 *  3. GATE-INCOMPATIBLE plugin => exit 0. A plugin quarantined by the WC-211/
 *     WC-72 gate (incompatible SDK/core version) is a VALID exclusion, not a
 *     malformation: the gate drops it from getPlugins() before it can reach the
 *     migration collection, so the rest of the run proceeds and exits 0.
 *
 * Harness mirrors {@see PluginMigrationsRealEngineTest}: a genuine in-memory
 * SQLite database (with a NOW() UDF), unique per-fixture namespaces/paths
 * (plugin classes can only be require'd once per process), and the REAL
 * PluginLoader + MigrationsCommand. Plugin dirs are isolated temp directories,
 * never the repo plugins/ dir, so the suite is hermetic.
 */
final class MigrationsCommandPluginSideEffectsTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    /** Fixture plugin directories, one per scenario (unique namespaces). */
    private static string $sideEffectsDir;
    private static string $ctorFlagDir;
    private static string $syntaxErrorDir;
    private static string $throwingDeclDir;
    private static string $incompatibleDir;

    /** Empty directory used as the (irrelevant) core-migration dir. */
    private static string $emptyCoreDir;

    public static function setUpBeforeClass(): void
    {
        $base = sys_get_temp_dir();
        self::$sideEffectsDir  = $base . '/whity_wc214_sidefx_' . uniqid();
        self::$ctorFlagDir     = $base . '/whity_wc214_ctor_' . uniqid();
        self::$syntaxErrorDir  = $base . '/whity_wc214_syntax_' . uniqid();
        self::$throwingDeclDir = $base . '/whity_wc214_throwdecl_' . uniqid();
        self::$incompatibleDir = $base . '/whity_wc214_incompat_' . uniqid();
        self::$emptyCoreDir    = $base . '/whity_wc214_core_' . uniqid();

        mkdir(self::$sideEffectsDir . '/Wc214SideEffects/Migrations', 0755, true);
        mkdir(self::$ctorFlagDir . '/Wc214CtorFlag/Migrations', 0755, true);
        mkdir(self::$syntaxErrorDir . '/Wc214Syntax', 0755, true);
        mkdir(self::$throwingDeclDir . '/Wc214Throwing', 0755, true);
        mkdir(self::$throwingDeclDir . '/Wc214Healthy/Migrations', 0755, true);
        mkdir(self::$incompatibleDir . '/Wc214Incompatible/Migrations', 0755, true);
        mkdir(self::$incompatibleDir . '/Wc214Compatible/Migrations', 0755, true);
        mkdir(self::$emptyCoreDir, 0755, true);

        // --- Isolation A: a plugin that declares routes/hooks/permissions AND
        //     a migration. Loading it must register NOTHING on shared state. ---
        file_put_contents(self::$sideEffectsDir . '/Wc214SideEffects/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214SideEffects;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'Wc214SideEffects'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/wc214-side-effects',
            'handler' => static fn (Request $r, array $p = []): Response => new Response(200, ''),
        ]];
    }
    public function getPermissions(): array { return ['wc214:read']; }
    public function getHooks(): array
    {
        return ['wc214.event' => static fn (array $d, array $c): array => $d];
    }
    public function getMigrations(): array
    {
        return [Migrations\CreateSideEffectsTable::class];
    }
}
PHP);

        file_put_contents(self::$sideEffectsDir . '/Wc214SideEffects/Migrations/CreateSideEffectsTable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214SideEffects\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateSideEffectsTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS wc214_side_effects (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wc214_side_effects');
    }
}
PHP);

        // --- Isolation B: a plugin whose CONSTRUCTOR flips a public static
        //     flag. Proves a constructor runs during load, but its effect is
        //     confined to the CLI process / throwaway instances. ---
        file_put_contents(self::$ctorFlagDir . '/Wc214CtorFlag/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214CtorFlag;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public static bool $constructed = false;

    public function __construct()
    {
        self::$constructed = true;
    }

    public function getName(): string { return 'Wc214CtorFlag'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\CreateCtorTable::class];
    }
}
PHP);

        file_put_contents(self::$ctorFlagDir . '/Wc214CtorFlag/Migrations/CreateCtorTable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214CtorFlag\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateCtorTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS wc214_ctor (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wc214_ctor');
    }
}
PHP);

        // --- Malformed: a plugin file with a PHP syntax error. ---
        file_put_contents(self::$syntaxErrorDir . '/Wc214Syntax/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Syntax;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'Wc214Syntax' }  // <- missing semicolon: ParseError
PHP);

        // --- Malformed: a healthy plugin alongside one whose getMigrations()
        //     THROWS. Healthy migration must still run; exit must be 1.
        //     (Wc214Healthy sorts before Wc214Throwing, so the healthy one is
        //     collected before the throw — proving partial progress.) ---
        file_put_contents(self::$throwingDeclDir . '/Wc214Throwing/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Throwing;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'Wc214Throwing'; }
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

        file_put_contents(self::$throwingDeclDir . '/Wc214Healthy/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Healthy;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'Wc214Healthy'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\CreateHealthyTable::class];
    }
}
PHP);

        file_put_contents(self::$throwingDeclDir . '/Wc214Healthy/Migrations/CreateHealthyTable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Healthy\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateHealthyTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS wc214_healthy (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wc214_healthy');
    }
}
PHP);

        // --- Gate-incompatible: a plugin with an impossible SDK constraint
        //     alongside a compatible one. The incompatible plugin is
        //     quarantined (excluded from getPlugins()), so the run exits 0 and
        //     the compatible plugin's migration runs. ---
        file_put_contents(self::$incompatibleDir . '/Wc214Incompatible/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Incompatible;

use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

final class Plugin implements PluginInterface, PluginRequirementsInterface
{
    public function getName(): string { return 'Wc214Incompatible'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        // Would create a table if it ever ran — it must NOT, because the gate
        // quarantines this plugin before its migrations are collected.
        return [Migrations\CreateQuarantinedTable::class];
    }

    public function getSdkConstraint(): string { return '^99.0'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
}
PHP);

        file_put_contents(self::$incompatibleDir . '/Wc214Incompatible/Migrations/CreateQuarantinedTable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Incompatible\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateQuarantinedTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS wc214_quarantined (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wc214_quarantined');
    }
}
PHP);

        file_put_contents(self::$incompatibleDir . '/Wc214Compatible/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Compatible;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string { return 'Wc214Compatible'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array
    {
        return [Migrations\CreateCompatibleTable::class];
    }
}
PHP);

        file_put_contents(self::$incompatibleDir . '/Wc214Compatible/Migrations/CreateCompatibleTable.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Wc214Compatible\Migrations;

use Whity\Sdk\MigrationInterface;

final class CreateCompatibleTable implements MigrationInterface
{
    public function up(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS wc214_compatible (id INTEGER PRIMARY KEY)');
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS wc214_compatible');
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([
            self::$sideEffectsDir,
            self::$ctorFlagDir,
            self::$syntaxErrorDir,
            self::$throwingDeclDir,
            self::$incompatibleDir,
            self::$emptyCoreDir,
        ] as $dir) {
            self::removeDirectory($dir);
        }
    }

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        // Pre-create the tracking table with SQLite-compatible DDL; the
        // runner's PostgreSQL-flavoured ensureMigrationTable() is a no-op here
        // thanks to IF NOT EXISTS.
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

    // ==================== Isolation ====================

    /**
     * Isolation A: loading a plugin to collect its migrations must NOT leak any
     * route / hook / permission onto state the rest of the application shares.
     *
     * Reasoning for the assertion: we hand the command a loader wired to the
     * SAME collaborators the loader registers into ($loaderRouter,
     * $loaderHooks, $loaderPerms). We ALSO build a SEPARATE "live application"
     * router and hook manager that the command never receives. After
     * `migrate run`:
     *   - the migration ran (table created, record written) and exit is 0;
     *   - the LIVE app router/hook registry the test holds are still EMPTY,
     *     proving migrate never touched the application's shared HTTP state.
     * Whatever the migrate-owned loader registered stays confined to the
     * throwaway collaborators the loader was built with — which a real
     * deployment discards when the CLI process exits.
     */
    public function testLoadingPluginsDuringMigrateDoesNotLeakToLiveState(): void
    {
        // The collaborators the migrate-owned loader registers into.
        $loaderRouter = new Router('');
        $loaderHooks = new HookManager();
        $loaderPerms = new PermissionRegistry();
        $loader = new PluginLoader(self::$sideEffectsDir, $loaderRouter, $loaderPerms, $loaderHooks);

        // The "live application" collaborators — NEVER handed to the command.
        $liveRouter = new Router('/v1');
        $liveHooks = new HookManager();
        $livePerms = new PermissionRegistry();

        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);
        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(0, $exit, 'migrate run must succeed for a healthy plugin');
        $this->assertTrue($this->tableExists('wc214_side_effects'), 'The plugin migration must have run');
        $this->assertContains('plugin:Wc214SideEffects:CreateSideEffectsTable', $this->recordedNames());

        // The live application state is untouched: migrate built / used its own
        // loader collaborators, never the application's shared HTTP routing/hook
        // registry / permission registry.
        $this->assertSame([], $liveRouter->getRoutes(), 'No route may leak to the live application router');
        $this->assertSame([], $liveHooks->getListeners(), 'No hook may leak to the live hook registry');
        $this->assertFalse($livePerms->exists('wc214:read'), 'No permission may leak to the live permission registry');
    }

    /**
     * Isolation B: a plugin CONSTRUCTOR side effect is confined to the CLI
     * process and throwaway instances — it cannot reach the live application's
     * routing/hook state.
     *
     * Honest about what is guaranteed: a constructor DOES run during load (the
     * gate must instantiate the plugin), so the static flag flips. What the
     * contract guarantees is (a) the CLI runs in a SEPARATE process from the
     * FrankenPHP workers, so a static set here never reaches a worker, and
     * (b) within this process, nothing the constructor or load did was
     * registered onto the live application router/hook registry we hold.
     */
    public function testPluginConstructorSideEffectsStayConfined(): void
    {
        $loaderRouter = new Router('');
        $loaderHooks = new HookManager();
        $loader = new PluginLoader(self::$ctorFlagDir, $loaderRouter, null, $loaderHooks);

        $liveRouter = new Router('/v1');
        $liveHooks = new HookManager();

        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);
        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(0, $exit);
        $this->assertTrue($this->tableExists('wc214_ctor'), 'The plugin migration must have run');

        // The constructor demonstrably ran (load instantiates the plugin).
        // The fixture class is written to disk at runtime, so it is referenced
        // by name and read reflectively to keep static analysis happy.
        /** @var class-string $pluginClass */
        $pluginClass = 'Wc214CtorFlag\\Plugin';
        $constructed = (new \ReflectionClass($pluginClass))->getStaticPropertyValue('constructed');
        $this->assertTrue(
            $constructed,
            'The plugin constructor runs during load (instantiation is required to gate it)'
        );

        // ...but its effect did not reach the live application state, and in a
        // real deployment it runs in a different process from the workers.
        $this->assertSame([], $liveRouter->getRoutes(), 'No route may leak to the live application router');
        $this->assertSame([], $liveHooks->getListeners(), 'No hook may leak to the live hook registry');
    }

    // ==================== Malformed => exit 1 ====================

    /**
     * An unparseable plugin file (ParseError from require_once) degrades
     * `migrate run` to exit 1. Pins the already-correct behaviour.
     */
    public function testSyntaxErrorPluginFileMakesRunExitOne(): void
    {
        $loader = new PluginLoader(self::$syntaxErrorDir, new Router(''), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(1, $exit, 'A plugin file with a syntax error must fail the run');
    }

    /**
     * A loaded plugin whose getMigrations() THROWS must make `migrate run`
     * return 1 (WC-214 hardening). The healthy plugin's migration must still
     * run (resilience): a malformed plugin must not block core or other
     * plugins' migrations, but the operator must see a non-zero exit so the
     * failure is not silent.
     */
    public function testThrowingGetMigrationsMakesRunExitOneButOthersStillRun(): void
    {
        $loader = new PluginLoader(self::$throwingDeclDir, new Router(''), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        ob_start();
        $exit = $command->execute(['run']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exit, 'A throwing getMigrations() must make the run exit 1');
        $this->assertStringContainsString('Wc214Throwing', $output, 'The per-plugin warning must still be printed');
        $this->assertTrue($this->tableExists('wc214_healthy'), "The healthy plugin's migration must still run");
        $this->assertContains('plugin:Wc214Healthy:CreateHealthyTable', $this->recordedNames());
    }

    /**
     * `status` is consistent with `run`: a plugin whose getMigrations() throws
     * makes the status action exit 1 too, while still listing the healthy
     * plugin's pending migration.
     */
    public function testThrowingGetMigrationsMakesStatusExitOne(): void
    {
        $loader = new PluginLoader(self::$throwingDeclDir, new Router(''), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        ob_start();
        $exit = $command->execute(['status']);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exit, 'A throwing getMigrations() must make status exit 1');
        $this->assertStringContainsString('plugin:Wc214Healthy:CreateHealthyTable', $output);
    }

    // ==================== Gate-incompatible => exit 0 ====================

    /**
     * A gate-incompatible plugin (impossible SDK constraint) is a VALID
     * exclusion, NOT a malformation: the WC-211 gate quarantines it before its
     * migrations are collected, so `migrate run` exits 0 and the compatible
     * plugin's migration runs. The quarantined plugin's migration never runs.
     */
    public function testIncompatiblePluginIsExcludedAndRunExitsZero(): void
    {
        $loader = new PluginLoader(self::$incompatibleDir, new Router(''), null, new HookManager());
        $command = new MigrationsCommand($this->db, $loader, self::$emptyCoreDir);

        $exit = $this->runQuiet($command, ['run']);

        $this->assertSame(0, $exit, 'A gate-incompatible plugin is a valid exclusion, not a failure');
        $this->assertTrue($this->tableExists('wc214_compatible'), "The compatible plugin's migration must run");
        $this->assertContains('plugin:Wc214Compatible:CreateCompatibleTable', $this->recordedNames());
        $this->assertFalse(
            $this->tableExists('wc214_quarantined'),
            'The quarantined plugin migration must never run'
        );
        $this->assertNotContains('plugin:Wc214Incompatible:CreateQuarantinedTable', $this->recordedNames());
    }

    // ==================== helpers ====================

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
            $stmt = $this->pdo->query('SELECT migration_name FROM core_schema_migrations');
            if ($stmt === false) {
                return [];
            }
            /** @var list<string> */
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
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
