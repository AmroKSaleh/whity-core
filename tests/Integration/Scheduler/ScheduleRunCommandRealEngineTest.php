<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Cli\Commands\ScheduleRunCommand;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Scheduler\ScheduledJobRepository;
use Whity\Core\Store\ArraySharedStore;

/**
 * Real-engine tests for the `schedule:run` tick (WC-scheduler): a due schedule
 * is enqueued onto the durable queue under its tenant and its next_run_at
 * advances; the exactly-once-per-minute lock stops a second worker from
 * double-firing; and the built-in retention GC prunes old completed jobs.
 *
 * The tick lock uses an in-memory ArraySharedStore so the exactly-once path is
 * deterministic; a no-op sleeper keeps the loop from blocking.
 */
final class ScheduleRunCommandRealEngineTest extends TestCase
{
    private const TENANT = 1;

    private PDO $pdo;
    private ScheduledJobRepository $schedules;
    private JobRepository $jobs;
    private ArraySharedStore $store;
    private ScheduleRunCommand $command;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->schedules = new ScheduledJobRepository($this->pdo);
        $this->jobs = new JobRepository($this->pdo);
        $this->store = new ArraySharedStore();
        $this->command = new ScheduleRunCommand(
            $this->schedules,
            new QueueService($this->jobs),
            $this->store,
            $this->jobs,
            null,
            static function (int $seconds): void {}
        );
    }

    public function testTickEnqueuesDueScheduleUnderItsTenantAndAdvancesNextRun(): void
    {
        $id = $this->schedules->register(self::TENANT, 'demo.job', '*/5 * * * *', ['x' => 1]);
        $this->makeDue($id);

        $dispatched = $this->command->tick(300, 86400);
        self::assertSame(1, $dispatched);

        // A job was enqueued onto the durable queue for this tenant.
        $job = $this->fetchOne("SELECT * FROM jobs WHERE name = 'demo.job'");
        self::assertNotNull($job);
        self::assertSame(self::TENANT, (int) $job['tenant_id']);
        self::assertSame(['x' => 1], json_decode((string) $job['payload'], true));
        self::assertSame('pending', $job['status']);

        // next_run_at advanced into the future (won't immediately re-fire).
        $row = $this->schedules->find(self::TENANT, $id);
        self::assertNotNull($row);
        self::assertGreaterThan(gmdate('Y-m-d H:i:s'), (string) $row['next_run_at']);
        self::assertNotNull($row['last_run_at']);
    }

    public function testExactlyOnceLockStopsASecondWorkerFromDoubleFiring(): void
    {
        $id = $this->schedules->register(self::TENANT, 'demo.job', '*/5 * * * *');
        $this->makeDue($id);

        // Simulate another worker having already won this minute's tick lock.
        $bucket = gmdate('YmdHi');
        self::assertSame(1, $this->store->increment('scheduler:tick:' . $bucket, 300));

        $dispatched = $this->command->tick(300, 86400);
        self::assertSame(0, $dispatched, 'this worker lost the exactly-once lock and must not fire');
        self::assertSame(0, $this->countJobs(), 'no job enqueued when the tick is not owned');
    }

    public function testTickPrunesOldCompletedJobs(): void
    {
        // A retained, completed job whose completion is older than the window.
        $jobId = (int) $this->jobs->enqueue(self::TENANT, 'x', [], ['retain_result' => true]);
        $this->jobs->reserve();
        $this->jobs->markCompleted($jobId, []);
        $this->pdo->prepare('UPDATE jobs SET completed_at = :old WHERE id = :id')
            ->execute([':old' => gmdate('Y-m-d H:i:s', time() - 7200), ':id' => $jobId]);

        $this->command->tick(300, 3600); // retention 1h → the 2h-old completed job is pruned

        self::assertNull($this->fetchOne('SELECT * FROM jobs WHERE id = ' . $jobId), 'old completed job pruned by the tick');
    }

    private function makeDue(int $scheduleId): void
    {
        $this->pdo->prepare('UPDATE scheduled_jobs SET next_run_at = :past WHERE id = :id')
            ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 3600), ':id' => $scheduleId]);
    }

    private function countJobs(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM jobs');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql): ?array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
