<?php

declare(strict_types=1);

namespace Whity\Core\Health;

use PDO;

/**
 * Append-only store for {@see health_samples}.
 *
 * Writers (the in-app probe and the external watchdog) only ever INSERT, so the
 * two never contend: there is no current-status row to race over. Readers
 * aggregate.
 *
 * NOT tenant-scoped by design — see the 085 migration. Service health belongs to
 * the deployment, so these queries carry no tenant predicate and the table is
 * correctly outside {@see \Whity\Core\Tenant\TenantOwnedTables}.
 */
final class HealthSampleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record one observation.
     *
     * @param string      $component  Stable component key ('database', 'queue', …).
     * @param string      $source     'internal' (in-app probe) or 'external' (watchdog).
     * @param int|null    $latencyMs  Round-trip in ms where meaningful.
     * @param string|null $detail     Operator-facing note; NEVER surfaced publicly.
     */
    public function record(
        string $component,
        HealthStatus $status,
        string $source = 'internal',
        ?int $latencyMs = null,
        ?string $detail = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO health_samples (component, status, source, latency_ms, detail)
             VALUES (:component, :status, :source, :latency_ms, :detail)'
        );
        $stmt->execute([
            ':component' => $component,
            ':status' => $status->value,
            ':source' => $source,
            ':latency_ms' => $latencyMs,
            ':detail' => $detail,
        ]);
    }

    /**
     * The most recent sample per component.
     *
     * DISTINCT ON is the cheap Postgres idiom for "latest row per group"; the
     * (component, observed_at DESC) index serves it directly.
     *
     * @return array<string, array{status: string, latency_ms: ?int, observed_at: string, source: string}>
     */
    public function latestPerComponent(): array
    {
        $sql = $this->isSqlite()
            // SQLite has no DISTINCT ON; the correlated max() is fine at the
            // row counts a test fixture produces.
            ? 'SELECT h.component, h.status, h.latency_ms, h.observed_at, h.source
                 FROM health_samples h
                 JOIN (SELECT component, MAX(observed_at) AS m FROM health_samples GROUP BY component) t
                   ON t.component = h.component AND t.m = h.observed_at
                GROUP BY h.component'
            : 'SELECT DISTINCT ON (component) component, status, latency_ms, observed_at, source
                 FROM health_samples
                ORDER BY component, observed_at DESC';

        $out = [];
        foreach ($this->pdo->query($sql) ?: [] as $row) {
            $out[(string) $row['component']] = [
                'status' => (string) $row['status'],
                'latency_ms' => $row['latency_ms'] === null ? null : (int) $row['latency_ms'],
                'observed_at' => (string) $row['observed_at'],
                'source' => (string) $row['source'],
            ];
        }

        return $out;
    }

    /**
     * Per-component sample counts within a window, split by state.
     *
     * Uptime is derived from these counts rather than stored, so a change to
     * what counts as downtime is a code change, not a backfill.
     *
     * @return array<string, array{total: int, down: int}>
     */
    public function countsSince(string $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT component,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status <> :ok THEN 1 ELSE 0 END) AS down
               FROM health_samples
              WHERE observed_at >= :since
              GROUP BY component'
        );
        $stmt->execute([':ok' => HealthStatus::Operational->value, ':since' => $since]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['component']] = [
                'total' => (int) $row['total'],
                'down' => (int) $row['down'],
            ];
        }

        return $out;
    }

    /**
     * Non-operational samples in the window, oldest first, for incident
     * derivation. Detail is intentionally NOT selected — incidents shown to the
     * public carry timing and component only.
     *
     * @return list<array{component: string, status: string, observed_at: string}>
     */
    public function nonOperationalSince(string $since, int $limit = 5000): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT component, status, observed_at
               FROM health_samples
              WHERE observed_at >= :since AND status <> :ok
              ORDER BY observed_at ASC
              LIMIT :limit'
        );
        $stmt->bindValue(':since', $since);
        $stmt->bindValue(':ok', HealthStatus::Operational->value);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array{component: string, status: string, observed_at: string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Retention GC: drop samples older than the window the page can show. */
    public function pruneOlderThan(string $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM health_samples WHERE observed_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function isSqlite(): bool
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
