<?php

declare(strict_types=1);

namespace Whity\Core\Subscription;

use RuntimeException;

/**
 * Raised when a subscription write is invalid (WC-billing) — an unknown status or
 * enforcement mode, or an attempt to subscribe the system tenant. The admin API
 * maps it to a 422.
 *
 * The message is a curated, client-safe validation reason (it only ever echoes the
 * caller's own invalid input or a fixed sentence). {@see reason()} exposes it so
 * handlers surface it WITHOUT calling `getMessage()` inside a response — the
 * WC-186 leak guard forbids `$e->getMessage()` in `Response::error(...)`.
 */
final class SubscriptionException extends RuntimeException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
