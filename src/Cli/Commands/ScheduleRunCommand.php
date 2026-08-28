<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\Scheduler\CronExpression;
use Whity\Core\Scheduler\ScheduledJobRepository;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Database\Database;

/**
 * `schedule:run` — the cron-tick scheduler (WC-scheduler / #a934420e).
 *
 * Each TICK:
 *  1. Acquire an EXACTLY-ONCE-PER-MINUTE lock across all workers via the shared
 *     store: `increment('scheduler:tick:<YYYYMMDDHHMM>', ttl)`. The FIRST worker
 *     to increment this minute's key gets counter == 1 and owns the tick; every
 *     other worker sees ≥ 2 and skips. This is what makes N schedulers safe.
 *  2. Claim every ENABLED schedule whose `next_run_at <= now` (across tenants),
 *     enqueue each onto the durable jobs queue under its ORIGIN tenant (with a
 *     per-(schedule, minute) idempotency key as belt-and-suspenders), and advance
 *     `next_run_at` to the cron's next occurrence — a missed minute catches up
 *     ONCE, never replays.
 *  3. Run built-in retention GC: prune completed jobs older than the retention
 *     window (wires JobRepository::pruneCompleted into the scheduler).
 *
 * `--once` runs a single tick and exits (cron-style / tests); otherwise it loops
 * (sleep between ticks), recycling on `--max-runtime` so a supervisor respawns a
 * fresh process (bounding memory, like queue:work). All times are UTC.
 *
 * No-arg constructable (Database::connect) so CliRunner + public/index.php can
 * dispatch it; deps are injectable for tests.
 */
final class ScheduleRunCommand implements CliCommand
{
    private ScheduledJobRepository $schedules;
    private QueueService $queue;
    private SharedStoreInterface $store;
    private JobRepository $jobs;
    private LoggerInterface $logger;
    /** @var \Closure(int): void */
    private \Closure $sleeper;

    /**
     * @param \Closure(int): void|null $sleeper Idle sleeper (seconds); default sleep(), a no-op in tests.
     */
    public function __construct(
        ?ScheduledJobRepository $schedules = null,
        ?QueueService $queue = null,
        ?SharedStoreInterface $store = null,
        ?JobRepository $jobs = null,
        ?LoggerInterface $logger = null,
        ?\Closure $sleeper = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        if ($schedules === null || $queue === null || $store === null || $jobs === null) {
            $pdo = Database::connect()->getPdo();
            $schedules ??= new ScheduledJobRepository($pdo);
            $queue ??= new QueueService(new JobRepository($pdo));
            $store ??= new DatabaseSharedStore($pdo);
            $jobs ??= new JobRepository($pdo);
        }
        $this->schedules = $schedules;
        $this->queue = $queue;
        $this->store = $store;
        $this->jobs = $jobs;
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
    }

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        $opts = self::parseOptions($argv);
        $startedAt = time();

        $this->logger->info('[schedule:run] started', ['once' => $opts['once']]);

        while (true) {
            $this->tick($opts['lockTtl'], $opts['retention']);

            if ($opts['once']) {
                break;
            }
            ($this->sleeper)($opts['sleep']);

            if ($opts['maxRuntime'] > 0 && (time() - $startedAt) >= $opts['maxRuntime']) {
                $this->logger->info('[schedule:run] recycling (max-runtime reached)', ['seconds' => time() - $startedAt]);
                break;
            }
        }

        return 0;
    }

    /**
     * Run one tick. Returns the number of schedules dispatched (0 if this worker
     * did not win the minute's exactly-once lock).
     */
    public function tick(int $lockTtl, int $retentionSeconds): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $bucket = $now->format('YmdHi');

        // Exactly-once-per-minute across workers: only the first incrementer runs.
        if ($this->store->increment('scheduler:tick:' . $bucket, $lockTtl) !== 1) {
            $this->logger->debug('[schedule:run] tick skipped — another worker owns this minute', ['bucket' => $bucket]);

            return 0;
        }

        $nowStr = $now->format('Y-m-d H:i:s');
        $dispatched = 0;
        foreach ($this->schedules->claimDue($nowStr) as $schedule) {
            $id = (int) $schedule['id'];
            $tenantId = (int) $schedule['tenant_id'];
            $name = (string) $schedule['name'];

            try {
                $this->queue->dispatch($name, is_array($schedule['payload']) ? $schedule['payload'] : [], [
                    'tenant_id'       => $tenantId,
                    'queue'           => (string) $schedule['queue'],
                    'idempotency_key' => 'sched:' . $id . ':' . $bucket,
                ]);
                $next = (new CronExpression((string) $schedule['cron_expression']))->nextRunAfter($now)->format('Y-m-d H:i:s');
                $this->schedules->markRan($id, $nowStr, $next);
                $dispatched++;
            } catch (\Throwable $e) {
                // A bad cron / enqueue failure on one schedule must not abort the
                // whole tick. Log and continue; the row keeps its old next_run_at
                // and is retried next tick.
                $this->logger->error('[schedule:run] failed to dispatch schedule', [
                    'schedule_id' => $id,
                    'name'        => $name,
                    'tenant_id'   => $tenantId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Built-in retention GC for the durable queue's retained completed jobs.
        $pruned = $this->jobs->pruneCompleted($retentionSeconds);

        $this->logger->info('[schedule:run] tick complete', [
            'bucket'     => $bucket,
            'dispatched' => $dispatched,
            'pruned'     => $pruned,
        ]);

        return $dispatched;
    }

    /**
     * @param list<string> $argv
     * @return array{once: bool, sleep: int, maxRuntime: int, lockTtl: int, retention: int}
     */
    private static function parseOptions(array $argv): array
    {
        return [
            'once'       => in_array('--once', $argv, true),
            'sleep'      => self::intOpt($argv, 'sleep', 60),          // seconds between ticks
            'maxRuntime' => self::intOpt($argv, 'max-runtime', 0),     // seconds; 0 = unlimited
            'lockTtl'    => self::intOpt($argv, 'lock-ttl', 300),      // per-minute tick lock TTL (s)
            'retention'  => self::intOpt($argv, 'job-retention', 86400), // completed-job retention (s)
        ];
    }

    /**
     * @param list<string> $argv
     */
    private static function intOpt(array $argv, string $name, int $default): int
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return max(0, (int) substr($arg, strlen($name) + 3));
            }
        }

        return $default;
    }
}
