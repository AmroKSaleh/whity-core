<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Psr\Log\LoggerInterface;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Notification\Jobs\SendNotificationDeliveryJob;
use Whity\Core\Queue\QueueService;

/**
 * The notification orchestrator (WC-notifications) — an AuditLogger-equivalent
 * for notifications. `dispatch()` is the programmatic API: it persists the
 * logical notification (which also backs the in-app inbox), then for each target
 * channel records a per-channel delivery row and enqueues a durable delivery job
 * (so the actual send + its retry/backoff run off the request path, owned by the
 * queue). Everything is tenant-scoped and FAIL-SOFT: one channel's failure is
 * recorded and never aborts the notification or the other channels.
 *
 * SEAMS for the later slices, so the dispatcher does not change when they land:
 *  - recipients/preferences: today the caller supplies the channels + the
 *    channel address (`to`); the per-user preference filter + per-tenant sender
 *    address resolution layer in via their own tasks.
 *  - templating: the injected {@see NotificationRenderer} (default
 *    {@see PassthroughRenderer}) renders subject/body per channel; the full
 *    engine is a drop-in replacement.
 *  - transport selection: {@see TransportRegistry::resolve()} already takes the
 *    tenant, so per-tenant transport config layers in without touching callers.
 *
 * HOOK-SUBSCRIBER MODE ({@see self::subscribe()}): register the dispatcher on the
 * HookManager so firing the `notification.dispatch` hook (from core or a plugin)
 * dispatches a notification — the same code path as the programmatic API.
 */
final class NotificationDispatcher
{
    /** The synchronous hook a caller fires to dispatch a notification. */
    public const HOOK_EVENT = 'notification.dispatch';

    /** @var list<string> */
    private const DEFAULT_CHANNELS = ['in_app'];

    private NotificationRepository $repo;
    private TransportRegistry $transports;
    private QueueService $queue;
    private NotificationRenderer $renderer;
    private ?LoggerInterface $logger;
    private ?NotificationPreferenceResolver $preferences;

    public function __construct(
        NotificationRepository $repo,
        TransportRegistry $transports,
        QueueService $queue,
        ?NotificationRenderer $renderer = null,
        ?LoggerInterface $logger = null,
        ?NotificationPreferenceResolver $preferences = null
    ) {
        $this->repo = $repo;
        $this->transports = $transports;
        $this->queue = $queue;
        $this->renderer = $renderer ?? new PassthroughRenderer();
        $this->logger = $logger;
        $this->preferences = $preferences;
    }

    /**
     * Dispatch a notification to a recipient over one or more channels. Returns
     * the new notification id. Never throws for a per-channel failure (fail-soft);
     * the delivery row records it.
     *
     * @param array{
     *   channels?: list<string>,
     *   to?: string,
     *   subject?: string,
     *   body?: string,
     *   bodyHtml?: string|null,
     *   data?: array<string, mixed>,
     *   locale?: string|null,
     *   queue?: string
     * } $options
     */
    public function dispatch(int $tenantId, ?int $recipientProfileId, string $type, array $options = []): int
    {
        $channels = self::resolveChannels($options['channels'] ?? null);
        $data = $options['data'] ?? [];
        $locale = isset($options['locale']) ? (string) $options['locale'] : null;

        // Filter to the channels the recipient has not opted out of (transactional
        // types + a null recipient bypass this). The notification row is still
        // persisted below regardless, so the in-app inbox stays the complete record.
        if ($this->preferences !== null) {
            $channels = $this->preferences->filterChannels($tenantId, $recipientProfileId, $type, $channels);
        }

        // Persist the logical notification (also the in-app inbox row). The
        // caller-supplied subject/body are stored raw; per-channel rendering
        // happens below for each delivery.
        $notificationId = $this->repo->create($tenantId, $recipientProfileId, $type, [
            'subject' => (string) ($options['subject'] ?? ''),
            'body'    => (string) ($options['body'] ?? ''),
            'data'    => $data,
        ]);

        $recipient = isset($options['to']) && (string) $options['to'] !== ''
            ? (string) $options['to']
            : ($recipientProfileId !== null ? (string) $recipientProfileId : '');

        foreach ($channels as $channel) {
            try {
                $this->dispatchChannel($tenantId, $notificationId, $channel, $type, $recipient, $locale, $data, $options);
            } catch (\Throwable $e) {
                // Fail-soft: a single channel must never abort the others.
                $this->logger?->error('[notifications] channel dispatch failed', [
                    'tenant_id'       => $tenantId,
                    'notification_id' => $notificationId,
                    'channel'         => $channel,
                    'error'           => $e->getMessage(),
                ]);
            }
        }

        return $notificationId;
    }

