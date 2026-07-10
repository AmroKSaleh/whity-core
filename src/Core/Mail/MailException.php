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
}
