<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * Tests for the loader half of plugin-declared audited events
 * ({@see \Whity\Sdk\PluginEventsInterface}, SDK 1.29).
 *
 * Drives the real {@see PluginLoader} over on-disk fixture plugins, into a real
 * {@see AuditLogger} over a real SQL engine, so discovery, namespacing and the
 * write all run exactly as they do in the host. The acceptance bar is the one
 * the audit trail lives or dies by: every row in the table is attributable to
 * the plugin that actually produced it, and NO plugin defect can cost core — or
 * any other plugin — its own auditing.
 */
final class PluginLoaderAuditEventsTest extends TestCase
{
    private static string $pluginDir;

    private PDO $pdo;

    private HookManager $hooks;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = sys_get_temp_dir() . '/whity_events_' . uniqid();
        foreach (['EventsA', 'EventsB', 'EventsBad', 'EventsThrows', 'EventsPlain'] as $dir) {
            mkdir(self::$pluginDir . '/' . $dir, 0755, true);
        }

        // EventsA: two audited events, one of them explicitly targetless.
        file_put_contents(self::$pluginDir . '/EventsA/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace EventsA;

use Whity\Sdk\PluginEventsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginEventsInterface
{
    public function getName(): string    { return 'EventsA'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getAuditedEvents(): array
    {
        return [
            'task.completed'     => ['targetType' => 'task',  'idKey' => 'task_id'],
            'board.recalculated' => ['targetType' => 'board', 'idKey' => null],
        ];
    }
}
PHP);

        // EventsB: declares the SAME bare event as EventsA. Different plugin, so
        // a different action — and one plugin's dispatch is never the other's.
        file_put_contents(self::$pluginDir . '/EventsB/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace EventsB;

use Whity\Sdk\PluginEventsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginEventsInterface
{
    public function getName(): string    { return 'EventsB'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getAuditedEvents(): array
    {
        return ['task.completed' => ['targetType' => 'task', 'idKey' => 'task_id']];
    }
}
PHP);

        // EventsBad: one good entry and one that writes its own prefix — the
        // move that would let it file rows under somebody else's name.
        file_put_contents(self::$pluginDir . '/EventsBad/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace EventsBad;

use Whity\Sdk\PluginEventsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginEventsInterface
{
    public function getName(): string    { return 'EventsBad'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getAuditedEvents(): array
    {
        return [
            'fine'            => ['targetType' => 'thing', 'idKey' => 'id'],
            'core:user.deleted' => ['targetType' => 'user', 'idKey' => 'id'],
        ];
    }
}
PHP);

        // EventsThrows: getAuditedEvents() raises. Core's auditing must survive.
        file_put_contents(self::$pluginDir . '/EventsThrows/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace EventsThrows;

use Whity\Sdk\PluginEventsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginEventsInterface
{
    public function getName(): string    { return 'EventsThrows'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getAuditedEvents(): array { throw new \RuntimeException('boom'); }
}
PHP);

        // EventsPlain: no PluginEventsInterface at all — loads exactly as before.
        file_put_contents(self::$pluginDir . '/EventsPlain/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace EventsPlain;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return 'EventsPlain'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['EventsA', 'EventsB', 'EventsBad', 'EventsThrows', 'EventsPlain'] as $dir) {
            @unlink(self::$pluginDir . '/' . $dir . '/Plugin.php');
            @rmdir(self::$pluginDir . '/' . $dir);
        }
        @rmdir(self::$pluginDir);
    }

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
        $this->hooks = new HookManager();
        TenantContext::reset();
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    private function loadedLoader(?string $dir = null): PluginLoader
    {
        $loader = new PluginLoader(
            $dir ?? self::$pluginDir,
            new Router(''),
            new PermissionRegistry(),
            $this->hooks,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new AuditLogger($this->pdo),
        );
        $loader->load();

        return $loader;
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    public function testADeclaredEventProducesANamespacedAuditRecord(): void
    {
        $this->loadedLoader();
        TenantContext::setTenantId(1);
        AuditContext::set(9, null);

        $this->hooks->dispatch('eventsa:task.completed', [
            'task_id' => 42,
            'tenant_id' => 1,
            'title' => 'Ship it',
        ]);

        $row = $this->onlyRow();
        $this->assertSame('eventsa:task.completed', $row['action']);
        $this->assertSame('eventsa:task', $row['target_type']);
        $this->assertSame('42', (string) $row['target_id']);
        $this->assertSame('9', (string) $row['actor_user_id']);
        $this->assertSame(['title' => 'Ship it'], json_decode($row['metadata'], true));
    }

    public function testTheBareEventNameIsNeverListenedFor(): void
    {
        $this->loadedLoader();
        TenantContext::setTenantId(1);

        $this->hooks->dispatch('task.completed', ['task_id' => 42, 'tenant_id' => 1]);

        $this->assertSame([], $this->allRows(), 'only the namespaced name is attributable');
    }

    public function testTwoPluginsDeclaringTheSameEventAreNeverConfusedForEachOther(): void
    {
        $this->loadedLoader();
        TenantContext::setTenantId(1);

        $this->hooks->dispatch('eventsa:task.completed', ['task_id' => 1, 'tenant_id' => 1]);
        $this->hooks->dispatch('eventsb:task.completed', ['task_id' => 2, 'tenant_id' => 1]);

        $rows = $this->allRows();
        $this->assertCount(2, $rows, 'one dispatch, one row — never a row per declarant');
        $this->assertSame('eventsa:task.completed', $rows[0]['action']);
        $this->assertSame('eventsb:task.completed', $rows[1]['action']);
    }

    public function testAPluginCannotShadowACoreAuditAction(): void
    {
        $this->loadedLoader();
        // Core's own map is live beside the plugins', as it is in the host.
        (new AuditLogger($this->pdo))->subscribe($this->hooks);
        TenantContext::setTenantId(1);

        $this->hooks->dispatch('user.deleted', ['id' => 7, 'tenant_id' => 1]);

        // Exactly one row, core's own: no fixture plugin's declaration could
        // reach the bare name, and EventsBad's attempt to write the prefix
        // itself cost it its whole declaration (below).
        $row = $this->onlyRow();
        $this->assertSame('user.deleted', $row['action']);
        $this->assertSame('user', $row['target_type']);
    }

    public function testAPluginWithoutTheInterfaceContributesNothing(): void
    {
        $this->loadedLoader();
        TenantContext::setTenantId(1);

        $this->hooks->dispatch('eventsplain:anything', ['id' => 1, 'tenant_id' => 1]);

        $this->assertSame([], $this->allRows());
    }

    // ── Failure isolation ─────────────────────────────────────────────────────

    public function testAThrowingDeclarationIsIsolatedToItsOwnPlugin(): void
    {
        // Must not propagate EventsThrows' RuntimeException out of load()…
        $this->loadedLoader();
        TenantContext::setTenantId(1);

        // …and every other plugin's events must still be audited.
        $this->hooks->dispatch('eventsa:task.completed', ['task_id' => 1, 'tenant_id' => 1]);
        $this->hooks->dispatch('eventsb:task.completed', ['task_id' => 2, 'tenant_id' => 1]);
        $this->hooks->dispatch('eventsthrows:anything', ['id' => 3, 'tenant_id' => 1]);

        $actions = array_column($this->allRows(), 'action');
        $this->assertSame(['eventsa:task.completed', 'eventsb:task.completed'], $actions);
    }

    public function testADeclarationWritingItsOwnPrefixIsRefusedWhole(): void
    {
        $this->loadedLoader();
        TenantContext::setTenantId(1);

        // Neither the offending entry…
        $this->hooks->dispatch('eventsbad:core:user.deleted', ['id' => 1, 'tenant_id' => 1]);
        $this->hooks->dispatch('core:user.deleted', ['id' => 1, 'tenant_id' => 1]);
        // …nor the well-formed one that shared the declaration with it.
        $this->hooks->dispatch('eventsbad:fine', ['id' => 1, 'tenant_id' => 1]);

        $this->assertSame([], $this->allRows(), 'refusal is whole-declaration, not per entry');
    }

    public function testAHostWithNoAuditLoggerWiredLoadsPluginsUnchanged(): void
    {
        // The CLI and the plugin smoke test wire no audit writer; a declaring
        // plugin must load there exactly as a non-declaring one does.
        $loader = new PluginLoader(
            self::$pluginDir,
            new Router(''),
            new PermissionRegistry(),
            $this->hooks,
        );
        $loader->load();

        $this->assertNotEmpty($loader->getPlugins());
        TenantContext::setTenantId(1);
        $this->hooks->dispatch('eventsa:task.completed', ['task_id' => 1, 'tenant_id' => 1]);
        $this->assertSame([], $this->allRows());
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function testADisabledPluginIsNoLongerAuditedAndIsAuditedAgainOnReEnable(): void
    {
        // Its own directory and namespace: disabling writes a `.disabled`
        // sentinel into the plugin folder, which the shared fixture set would
        // then carry into every other test in this class.
        $dir = sys_get_temp_dir() . '/whity_eventsoff_' . uniqid();
        mkdir($dir . '/EventsOff', 0755, true);
        file_put_contents($dir . '/EventsOff/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace EventsOff;

use Whity\Sdk\PluginEventsInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginEventsInterface
{
    public function getName(): string    { return 'EventsOff'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getAuditedEvents(): array
    {
        return ['thing.done' => ['targetType' => 'thing', 'idKey' => 'id']];
    }
}
PHP);

        $loader = $this->loadedLoader($dir);
        TenantContext::setTenantId(1);

        $this->hooks->dispatch('eventsoff:thing.done', ['id' => 1, 'tenant_id' => 1]);
        $this->assertCount(1, $this->allRows());

        // A disabled plugin that kept being audited would be a plugin still
        // doing something — the subscriptions are tracked with its own hooks,
        // so disabling removes them.
        $loader->disablePlugin('EventsOff\\Plugin');
        $this->hooks->dispatch('eventsoff:thing.done', ['id' => 2, 'tenant_id' => 1]);
        $this->assertCount(1, $this->allRows(), 'a disabled plugin contributes nothing');

        // …and re-enabling restores them, without a reboot: the subscription is
        // part of the capability set reEnablePlugin() re-registers.
        $loader->reEnablePlugin('EventsOff\\Plugin');
        $this->hooks->dispatch('eventsoff:thing.done', ['id' => 3, 'tenant_id' => 1]);
        $rows = $this->allRows();
        $this->assertCount(2, $rows);
        $this->assertSame('3', (string) $rows[1]['target_id']);

        @unlink($dir . '/EventsOff/' . PluginLoader::DIR_DISABLED_SENTINEL);
        @unlink($dir . '/EventsOff/Plugin.php');
        @rmdir($dir . '/EventsOff');
        @rmdir($dir);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(): array
    {
        $rows = $this->allRows();
        $this->assertCount(1, $rows, 'Expected exactly one audit row.');
        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRows(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM audit_log ORDER BY id');
        $this->assertNotFalse($stmt);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 't1')");
        return $pdo;
    }
}
