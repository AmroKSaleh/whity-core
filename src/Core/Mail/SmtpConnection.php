<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Low-level line-oriented SMTP socket, abstracted so {@see SmtpMailer}'s protocol
 * logic (EHLO / STARTTLS / AUTH / MAIL / RCPT / DATA) is unit-testable with a
 * scripted fake connection — no live server, no network.
 *
 * Implementations MUST throw {@see MailException} on I/O failure (connect,
 * read/write, EOF, timeout) so the mailer surfaces one exception type.
 */
interface SmtpConnection
{
    /** Read one CRLF-terminated response line (without the trailing CRLF). */
    public function readLine(): string;

    /** Write raw bytes to the server. */
    public function write(string $data): void;

    /** Upgrade the plaintext connection to TLS (after a 220 STARTTLS reply). */
    public function enableCrypto(): void;

    public function close(): void;
}
