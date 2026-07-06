<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Contract for kicking off email-address verification (WC-235).
 *
 * This is the extension seam for the (deferred) email-verification flow. The
 * MVP ships {@see NullEmailVerificationProvider} (a deliberate no-op); a real
 * implementation — issuing + persisting a verification token and delivering it
 * (SMTP/SES/etc.) plus the request/confirm endpoints that consume it — can be
 * dropped in later by binding a different implementation in the composition
 * root (public/index.php) and setting EMAIL_VERIFICATION_ENFORCED=1, WITHOUT
 * editing any caller (Open/Closed). Callers depend only on this interface.
 */
interface EmailVerificationProvider
{
    /**
     * Begin verification for a freshly-registered (or newly-added) email.
     *
     * Called by the registration flow only when verification is enforced
     * ({@see EmailVerificationPolicy::isEnforced()}), AFTER the profile/email are
     * committed. Implementations OWN token issuance, persistence, and delivery.
     * They MUST be resilient — a delivery failure must not invalidate the
     * already-created account (the caller logs and still succeeds; verification
     * can be re-requested).
     *
     * @param int    $profileId The profile the email belongs to (profiles.id).
     * @param string $email     The normalized (lowercased) email to verify.
     */
    public function sendVerification(int $profileId, string $email): void;
}
