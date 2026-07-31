<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\NotificationMetricsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Notification\NotificationMetricsRepository;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see NotificationMetricsApiHandler}: RBAC gating
 * (notifications:manage), tenant scoping, and the metrics envelope.
 */
final class NotificationMetricsApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;

    private PDO $pdo;
    private NotificationMetricsRepository $metrics;
    private NotificationRepository $notifications;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1,'a','a') ON CONFLICT (id) DO NOTHING");
        $this->metrics = new NotificationMetricsRepository($this->pdo);
        $this->notifications = new NotificationRepository($this->pdo);
        TenantContext::setTenantId(self::TENANT_A);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function handler(bool $grant): NotificationMetricsApiHandler
    {
        $rc = $this->createMock(RoleChecker::class);
        $rc->method('hasPermissionForProfile')->willReturn($grant);

        return new NotificationMetricsApiHandler($rc, $this->metrics);
    }

    private function req(): Request
    {
        $r = new Request('GET', '/api/notification-metrics');
        $r->user = (object) ['profile_id' => 10];

        return $r;
    }

    public function testRequiresNotificationsManage(): void
    {
        $response = $this->handler(false)->show($this->req());
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('notifications:manage', $response->getBody());
    }

    public function testMissingActorIs403(): void
    {
        self::assertSame(403, $this->handler(true)->show(new Request('GET', '/api/notification-metrics'))->getStatusCode());
    }

    public function testReturnsTenantScopedMetrics(): void
    {
        // One queued + one delivery for the caller tenant.
        $id = $this->notifications->create(self::TENANT_A, 101, 'user.invited');
        $this->notifications->recordDelivery(self::TENANT_A, $id, 'email');

        $response = $this->handler(true)->show($this->req());
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(1, $body['data']['total']);
        self::assertSame(1, $body['data']['queue_depth']);
        self::assertArrayHasKey('failure_rate', $body['data']);
        self::assertArrayHasKey('avg_latency_seconds', $body['data']);
        self::assertArrayHasKey('by_status', $body['data']);
    }
}
