<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\QueueWorkCommand;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\JobInterface;

/**
 * Real-engine tests for the `queue:work` worker command (WC-627 follow-up):
 * `--once` drains the queue, `--max-jobs` recycles after the limit, and an empty
 * queue returns immediately. The worker is driven with a no-op sleeper so no
 * test ever blocks; the daemon (infinite) mode is the same loop without `--once`
 * / with unbounded limits, so bounded runs cover the loop body deterministically.
 */
final class QueueWorkCommandRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private JobRepository $repo;
    private JobRegistry $registry;
    private QueueWorkCommand $command;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $this->repo = new JobRepository($this->pdo);
        $this->registry = new JobRegistry();
        $runner = new JobRunner($this->repo, $this->registry);
        // No-op sleeper so an idle poll never blocks the test.
        $this->command = new QueueWorkCommand($runner, $this->repo, null, static function (int $seconds): void {});
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testOnceDrainsAllDueJobsThenExits(): void
    {
        $handler = $this->countingHandler();
        $this->registry->register('count', $handler);
        $this->repo->enqueue(self::TENANT, 'count', ['n' => 1]);
        $this->repo->enqueue(self::TENANT, 'count', ['n' => 2]);
        $this->repo->enqueue(self::TENANT, 'count', ['n' => 3]);

        // `--memory=0` disables the recycle ceiling: these tests exercise the
        // DRAIN/limit semantics of the loop, which must not depend on the host
        // process's RSS. The default ceiling is a production leak-guard, and a
        // coverage-instrumented PHPUnit process alone already exceeds a small
        // ceiling — which would otherwise recycle the worker after one job. The
        // ceiling itself is covered explicitly by testMemoryCeilingRecycles*.
        $exit = $this->command->execute(['--once', '--memory=0']);

        self::assertSame(0, $exit);
        self::assertSame(3, $handler->runs, 'all three due jobs ran');
        self::assertSame(0, $this->countJobs(), 'completed jobs are removed — queue drained');
    }

    public function testMaxJobsRecyclesAfterTheLimit(): void
    {
        $handler = $this->countingHandler();
        $this->registry->register('count', $handler);
        $this->repo->enqueue(self::TENANT, 'count', []);
        $this->repo->enqueue(self::TENANT, 'count', []);
        $this->repo->enqueue(self::TENANT, 'count', []);

        $exit = $this->command->execute(['--max-jobs=2', '--memory=0']);

        self::assertSame(0, $exit);
        self::assertSame(2, $handler->runs, 'stopped after the max-jobs limit');
        self::assertSame(1, $this->countJobs(), 'the third job is left for the next worker');
    }

    public function testEmptyQueueOnceReturnsImmediately(): void
    {
        $handler = $this->countingHandler();
        $this->registry->register('count', $handler);

        $exit = $this->command->execute(['--once', '--memory=0']);

        self::assertSame(0, $exit);
        self::assertSame(0, $handler->runs);
    }

    public function testOnlyProcessesTheRequestedQueue(): void
    {
        $handler = $this->countingHandler();
        $this->registry->register('count', $handler);
        $this->repo->enqueue(self::TENANT, 'count', [], ['queue' => 'emails']);

        $this->command->execute(['--once', '--memory=0']); // default queue
        self::assertSame(0, $handler->runs, 'a job on another queue is not processed');

        $this->command->execute(['--once', '--queue=emails', '--memory=0']);
        self::assertSame(1, $handler->runs);
    }

    /**
     * The memory ceiling is a production leak-guard: the loop recycles (clean
     * exit at a job boundary) once RSS reaches the ceiling, so a supervisor
     * respawns a fresh process. `--memory=1` (1 MB) is below any real PHP
     * process's baseline allocation, so it trips after exactly one job — which
     * both proves the guard and pins the mechanism behind the earlier CI-only
     * failure (a coverage-instrumented harness exceeding the default ceiling).
     */
    public function testMemoryCeilingRecyclesAfterAJob(): void
    {
        $handler = $this->countingHandler();
        $this->registry->register('count', $handler);
        $this->repo->enqueue(self::TENANT, 'count', []);
        $this->repo->enqueue(self::TENANT, 'count', []);

        $exit = $this->command->execute(['--memory=1']);

        self::assertSame(0, $exit, 'a memory recycle is a clean exit');
        self::assertSame(1, $handler->runs, 'recycled after the first job');
        self::assertSame(1, $this->countJobs(), 'the second job is left for the respawned worker');
    }

    /**
     * A JobInterface handler that counts its runs.
     *
     * @return JobInterface&object{runs: int}
     */
    private function countingHandler(): JobInterface
    {
        return new class implements JobInterface {
            public int $runs = 0;

            public function handle(array $payload): void
            {
                $this->runs++;
            }
        };
    }

    private function countJobs(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM jobs');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }
}
