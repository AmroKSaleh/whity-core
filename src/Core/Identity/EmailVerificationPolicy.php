<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Whether self-service registration must verify the owner's email before the
 * account is treated as fully provisioned (WC-235).
 *
 * Controlled by the EMAIL_VERIFICATION_ENFORCED environment flag, DISABLED by
 * default for the MVP. When disabled, registration marks the primary email
 * verified and sends nothing (current behaviour). When enabled, registration
 * marks the primary email UNVERIFIED and hands off to the configured
 * {@see EmailVerificationProvider}. Flipping this flag requires no code change.
 */
final class EmailVerificationPolicy
{
    /** Environment flag name (accepts "1" or "true", case-insensitive). */
    public const ENV_FLAG = 'EMAIL_VERIFICATION_ENFORCED';

    /**
     * True when the email-verification step must be enforced. Default: false.
     */
    public static function isEnforced(): bool
    {
        $raw = (string) ($_ENV[self::ENV_FLAG] ?? getenv(self::ENV_FLAG) ?: '0');

        return $raw === '1' || strtolower($raw) === 'true';
    }
}
