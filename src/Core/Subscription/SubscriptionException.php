<?php

declare(strict_types=1);

namespace Whity\Core\Subscription;

use RuntimeException;

/**
 * Raised when a subscription write is invalid (WC-billing) — an unknown status or
 * enforcement mode, or an attempt to subscribe the system tenant. The admin API
 * maps it to a 422.
 */
final class SubscriptionException extends RuntimeException
{
}
