<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

/**
 * WC-766: ops/health-watch-healthcheck.php — the container probe for the
 * status-page collector.
 *
 * This runs the script the way Docker runs it: as a separate process, asserting
 * on its exit code, because the exit code IS the contract (0 healthy, 1 not).
 *
 * The property under test is the one the old probe did not have. It asked the
 * DATABASE whether `health_samples` had a recent row — a fact about a shared,
 * append-only table, not about this container — so it stayed green on anybody
 * else's rows and could never report "I have recorded nothing". A healthcheck
 * that cannot go red when the thing it names is dead is worse than none,
 * because it actively asserts the opposite.
 *
 * So every red case below is a case the previous probe reported as healthy.
 */
final class HealthWatchHealthcheckTest extends TestCase
{
    private string $heartbeat = '';

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'whity-hc-');
        self::assertIsString($path);
        @unlink($path);
        $this->heartbeat = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->heartbeat);
    }

    /** @param array<string, mixed> $heartbeat */
    private function writeHeartbeat(array $heartbeat): void
    {
        $json = json_encode($heartbeat);
        self::assertIsString($json);
        file_put_contents($this->heartbeat, $json);
    }

    /** @return array{0: int, 1: string} Exit code and stderr. */
    private function runProbe(?string $maxAge = null): array
    {
        $script = dirname(__DIR__, 2) . '/ops/health-watch-healthcheck.php';
        self::assertFileExists($script);

        $env = getenv();
        $env['WHITY_HEALTH_WATCH_HEARTBEAT'] = $this->heartbeat;
        if ($maxAge !== null) {
            $env['WHITY_HEALTH_SAMPLE_MAX_AGE'] = $maxAge;
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            [PHP_BINARY, $script],
            $descriptors,
            $pipes,
            dirname(__DIR__, 2),
            $env
        );
        self::assertIsResource($process);

        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [proc_close($process), is_string($stderr) ? $stderr : ''];
    }

    /**
     * Nothing has ever completed a pass. The old probe answered this from the
     * table, so a container that had never written a byte reported healthy for
     * as long as any other writer kept the table fresh.
     */
    public function testItIsRedWhenTheCollectorHasNeverRecordedAnything(): void
    {
        [$exit, $stderr] = $this->runProbe();

        self::assertSame(1, $exit, 'a collector that has recorded nothing must be UNHEALTHY');
        self::assertStringContainsString('recorded no samples yet', $stderr);
    }

    /**
     * The collector is alive, looping, and persisting nothing — the exact state
     * the live container was in during the outage, and the state that has to be
     * reportable as such rather than as "up".
     */
    public function testItIsRedWhenTheCollectorIsRunningButHasRecordedNothing(): void
    {
        $this->writeHeartbeat([
            'pid' => 1,
            'updated_at' => time(),
            'last_sample_at' => null,
            'consecutive_failures' => 42,
            'last_error' => 'database unreachable',
        ]);

        [$exit, $stderr] = $this->runProbe();

        self::assertSame(1, $exit);
        self::assertStringContainsString('recorded NOTHING', $stderr);
        self::assertStringContainsString('database unreachable', $stderr);
    }

    /** It recorded once, long ago, and stopped. Silence is not health. */
    public function testItIsRedWhenTheLastRecordedSampleIsStale(): void
    {
        $this->writeHeartbeat([
            'pid' => 1,
            'updated_at' => time(),
            'last_sample_at' => time() - 3600,
            'consecutive_failures' => 59,
            'last_error' => 'connected but recorded no samples',
        ]);

        [$exit, $stderr] = $this->runProbe();

        self::assertSame(1, $exit);
        self::assertStringContainsString('last recorded a sample', $stderr);
    }

    /** A corrupt or truncated heartbeat is not evidence of anything. */
    public function testItIsRedWhenTheHeartbeatCannotBeParsed(): void
    {
        file_put_contents($this->heartbeat, '{"last_sample_at": ');

        [$exit, $stderr] = $this->runProbe();

        self::assertSame(1, $exit);
        self::assertStringContainsString('not valid JSON', $stderr);
    }

    /** And the one green case: this process recorded a sample, recently. */
    public function testItIsGreenOnlyWhenThisCollectorRecordedRecently(): void
    {
        $this->writeHeartbeat([
            'pid' => 1,
            'updated_at' => time(),
            'last_sample_at' => time() - 30,
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);

        [$exit, $stderr] = $this->runProbe();

        self::assertSame(0, $exit, $stderr);
    }

    /**
     * The freshness budget is a deployment knob because the pass interval is
     * one: a collector on a 10-minute interval must not be permanently red.
     */
    public function testTheFreshnessBudgetIsConfigurable(): void
    {
        $this->writeHeartbeat([
            'pid' => 1,
            'updated_at' => time(),
            'last_sample_at' => time() - 600,
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);

        [$tightExit] = $this->runProbe('300');
        [$looseExit] = $this->runProbe('900');

        self::assertSame(1, $tightExit);
        self::assertSame(0, $looseExit);
    }
}
