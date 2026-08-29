<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Events\DomainEventStore;
use Whity\Core\Events\EventRelay;
use Whity\Core\Hooks\HookManager;
use Whity\Database\Database;

/**
 * `events:relay` — run the listeners for events `dispatchAsync()` persisted.
 *
 * WHY THIS DID NOT EXIST. `HookManager::dispatchAsync()` wrote an outbox row and
 * ran nothing. {@see DomainEventStore} carried the whole relay API and no
 * production code called any of it, so an async event was durably recorded and
 * permanently undelivered — and `event_outbox` grew without bound (#1063).
 *
 * The dangerous half was not the growth. A listener bound to an async event name
 * would have been written, tested in isolation, merged, and silently done
 * nothing in production, because everything about `dispatchAsync()` looks like
 * delivery. This is the caller that was missing.
 *
 * SHAPED LIKE `queue:work`, deliberately. Same loop, same recycling options,
 * same reclaim sweep, same `--once`. It is the same problem — claim, run, retry,
 * dead-letter — over a different table, and an operator who has run one should
 * not have to learn a second vocabulary. The relay logic itself lives in
 * {@see EventRelay} so it is testable without a process that runs forever.
 *
 * RUN IT ALONGSIDE `queue:work`. They are separate processes on purpose: a
 * listener that blocks must not stall the job queue, and a job that blocks must
 * not stall event delivery.
 */
final class EventRelayCommand implements CliCommand, CommandHelp
{
    private ?EventRelay $relay;
    private LoggerInterface $logger;
    /** @var \Closure(int): void */
    private \Closure $sleeper;

    /**
     * Collaborators are injectable so the tests need no database and no clock.
     *
     * THE CONSTRUCTOR CONNECTS TO NOTHING, deliberately. `CliRunner` builds the
     * command before it looks for `--help`, so a constructor that reached for a
     * database would make `events:relay --help` fail with a connection error on
     * any machine that has not configured one — which is precisely the machine
     * where somebody is reading the help. `queue:work --help` and
     * `schedule:run --help` did exactly that, and this file is not adding a
     * third.
     *
     * @param \Closure(int): void|null $sleeper
     */
    public function __construct(
        ?EventRelay $relay = null,
        ?LoggerInterface $logger = null,
        ?\Closure $sleeper = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->relay = $relay;
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Build the relay from the environment, the first time it is actually needed.
     */
    private function relay(): EventRelay
    {
        if ($this->relay !== null) {
            return $this->relay;
        }

        $database = Database::connect();

        // Registered for the reason queue:work registers its own: a listener
        // resolving a service from a bare CLI process would otherwise get an
        // improvised one — a dispatch that succeeds, reaches nobody, and reports
        // nothing. That is the HTTP/CLI divergence this codebase has paid for
        // repeatedly (#717, #724, #727), and it would be especially cruel here,
        // in the command whose whole purpose is to stop events silently going
        // nowhere.
        \Whity\register_service(Database::class, $database);

        $hooks = new HookManager();
        \Whity\register_service(HookManager::class, $hooks);

        return $this->relay = new EventRelay(new DomainEventStore($database->getPdo()), $hooks, $this->logger);
    }

    public function printHelp(string $commandName): bool
    {
        echo "Relay durable domain events to their listeners.\n\n";
        echo "Runs the listeners for events persisted by HookManager::dispatchAsync().\n";
        echo "Without this running, async events are recorded and never delivered.\n\n";
        echo "Usage:\n";
        echo "  whity-cli events:relay [options]\n\n";
        echo "Options:\n";
        echo "  --once                  Relay everything due, then exit. For cron and for tests.\n";
        echo "  --sleep=N               Seconds to wait when the outbox is empty (default 1).\n";
        echo "  --max-events=N          Exit after relaying N events (default 0 = unlimited).\n";
        echo "  --max-runtime=N         Exit after N seconds (default 0 = unlimited).\n";
        echo "  --reclaim=N             Sweep for crashed workers every N seconds (default 60).\n";
        echo "  --visibility=N          Treat a reservation older than N seconds as abandoned (default 300).\n";
        echo "  --help, -h              Show this help and do nothing else.\n\n";
        echo "Run it alongside `queue:work`, not instead of it: a blocked listener must not\n";
        echo "stall the job queue, and a blocked job must not stall event delivery.\n";

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): ?array
    {
        return [
            '--once',
            '--sleep',
            '--max-events',
            '--max-runtime',
            '--reclaim',
            '--visibility',
            '--help',
            '-h',
        ];
    }

    /**
     * @param list<string> $argv
     */
    public function execute(array $argv): int
    {
        // Connects here rather than in the constructor: `--help` must not need a
        // database, and CliRunner builds the command before it looks for it.
        $relay = $this->relay();

        $opts = self::parseOptions($argv);
        $relayed = 0;
        $startedAt = time();
        $lastReclaim = 0;

        $this->logger->info('[events:relay] started', ['once' => $opts['once']]);

        while (true) {
            if (time() - $lastReclaim >= $opts['reclaim']) {
                $reclaimed = $relay->reclaimExpired($opts['visibility']);
                if ($reclaimed > 0) {
                    $this->logger->info('[events:relay] reclaimed abandoned reservations', ['count' => $reclaimed]);
                }
                $lastReclaim = time();
            }

            $ran = $relay->relayNext();

            if ($ran) {
                $relayed++;
                if ($opts['maxEvents'] > 0 && $relayed >= $opts['maxEvents']) {
                    $this->logger->info('[events:relay] stopping (max-events reached)', ['relayed' => $relayed]);
                    break;
                }
            } else {
                if ($opts['once']) {
                    break; // outbox drained
                }
                ($this->sleeper)($opts['sleep']);
            }

            if ($opts['maxRuntime'] > 0 && (time() - $startedAt) >= $opts['maxRuntime']) {
                $this->logger->info('[events:relay] stopping (max-runtime reached)', ['seconds' => time() - $startedAt]);
                break;
            }
        }

        $this->logger->info('[events:relay] stopped', ['relayed' => $relayed]);
        echo "Relayed {$relayed} event(s).\n";

        return 0;
    }

    /**
     * @param list<string> $argv
     * @return array{once: bool, sleep: int, maxEvents: int, maxRuntime: int, reclaim: int, visibility: int}
     */
    private static function parseOptions(array $argv): array
    {
        return [
            'once'       => in_array('--once', $argv, true),
            'sleep'      => self::intOpt($argv, 'sleep', 1),
            'maxEvents'  => self::intOpt($argv, 'max-events', 0),
            'maxRuntime' => self::intOpt($argv, 'max-runtime', 0),
            'reclaim'    => self::intOpt($argv, 'reclaim', 60),
            'visibility' => self::intOpt($argv, 'visibility', 300),
        ];
    }

    /**
     * @param list<string> $argv
     */
    private static function intOpt(array $argv, string $name, int $default): int
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                return (int) substr($arg, strlen($name) + 3);
            }
        }

        return $default;
    }
}
