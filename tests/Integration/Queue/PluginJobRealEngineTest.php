<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\QueueWorkCommand;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\Queue\Jobs\EchoJob;
use Whity\Core\Queue\PluginJobs;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

require_once dirname(__DIR__, 3) . '/plugins/HelloWorld/Migrations/CreateHelloGreetingsTable.php';

/**
 * The whole path, end to end, on a real SQL engine: the in-tree HelloWorld
 * reference plugin DECLARES a job, the registry the shipped `queue:work` worker
 * builds DISCOVERS it, and the worker RUNS it against real rows.
 *
 * The gap this closes is that every piece already existed — {@see \Whity\Sdk\JobInterface},
 * a public {@see JobRegistry::register()}, a durable queue — and nothing joined
 * them, so a job a plugin enqueued was dead-lettered by the shipped worker as
 * "No handler registered for job". Asserting the registry alone would not have
 * caught that; the assertion has to be that the job actually ran.
 */
final class PluginJobRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    /** The canonical name the host stamps onto HelloWorld's declared `greeting_digest`. */
    private const DIGEST_JOB = 'helloworld:greeting_digest';

    private PDO $pdo;
    private JobRepository $repo;
    private JobRegistry $registry;
    private QueueWorkCommand $command;

    /** @var array<string, mixed> Saved service-container state to restore. */
    private array $savedServices = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'b', 'b')");
        // HelloWorld owns this table through its own migration; the job reads it.
        (new \HelloWorld\Migrations\CreateHelloGreetingsTable())->up($this->pdo);

        // The plugin resolves its PDO from the host container — the documented
        // data-access seam. The worker must therefore register it, which is what
        // the fixture below stands in for.
        $this->savedServices = $GLOBALS['whity_services'] ?? [];
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        \Whity\register_service(Database::class, $db);

        $this->repo = new JobRepository($this->pdo);
        $this->registry = new JobRegistry();
        // Exactly what the shipped worker assembles: core handlers, then the
        // plugin-declared ones discovered from the real plugins directory.
        CoreJobs::register($this->registry, $this->pdo);
        PluginJobs::register($this->registry, dirname(__DIR__, 3) . '/plugins');

        $this->command = new QueueWorkCommand(
            new JobRunner($this->repo, $this->registry),
            $this->repo,
            null,
            static function (int $seconds): void {
            }
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $GLOBALS['whity_services'] = $this->savedServices;
    }

    // ── Discovery ─────────────────────────────────────────────────────────────

    public function testTheWorkersRegistryCarriesBothCoreAndPluginHandlers(): void
    {
        self::assertTrue($this->registry->has(EchoJob::NAME), 'core handlers are still there');
        self::assertTrue($this->registry->has(self::DIGEST_JOB), 'the plugin handler was discovered');
    }

    public function testThePluginJobIsNamespacedUnderItsPlugin(): void
    {
        self::assertFalse(
            $this->registry->has('greeting_digest'),
            'the bare name the plugin declared is never registered'
        );
    }

    // ── The job actually runs ─────────────────────────────────────────────────

    public function testTheWorkerRunsAJobDeclaredByAPlugin(): void
    {
        $this->seedGreeting(self::TENANT_A, 'hello');
        $this->seedGreeting(self::TENANT_A, 'again');

        $id = $this->repo->enqueue(self::TENANT_A, self::DIGEST_JOB, [], ['retain_result' => true]);
        self::assertNotNull($id);

        $exit = $this->command->execute(['--once', '--memory=0']);

        self::assertSame(0, $exit);
        $job = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status'], 'not dead-lettered as "no handler"');
        self::assertSame(2, $job['result']['greetings'] ?? null);
    }

    public function testThePluginJobRunsUnderTheEnqueuingTenant(): void
    {
        $this->seedGreeting(self::TENANT_A, 'a1');
        $this->seedGreeting(self::TENANT_B, 'b1');
        $this->seedGreeting(self::TENANT_B, 'b2');
        $this->seedGreeting(self::TENANT_B, 'b3');

        $id = $this->repo->enqueue(self::TENANT_B, self::DIGEST_JOB, [], ['retain_result' => true]);
        self::assertNotNull($id);

        $this->command->execute(['--once', '--memory=0']);

        $job = $this->repo->find(self::TENANT_B, $id);
        self::assertNotNull($job);
        self::assertSame(3, $job['result']['greetings'] ?? null, "tenant A's rows are not in tenant B's digest");
    }

    public function testAnUnregisteredPluginJobNameIsStillDeadLettered(): void
    {
        // The fail-closed behaviour is unchanged: discovery adds handlers, it
        // does not make the worker permissive about names nothing declared.
        $id = $this->repo->enqueue(self::TENANT_A, 'helloworld:not_a_real_job', [], ['retain_result' => true]);
        self::assertNotNull($id);

        $this->command->execute(['--once', '--memory=0']);

        $job = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($job);
        self::assertSame('dead', $job['status']);
    }

    // ── Submittability ────────────────────────────────────────────────────────

    public function testThePluginJobIsSubmittableBecauseItDeclaredItself(): void
    {
        self::assertTrue($this->registry->isSubmittable(self::DIGEST_JOB));
        self::assertContains(self::DIGEST_JOB, $this->registry->submittableNames());
    }

    private function seedGreeting(int $tenantId, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hello_greetings (tenant_id, message, created_at) VALUES (:t, :m, NOW())'
        );
        $stmt->execute([':t' => $tenantId, ':m' => $message]);
    }
}
