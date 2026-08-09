<?php

declare(strict_types=1);

namespace Whity\Core\Health;

/**
 * Turns raw {@see health_samples} rows into the shape the public status page
 * renders: an overall banner, one card per component, and a list of recent
 * incidents.
 *
 * PUBLIC OUTPUT — everything here is served unauthenticated. It carries
 * component keys, states, uptime percentages and timestamps, and deliberately
 * NOT: the `detail` column (error text can name hosts, drivers and paths),
 * latency figures per sample, worker counts, versions, or which tenant anything
 * belongs to. An anonymous reader should learn whether the service works, and
 * nothing about how it is built.
 */
final class StatusReport
{
    /** Components the page always lists, in display order, even with no samples yet. */
    private const COMPONENTS = [
        'api' => 'API',
        'database' => 'Database',
        'queue' => 'Background jobs',
        'scheduler' => 'Scheduled tasks',
        'render' => 'Document rendering',
        'web' => 'Web application',
    ];

    /**
     * A component with no sample newer than this is reported `unknown` rather
     * than `operational`. Silence is not health: if the probe stops running,
     * the page must say so instead of freezing on the last good state — that
     * is precisely how an outage goes unnoticed.
     */
    private const STALE_AFTER_SECONDS = 900;

    /**
     * @param HealthProbeRegistry|null $registry Optional component catalogue. When
     *        wired, PLUGIN-contributed components are listed after core's, using
     *        the label the plugin declared. Without it the page shows exactly the
     *        core components it always has — a host that never wired a registry
     *        loses nothing.
     */
    public function __construct(
        private readonly HealthSampleRepository $samples,
        private readonly ?HealthProbeRegistry $registry = null,
    ) {
    }

    /**
     * @param int $windowDays How far back uptime and incidents are computed.
     * @return array{
     *   status: string,
     *   components: list<array{key: string, name: string, status: string, uptime: ?float, checked_at: ?string}>,
     *   incidents: list<array{component: string, status: string, started_at: string, ended_at: string, minutes: int}>,
     *   window_days: int,
     *   generated_at: string
     * }
     */
    public function build(int $windowDays = 90): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - ($windowDays * 86400));
        $latest = $this->samples->latestPerComponent();
        $counts = $this->samples->countsSince($since);

        $components = [];
        $states = [];

        foreach ($this->componentLabels() as $key => $name) {
            $sample = $latest[$key] ?? null;
            $status = $this->resolveStatus($sample);
            if ($status !== null) {
                $states[] = $status;
            }

            $count = $counts[$key] ?? null;
            $components[] = [
                'key' => $key,
                'name' => $name,
                'status' => $status?->value ?? 'unknown',
                'uptime' => $count !== null && $count['total'] > 0
                    ? round((($count['total'] - $count['down']) / $count['total']) * 100, 3)
                    : null,
                'checked_at' => $sample['observed_at'] ?? null,
            ];
        }

        return [
            'status' => ($states === [] ? HealthStatus::Operational : HealthStatus::worst(...$states))->value,
            'components' => $components,
            'incidents' => $this->incidentsSince($since),
            'window_days' => $windowDays,
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * The components to render, in display order: core's fixed list first, then
     * whatever plugins contributed.
     *
     * Core stays first and unchanged — an operator scanning the page during an
     * incident should find the database card where it has always been, not
     * shifted down by however many plugins are installed. Contributed
     * components are sorted by key so the order is stable across workers rather
     * than reflecting plugin discovery order.
     *
     * A contributed key can never equal a core one (core keys are bare,
     * contributed keys are namespaced `plugin:key`), but the union is written
     * core-last-wins anyway so a future collision cannot rename a core card.
     *
     * @return array<string, string> component key => display label
     */
    private function componentLabels(): array
    {
        $contributed = $this->registry?->contributedLabels() ?? [];
        if ($contributed === []) {
            return self::COMPONENTS;
        }

        ksort($contributed);

        // `+` keeps the LEFT operand's entry on a key collision: core's label
        // and position always win.
        return self::COMPONENTS + $contributed;
    }

    /**
     * A sample older than the stale window tells us nothing current.
     *
     * @param array{status: string, latency_ms: ?int, observed_at: string, source: string}|null $sample
     */
    private function resolveStatus(?array $sample): ?HealthStatus
    {
        if ($sample === null) {
            return null;
        }

        $age = time() - (int) strtotime((string) $sample['observed_at']);
        if ($age > self::STALE_AFTER_SECONDS) {
            return null;
        }

        return HealthStatus::tryFrom((string) $sample['status']);
    }

    /**
     * Collapse consecutive non-operational samples per component into incidents.
     *
     * Samples arrive at a roughly fixed cadence, so a gap larger than the
     * stitch window means recovery happened in between and this is a NEW
     * incident rather than a continuation of the old one.
     *
     * @return list<array{component: string, status: string, started_at: string, ended_at: string, minutes: int}>
     */
    private function incidentsSince(string $since): array
    {
        $rows = $this->samples->nonOperationalSince($since);
        if ($rows === []) {
            return [];
        }

        $stitchSeconds = self::STALE_AFTER_SECONDS;
        $open = [];
        $incidents = [];

        foreach ($rows as $row) {
            $component = $row['component'];
            $at = (int) strtotime($row['observed_at']);

            if (isset($open[$component]) && ($at - $open[$component]['last']) <= $stitchSeconds) {
                $open[$component]['last'] = $at;
                $open[$component]['status'] = HealthStatus::worst(
                    HealthStatus::from($open[$component]['status']),
                    HealthStatus::tryFrom($row['status']) ?? HealthStatus::Degraded
                )->value;
                continue;
            }

            if (isset($open[$component])) {
                $incidents[] = $this->close($component, $open[$component]);
            }

            $open[$component] = [
                'start' => $at,
                'last' => $at,
                'status' => (HealthStatus::tryFrom($row['status']) ?? HealthStatus::Degraded)->value,
            ];
        }

        foreach ($open as $component => $state) {
            $incidents[] = $this->close($component, $state);
        }

        // Most recent first — that is what a reader wants at the top.
        usort($incidents, static fn(array $a, array $b): int => strcmp($b['started_at'], $a['started_at']));

        return array_slice($incidents, 0, 20);
    }

    /**
     * @param array{start: int, last: int, status: string} $state
     * @return array{component: string, status: string, started_at: string, ended_at: string, minutes: int}
     */
    private function close(string $component, array $state): array
    {
        return [
            'component' => $component,
            'status' => $state['status'],
            'started_at' => gmdate('c', $state['start']),
            'ended_at' => gmdate('c', $state['last']),
            'minutes' => (int) max(1, round(($state['last'] - $state['start']) / 60)),
        ];
    }
}
