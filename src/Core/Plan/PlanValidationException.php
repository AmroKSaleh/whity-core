<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use RuntimeException;

/**
 * Raised when a plan write fails validation (WC-plans) — a bad plan key/name, or
 * an invalid entitlement key/value in a plan's bundle.
 *
 * Carries the offending field and a human-readable reason so the admin API can
 * surface a 422 with a `{ details: { <field>: <reason> } }` map without leaking
 * internal detail. Mirrors {@see \Whity\Core\Entitlement\EntitlementValidationException}.
 */
final class PlanValidationException extends RuntimeException
{
    private string $field;
    private string $reason;

    public function __construct(string $field, string $reason)
    {
        $this->field = $field;
        $this->reason = $reason;
        parent::__construct("Invalid plan '{$field}': {$reason}");
    }

    public function field(): string
    {
        return $this->field;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
