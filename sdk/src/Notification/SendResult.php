<?php

declare(strict_types=1);

namespace Whity\Sdk\Notification;

/**
 * The immutable outcome of a {@see NotificationTransport::send()} attempt
 * (SDK v1.x).
 *
 * A transport reports delivery success/failure through this value object rather
 * than by throwing, so the host's dispatcher can record per-channel delivery
 * state and apply retry/backoff uniformly (fail-soft).
 */
final class SendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $providerId = null,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * A successful send. `$providerId` is the transport/provider's message id
     * (for correlation/inspection), if any.
     */
    public static function sent(?string $providerId = null): self
    {
        return new self(true, $providerId, null);
    }

    /**
     * A failed send. `$error` is a short, human-readable reason — never a secret
     * or a raw provider exception/stack.
     */
    public static function failed(string $error): self
    {
        return new self(false, null, $error);
    }
}
