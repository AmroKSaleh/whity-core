<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\JobRunner;
use Whity\Core\Queue\QueueService;
use Whity\Core\Tenant\TenantContext;

/**
 * End-to-end (in-process) proof of the WC-notifications delivery path: dispatch
 * a multi-channel notification, then drive the REAL {@see JobRunner} over the
 * durable queue — exactly what `queue:work` does — and confirm each enqueued
 * delivery job runs (restoring the origin tenant) and marks its delivery `sent`
 * via the built-in log transports. This exercises dispatcher + queue + JobRunner
 * + CoreJobs registration + transport registry together.
 */
final class NotificationDeliveryEndToEndTest extends TestCase
{
    private const TENANT_A = 1;

    protected function setUp(): void
    {
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testDispatchThenDrainQueueDeliversEveryChannel(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        // The recipients these fixtures address must exist: #751 gave
        // notifications.recipient_profile_id a real foreign key to profiles.
        RecipientProfiles::seed($pdo);

        $repo = new NotificationRepository($pdo);
        $transports = CoreTransports::make(); // in_app + email log transports

        $dispatcher = new NotificationDispatcher($repo, $transports, new QueueService(new JobRepository($pdo)));

        // The worker's registry: the delivery job registered against the same
        // schema + transports (exactly what queue:work builds).
        $registry = new JobRegistry();
        CoreJobs::register($registry, $pdo, $transports);
        $runner = new JobRunner(new JobRepository($pdo), $registry);

        $notificationId = $dispatcher->dispatch(self::TENANT_A, 101, 'user.invited', [
            'channels' => ['in_app', 'email'],
            'subject'  => 'Welcome {{name}}',
            'body'     => 'Hello',
            'data'     => ['name' => 'Alice'],
            'to'       => 'alice@example.com',
        ]);

        // Drain the queue the way the worker loop does.
        $ran = 0;
        while ($runner->processNext('default')) {
            if (++$ran > 10) {
                self::fail('queue did not drain — possible re-enqueue loop');
            }
        }
        self::assertSame(2, $ran, 'both per-channel delivery jobs ran');

        $statuses = [];
        foreach ($repo->listDeliveries(self::TENANT_A, $notificationId) as $d) {
            $statuses[$d['channel']] = $d['status'];
        }
        self::assertSame('sent', $statuses['in_app'] ?? null);
        self::assertSame('sent', $statuses['email'] ?? null);

        // The transient (non-retained) delivery jobs are gone once completed.
        $remaining = $pdo->query("SELECT COUNT(*) FROM jobs WHERE name = 'core.notifications.deliver'");
        self::assertNotFalse($remaining);
        self::assertSame(0, (int) $remaining->fetchColumn(), 'completed fire-and-forget delivery jobs leave no queue row');
    }
}
