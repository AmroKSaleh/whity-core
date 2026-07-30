<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Whity\Sdk\Notification\NotificationMessage;
use Whity\Sdk\Notification\NotificationTransport;
use Whity\Sdk\Notification\SendResult;

/**
 * The built-in in-app ("inbox") transport (WC-notifications). For the `in_app`
 * channel there is nothing to send OUT: the dispatcher already persisted the
 * `notifications` row (which IS the inbox item) before enqueuing this delivery,
 * and {@see \Whity\Api\InboxApiHandler} serves it from that row. So delivery is
 * simply confirming the inbox item is available — this transport reports success
 * and carries no external provider.
 *
 * It is registered as the default transport for `in_app` (overriding the generic
 * log transport) by {@see CoreTransports}.
 */
final class InAppTransport implements NotificationTransport
{
    public const CHANNEL = 'in_app';

    public function channel(): string
    {
        return self::CHANNEL;
    }

    public function send(NotificationMessage $message): SendResult
    {
        // The inbox row already exists (persisted by the dispatcher); nothing is
        // sent over the wire for in-app delivery.
        return SendResult::sent('in_app');
    }

    /**
     * In-app delivery needs no per-tenant configuration.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function validateConfig(array $config): array
    {
        return [];
    }
}
