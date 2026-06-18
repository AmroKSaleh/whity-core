<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

use RuntimeException;

/**
 * Raised when a setting write fails registry validation (Website Settings).
 *
 * Carries the offending key and a human-readable reason so the API layer can
 * surface a 422 with a `{ details: { <key>: <reason> } }` field map without
 * leaking any internal/SQL detail.
 */
final class SettingsValidationException extends RuntimeException
{
    private string $settingKey;
    private string $reason;

    public function __construct(string $settingKey, string $reason)
    {
        $this->settingKey = $settingKey;
        $this->reason = $reason;
        parent::__construct("Invalid setting '{$settingKey}': {$reason}");
    }

    public function settingKey(): string
    {
        return $this->settingKey;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
