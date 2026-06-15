<?php

namespace Whity\Core;

/**
 * Enforces the site-wide password strength policy.
 *
 * A single source of truth for the minimum password length so every flow
 * (admin create, admin edit, self-service profile, invite-accept, reset)
 * rejects the same too-short input rather than each hard-coding its own
 * threshold.  The message is intentionally generic and safe to surface to
 * end-users; never include the raw input in any error returned from here.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * Assert that $password satisfies the minimum policy.
     *
     * @param string $password The plaintext password to check (never logged).
     * @throws \InvalidArgumentException when the password is too short.
     */
    public static function validate(string $password): void
    {
        if (strlen($password) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException(
                'Password must be at least ' . self::MIN_LENGTH . ' characters'
            );
        }
    }
}
