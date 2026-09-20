<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * Whether an action may spend from a metered limit, and what to say if not.
 *
 * CARRIES THE REASON, NOT JUST THE VERDICT. "You have reached your limit" is
 * not a usable message: a customer who is told no needs to know WHICH limit —
 * the daily one they will get back tonight, or the monthly one that means they
 * should upgrade — and WHEN it returns. Those two answers lead to completely
 * different actions, and a boolean can express neither.
 */
final class MeterDecision
{
    private function __construct(
        public readonly bool $allowed,
        /** The limit that refused, or null when allowed. */
        public readonly ?string $key = null,
        public readonly int $limit = 0,
        public readonly int $remaining = 0,
        /** ISO-8601 instant when the refusing window reopens. */
        public readonly ?string $resetsAt = null,
    ) {
    }

    public static function allowed(): self
    {
        return new self(true);
    }

    public static function refused(string $key, int $limit, int $remaining, string $resetsAt): self
    {
        return new self(false, $key, $limit, max(0, $remaining), $resetsAt);
    }

    /**
     * The period the refusing limit runs on, for wording the message.
     *
     * @throws \InvalidArgumentException When called on an allowed decision.
     */
    public function period(): ?string
    {
        if ($this->key === null) {
            throw new \InvalidArgumentException('An allowed decision has no refusing limit.');
        }

        return EntitlementRegistry::periodFor($this->key);
    }
}
