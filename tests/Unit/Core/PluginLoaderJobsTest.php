<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\Jobs\EchoJob;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * TDD tests for PluginLoader::collectJobs().
 *
 * Drives the real PluginLoader over on-disk fixture plugins so discovery and
 * namespacing run exactly as in production. The worker that runs these handlers
 * is infrastructure — it runs core's notification delivery and error alerting
 * too — so the acceptance bar here is that NO plugin defect can stop it: a
 * throwing declaration, a malformed one, and a name another plugin already owns
 * all cost that plugin its jobs and cost the worker nothing.
 */
final class PluginLoaderJobsTest extends TestCase
{
    private static string $pluginDir;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = sys_get_temp_dir() . '/whity_jobs_' . uniqid();
        foreach (['JobsA', 'JobsB', 'JobsBad', 'JobsThrows', 'JobsPlain'] as $dir) {
            mkdir(self::$pluginDir . '/' . $dir, 0755, true);
        }

        // JobsA: two handlers, one of them declared API-submittable.
        file_put_contents(self::$pluginDir . '/JobsA/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace JobsA;

use Whity\Sdk\JobInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginJobsInterface;

final class TaggedJob implements JobInterface
{
    public function __construct(private string $tag) {}
    public function handle(array $payload): array { return ['tag' => $this->tag, 'payload' => $payload]; }
}

final class Plugin implements PluginInterface, PluginJobsInterface
{
    public function getName(): string    { return 'JobsA'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getJobs(): array
    {
        return [
            'sync'   => new TaggedJob('JobsA/sync'),
            'digest' => new TaggedJob('JobsA/digest'),
        ];
    }

    public function getSubmittableJobs(): array
    {
        return ['digest'];
    }
}
PHP);

        // JobsB: declares the SAME bare name as JobsA. Different plugin, so a
        // different canonical name — neither shadows the other.
        file_put_contents(self::$pluginDir . '/JobsB/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace JobsB;

use Whity\Sdk\JobInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginJobsInterface;

final class TaggedJob implements JobInterface
{
    public function __construct(private string $tag) {}
    public function handle(array $payload): array { return ['tag' => $this->tag]; }
}

final class Plugin implements PluginInterface, PluginJobsInterface
{
    public function getName(): string    { return 'JobsB'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getJobs(): array    { return ['sync' => new TaggedJob('JobsB/sync')]; }
    public function getSubmittableJobs(): array { return []; }
}
PHP);

        // JobsBad: a malformed declaration — a string where a handler belongs.
        file_put_contents(self::$pluginDir . '/JobsBad/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace JobsBad;

use Whity\Sdk\JobInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginJobsInterface;

final class OkJob implements JobInterface
{
    public function handle(array $payload): array { return []; }
}

final class Plugin implements PluginInterface, PluginJobsInterface
{
    public function getName(): string    { return 'JobsBad'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getJobs(): array
    {
        return ['fine' => new OkJob(), 'broken' => 'this is not a handler'];
    }

    public function getSubmittableJobs(): array { return []; }
}
PHP);

        // JobsThrows: getJobs() raises. The worker must survive it.
        file_put_contents(self::$pluginDir . '/JobsThrows/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace JobsThrows;

use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginJobsInterface;

final class Plugin implements PluginInterface, PluginJobsInterface
{
    public function getName(): string    { return 'JobsThrows'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getJobs(): array { throw new \RuntimeException('boom'); }
    public function getSubmittableJobs(): array { return []; }
}
PHP);

        // JobsPlain: no PluginJobsInterface at all — must be skipped silently.
        file_put_contents(self::$pluginDir . '/JobsPlain/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace JobsPlain;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return 'JobsPlain'; }
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
        foreach (['JobsA', 'JobsB', 'JobsBad', 'JobsThrows', 'JobsPlain'] as $dir) {
            @unlink(self::$pluginDir . '/' . $dir . '/Plugin.php');
            @rmdir(self::$pluginDir . '/' . $dir);
        }
        @rmdir(self::$pluginDir);
    }

    private function makeLoader(): PluginLoader
    {
        return new PluginLoader(
            self::$pluginDir,
            new Router(''),
            new PermissionRegistry(),
            new HookManager(),
        );
    }

    private function collected(): JobRegistry
    {
        $loader = $this->makeLoader();
        $loader->load();
        $registry = new JobRegistry();
        $loader->collectJobs($registry);

        return $registry;
    }

    // ── Baseline ──────────────────────────────────────────────────────────────

    public function testCollectJobsDoesNothingWhenNoPluginsLoaded(): void
    {
        $loader = new PluginLoader(sys_get_temp_dir() . '/empty_' . uniqid(), new Router(''), new PermissionRegistry(), new HookManager());
        $registry = new JobRegistry();

        $loader->collectJobs($registry);

        self::assertSame([], $registry->names());
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    public function testDeclaredJobsAreRegisteredUnderThePluginNamespace(): void
    {
        $registry = $this->collected();

        self::assertTrue($registry->has('jobsa:sync'));
        self::assertTrue($registry->has('jobsa:digest'));
        self::assertFalse($registry->has('sync'), 'a bare name is never registered');
    }

    public function testTheRegisteredHandlerIsTheOneThePluginBuilt(): void
    {
        $handler = $this->collected()->get('jobsa:sync');

        self::assertNotNull($handler);
        self::assertSame(
            ['tag' => 'JobsA/sync', 'payload' => ['n' => 1]],
            $handler->handle(['n' => 1]),
            'the plugin constructs its own handler, so its own collaborators reach it'
        );
    }

    public function testTwoPluginsDeclaringTheSameNameBothGetTheirJob(): void
    {
        $registry = $this->collected();

        $a = $registry->get('jobsa:sync');
        $b = $registry->get('jobsb:sync');
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame(['tag' => 'JobsA/sync', 'payload' => []], $a->handle([]));
        self::assertSame(['tag' => 'JobsB/sync'], $b->handle([]));
    }

    public function testOnlyDeclaredSubmittableJobsAreApiSubmittable(): void
    {
        $registry = $this->collected();

        self::assertTrue($registry->isSubmittable('jobsa:digest'));
        self::assertFalse($registry->isSubmittable('jobsa:sync'));
        self::assertFalse($registry->isSubmittable('jobsb:sync'));
    }

    public function testPluginWithoutTheInterfaceContributesNothing(): void
    {
        foreach ($this->collected()->names() as $name) {
            self::assertStringStartsNotWith('jobsplain:', $name);
        }
    }

    // ── Failure isolation ─────────────────────────────────────────────────────

    public function testAThrowingDeclarationDoesNotTakeTheWorkerDown(): void
    {
        // Must not propagate JobsThrows' RuntimeException, and every other
        // plugin's jobs must still be there.
        $registry = $this->collected();

        self::assertTrue($registry->has('jobsa:sync'));
        self::assertTrue($registry->has('jobsb:sync'));
        foreach ($registry->names() as $name) {
            self::assertStringStartsNotWith('jobsthrows:', $name);
        }
    }

    public function testAMalformedDeclarationIsQuarantinedWhole(): void
    {
        $registry = $this->collected();

        // Its one good handler goes with the bad one: a half-registered plugin
        // silently dead-letters the jobs that did not make it.
        self::assertFalse($registry->has('jobsbad:fine'));
        self::assertFalse($registry->has('jobsbad:broken'));
        self::assertTrue($registry->has('jobsa:sync'), 'other plugins are unaffected');
    }

    // ── Core jobs are untouched ───────────────────────────────────────────────

    public function testCoreJobsRegisteredBeforehandSurviveCollection(): void
    {
        $loader = $this->makeLoader();
        $loader->load();
        $registry = new JobRegistry();
        CoreJobs::register($registry);

        $loader->collectJobs($registry);

        self::assertInstanceOf(EchoJob::class, $registry->get(EchoJob::NAME));
        self::assertTrue($registry->isSubmittable(EchoJob::NAME));
    }

    // ── Disabled plugins ──────────────────────────────────────────────────────

    public function testADisabledPluginContributesNoJobs(): void
    {
        // Its own directory and namespace: disabling writes a `.disabled`
        // sentinel into the plugin folder, which the shared fixture set would
        // then carry into every other test in this class.
        $dir = sys_get_temp_dir() . '/whity_jobsoff_' . uniqid();
        mkdir($dir . '/JobsOff', 0755, true);
        file_put_contents($dir . '/JobsOff/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace JobsOff;

use Whity\Sdk\JobInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginJobsInterface;

final class OffJob implements JobInterface
{
    public function handle(array $payload): array { return []; }
}

final class Plugin implements PluginInterface, PluginJobsInterface
{
    public function getName(): string    { return 'JobsOff'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getJobs(): array { return ['sync' => new OffJob()]; }
    public function getSubmittableJobs(): array { return []; }
}
PHP);

        $loader = new PluginLoader($dir, new Router(''), new PermissionRegistry(), new HookManager());
        $loader->load();

        $enabled = new JobRegistry();
        $loader->collectJobs($enabled);
        self::assertTrue($enabled->has('jobsoff:sync'));

        $loader->disablePlugin('JobsOff\\Plugin');
        $disabled = new JobRegistry();
        $loader->collectJobs($disabled);

        self::assertFalse($disabled->has('jobsoff:sync'), 'a disabled plugin must not keep running work');

        @unlink($dir . '/JobsOff/' . PluginLoader::DIR_DISABLED_SENTINEL);
        @unlink($dir . '/JobsOff/Plugin.php');
        @rmdir($dir . '/JobsOff');
        @rmdir($dir);
    }
}