    /**
     * Register the dispatcher as a synchronous hook subscriber. Firing
     * `notification.dispatch` with `{tenant_id?, recipient_profile_id?, type,
     * options?}` dispatches a notification and returns the id under
     * `notification_id`.
     */
    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen(self::HOOK_EVENT, function (array $data, array $context): array {
            $type = (string) ($data['type'] ?? '');
            if ($type === '') {
                return $data;
            }
            $tenantId = (int) ($data['tenant_id'] ?? $context['tenant_id'] ?? 0);
            $recipient = isset($data['recipient_profile_id']) ? (int) $data['recipient_profile_id'] : null;
            /** @var array<string, mixed> $options */
            $options = is_array($data['options'] ?? null) ? $data['options'] : [];

            $data['notification_id'] = $this->dispatch($tenantId, $recipient, $type, $options);

            return $data;
        });
    }

    /**
     * Record a per-channel delivery + enqueue its durable send job. A channel
     * with no registered transport is recorded failed (fail-closed) and no job
     * is enqueued.
     *
     * @param array<string, mixed>                                                                       $data
     * @param array{channels?: list<string>, to?: string, subject?: string, body?: string, bodyHtml?: string|null, data?: array<string, mixed>, locale?: string|null, queue?: string} $options
     */
    private function dispatchChannel(
        int $tenantId,
        int $notificationId,
        string $channel,
        string $type,
        string $recipient,
        ?string $locale,
        array $data,
        array $options
    ): void {
        $deliveryId = $this->repo->recordDelivery($tenantId, $notificationId, $channel);

        if (!$this->transports->has($channel)) {
            $this->repo->markDeliveryFailed($deliveryId, 'no transport registered for channel: ' . $channel);

            return;
        }

        $rendered = $this->renderer->render($type, $channel, $locale, [
            'subject'  => (string) ($options['subject'] ?? ''),
            'body'     => (string) ($options['body'] ?? ''),
            'bodyHtml' => isset($options['bodyHtml']) ? (string) $options['bodyHtml'] : null,
            'data'     => $data,
        ]);

        $this->queue->dispatch(
            SendNotificationDeliveryJob::NAME,
            [
                'delivery_id'     => $deliveryId,
                'notification_id' => $notificationId,
                'tenant_id'       => $tenantId,
                'channel'         => $channel,
                'recipient'       => $recipient,
                'type'            => $type,
                'subject'         => $rendered->subject,
                'body'            => $rendered->body,
                'body_html'       => $rendered->bodyHtml,
                'data'            => $data,
                'locale'          => $locale,
            ],
            [
                'tenant_id'       => $tenantId,
                'queue'           => (string) ($options['queue'] ?? 'default'),
                // One send per delivery even if dispatch is retried.
                'idempotency_key' => 'notif-delivery:' . $deliveryId,
            ]
        );
    }

    /**
     * @param list<string>|null $channels
     * @return list<string>
     */
    private static function resolveChannels(?array $channels): array
    {
        if ($channels === null) {
            return self::DEFAULT_CHANNELS;
        }

        $out = [];
        foreach ($channels as $channel) {
            $channel = (string) $channel;
            if ($channel !== '' && !in_array($channel, $out, true)) {
                $out[] = $channel;
            }
        }

        return $out === [] ? self::DEFAULT_CHANNELS : $out;
    }
}
