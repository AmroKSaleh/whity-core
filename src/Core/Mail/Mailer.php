<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Minimal transactional-email seam (WC-235).
 *
 * The platform sends no email in the MVP. This is the extension point: a
 * verification/notification flow depends only on this interface, and a concrete
 * transport (SMTP/SES/Postmark/…) can be dropped in later by binding a different
 * implementation in the composition root — no caller changes (Open/Closed),
 * mirroring the {@see \Whity\Core\Observability\ErrorTracker} seam.
 *
 * Implementations MUST be resilient callers-side: delivery is best-effort and a
 * failure must never corrupt application state (the caller catches + logs).
 */
interface Mailer
{
    /**
     * Send an email. Best-effort; may throw on transport failure (the caller is
     * expected to treat delivery as non-critical and catch).
     *
     * A plain-text body is ALWAYS required (text-only clients + deliverability).
     * When $htmlBody is provided the message is sent as multipart/alternative —
     * the client shows the HTML and falls back to text — so the two MUST convey
     * the same information.
     *
     * @param string      $toEmail   Recipient address (already validated/normalized).
     * @param string      $subject   Message subject.
     * @param string      $textBody  Plain-text body (always sent).
     * @param string|null $htmlBody  Optional HTML alternative; null = text-only.
     */
    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void;
}
