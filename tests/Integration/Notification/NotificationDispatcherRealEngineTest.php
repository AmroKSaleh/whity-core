<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\LogTransport;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\TransportRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;

/**
 * Real-engine tests for {@see NotificationDispatcher}: it persists the logical
 * notification, records a per-channel delivery, and enqueues one durable send
 * job per channel that HAS a transport — while a channel with NO transport is
 * recorded failed (fail-closed) with no job, and the notification itself always
 * survives (fail-soft). Runs on the migration-built schema (SQLite locally,
 * Postgres in CI).
 */
final class NotificationDispatcherRealEngineTest extends TestCase
{
    private const TENANT_A = 1;

    private PDO $pdo;
    private NotificationRepository $repo;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        // The recipients these fixtures address must exist: #751 gave
        // notifications.recipient_profile_id a real foreign key to profiles.
        RecipientProfiles::seed($this->pdo);
        $this->repo = new NotificationRepository($this->pdo);

        // in_app + email have (log) transports; 'push' deliberately has none.
        $transports = CoreTransports::make();
        $this->dispatcher = new NotificationDispatcher(
            $this->repo,
            $transports,
            new QueueService(new JobRepository($this->pdo))
        );
    }

    public function testDispatchPersistsNotificationAndPerChannelDeliveries(): void
    {
        $id = $this->dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', [
            'channels' => ['in_app', 'email'],
            'subject'  => 'Hi {{name}}',
            'body'     => 'Welcome',
            'data'     => ['name' => 'Alice'],
            'to'       => 'alice@example.com',
        ]);

        $notification = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($notification);
        self::assertSame('user.invited', $notification['type']);
        self::assertSame(101, $notification['recipient_profile_id']);
        self::assertSame(['name' => 'Alice'], $notification['data']);

        $deliveries = $this->repo->listDeliveries(self::TENANT_A, $id);
        self::assertCount(2, $deliveries);
        $byChannel = [];
        foreach ($deliveries as $d) {
            $byChannel[$d['channel']] = $d;
        }
        self::assertSame('queued', $byChannel['in_app']['status']);
        self::assertSame('queued', $byChannel['email']['status']);
    }

    public function testDispatchEnqueuesOneDeliveryJobPerTransportedChannel(): void
    {
        $this->dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', [
            'channels' => ['in_app', 'email'],
        ]);

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM jobs WHERE name = 'core.notifications.deliver'");
        self::assertNotFalse($stmt);
        self::assertSame(2, (int) $stmt->fetchColumn(), 'one durable delivery job enqueued per channel with a transport');
    }

    public function testChannelWithNoTransportIsRecordedFailedWithNoJob(): void
    {
        $id = $this->dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', [
            'channels' => ['push'], // no transport registered for 'push'
        ]);

        $deliveries = $this->repo->listDeliveries(self::TENANT_A, $id);
        self::assertCount(1, $deliveries);
        self::assertSame('failed', $deliveries[0]['status'], 'fail-closed: no transport → failed delivery');
        self::assertNotNull($deliveries[0]['error']);

        $jobCount = $this->pdo->query("SELECT COUNT(*) FROM jobs WHERE name = 'core.notifications.deliver'");
        self::assertNotFalse($jobCount);
        self::assertSame(0, (int) $jobCount->fetchColumn(), 'no send job is enqueued for a channel with no transport');
    }

    public function testOneChannelFailingClosedDoesNotAbortTheOthers(): void
    {
        $id = $this->dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', [
            'channels' => ['email', 'push'], // email transported; push not
        ]);

        $statuses = [];
        foreach ($this->repo->listDeliveries(self::TENANT_A, $id) as $d) {
            $statuses[$d['channel']] = $d['status'];
        }
        self::assertSame('queued', $statuses['email'], 'the transported channel is still enqueued');
        self::assertSame('failed', $statuses['push'], 'the untransported channel is recorded failed');
    }

    public function testDefaultChannelIsInAppWhenNoneGiven(): void
    {
        $id = $this->dispatcher->dispatch(self::TENANT_A, 101, 'system.notice');

        $deliveries = $this->repo->listDeliveries(self::TENANT_A, $id);
        self::assertCount(1, $deliveries);
        self::assertSame('in_app', $deliveries[0]['channel']);
    }

    public function testDeliveryJobIsDedupedPerDeliveryViaIdempotencyKey(): void
    {
        // A fresh dispatcher whose only channel is a custom 'sms' log transport,
        // so the enqueued job's idempotency key can be checked in isolation.
        $transports = new TransportRegistry();
        $transports->register(new LogTransport('sms'));
        $dispatcher = new NotificationDispatcher($this->repo, $transports, new QueueService(new JobRepository($this->pdo)));

        $id = $dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', ['channels' => ['sms']]);
        $deliveryId = (int) $this->repo->listDeliveries(self::TENANT_A, $id)[0]['id'];

        $keyStmt = $this->pdo->query("SELECT idempotency_key FROM jobs WHERE name = 'core.notifications.deliver'");
        self::assertNotFalse($keyStmt);
        self::assertSame('notif-delivery:' . $deliveryId, $keyStmt->fetchColumn());
    }

    // ---- preference filtering (c56a6455) ----

    /** A dispatcher wired with a preference resolver over the same schema. */
    private function dispatcherWithPreferences(): NotificationDispatcher
    {
        $prefRepo = new NotificationPreferenceRepository($this->pdo);

        return new NotificationDispatcher(
            $this->repo,
            CoreTransports::make(),
            new QueueService(new JobRepository($this->pdo)),
            null,
            null,
            new NotificationPreferenceResolver($prefRepo)
        );
    }

    public function testPreferenceOptOutSkipsThatChannelButKeepsTheNotificationRow(): void
    {
        // The recipient disabled email for everything.
        (new NotificationPreferenceRepository($this->pdo))->set(self::TENANT_A, 101, '*', 'email', false);

        $id = $this->dispatcherWithPreferences()->dispatch(self::TENANT_A, 101, 'marketing.digest', [
            'channels' => ['in_app', 'email'],
        ]);

        // The notification row still exists (the inbox stays the complete record).
        self::assertNotNull($this->repo->find(self::TENANT_A, $id));
        // Only the un-opted-out channel got a delivery.
        $channels = array_column($this->repo->listDeliveries(self::TENANT_A, $id), 'channel');
        self::assertSame(['in_app'], $channels, 'the opted-out email channel produced no delivery');
    }

    public function testTransactionalTypeIgnoresOptOut(): void
    {
        (new NotificationPreferenceRepository($this->pdo))->set(self::TENANT_A, 101, '*', 'email', false);

        $id = $this->dispatcherWithPreferences()->dispatch(self::TENANT_A, 101, 'security.login_alert', [
            'channels' => ['in_app', 'email'],
        ]);

        $channels = array_column($this->repo->listDeliveries(self::TENANT_A, $id), 'channel');
        sort($channels);
        self::assertSame(['email', 'in_app'], $channels, 'a transactional type delivers on every channel despite the opt-out');
    }

    // ---- dispatch-time audit (4d40cc1c follow-up: the 'queued' lifecycle state) ----

    /**
     * Capture every AuditLogger::record() call.
     *
     * @param list<array{action: string, options: array<string, mixed>}> $sink
     */
    private function capturingAudit(array &$sink): AuditLoggerInterface
    {
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->method('record')->willReturnCallback(
            function (string $action, array $options = []) use (&$sink): void {
                $sink[] = ['action' => $action, 'options' => $options];
            }
        );

        return $audit;
    }

    public function testDispatchAuditsQueuedForEachChannelWithATransportWithoutPii(): void
    {
        /** @var list<array{action: string, options: array<string, mixed>}> $sink */
        $sink = [];
        $dispatcher = new NotificationDispatcher(
            $this->repo,
            CoreTransports::make(),
            new QueueService(new JobRepository($this->pdo)),
            null,
            null,
            null,
            $this->capturingAudit($sink)
        );

        $dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', [
            'channels' => ['in_app', 'email'],
            'to'       => 'pii-marker@example.com',
            'subject'  => 'SECRET_SUBJECT',
        ]);

        $queuedEntries = array_values(array_filter($sink, static fn (array $e): bool => $e['action'] === 'notification.delivery.queued'));
        self::assertCount(2, $queuedEntries, 'one queued audit entry per channel with a transport');
        foreach ($queuedEntries as $entry) {
            $meta = $entry['options']['metadata'] ?? [];
            self::assertIsArray($meta);
            self::assertSame('user.invited', $meta['type']);
            self::assertContains($meta['channel'], ['in_app', 'email']);
        }

        // NON-PII: the recipient address / subject must never appear anywhere in the audit entries.
        $flat = (string) json_encode($sink);
        self::assertStringNotContainsString('pii-marker@example.com', $flat);
        self::assertStringNotContainsString('SECRET_SUBJECT', $flat);
    }

    public function testDispatchAuditsFailedForAChannelWithNoTransportAtDispatchTime(): void
    {
        /** @var list<array{action: string, options: array<string, mixed>}> $sink */
        $sink = [];
        $dispatcher = new NotificationDispatcher(
            $this->repo,
            CoreTransports::make(), // no 'push' transport registered
            new QueueService(new JobRepository($this->pdo)),
            null,
            null,
            null,
            $this->capturingAudit($sink)
        );

        $dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', ['channels' => ['push']]);

        self::assertCount(1, $sink);
        self::assertSame('notification.delivery.failed', $sink[0]['action']);
        $meta = $sink[0]['options']['metadata'] ?? [];
        self::assertIsArray($meta);
        self::assertSame('no_transport_at_dispatch', $meta['reason'] ?? null);
        self::assertSame('push', $meta['channel']);
    }

    public function testDispatchWorksWithNoAuditLoggerInjected(): void
    {
        // Back-compat: the audit param is optional; dispatch must still succeed.
        $id = $this->dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', ['channels' => ['in_app']]);
        self::assertNotNull($this->repo->find(self::TENANT_A, $id));
    }
}
