<?php

declare(strict_types=1);

namespace Whity\Core\Notification\Jobs;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\TransportRegistry;
use Whity\Sdk\JobInterface;
use Whity\Sdk\Notification\NotificationMessage;

/**
 * The durable job that actually delivers ONE notification_deliveries row over
 * its channel (WC-notifications). Enqueued by {@see \Whity\Core\Notification\NotificationDispatcher};
 * the queue restores the origin tenant before this runs.
 *
 * It resolves the channel's transport, builds the {@see NotificationMessage},
 * sends it (transports are fail-soft — they report via {@see \Whity\Sdk\Notification\SendResult},
 * never by throwing), and records the outcome on the delivery row:
 *  - SENT   → mark the delivery sent + store the provider id; job completes.
 *  - FAILED → mark the delivery failed + THROW, so the durable queue applies its
 *             own retry-with-backoff (and eventually dead-letters). A later retry
 *             that succeeds flips the delivery back to 'sent'.
 *  - NO TRANSPORT (deregistered since dispatch) → mark failed and stop WITHOUT
 *             throwing (retrying cannot conjure a transport).
 *
 * At-least-once by the JobInterface contract: a transport should treat the
 * provider id / delivery id as an idempotency key to avoid a double send on a
 * queue retry. Internal (not API-submittable).
 */
final class SendNotificationDeliveryJob implements JobInterface
{
    public const NAME = 'core.notifications.deliver';

    private NotificationRepository $repo;
    private TransportRegistry $transports;
    private ?LoggerInterface $logger;
    private ?AuditLoggerInterface $audit;

    public function __construct(
        NotificationRepository $repo,
        TransportRegistry $transports,
        ?LoggerInterface $logger = null,
        ?AuditLoggerInterface $audit = null
    ) {
        $this->repo = $repo;
        $this->transports = $transports;
        $this->logger = $logger;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $deliveryId = (int) ($payload['delivery_id'] ?? 0);
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $channel = (string) ($payload['channel'] ?? '');

        $transport = $this->transports->resolve($tenantId, $channel);
        if ($transport === null) {
            // Terminal: a retry will not produce a transport. Record + stop.
            $this->repo->markDeliveryFailed($deliveryId, 'no transport for channel: ' . $channel);
            $this->auditOutcome('failed', $tenantId, $deliveryId, $payload, ['reason' => 'no_transport']);

            return ['status' => 'failed', 'reason' => 'no_transport', 'channel' => $channel];
        }

        $data = $payload['data'] ?? [];
        $message = new NotificationMessage(
            channel: $channel,
            recipient: (string) ($payload['recipient'] ?? ''),
            tenantId: $tenantId,
            type: (string) ($payload['type'] ?? ''),
            subject: (string) ($payload['subject'] ?? ''),
            body: (string) ($payload['body'] ?? ''),
            bodyHtml: isset($payload['body_html']) && $payload['body_html'] !== null ? (string) $payload['body_html'] : null,
            data: is_array($data) ? $data : [],
            locale: isset($payload['locale']) && $payload['locale'] !== null ? (string) $payload['locale'] : null,
        );

        $result = $transport->send($message);

        if ($result->success) {
            $this->repo->markDeliverySent($deliveryId, $result->providerId);
            $this->auditOutcome('sent', $tenantId, $deliveryId, $payload, ['provider_id' => $result->providerId]);

            return ['status' => 'sent', 'provider_id' => $result->providerId, 'channel' => $channel];
        }

        $error = $result->error ?? 'send failed';
        $this->repo->markDeliveryFailed($deliveryId, $error);
        // Audit the outcome with a COARSE reason only — never the raw transport
        // error, which may embed a recipient address or other PII.
        $this->auditOutcome('failed', $tenantId, $deliveryId, $payload, ['reason' => 'send_failed']);
        $this->logger?->warning('[notifications] delivery failed', [
            'delivery_id' => $deliveryId,
            'tenant_id'   => $tenantId,
            'channel'     => $channel,
        ]);

        // Throw so the durable queue retries with backoff (and eventually dead-letters).
        throw new RuntimeException('notification delivery failed on channel ' . $channel . ': ' . $error);
    }

    /**
     * Record a NON-PII audit entry for a delivery outcome. Only routing metadata
     * (notification id, channel, type) + a coarse reason/provider id — NEVER the
     * recipient, subject, body, data, or the raw transport error, any of which
     * may hold PII. Fail-soft (AuditLogger swallows its own errors).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $extra
     */
    private function auditOutcome(string $status, int $tenantId, int $deliveryId, array $payload, array $extra = []): void
    {
        $this->audit?->record('notification.delivery.' . $status, [
            'tenant_id'   => $tenantId,
            'target_type' => 'notification_delivery',
            'target_id'   => $deliveryId,
            'metadata'    => array_merge([
                'notification_id' => (int) ($payload['notification_id'] ?? 0),
                'channel'         => (string) ($payload['channel'] ?? ''),
                'type'            => (string) ($payload['type'] ?? ''),
            ], $extra),
        ]);
    }
}
