<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Whether a self-service registration must be APPROVED by a system-tenant admin
 * before the workspace owner can sign in (WC-235).
 *
 * Controlled by the ADMIN_APPROVAL_ENFORCED environment flag, DISABLED by
 * default for the MVP. When disabled, registration provisions the owner's
 * membership as 'active' and the owner can log in immediately (current
 * behaviour). When enabled, the owner's membership is provisioned as 'invited'
 * (pending): login is refused until a system-tenant admin approves it
 * (invited → active). Flipping this flag requires no code change.
 *
 * This is the account-level companion to {@see EmailVerificationPolicy}: the
 * two gates are independent (an install may enforce neither, either, or both).
 * Approval is a PLATFORM concern — a freshly-registered tenant's only member is
 * the pending owner, so no in-tenant admin exists to approve; the authority is
 * the system tenant (id 0), consistent with the WC-235 global/tenant split.
 */
final class AccountActivationPolicy
{
    /** Environment flag name (accepts "1" or "true", case-insensitive). */
    public const ENV_FLAG = 'ADMIN_APPROVAL_ENFORCED';

    /**
     * True when new self-service registrations must be approved by a
     * system-tenant admin before the owner can log in. Default: false.
     */
    public static function isEnforced(): bool
    {
        $raw = (string) ($_ENV[self::ENV_FLAG] ?? getenv(self::ENV_FLAG) ?: '0');

        return $raw === '1' || strtolower($raw) === 'true';
    }
}
