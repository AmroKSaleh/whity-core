<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

use RuntimeException;

/**
 * Raised when an entitlement write fails registry validation (WC-ent).
 *
 * Carries the offending key and a human-readable reason so the operator API can
 * surface a 422 with a `{ details: { <key>: <reason> } }` field map without
 * leaking any internal/SQL detail. Mirrors
 * {@see \Whity\Core\Settings\SettingsValidationException}.
 */
final class EntitlementValidationException extends RuntimeException
{
    private string $entitlementKey;
    private string $reason;

    public function __construct(string $entitlementKey, string $reason)
    {
        $this->entitlementKey = $entitlementKey;
        $this->reason = $reason;
        parent::__construct("Invalid entitlement '{$entitlementKey}': {$reason}");
    }

    public function entitlementKey(): string
    {
        return $this->entitlementKey;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
