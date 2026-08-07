<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;

/**
 * Aggregates delivery observability metrics from `notification_deliveries` for a
 * tenant (WC-notifications #4d40cc1c): per-status counts, failure rate, current
 * queue depth, and average send latency. Every query binds tenant_id in the SQL
 * literal (guard-visible) so a tenant only ever sees its own metrics.
 *
 * Latency (`sent_at - created_at`) is computed driver-aware — EXTRACT(EPOCH …)
 * on Postgres, julianday() on the SQLite test engine — so it works on both.
 */
final class NotificationMetricsRepository
{
    /** The delivery statuses reported (matches the notification_deliveries CHECK). */
    private const STATUSES = ['queued', 'sent', 'failed', 'bounced'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array{
     *   total: int,
     *   by_status: array<string, int>,
     *   queue_depth: int,
     *   failure_rate: float,
     *   avg_latency_seconds: float|null
     * }
     */
    public function forTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS cnt FROM notification_deliveries WHERE tenant_id = :tenant_id GROUP BY status'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $byStatus = array_fill_keys(self::STATUSES, 0);
        $total = 0;
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $cnt = (int) $row['cnt'];
            if (array_key_exists($status, $byStatus)) {
                $byStatus[$status] = $cnt;
            }
            $total += $cnt;
        }

        $failed = $byStatus['failed'] + $byStatus['bounced'];

        return [
            'total'               => $total,
            'by_status'           => $byStatus,
            'queue_depth'         => $byStatus['queued'],
            'failure_rate'        => $total > 0 ? round($failed / $total, 4) : 0.0,
            'avg_latency_seconds' => $this->avgLatencySeconds($tenantId),
        ];
    }

    /**
     * Average seconds between a delivery being created and marked sent, for the
     * tenant's sent deliveries — or null when there are none.
     */
    private function avgLatencySeconds(int $tenantId): ?float
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        // A driver-selected LITERAL expression (not user input); tenant_id is bound.
        $latencyExpr = $driver === 'pgsql'
            ? 'AVG(EXTRACT(EPOCH FROM (sent_at - created_at)))'
            : 'AVG((julianday(sent_at) - julianday(created_at)) * 86400)';

        $stmt = $this->pdo->prepare(
            "SELECT {$latencyExpr} AS avg_latency FROM notification_deliveries
              WHERE tenant_id = :tenant_id AND status = 'sent' AND sent_at IS NOT NULL"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : round((float) $value, 3);
    }
}
