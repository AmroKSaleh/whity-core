<?php

declare(strict_types=1);

namespace Whity\Core\Health;

use PDO;
use Throwable;

/**
 * Samples the health of each component this deployment depends on.
 *
 * Runs INSIDE the app (scheduled tick), so it sees things no outside prober
 * can: real database latency, queue backlog age, whether the scheduler is still
 * ticking. What it CANNOT see is itself being down — if the app is not running,
 * nothing here executes and no sample is written. That blind spot is exactly
 * why {@see \Whity\Cli\Commands\HealthWatchCommand} exists as a separate
 * process; the two together cover both failure shapes.
 *
 * Every probe is individually guarded: one component failing to answer must
 * record THAT component as down, never abort the tick and leave the rest of the
 * page stale — a status page that stops updating during an incident is worse
 * than no status page. That guarantee now also covers PLUGIN-contributed
 * probes ({@see HealthProbeRegistry}): a plugin's dependency going down, or its
 * probe throwing outright, records that plugin's component as down and leaves
 * every core component sampled exactly as before.
 */
final class HealthProbe
{
    /** Beyond this, the database is answering but unhappy. */
    private const DB_DEGRADED_MS = 250;

    /** A queue whose oldest pending job is older than this is not keeping up. */
    private const QUEUE_DEGRADED_SECONDS = 300;
    private const QUEUE_DOWN_SECONDS = 1800;

    /** The scheduler ticks every minute; this much silence means it stopped. */
    private const SCHEDULER_DEGRADED_SECONDS = 300;
    private const SCHEDULER_DOWN_SECONDS = 900;

    /**
     * @param HealthProbeRegistry|null $registry The component catalogue. Null
     *        means "core's four, exactly as before" — the registry is a widening
     *        of what is sampled, never a precondition for sampling at all, so a
     *        host that has not wired one still collects a full status page.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly HealthSampleRepository $samples,
        private readonly ?string $renderUrl = null,
        private readonly ?HealthProbeRegistry $registry = null,
    ) {
    }

    /**
     * Probe everything and persist one sample per component.
     *
     * @return array<string, HealthStatus> What was recorded, for CLI output.
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->registry?->getAll() ?? HealthProbeRegistry::CORE_PROBES as $component) {
            $results[$component] = $this->runOne($component);
        }

        return $results;
    }

    private function runOne(string $component): HealthStatus
    {
        try {
            // Core's four are matched BY NAME here, before the registry is ever
            // consulted, so no contributed probe can intercept them even if the
            // namespacing that already prevents it were somehow bypassed.
            [$status, $latency, $detail] = match ($component) {
                'database' => $this->probeDatabase(),
                'queue' => $this->probeQueue(),
                'scheduler' => $this->probeScheduler(),
                'render' => $this->probeRender(),
                default => $this->probeContributed($component),
            };
        } catch (Throwable $e) {
            // A probe that throws IS a down signal for its component, not a
            // reason to lose the rest of the tick.
            $status = HealthStatus::Down;
            $latency = null;
            $detail = $e->getMessage();
        }

        try {
            $this->samples->record($component, $status, 'internal', $latency, $detail);
        } catch (Throwable) {
            // If the database is the thing that is down we cannot record that we
            // noticed. The external watchdog covers this case.
        }

        return $status;
    }

    /**
     * Run a PLUGIN-contributed probe (WC-status-probes).
     *
     * The registry hands back the plugin's own callable; the surrounding
     * try/catch in {@see runOne()} is what makes contributing one safe — a
     * probe that throws, or returns something that is not a
     * {@see \Whity\Sdk\Health\ProbeResult}, records ITS component as down and
     * the pass continues to the next component.
     *
     * An unknown component (registered nowhere, or a core key reaching here,
     * which cannot happen) keeps the historical default of "nothing to say" =
     * operational, so this arm never invents an outage.
     *
     * @return array{0: HealthStatus, 1: ?int, 2: ?string}
     */
    private function probeContributed(string $component): array
    {
        $definition = $this->registry?->definitionFor($component);
        if ($definition === null) {
            return [HealthStatus::Operational, null, null];
        }

        $result = $definition->run();

        // ProbeResult's private constructor guarantees one of the three states,
        // so from() cannot fail here; if a future SDK widened it, the ValueError
        // would land in runOne()'s boundary and read as "that component is down"
        // — the honest reading of a probe the host cannot interpret.
        return [
            HealthStatus::from($result->status),
            $result->latencyMs,
            $result->detail,
        ];
    }

