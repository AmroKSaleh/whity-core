<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

/**
 * The rendered subject/body a {@see NotificationRenderer} produces for one
 * (type, channel, locale) — an immutable value object handed to the transport
 * via a NotificationMessage. `bodyHtml` is optional (channels without HTML, or a
 * renderer that produces only plain text, leave it null).
 */
final class RenderedNotification
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $bodyHtml = null,
    ) {
    }
}
