<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Notification\Jobs\SendNotificationDeliveryJob;
use Whity\Core\Notification\LogTransport;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\TransportRegistry;
use Whity\Sdk\Notification\NotificationMessage;
use Whity\Sdk\Notification\NotificationTransport;
use Whity\Sdk\Notification\SendResult;

/**
 * Real-engine tests for {@see SendNotificationDeliveryJob}: it resolves the
 * channel transport, sends, and records the delivery outcome — sent (+ provider
 * id, attempts bumped), failed (records + THROWS so the queue retries), or
 * terminal no-transport (records + does NOT throw).
 */
final class SendNotificationDeliveryJobTest extends TestCase
{
    private const TENANT_A = 1;

    private PDO $pdo;
    private NotificationRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        // The recipients these fixtures address must exist: #751 gave
        // notifications.recipient_profile_id a real foreign key to profiles.
        RecipientProfiles::seed($this->pdo);
        $this->repo = new NotificationRepository($this->pdo);
    }

    /** Seed a notification + a queued delivery, returning the delivery id. */
    private function seedDelivery(string $channel): int
    {
        $notificationId = $this->repo->create(self::TENANT_A, 101, 'user.invited');

        return $this->repo->recordDelivery(self::TENANT_A, $notificationId, $channel);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(int $deliveryId, string $channel, array $extra = []): array
    {
        return array_merge([
            'delivery_id' => $deliveryId,
            'tenant_id'   => self::TENANT_A,
            'channel'     => $channel,
            'recipient'   => 'alice@example.com',
            'type'        => 'user.invited',
            'subject'     => 'Hi',
            'body'        => 'Welcome',
        ], $extra);
    }

    public function testSuccessfulSendMarksDeliverySentWithProviderId(): void
    {
        $deliveryId = $this->seedDelivery('email');
        $transports = new TransportRegistry();
        $transports->register($this->fakeTransport('email', SendResult::sent('prov-123')));

        $result = (new SendNotificationDeliveryJob($this->repo, $transports))->handle($this->payload($deliveryId, 'email'));

        self::assertSame('sent', $result['status']);
        $delivery = $this->repo->findDelivery(self::TENANT_A, $deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('sent', $delivery['status']);
        self::assertSame('prov-123', $delivery['provider_id']);
        self::assertSame(1, $delivery['attempts']);
        self::assertNotNull($delivery['sent_at']);
    }

    public function testFailedSendRecordsFailureAndThrowsForQueueRetry(): void
    {
        $deliveryId = $this->seedDelivery('email');
        $transports = new TransportRegistry();
        $transports->register($this->fakeTransport('email', SendResult::failed('smtp refused')));

        try {
            (new SendNotificationDeliveryJob($this->repo, $transports))->handle($this->payload($deliveryId, 'email'));
            self::fail('a failed send must throw so the durable queue retries');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('smtp refused', $e->getMessage());
        }

        $delivery = $this->repo->findDelivery(self::TENANT_A, $deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('failed', $delivery['status']);
        self::assertSame('smtp refused', $delivery['error']);
        self::assertSame(1, $delivery['attempts']);
    }

    public function testNoTransportAtRunTimeRecordsFailedWithoutThrowing(): void
    {
        $deliveryId = $this->seedDelivery('push');
        $transports = new TransportRegistry(); // nothing registered for 'push'

        // Must NOT throw — retrying could never conjure a transport.
        $result = (new SendNotificationDeliveryJob($this->repo, $transports))->handle($this->payload($deliveryId, 'push'));

        self::assertSame('failed', $result['status']);
        self::assertSame('no_transport', $result['reason']);
        $delivery = $this->repo->findDelivery(self::TENANT_A, $deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('failed', $delivery['status']);
    }

    public function testLogTransportDeliversAndReportsSent(): void
    {
        $deliveryId = $this->seedDelivery('email');
        $transports = new TransportRegistry();
        $transports->register(new LogTransport('email'));

        (new SendNotificationDeliveryJob($this->repo, $transports))->handle($this->payload($deliveryId, 'email'));

        $delivery = $this->repo->findDelivery(self::TENANT_A, $deliveryId);
        self::assertNotNull($delivery);
        self::assertSame('sent', $delivery['status']);
        self::assertSame('log:email', $delivery['provider_id']);
    }

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

    public function testAuditsSentOutcomeWithoutPii(): void
    {
        $deliveryId = $this->seedDelivery('email');
        $transports = new TransportRegistry();
        $transports->register($this->fakeTransport('email', SendResult::sent('prov-9')));
        $payload = $this->payload($deliveryId, 'email', [
            'recipient' => 'pii@secret.example',
            'subject'   => 'SECRET_SUBJECT',
            'body'      => 'SECRET_BODY',
        ]);

        /** @var list<array{action: string, options: array<string, mixed>}> $sink */
        $sink = [];
        (new SendNotificationDeliveryJob($this->repo, $transports, null, $this->capturingAudit($sink)))->handle($payload);

        self::assertCount(1, $sink);
        self::assertSame('notification.delivery.sent', $sink[0]['action']);
        $meta = $sink[0]['options']['metadata'] ?? [];
        self::assertIsArray($meta);
        self::assertSame('user.invited', $meta['type']);
        self::assertSame('email', $meta['channel']);

        // NON-PII: recipient / subject / body must NEVER appear anywhere in the entry.
        $flat = (string) json_encode($sink[0]['options']);
        self::assertStringNotContainsString('pii@secret.example', $flat, 'the recipient must not be audited');
        self::assertStringNotContainsString('SECRET_SUBJECT', $flat, 'the subject must not be audited');
        self::assertStringNotContainsString('SECRET_BODY', $flat, 'the body must not be audited');
    }

    public function testAuditsFailedOutcomeWithCoarseReasonNotTheRawError(): void
    {
        $deliveryId = $this->seedDelivery('email');
        $transports = new TransportRegistry();
        // An adversarial transport error that embeds a recipient address.
        $transports->register($this->fakeTransport('email', SendResult::failed('550 mailbox unavailable: pii@secret.example')));

        /** @var list<array{action: string, options: array<string, mixed>}> $sink */
        $sink = [];
        try {
            (new SendNotificationDeliveryJob($this->repo, $transports, null, $this->capturingAudit($sink)))
                ->handle($this->payload($deliveryId, 'email'));
            self::fail('a failed send must throw');
        } catch (RuntimeException) {
            // expected — the job throws so the queue retries.
        }

        self::assertCount(1, $sink);
        self::assertSame('notification.delivery.failed', $sink[0]['action']);
        $meta = $sink[0]['options']['metadata'] ?? [];
        self::assertIsArray($meta);
        self::assertSame('send_failed', $meta['reason'] ?? null);
        // The raw transport error (with the embedded email) must NOT be audited.
        $flat = (string) json_encode($sink[0]['options']);
        self::assertStringNotContainsString('pii@secret.example', $flat);
        self::assertStringNotContainsString('mailbox unavailable', $flat);
    }

    private function fakeTransport(string $channel, SendResult $result): NotificationTransport
    {
        return new class ($channel, $result) implements NotificationTransport {
            public function __construct(private string $ch, private SendResult $result)
            {
            }

            public function channel(): string
            {
                return $this->ch;
            }

            public function send(NotificationMessage $message): SendResult
            {
                return $this->result;
            }

            public function validateConfig(array $config): array
            {
                return [];
            }
        };
    }
}
