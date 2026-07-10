<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

use Closure;

/**
 * SMTP transport for {@see Mailer} (WC-email) — a small, dependency-free client
 * (the container has no composer/mail SDK) speaking the SMTP submission dialogue
 * over an injectable {@see SmtpConnection}:
 *
 *   greeting(220) → EHLO → [STARTTLS(220) → EHLO] → [AUTH LOGIN(235)]
 *   → MAIL FROM(250) → RCPT TO(250/251) → DATA(354) → body + <CRLF>.<CRLF> (250) → QUIT
 *
 * The connection is abstracted so the protocol is unit-tested with a scripted
 * fake (no live server). Delivery is best-effort: a {@see MailException} is thrown
 * on any unexpected reply and the caller (verification flow, hook notifications)
 * treats mail as non-critical and catches it.
 */
final class SmtpMailer implements Mailer
{
    /** @var Closure(SmtpConfig): SmtpConnection */
    private Closure $connector;

    /**
     * @param (Closure(SmtpConfig): SmtpConnection)|null $connector Test seam; defaults
     *        to a real stream connection.
     */
    public function __construct(
        private readonly SmtpConfig $config,
        ?Closure $connector = null,
    ) {
        $this->connector = $connector ?? static fn(SmtpConfig $c): SmtpConnection => StreamSmtpConnection::connect($c);
    }

    public function send(string $toEmail, string $subject, string $textBody): void
    {
        $conn = ($this->connector)($this->config);
        try {
            $this->expect($conn, 220);            // server greeting
            $this->ehlo($conn);

            if ($this->config->usesStartTls()) {
                $this->command($conn, 'STARTTLS', 220);
                $conn->enableCrypto();
                $this->ehlo($conn);               // re-EHLO over the encrypted channel
            }

            if ($this->config->hasAuth()) {
                $this->authLogin($conn);
            }

            $this->command($conn, 'MAIL FROM:<' . $this->config->fromEmail . '>', 250);
            // 250 = ok, 251 = will forward — both accept the recipient.
            $this->command($conn, 'RCPT TO:<' . $toEmail . '>', 250, 251);
            $this->command($conn, 'DATA', 354);
            $conn->write($this->buildMessage($toEmail, $subject, $textBody) . "\r\n.\r\n");
            $this->expect($conn, 250);            // message accepted

            // Politely end the session; a QUIT failure must not fail a sent message.
            try {
                $this->command($conn, 'QUIT', 221);
            } catch (MailException) {
                // ignore — the mail was already accepted above.
            }
        } finally {
            $conn->close();
        }
    }

    private function ehlo(SmtpConnection $conn): void
    {
        $this->command($conn, 'EHLO ' . $this->config->ehloDomain, 250);
    }

    private function authLogin(SmtpConnection $conn): void
    {
        $this->command($conn, 'AUTH LOGIN', 334);
        $this->command($conn, base64_encode((string) $this->config->username), 334);
        $this->command($conn, base64_encode((string) $this->config->password), 235);
    }

    /**
     * Write a command line (CRLF-terminated) and assert the reply's status code is
     * one of $accepted.
     */
    private function command(SmtpConnection $conn, string $line, int ...$accepted): void
    {
        $conn->write($line . "\r\n");
        $this->expect($conn, ...$accepted);
    }

    /**
     * Read a (possibly multi-line) SMTP reply and assert its 3-digit status code
     * is one of $accepted. Multi-line replies use "code-" continuation and a final
     * "code " line.
     */
    private function expect(SmtpConnection $conn, int ...$accepted): void
    {
        $code = null;
        do {
            $line = $conn->readLine();
            $code = (int) substr($line, 0, 3);
            $continues = isset($line[3]) && $line[3] === '-';
        } while ($continues);

        if (!in_array($code, $accepted, true)) {
            throw new MailException(sprintf('unexpected SMTP reply %d (expected %s)', $code, implode('/', $accepted)));
        }
    }

    /**
     * Build the RFC 5322 message: minimal headers + a plain-text UTF-8 body with
     * CRLF line endings and SMTP dot-stuffing.
     */
    private function buildMessage(string $toEmail, string $subject, string $textBody): string
    {
        // Addresses go into headers verbatim, so a CR/LF in them would be header
        // injection (smuggling a Bcc:, extra body, etc.). Reject rather than strip —
        // a newline in an email address is always malformed and signals an attack.
        $fromEmail = self::assertNoCrlf($this->config->fromEmail, 'from address');
        $toEmail = self::assertNoCrlf($toEmail, 'recipient address');

        $from = $this->config->fromName !== ''
            ? sprintf('%s <%s>', self::encodeHeader($this->config->fromName), $fromEmail)
            : $fromEmail;

        $headers = [
            'From: ' . $from,
            'To: ' . $toEmail,
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . self::prepareBody($textBody);
    }

    /**
     * Reject a value containing a CR or LF (email header injection). Returns the
     * value unchanged when clean.
     *
     * @throws MailException When $value contains a CR or LF.
     */
    private static function assertNoCrlf(string $value, string $label): string
    {
        if (strpbrk($value, "\r\n") !== false) {
            throw new MailException(sprintf('invalid %s: contains a line break', $label));
        }

        return $value;
    }

    /**
     * Encode a header value safely: any CR/LF is stripped first (a header value is
     * single-line, and a newline here would be header injection), then the value is
     * RFC 2047-encoded when it contains non-ASCII so unicode subjects and display
     * names survive intact.
     */
    private static function encodeHeader(string $value): string
    {
        // Fold away any embedded line breaks — a header value is a single line, and
        // display names / subjects are free text that must never break the header.
        // A run of CR/LF collapses to one space.
        $value = (string) preg_replace('/[\r\n]+/', ' ', $value);

        if (preg_match('/[\x80-\xFF]/', $value) === 1) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    /**
     * Normalise to CRLF and dot-stuff (a line starting with '.' gets an extra '.')
     * so a body line cannot prematurely terminate the DATA phase.
     */
    private static function prepareBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $body);
        $lines = explode("\r\n", $body);
        foreach ($lines as $i => $line) {
            if (isset($line[0]) && $line[0] === '.') {
                $lines[$i] = '.' . $line;
            }
        }
        return implode("\r\n", $lines);
    }
}
