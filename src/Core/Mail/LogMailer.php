<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Psr\Log\LoggerInterface;

/**
 * Logging mailer (WC-235) — writes each message to the PSR-3 logger instead of
 * delivering it. Selected with MAIL_TRANSPORT=log.
 *
 * Intended for development and single-host self-service deployments without an
 * SMTP relay: an operator recovers a verification link from the application log.
 *
 * SECURITY NOTE: the body of a verification email contains a bearer-equivalent
 * link (valid until the token expires). Logging it is a deliberate trade-off for
 * dev/self-host convenience — do NOT select this transport for a multi-tenant
 * production deployment whose logs are broadly readable; use a real SMTP/API
 * transport there. This is why `log` is opt-in, never the default.
 */
final class LogMailer implements Mailer
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function send(string $toEmail, string $subject, string $textBody): void
    {
        $this->logger->info('[mail] would send email', [
            'to'      => $toEmail,
            'subject' => $subject,
            'body'    => $textBody,
        ]);
    }
}
