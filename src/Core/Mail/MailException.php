<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Raised by {@see SmtpMailer} on a transport/protocol failure (connect, TLS,
 * auth, or an unexpected SMTP reply code). Delivery is best-effort: callers
 * (e.g. the email-verification flow) catch this and treat mail as non-critical.
 *
 * The message is safe to log; it never contains the password or the message body.
 */
final class MailException extends \RuntimeException
{
    /**
     * A short, safe, operator-facing hint describing the likely cause, derived
     * from the (already-safe) exception message. Unlike the raw message it is a
     * curated, enumerated string — so it can be shown to the admin who triggered
     * a "send test" without leaking transport internals (WC-186). Defaults to a
     * generic line when the failure doesn't match a known category.
     */
    public function publicHint(): string
    {
        $m = $this->getMessage();

        return match (true) {
            str_contains($m, 'connect failed')
                => 'could not reach the mail server — check the host, port, and that outbound SMTP is allowed',
            str_contains($m, 'STARTTLS')
                => 'TLS negotiation failed — use SSL on port 465 or TLS on 587, and make sure the host matches its certificate',
            // AUTH LOGIN ends by expecting 235; 535/534/530 are auth-rejection codes.
            str_contains($m, '(expected 235)')
            || str_contains($m, ' 535 ') || str_contains($m, ' 534 ') || str_contains($m, ' 530 ')
                => 'authentication failed — check the SMTP username and password',
            str_contains($m, '(expected 250/251)') || str_contains($m, '(expected 250)')
                => 'the server rejected the sender or recipient address',
            str_contains($m, 'read failed') || str_contains($m, 'write failed')
                => 'lost the connection to the mail server — check the host, port, and encryption',
            str_contains($m, 'line break')
                => 'the from/recipient address contains invalid characters',
            default
                => 'the mail server rejected the request',
        };
    }
}
