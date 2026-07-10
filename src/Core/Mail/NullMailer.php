<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * No-op mailer — the MVP default (WC-235).
 *
 * Silently drops every message. Selected when MAIL_TRANSPORT is unset/`null`.
 * NOTE: enforcing a delivery-dependent flow (e.g. EMAIL_VERIFICATION_ENFORCED=1)
 * while the mailer is Null means links are never delivered — configure a real
 * transport (`MAIL_TRANSPORT=log` at minimum) before enforcing.
 */
final class NullMailer implements Mailer
{
    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        // Intentionally empty: no transport configured.
    }
}
