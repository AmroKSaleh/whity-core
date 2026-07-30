<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

/**
 * Renders a notification's subject/body for one (type, channel, locale) from a
 * context bag — the templating SEAM the dispatcher calls per channel before
 * enqueuing a delivery.
 *
 * The default {@see PassthroughRenderer} uses the caller-supplied subject/body
 * with simple `{{var}}` interpolation; the per-type/channel/locale templating
 * engine (a later task) is a drop-in replacement that implements this same
 * interface, so the dispatcher never changes when it lands.
 */
interface NotificationRenderer
{
    /**
     * @param array{subject?: string, body?: string, bodyHtml?: string|null, data?: array<string, mixed>} $context
     */
    public function render(string $type, string $channel, ?string $locale, array $context): RenderedNotification;
}
