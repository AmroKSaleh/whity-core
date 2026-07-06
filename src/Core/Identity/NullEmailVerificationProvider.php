<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * No-op email-verification provider — the MVP default (WC-235).
 *
 * Sends nothing and issues no token. Registration marks the owner's primary
 * email verified and proceeds without a verification round-trip. To turn the
 * real flow on later, bind a concrete {@see EmailVerificationProvider} in the
 * composition root and set EMAIL_VERIFICATION_ENFORCED=1 — no changes to
 * {@see \Whity\Api\RegisterApiHandler} or any other caller are required.
 */
final class NullEmailVerificationProvider implements EmailVerificationProvider
{
    public function sendVerification(int $profileId, string $email): void
    {
        // Intentionally empty: verification is not enforced in the MVP.
    }
}
