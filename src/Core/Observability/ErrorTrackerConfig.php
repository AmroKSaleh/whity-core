<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

/**
 * The resolved error-tracking configuration for this process
 * (WC-error-tracking).
 *
 * Settings live in the admin UI, but the DSN does NOT: it is a credential, so it
 * is stored encrypted under a reserved global-settings key and only ever handed
 * back decrypted here, never through the settings API. That mirrors how the SMTP
 * password is handled.
 */
final class ErrorTrackerConfig
{
    /** Reserved global-settings key holding the ENCRYPTED DSN ciphertext. */
    public const DSN_SETTING_KEY = 'error_tracking.dsn_encrypted';

    public function __construct(
        public readonly bool $enabled,
        public readonly string $provider,
        public readonly ?string $dsn,
        public readonly ?string $environment,
        public readonly bool $notifyAdmins,
        public readonly int $retentionDays,
    ) {
    }

    public function isSentry(): bool
    {
        return $this->enabled && $this->provider === 'sentry' && $this->dsn !== null && $this->dsn !== '';
    }

    public function isInternal(): bool
    {
        return $this->enabled && $this->provider === 'internal';
    }
}
