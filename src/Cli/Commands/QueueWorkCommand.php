<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Database\Database;

/**
 * `queue:work` — the durable-queue worker loop (WC-627 follow-up).
 *
 * Drives {@see JobRunner::processNext()} in a loop: reserve a runnable job,
 * restore its tenant, run its handler, record the outcome; sleep briefly when
 * the queue is empty; periodically reclaim lease-expired jobs from crashed
 * workers.
 *
 * SHUTDOWN SAFETY without ext-pcntl (not in the FrankenPHP image): the loop
 * exits cleanly at a JOB BOUNDARY on any recycle trigger — `--max-jobs`,
 * `--max-runtime`, or the `--memory` ceiling — so a supervisor (docker
 * `restart: unless-stopped`) starts a fresh process, bounding memory growth in
 * the persistent worker. A HARD kill (docker stop → SIGTERM) mid-job is safe by
 * construction: the job's lease expires and the reaper (`reclaimExpired`)
 * returns it to `pending` for another attempt — and handlers are idempotent by
 * the JobInterface contract, so a re-run causes no double effect. (Trap-based
 * finish-current-job-then-stop graceful shutdown would additionally need
 * ext-pcntl added to the image — a separate follow-up.)
 *
 * `--once` drains all currently-due jobs then exits (cron-style / tests).
 *
 * No-arg constructable (Database::connect) so CliRunner + public/index.php can
 * dispatch it; deps are injectable for tests. Registered handlers come from the
 * JobRegistry: the default (no-arg) worker registers the core jobs via
 * {@see CoreJobs::register()} so it can run them; a job whose name has no
 * registered handler is dead-lettered as "no handler".
 */
final class QueueWorkCommand
{
    private JobRunner $runner;
    private JobRepository $repo;
    private LoggerInterface $logger;
    /** @var \Closure(int): void */
    private \Closure $sleeper;

    /**
     * @param \Closure(int): void|null $sleeper Idle sleeper (seconds); default sleep(), a no-op in tests.
     */
    public function __construct(
        ?JobRunner $runner = null,
        ?JobRepository $repo = null,
        ?LoggerInterface $logger = null,
        ?\Closure $sleeper = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        if ($repo === null || $runner === null) {
            $pdo = Database::connect()->getPdo();
            $repo ??= new JobRepository($pdo);
            $registry = new JobRegistry();
            // Pass the PDO so the internal notification-delivery job (+ its default
            // log transports) is registered and thus RUNNABLE by the worker.
            CoreJobs::register($registry, $pdo, null, $this->logger);
            $runner ??= new JobRunner($repo, $registry, $this->logger);
        }
        $this->repo = $repo;
        $this->runner = $runner;
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
        $processed = 0;
        $startedAt = time();
        $lastReclaim = 0;

        $this->logger->info('[queue:work] started', ['queue' => $opts['queue'], 'once' => $opts['once']]);

        while (true) {
            // Periodically return lease-expired jobs (crashed workers) to pending.
            if (time() - $lastReclaim >= $opts['reclaim']) {
                $reclaimed = $this->repo->reclaimExpired($opts['visibility']);
                if ($reclaimed > 0) {
                    $this->logger->info('[queue:work] reclaimed stuck jobs', ['count' => $reclaimed]);
                }
                $lastReclaim = time();
            }

            $ran = $this->runner->processNext($opts['queue']);

            if ($ran) {
                $processed++;
                if ($opts['maxJobs'] > 0 && $processed >= $opts['maxJobs']) {
                    $this->logger->info('[queue:work] recycling (max-jobs reached)', ['processed' => $processed]);
                    break;
                }
                if ($opts['memory'] > 0 && self::memoryMb() >= $opts['memory']) {
                    $this->logger->info('[queue:work] recycling (memory ceiling)', ['mb' => self::memoryMb()]);
                    break;
                }
            } else {
                if ($opts['once']) {
                    break; // queue drained
                }
                ($this->sleeper)($opts['sleep']);
            }

            if ($opts['maxRuntime'] > 0 && (time() - $startedAt) >= $opts['maxRuntime']) {
                $this->logger->info('[queue:work] recycling (max-runtime reached)', ['seconds' => time() - $startedAt]);
                break;
            }
        }

        $this->logger->info('[queue:work] stopped', ['processed' => $processed]);

        return 0;
    }

    /**
     * @param list<string> $argv
     * @return array{queue: string, once: bool, maxJobs: int, maxRuntime: int, memory: int, sleep: int, reclaim: int, visibility: int}
     */
    private static function parseOptions(array $argv): array
    {
        return [
            'queue'      => self::stringOpt($argv, 'queue', 'default'),
            'once'       => in_array('--once', $argv, true),
            'maxJobs'    => self::intOpt($argv, 'max-jobs', 0),      // 0 = unlimited
            'maxRuntime' => self::intOpt($argv, 'max-runtime', 0),  // seconds; 0 = unlimited
            'memory'     => self::intOpt($argv, 'memory', 256),     // MB RSS ceiling before a clean recycle; 0 = unlimited
            'sleep'      => self::intOpt($argv, 'sleep', 1),        // idle poll seconds
            'reclaim'    => self::intOpt($argv, 'reclaim', 60),     // reclaim sweep interval seconds
            'visibility' => self::intOpt($argv, 'visibility', 300), // reserved-lease seconds
        ];
    }

    /**
     * @param list<string> $argv
     */
    private static function stringOpt(array $argv, string $name, string $default): string
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return substr($arg, strlen($name) + 3);
            }
        }

        return $default;
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

    private static function memoryMb(): int
    {
        return (int) round(memory_get_usage(true) / 1048576);
    }
}
