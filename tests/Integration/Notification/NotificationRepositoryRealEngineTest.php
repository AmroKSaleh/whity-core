<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\NotificationRepository;

/**
 * Real-engine tests for {@see NotificationRepository} (WC-notifications): the
 * create/find round-trip (including JSON `data` and a null recipient), the
 * per-channel delivery record + list, the queued default + attempts, and the
 * status CHECK constraint. Runs against the migration-built schema (SQLite
 * locally, Postgres in the postgres-integration CI job).
 */
final class NotificationRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;

    private PDO $pdo;
    private NotificationRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->repo = new NotificationRepository($this->pdo);
    }

    public function testCreateThenFindRoundTripsAllFields(): void
    {
        $id = $this->repo->create(self::TENANT_A, 101, 'user.invited', [
            'subject' => 'You are invited',
            'body'    => 'Join the workspace',
            'data'    => ['cta' => '/accept', 'nested' => ['n' => 1]],
        ]);
        self::assertGreaterThan(0, $id);

        $row = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($row);
        self::assertSame($id, $row['id']);
        self::assertSame(self::TENANT_A, $row['tenant_id']);
        self::assertSame(101, $row['recipient_profile_id']);
        self::assertSame('user.invited', $row['type']);
        self::assertSame('You are invited', $row['subject']);
        self::assertSame('Join the workspace', $row['body']);
        self::assertSame(['cta' => '/accept', 'nested' => ['n' => 1]], $row['data']);
        self::assertNull($row['read_at'], 'a fresh notification is unread');
    }

    public function testCreateAppliesDefaultsAndAllowsNullRecipient(): void
    {
        // A pure email-only notification (no in-app inbox target) has a null recipient.
        $id = $this->repo->create(self::TENANT_A, null, 'system.broadcast');

        $row = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($row);
        self::assertNull($row['recipient_profile_id']);
        self::assertSame('', $row['subject']);
        self::assertSame('', $row['body']);
        self::assertSame([], $row['data']);
    }

    public function testFindReturnsNullForMissingId(): void
    {
        self::assertNull($this->repo->find(self::TENANT_A, 999999));
    }

    public function testRecordDeliveryStartsQueuedWithZeroAttempts(): void
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');

        $deliveryId = $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'email');
        self::assertGreaterThan(0, $deliveryId);

        $deliveries = $this->repo->listDeliveries(self::TENANT_A, $notificationId);
        self::assertCount(1, $deliveries);
        self::assertSame($deliveryId, $deliveries[0]['id']);
        self::assertSame($notificationId, $deliveries[0]['notification_id']);
        self::assertSame('email', $deliveries[0]['channel']);
        self::assertSame('queued', $deliveries[0]['status']);
        self::assertSame(0, $deliveries[0]['attempts']);
        self::assertNull($deliveries[0]['provider_id']);
        self::assertNull($deliveries[0]['error']);
        self::assertNull($deliveries[0]['sent_at']);
    }

    public function testListDeliveriesReturnsEveryChannelOldestFirst(): void
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');
        $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'in_app');
        $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'email');
        $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'push');

        $channels = array_column($this->repo->listDeliveries(self::TENANT_A, $notificationId), 'channel');
        self::assertSame(['in_app', 'email', 'push'], $channels);
    }

    public function testDeliveryStatusCheckConstraintRejectsAnUnknownStatus(): void
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');

        $this->expectException(PDOException::class);
        $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'email', ['status' => 'not-a-status']);
    }

    public function testMarkDeliverySentRecordsProviderAndBumpsAttempts(): void
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');
        $deliveryId = $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'email');

        $this->repo->markDeliverySent($deliveryId, 'prov-xyz');

        $delivery = $this->repo->findDelivery(self::TENANT_A, $deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('sent', $delivery['status']);
        self::assertSame('prov-xyz', $delivery['provider_id']);
        self::assertSame(1, $delivery['attempts']);
        self::assertNotNull($delivery['sent_at']);
    }

    public function testMarkDeliveryFailedRecordsErrorAndBumpsAttempts(): void
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');
        $deliveryId = $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'email');

        $this->repo->markDeliveryFailed($deliveryId, 'provider down');

        $delivery = $this->repo->findDelivery(self::TENANT_A, $deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('failed', $delivery['status']);
        self::assertSame('provider down', $delivery['error']);
        self::assertSame(1, $delivery['attempts']);
    }

    public function testMarkDeliveryFailedAcceptsBouncedAndCoercesUnknownToFailed(): void
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');
        $bounced = $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'email');
        $coerced = $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'sms');

        $this->repo->markDeliveryFailed($bounced, 'hard bounce', 'bounced');
        $this->repo->markDeliveryFailed($coerced, 'weird', 'not-a-real-status');

        $byId = [];
        foreach ($this->repo->listDeliveries(self::TENANT_A, $notificationId) as $d) {
            $byId[$d['id']] = $d['status'];
        }
        self::assertSame('bounced', $byId[$bounced]);
        self::assertSame('failed', $byId[$coerced], 'an unrecognised status is coerced to failed (never violates the CHECK)');
    }

    public function testFindDeliveryIsTenantScoped(): void
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');
        $deliveryId = $this->repo->recordDelivery(self::TENANT_A, $notificationId, 'email');

        self::assertNotNull($this->repo->findDelivery(self::TENANT_A, $deliveryId));
        self::assertNull($this->repo->findDelivery(2, $deliveryId), "another tenant must not read this tenant's delivery");
    }
}
