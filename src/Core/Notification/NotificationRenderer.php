<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

/**
 * Renders a notification's subject/body for one (type, channel, locale) from a
 * context bag — the templating SEAM the dispatcher calls per channel before
 * enqueuing a delivery.
 *
 * The default {@see PassthroughRenderer} uses the caller-supplied subject/body
 * with simple `{{var}}` interpolation; the DB-backed
 * {@see DatabaseNotificationRenderer} resolves a stored template per
 * (tenant, type, channel, locale) and falls back to the passthrough. Both
 * implement this interface, so the dispatcher never changes.
 *
 * `$tenantId` is passed so a renderer can resolve a tenant's template override
 * (0 = the global default set).
 */
interface NotificationRenderer
{
    /**
     * @param array{subject?: string, body?: string, bodyHtml?: string|null, data?: array<string, mixed>} $context
     */
    public function render(int $tenantId, string $type, string $channel, ?string $locale, array $context): RenderedNotification;
}