    /** @return array{0: HealthStatus, 1: ?int, 2: ?string} */
    private function probeDatabase(): array
    {
        $start = microtime(true);
        $this->pdo->query('SELECT 1');
        $ms = (int) round((microtime(true) - $start) * 1000);

        return [
            $ms > self::DB_DEGRADED_MS ? HealthStatus::Degraded : HealthStatus::Operational,
            $ms,
            $ms > self::DB_DEGRADED_MS ? "SELECT 1 took {$ms}ms" : null,
        ];
    }

    /**
     * Backlog AGE, not depth: a thousand jobs enqueued a second ago is a busy
     * queue, while one job stuck for an hour is a broken worker. Depth alone
     * cannot tell those apart.
     *
     * @return array{0: HealthStatus, 1: ?int, 2: ?string}
     */
    private function probeQueue(): array
    {
        if (!$this->tableExists('jobs')) {
            return [HealthStatus::Operational, null, null];
        }

        // @tenant-guard-ignore: queue health is a property of the DEPLOYMENT, not of any one tenant — a stuck worker starves every tenant's jobs, so this reads the backlog across all of them (as the scheduler tick does). No tenant data is returned: the result is a single timestamp.
        $stmt = $this->pdo->query(
            "SELECT MIN(available_at) AS oldest FROM jobs WHERE status = 'pending'"
        );
        $oldest = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC)['oldest'] ?? null) : null;
        if ($oldest === null) {
            return [HealthStatus::Operational, null, null];
        }

        $ageSeconds = max(0, time() - (int) strtotime((string) $oldest));
        $status = match (true) {
            $ageSeconds >= self::QUEUE_DOWN_SECONDS => HealthStatus::Down,
            $ageSeconds >= self::QUEUE_DEGRADED_SECONDS => HealthStatus::Degraded,
            default => HealthStatus::Operational,
        };

        return [
            $status,
            null,
            $status === HealthStatus::Operational ? null : "oldest pending job is {$ageSeconds}s old",
        ];
    }

    /**
     * The scheduler advances `next_run_at` on every tick, so the newest
     * `last_run_at` across enabled schedules is a proxy for "the tick is alive".
     * With no schedules registered there is nothing to infer, so stay quiet
     * rather than invent an outage.
     *
     * @return array{0: HealthStatus, 1: ?int, 2: ?string}
     */
    private function probeScheduler(): array
    {
        if (!$this->tableExists('scheduled_jobs')) {
            return [HealthStatus::Operational, null, null];
        }

        // @tenant-guard-ignore: the cron tick is deployment-wide infrastructure that runs ACROSS tenants, so its liveness is measured the same way — the newest last_run_at of any enabled schedule. Returns one timestamp, no tenant data.
        $stmt = $this->pdo->query(
            'SELECT MAX(last_run_at) AS last FROM scheduled_jobs WHERE enabled = TRUE'
        );
        $last = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC)['last'] ?? null) : null;
        if ($last === null) {
            return [HealthStatus::Operational, null, null];
        }

        $ageSeconds = max(0, time() - (int) strtotime((string) $last));
        $status = match (true) {
            $ageSeconds >= self::SCHEDULER_DOWN_SECONDS => HealthStatus::Down,
            $ageSeconds >= self::SCHEDULER_DEGRADED_SECONDS => HealthStatus::Degraded,
            default => HealthStatus::Operational,
        };

        return [
            $status,
            null,
            $status === HealthStatus::Operational ? null : "last scheduler run was {$ageSeconds}s ago",
        ];
    }

    /**
     * The render microservice is optional: an instance without WHITY_RENDER_URL
     * configured is not "down", it simply has no renderer.
     *
     * @return array{0: HealthStatus, 1: ?int, 2: ?string}
     */
    private function probeRender(): array
    {
        if ($this->renderUrl === null || $this->renderUrl === '') {
            return [HealthStatus::Operational, null, null];
        }

        $start = microtime(true);
        $context = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true, 'method' => 'GET'],
        ]);
        $body = @file_get_contents(rtrim($this->renderUrl, '/') . '/health', false, $context);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($body === false) {
            return [HealthStatus::Down, $ms, 'render service unreachable'];
        }

        return [HealthStatus::Operational, $ms, null];
    }

    private function tableExists(string $table): bool
    {
        try {
            $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
