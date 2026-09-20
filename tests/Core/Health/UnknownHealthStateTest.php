<?php

declare(strict_types=1);

namespace Tests\Core\Health;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Health\HealthProbe;
use Whity\Core\Health\HealthSampleRepository;
use Whity\Core\Health\HealthStatus;
use Whity\Core\Health\StatusReport;

/**
 * UNKNOWN: the state the probe reaches when it could not measure something.
 *
 * Before it existed, every unmeasurable case returned OPERATIONAL — which is
 * how the scheduler came to report 100% uptime for a component that had never
 * run once, on a live deployment, for weeks. The rule these tests pin is that
 * "I could not look" is never recorded as "it is fine", and is never recorded
 * as an outage either.
 */
final class UnknownHealthStateTest extends TestCase
{
    public function testUnknownIsNotCountedAsDowntime(): void
    {
        self::assertFalse(
            HealthStatus::Unknown->countsAsDowntime(),
            'an unconfigured component has not had an outage'
        );
        self::assertTrue(HealthStatus::Down->countsAsDowntime());
        self::assertTrue(HealthStatus::Degraded->countsAsDowntime());
        self::assertFalse(HealthStatus::Operational->countsAsDowntime());
    }

    public function testUnknownStopsTheBannerClaimingEverythingIsFine(): void
    {
        self::assertSame(
            HealthStatus::Unknown,
            HealthStatus::worst(HealthStatus::Operational, HealthStatus::Unknown),
            'one unmeasured component means the page cannot say all is well'
        );
    }

    public function testARealFaultAlwaysOutranksAnUnmeasuredOne(): void
    {
        self::assertSame(
            HealthStatus::Down,
            HealthStatus::worst(HealthStatus::Unknown, HealthStatus::Down),
            'an unknown must never mask an actual outage in the roll-up'
        );
        self::assertSame(
            HealthStatus::Degraded,
            HealthStatus::worst(HealthStatus::Unknown, HealthStatus::Degraded)
        );
    }

    public function testUnknownSamplesAreExcludedFromUptimeEntirely(): void
    {
        $repo = new HealthSampleRepository($this->pdoWithSamples());

        // 2 operational, 1 down, 7 unknown. Uptime must come from 3 samples —
        // not 10 with the unknowns charged as downtime, and not 10 with them
        // counted as uptime, which is the bug this state exists to fix.
        $this->sample($repo, 'queue', HealthStatus::Operational, 2);
        $this->sample($repo, 'queue', HealthStatus::Down, 1);
        $this->sample($repo, 'queue', HealthStatus::Unknown, 7);

        $counts = $repo->countsSince('1970-01-01 00:00:00');

        self::assertSame(3, $counts['queue']['total'], 'unknown is not in the denominator');
        self::assertSame(1, $counts['queue']['down'], 'unknown is not in the numerator either');
    }

    public function testAComponentWithOnlyUnknownSamplesReportsNoUptimeFigure(): void
    {
        $repo = new HealthSampleRepository($this->pdoWithSamples());
        $this->sample($repo, 'render', HealthStatus::Unknown, 5);

        $report = (new StatusReport($repo))->build();

        self::assertNull(
            $this->component($report, 'render')['uptime'],
            'never measured is not 100%, and not 0% either'
        );
    }

    public function testUnknownSamplesDoNotBecomeIncidents(): void
    {
        $repo = new HealthSampleRepository($this->pdoWithSamples());
        $this->sample($repo, 'render', HealthStatus::Unknown, 4);

        $report = (new StatusReport($repo))->build();

        self::assertSame(
            [],
            $report['incidents'],
            'a render tier nobody configured has not had an outage to report'
        );
    }


    // ── the probes themselves, through the real runAll() path ───────────────

    public function testASchedulerThatHasNeverRunIsUnknownRatherThanOperational(): void
    {
        $pdo = $this->pdoWithEverything();
        // An ENABLED schedule that has never run: last_run_at IS NULL. This is
        // the exact shape that reported 100% uptime on a live deployment while
        // no scheduler process existed at all.
        $pdo->exec("INSERT INTO scheduled_jobs (enabled, last_run_at) VALUES (1, NULL)");

        self::assertSame('unknown', $this->statusAfterRun($pdo, 'scheduler', null));
    }

    public function testAnUnconfiguredRenderServiceIsUnknownRatherThanOperational(): void
    {
        self::assertSame('unknown', $this->statusAfterRun($this->pdoWithEverything(), 'render', ''));
    }

    public function testMissingTablesAreUnknownRatherThanOperational(): void
    {
        // health_samples only: the jobs and scheduled_jobs tables do not exist,
        // which means migrations have not run — not that the queue is healthy.
        $pdo = $this->pdoWithSamples();

        self::assertSame('unknown', $this->statusAfterRun($pdo, 'queue', null));
        self::assertSame('unknown', $this->statusAfterRun($pdo, 'scheduler', null));
    }

    public function testAnEmptyQueueIsStillOperational(): void
    {
        // The one unmeasurable-LOOKING case that is genuinely healthy: nothing
        // pending means nothing is stuck. This must not be swept up by the fix.
        self::assertSame('operational', $this->statusAfterRun($this->pdoWithEverything(), 'queue', null));
    }

    /** Run the real collection pass and read back what it recorded. */
    private function statusAfterRun(PDO $pdo, string $component, ?string $renderUrl): string
    {
        $repo = new HealthSampleRepository($pdo);
        (new HealthProbe($pdo, $repo, $renderUrl))->runAll();

        $latest = $repo->latestPerComponent();
        self::assertArrayHasKey($component, $latest, "no sample was recorded for {$component}");

        return $latest[$component]['status'];
    }

    private function pdoWithEverything(): PDO
    {
        $pdo = $this->pdoWithSamples();
        $pdo->exec('CREATE TABLE jobs (id INTEGER PRIMARY KEY, status TEXT, available_at TEXT)');
        $pdo->exec('CREATE TABLE scheduled_jobs (id INTEGER PRIMARY KEY, enabled INTEGER, last_run_at TEXT)');

        return $pdo;
    }

    /**
     * @param array{components: list<array<string, mixed>>} $report
     * @return array<string, mixed>
     */
    private function component(array $report, string $key): array
    {
        foreach ($report['components'] as $component) {
            if ($component['key'] === $key) {
                return $component;
            }
        }

        self::fail("component {$key} is not in the report");
    }

    private function sample(HealthSampleRepository $repo, string $component, HealthStatus $status, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $repo->record($component, $status);
        }
    }

    private function pdoWithSamples(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE health_samples (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                component   TEXT NOT NULL,
                status      TEXT NOT NULL,
                source      TEXT NOT NULL DEFAULT 'internal',
                latency_ms  INTEGER,
                detail      TEXT,
                observed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S', 'now'))
            )"
        );

        return $pdo;
    }
}
