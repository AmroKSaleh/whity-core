<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Psr\Log\LoggerInterface;

/**
 * Registers the built-in (core) notification transports into a
 * {@see TransportRegistry} — the transport analogue of {@see \Whity\Core\Queue\CoreJobs}.
 *
 * Out of the box each core channel is served by a {@see LogTransport} (log +
 * report-sent), so a fresh deployment never HARD-fails a notification merely for
 * lack of a provider. Real transports (SMTP email, in-app, push, …) register
 * themselves for their channel in their own slices; last-registration-wins, so
 * they transparently override the log default for that channel.
 *
 * Called at BOTH ends of the queue so the web boot path and the `queue:work`
 * worker resolve the same transport per channel.
 */
final class CoreTransports
{
    /**
     * Channels core ships a built-in (log) transport for out of the box. A smart
     * default, not a hard limit — any channel a real transport registers for is
     * served regardless of this list.
     *
     * @var list<string>
     */
    public const DEFAULT_CHANNELS = ['in_app', 'email'];

    public static function register(TransportRegistry $registry, ?LoggerInterface $logger = null): void
    {
        // `in_app` is served by the built-in inbox transport (the notification row
        // IS the delivery). Every OTHER core channel gets the log transport by
        // default until a real provider registers for it (last-registration-wins).
        $registry->register(new InAppTransport());
        foreach (self::DEFAULT_CHANNELS as $channel) {
            if ($channel === InAppTransport::CHANNEL) {
                continue;
            }
            $registry->register(new LogTransport($channel, $logger));
        }
    }

    public static function make(?LoggerInterface $logger = null): TransportRegistry
    {
        $registry = new TransportRegistry();
        self::register($registry, $logger);

        return $registry;
    }
}
