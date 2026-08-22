<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\NotificationMetricsRepository;
use Whity\Core\Notification\NotificationRepository;

/**
 * Real-engine tests for {@see NotificationMetricsRepository}: per-status counts,
 * failure rate, queue depth, driver-aware average latency, and tenant scoping —
 * aggregated from notification_deliveries. Runs on SQLite locally + Postgres in CI.
 */
final class NotificationMetricsRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;

    private PDO $pdo;
    private NotificationMetricsRepository $metrics;
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1,'a','a'),(2,'b','b') ON CONFLICT (id) DO NOTHING");
        // The recipients these fixtures address must exist: #751 gave
        // notifications.recipient_profile_id a real foreign key to profiles.
        RecipientProfiles::seed($this->pdo);
        $this->metrics = new NotificationMetricsRepository($this->pdo);
        $this->notifications = new NotificationRepository($this->pdo);
    }

    /** Seed one delivery with an explicit status + optional created/sent timestamps. */
    private function seed(int $tenantId, string $status, ?string $createdAt = null, ?string $sentAt = null): void
    {
        $notificationId = $this->notifications->create($tenantId, 101, 'user.invited');
        $createdAt ??= gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_deliveries (tenant_id, notification_id, channel, status, attempts, available_at, sent_at, created_at, updated_at)
             VALUES (:t, :n, :ch, :s, 0, NOW(), :sent, :created, NOW())'
        );
        $stmt->execute([
            ':t' => $tenantId, ':n' => $notificationId, ':ch' => 'email',
            ':s' => $status, ':sent' => $sentAt, ':created' => $createdAt,
        ]);
    }

    public function testCountsFailureRateAndQueueDepth(): void
    {
        $this->seed(self::TENANT_A, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:05');
        $this->seed(self::TENANT_A, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:05');
        $this->seed(self::TENANT_A, 'failed');
        $this->seed(self::TENANT_A, 'bounced');
        $this->seed(self::TENANT_A, 'queued');

        $m = $this->metrics->forTenant(self::TENANT_A);
        self::assertSame(5, $m['total']);
        self::assertSame(['queued' => 1, 'sent' => 2, 'failed' => 1, 'bounced' => 1], $m['by_status']);
        self::assertSame(1, $m['queue_depth']);
        // failed + bounced = 2 of 5.
        self::assertSame(0.4, $m['failure_rate']);
    }

    public function testAverageLatencyIsSecondsBetweenCreatedAndSent(): void
    {
        // Two sent deliveries, 10s and 20s latency → average 15s. (Deterministic on
        // both engines: EXTRACT(EPOCH ...) on PG, julianday*86400 on SQLite.)
        $this->seed(self::TENANT_A, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:10');
        $this->seed(self::TENANT_A, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:20');
        // A failed delivery must NOT contribute to latency.
        $this->seed(self::TENANT_A, 'failed');

        $m = $this->metrics->forTenant(self::TENANT_A);
        self::assertNotNull($m['avg_latency_seconds']);
        self::assertEqualsWithDelta(15.0, $m['avg_latency_seconds'], 0.01);
    }

    public function testAverageLatencyIsNullWithNoSentDeliveries(): void
    {
        $this->seed(self::TENANT_A, 'queued');
        $this->seed(self::TENANT_A, 'failed');

        $m = $this->metrics->forTenant(self::TENANT_A);
        self::assertNull($m['avg_latency_seconds']);
        self::assertSame(0.5, $m['failure_rate'], 'failed=1 of total 2 → 0.5');
    }

    public function testMetricsAreTenantScoped(): void
    {
        $this->seed(self::TENANT_A, 'sent', '2026-01-01 00:00:00', '2026-01-01 00:00:05');
        $this->seed(2, 'failed');
        $this->seed(2, 'bounced');

        $a = $this->metrics->forTenant(self::TENANT_A);
        self::assertSame(1, $a['total'], 'tenant A sees only its own delivery');
        self::assertSame(0.0, $a['failure_rate']);

        $b = $this->metrics->forTenant(2);
        self::assertSame(2, $b['total']);
        self::assertSame(1.0, $b['failure_rate'], "tenant B's 2 deliveries both count as failures");
    }
}
