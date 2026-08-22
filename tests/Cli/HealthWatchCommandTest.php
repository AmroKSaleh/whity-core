<?php

declare(strict_types=1);

namespace Tests\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whity\Cli\Commands\HealthWatchCommand;
use Whity\Core\Health\HealthProbeRegistry;

/**
 * WC-766: the status-page collector must survive a dependency outage, and must
 * be able to prove it is collecting.
 *
 * Two properties are pinned here, and they are the two halves of the incident:
 *
 *  1. The loop NEVER gives up. A pass that cannot record backs off and comes
 *     back — for as long as the outage lasts. A monitor with an attempt budget
 *     stops monitoring at exactly the moment its subject is in trouble.
 *  2. It publishes its OWN liveness. "I recorded a sample" is a claim only this
 *     process can make, and the heartbeat is where it makes it, so the
 *     container healthcheck can go red on silence instead of reading somebody
 *     else's rows in a shared table as evidence of health.
 *
 * The infinite loop is driven through the injected sleeper: it counts passes,
 * captures the backoff, heals the "database" mid-run, and finally throws to
 * unwind out of `while (true)`. If the loop ever exited on its own, the test
 * would see fewer passes than it asked for — which is the regression it exists
 * to catch.
 */
final class HealthWatchCommandTest extends TestCase
{
    private string $heartbeat = '';

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'whity-hb-');
        self::assertIsString($path);
        // tempnam() creates the file; the collector must be the thing that
        // brings it into existence, so start from "no heartbeat at all".
        @unlink($path);
        $this->heartbeat = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeat);
        @unlink($this->heartbeat . '.tmp');
    }

    /** An empty SQLite database: probes answer, but nothing can be recorded. */
    private static function pdoWithoutSampleTable(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        return $pdo;
    }

    private static function createSampleTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE health_samples (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                component   TEXT NOT NULL,
                status      TEXT NOT NULL,
                source      TEXT NOT NULL DEFAULT \'internal\',
                latency_ms  INTEGER,
                detail      TEXT,
                observed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    private static function coreProbes(): HealthProbeRegistry
    {
        $registry = new HealthProbeRegistry();
        $registry->registerCoreProbes();

        return $registry;
    }

    /** @return array<string, mixed> */
    private function readHeartbeat(): array
    {
        self::assertFileExists($this->heartbeat, 'the collector wrote no heartbeat');
        $raw = file_get_contents($this->heartbeat);
        self::assertIsString($raw);
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The whole of #766 in one test: three passes that cannot record, then the
     * dependency returns, then it records again — with the loop never having
     * stopped, and the heartbeat telling the truth at every point.
     */
    public function testItKeepsRetryingThroughAnOutageAndResumesWhenTheDependencyReturns(): void
    {
        $pdo = self::pdoWithoutSampleTable();

        /** @var list<string> $log */
        $log = [];
        /** @var list<int> $sleeps */
        $sleeps = [];
        $passes = 0;

        $sleeper = function (int $seconds) use (&$passes, &$sleeps, $pdo): void {
            $sleeps[] = $seconds;
            $passes++;

            // The blip ends after the third failed pass — DNS came back, the
            // database is reachable again, nothing restarted anything.
            if ($passes === 3) {
                self::createSampleTable($pdo);
            }

            if ($passes >= 5) {
                throw new RuntimeException('stop-loop');
            }
        };

        $command = new HealthWatchCommand(
            $pdo,
            $sleeper,
            self::coreProbes(),
            static function (string $line) use (&$log): void {
                $log[] = $line;
            },
            $this->heartbeat,
        );

        try {
            $command->execute(['--interval=60']);
            self::fail('the collector loop exited on its own — it must not');
        } catch (RuntimeException $e) {
            self::assertSame('stop-loop', $e->getMessage());
        }

        // 1. It did not give up. Five passes ran, three of them useless.
        self::assertSame(5, $passes);

        $failures = array_values(array_filter(
            $log,
            static fn (string $l): bool => str_contains($l, 'cannot record samples')
        ));
        self::assertCount(3, $failures, 'expected exactly three failed passes');
        self::assertStringContainsString('does not give up', $failures[0]);
        self::assertStringContainsString('failed pass 3', $failures[2]);

        // 2. Backoff grew per consecutive failure, then reset on success. The
        //    growth is what makes an hours-long outage cheap; the reset is what
        //    makes recovery prompt.
        self::assertSame([60, 120, 240, 60, 60], $sleeps);

        // 3. Recovery is stated explicitly. Without this line the last thing in
        //    the container log stays a failure forever, and a collector that
        //    healed reads exactly like one that died — which is how six days of
        //    healthy collection got reported as six days of silence.
        $recovered = array_values(array_filter(
            $log,
            static fn (string $l): bool => str_contains($l, 'recovered: recording again')
        ));
        self::assertCount(1, $recovered);
        self::assertStringContainsString('after 3 failed pass(es)', $recovered[0]);

        // 4. And it says so positively while it is working, so silence in the
        //    log means the process is gone rather than merely quiet.
        self::assertNotEmpty(array_filter(
            $log,
            static fn (string $l): bool => str_contains($l, 'alive — recorded')
        ));

        // 5. The heartbeat now substantiates the claim.
        $heartbeat = $this->readHeartbeat();
        self::assertIsInt($heartbeat['last_sample_at']);
        self::assertSame(0, $heartbeat['consecutive_failures']);
        self::assertNull($heartbeat['last_error']);

        // 6. Samples really were written — four core components on each of the
        //    two passes after the table appeared.
        $count = $pdo->query('SELECT COUNT(*) FROM health_samples');
        self::assertNotFalse($count);
        self::assertSame(8, (int) $count->fetchColumn());
    }

    /**
     * While it is failing, the heartbeat must say so rather than say nothing —
     * "running and recording nothing" is a distinct, reportable state, and it
     * is the one the container healthcheck has to be able to go red on.
     */
    public function testAHeartbeatWrittenDuringAnOutageClaimsNoSample(): void
    {
        $pdo = self::pdoWithoutSampleTable();
        $passes = 0;

        $command = new HealthWatchCommand(
            $pdo,
            function (int $seconds) use (&$passes): void {
                if (++$passes >= 2) {
                    throw new RuntimeException('stop-loop');
                }
            },
            self::coreProbes(),
            static function (string $line): void {
            },
            $this->heartbeat,
        );

        try {
            $command->execute(['--interval=60']);
        } catch (RuntimeException) {
            // expected
        }

        $heartbeat = $this->readHeartbeat();
        self::assertNull(
            $heartbeat['last_sample_at'],
            'a collector that has recorded nothing must not claim a sample'
        );
        self::assertSame(2, $heartbeat['consecutive_failures']);
        self::assertSame('connected but recorded no samples', $heartbeat['last_error']);
    }

    /**
     * A pass that probes successfully but persists nothing is a FAILED pass.
     * Counting intentions rather than writes is how the collector would have
     * gone on reporting itself healthy with an unwritable table.
     */
    public function testASinglePassRecordsAndPublishesItsHeartbeat(): void
    {
        $pdo = self::pdoWithoutSampleTable();
        self::createSampleTable($pdo);

        $command = new HealthWatchCommand(
            $pdo,
            static function (int $seconds): void {
                throw new RuntimeException('--once must never sleep');
            },
            self::coreProbes(),
            static function (string $line): void {
            },
            $this->heartbeat,
        );

        self::assertSame(0, $command->execute(['--once']));

        $heartbeat = $this->readHeartbeat();
        self::assertIsInt($heartbeat['last_sample_at']);
        self::assertGreaterThanOrEqual(time() - 5, $heartbeat['last_sample_at']);
        self::assertSame(0, $heartbeat['consecutive_failures']);
    }
}
