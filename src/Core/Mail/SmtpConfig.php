<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Immutable SMTP connection config for {@see SmtpMailer} (WC-email).
 *
 * `encryption`:
 *   - 'none' — plain TCP (e.g. Mailpit/MailHog on :1025, or an internal relay).
 *   - 'tls'  — plain TCP then STARTTLS upgrade (submission on :587).
 *   - 'ssl'  — implicit TLS from connect (SMTPS on :465).
 *
 * Auth is attempted only when `username` is non-empty. The password is a
 * secret supplied by the caller — decrypted from the encrypted-secret store by
 * {@see MailerFactory}, never held in plaintext settings.
 */
final class SmtpConfig
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $fromEmail,
        public readonly string $fromName = '',
        public readonly string $encryption = 'tls',
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly int $timeoutSeconds = 10,
        /** HELO/EHLO domain the client announces; kept generic by default. */
        public readonly string $ehloDomain = 'localhost',
    ) {
    }

    public function usesImplicitTls(): bool
    {
        return $this->encryption === 'ssl';
    }

    public function usesStartTls(): bool
    {
        return $this->encryption === 'tls';
    }

    public function hasAuth(): bool
    {
        return $this->username !== null && $this->username !== '';
    }
}
