<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Whity\Sdk\Notification\NotificationTransport;

/**
 * Maps a CHANNEL id to the transport that delivers it and resolves the active
 * transport for a (tenant, channel) — the routing layer for the notification
 * subsystem (WC-notifications). Core + plugins register transports at boot; the
 * notification dispatcher resolves per channel.
 *
 * FAIL-CLOSED: an unregistered channel resolves to null, so the dispatcher
 * records the delivery as failed ("no transport for channel X") rather than
 * silently dropping it.
 *
 * {@see self::resolve()} takes a tenant id so the eventual per-tenant transport
 * SELECTION (tenant_notification_settings, a later task) can layer in without
 * changing callers; today it returns the single transport registered for the
 * channel, tenant-agnostically.
 */
final class TransportRegistry
{
    /** @var array<string, NotificationTransport> */
    private array $transports = [];

    /**
     * Register (or replace) the transport for its channel. Last registration
     * wins, so a plugin can override a core default for a channel.
     */
    public function register(NotificationTransport $transport): void
    {
        $this->transports[$transport->channel()] = $transport;
    }

    public function has(string $channel): bool
    {
        return isset($this->transports[$channel]);
    }

    /**
     * Resolve the active transport for a tenant + channel, or null (fail-closed)
     * when no transport serves that channel.
     */
    public function resolve(int $tenantId, string $channel): ?NotificationTransport
    {
        return $this->transports[$channel] ?? null;
    }

    /**
     * The channels that currently have a registered transport.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->transports);
    }
}
