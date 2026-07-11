<?php

declare(strict_types=1);

namespace Whity\Core\Subscription;

/**
 * The payment wall's decision for one request (WC-billing).
 *
 * - `allowed` — false means the wall must short-circuit with 402 Payment Required.
 * - `warn`    — true means allow, but surface the lapsed state (e.g. an
 *               `X-Subscription-Status` header) so the UI can nudge.
 * - `status`  — the tenant's current subscription status (null when none), for
 *               the advisory header.
 * - `mode`    — the effective enforcement mode that produced this decision.
 */
final class SubscriptionDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $warn,
        public readonly ?string $status,
        public readonly string $mode,
    ) {
    }

    public static function allow(?string $status = null, string $mode = SubscriptionService::MODE_OFF): self
    {
        return new self(true, false, $status, $mode);
    }

    public static function warn(?string $status, string $mode): self
    {
        return new self(true, true, $status, $mode);
    }

    public static function block(?string $status, string $mode): self
    {
        return new self(false, false, $status, $mode);
    }
}
