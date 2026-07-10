<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mail\MailException;
use Whity\Core\Mail\SmtpConfig;
use Whity\Core\Mail\SmtpConnection;
use Whity\Core\Mail\SmtpMailer;

/**
 * Proves the SMTP dialogue in {@see SmtpMailer} against a scripted fake
 * connection (WC-email) — no live server. Covers the plain/Mailpit path, the
 * STARTTLS + AUTH path, error-code handling, dot-stuffing, and header encoding.
 */
final class SmtpMailerTest extends TestCase
{
    public function testPlainNoAuthDialogue(): void
    {
        // Mailpit-style: no TLS, no auth.
        $conn = new FakeSmtpConnection([
            '220 mailpit ready',
            '250 mailpit',            // EHLO
            '250 OK',                 // MAIL FROM
            '250 OK',                 // RCPT TO
            '354 End data with <CR><LF>.<CR><LF>',
            '250 OK: queued',         // message accepted
            '221 Bye',                // QUIT
        ]);
        $mailer = new SmtpMailer(
            new SmtpConfig('mailpit', 1025, 'no-reply@whity.local', 'Whity', 'none'),
            fn(): SmtpConnection => $conn,
        );

        $mailer->send('alice@example.com', 'Hello', "Line 1\nLine 2");

        $sent = $conn->written();
        self::assertStringContainsString("EHLO localhost\r\n", $sent);
        self::assertStringNotContainsString('STARTTLS', $sent);
        self::assertStringNotContainsString('AUTH', $sent);
        self::assertStringContainsString("MAIL FROM:<no-reply@whity.local>\r\n", $sent);
        self::assertStringContainsString("RCPT TO:<alice@example.com>\r\n", $sent);
        self::assertStringContainsString("DATA\r\n", $sent);
        self::assertStringContainsString('Subject: Hello', $sent);
        self::assertStringContainsString('From: Whity <no-reply@whity.local>', $sent);
        self::assertStringContainsString('To: alice@example.com', $sent);
        self::assertStringContainsString("Line 1\r\nLine 2", $sent);
        self::assertStringEndsWith("\r\n.\r\nQUIT\r\n", $sent);
    }

    public function testMultipartAlternativeWhenHtmlProvided(): void
    {
        $conn = new FakeSmtpConnection([
            '220 mailpit ready', '250 mailpit', '250 OK', '250 OK',
            '354 go', '250 queued', '221 Bye',
        ]);
        $mailer = new SmtpMailer(
            new SmtpConfig('mailpit', 1025, 'no-reply@whity.local', 'Whity', 'none'),
            fn(): SmtpConnection => $conn,
        );

        $mailer->send('alice@example.com', 'Hi', "text fallback", "<p>rich body</p>");

        $sent = $conn->written();
        // multipart/alternative envelope with a boundary, both parts present.
        self::assertMatchesRegularExpression(
            '/Content-Type: multipart\/alternative; boundary="=_whity_[0-9a-f]{24}"/',
            $sent
        );
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $sent);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $sent);
        self::assertStringContainsString('text fallback', $sent);
        self::assertStringContainsString('<p>rich body</p>', $sent);
        // Text part precedes the HTML part (clients pick the last supported = HTML).
        self::assertLessThan(strpos($sent, 'text/html'), strpos($sent, 'text/plain'));
        self::assertStringEndsWith("\r\n.\r\nQUIT\r\n", $sent);
    }

    public function testStartTlsAndAuthDialogue(): void
    {
        $conn = new FakeSmtpConnection([
            '220 smtp.example.com ESMTP',
            '250-smtp.example.com',   // EHLO (multi-line) …
            '250 STARTTLS',           // … continuation end
            '220 Go ahead',           // STARTTLS
            '250 smtp.example.com',   // EHLO after TLS
            '334 VXNlcm5hbWU6',       // AUTH LOGIN → username prompt
            '334 UGFzc3dvcmQ6',       // password prompt
            '235 Authentication succeeded',
            '250 OK',                 // MAIL FROM
            '250 OK',                 // RCPT TO
            '354 go',
            '250 queued',
            '221 bye',
        ]);
        $mailer = new SmtpMailer(
            new SmtpConfig('smtp.example.com', 587, 'ops@acme.com', 'Acme', 'tls', 'ops@acme.com', 's3cr3t'),
            fn(): SmtpConnection => $conn,
        );

        $mailer->send('bob@example.com', 'Hi', 'body');

        $sent = $conn->written();
        self::assertStringContainsString("STARTTLS\r\n", $sent);
        self::assertTrue($conn->cryptoEnabled(), 'STARTTLS must upgrade the connection');
        self::assertStringContainsString("AUTH LOGIN\r\n", $sent);
        self::assertStringContainsString(base64_encode('ops@acme.com') . "\r\n", $sent);
        self::assertStringContainsString(base64_encode('s3cr3t') . "\r\n", $sent);
    }

    public function testThrowsOnRejectedRecipient(): void
    {
        $conn = new FakeSmtpConnection([
            '220 ready',
            '250 hi',                 // EHLO
            '250 OK',                 // MAIL FROM
            '550 No such user',       // RCPT TO rejected
        ]);
        $mailer = new SmtpMailer(
            new SmtpConfig('h', 25, 'f@x.com', '', 'none'),
            fn(): SmtpConnection => $conn,
        );

        $this->expectException(MailException::class);
        $mailer->send('nobody@example.com', 'S', 'B');
    }

    public function testRejectsCrlfInRecipientBeforeWritingEnvelope(): void
    {
        // A CR/LF in the recipient would smuggle extra SMTP commands into the
        // MAIL FROM / RCPT TO envelope (recipient smuggling). The guard must run
        // BEFORE anything is written to the socket — not just before the DATA phase.
        $conn = new FakeSmtpConnection(['220 ready', '250 hi', '250 OK', '250 OK', '354 go', '250 ok', '221 bye']);
        $mailer = new SmtpMailer(
            new SmtpConfig('h', 25, 'f@x.com', '', 'none'),
            fn(): SmtpConnection => $conn,
        );

        try {
            $mailer->send("victim@example.com\r\nRCPT TO:<bcc@evil.com>", 'S', 'B');
            self::fail('expected MailException for CRLF in recipient');
        } catch (MailException) {
            // Expected — and crucially NOTHING was written to the socket, so no
            // smuggled RCPT ever reached the server.
            self::assertSame('', $conn->written(), 'no bytes may be sent when the recipient is rejected');
        }
    }

    public function testRejectsCrlfInFromAddress(): void
    {
        $conn = new FakeSmtpConnection(['220 ready', '250 hi']);
        $mailer = new SmtpMailer(
            new SmtpConfig('h', 25, "spoof@x.com\r\nMAIL FROM:<evil@x.com>", '', 'none'),
            fn(): SmtpConnection => $conn,
        );

        $this->expectException(MailException::class);
        $mailer->send('a@b.com', 'S', 'B');
    }

    public function testSubjectWithNewlineCannotInjectHeaders(): void
    {
        $conn = new FakeSmtpConnection([
            '220 ready', '250 hi', '250 OK', '250 OK', '354 go', '250 queued', '221 bye',
        ]);
        $mailer = new SmtpMailer(
            new SmtpConfig('h', 25, 'f@x.com', '', 'none'),
            fn(): SmtpConnection => $conn,
        );

        $mailer->send('a@b.com', "Hi\r\nBcc: attacker@evil.com", 'body');

        $sent = $conn->written();
        // The injected header must NOT appear as a real header line: the newline is
        // folded to a space inside the Subject, so "Bcc:" never starts a line.
        self::assertStringNotContainsString("\r\nBcc:", $sent);
        self::assertStringContainsString('Subject: Hi Bcc: attacker@evil.com', $sent);
    }

    public function testDotStuffingAndUnicodeSubject(): void
    {
        $conn = new FakeSmtpConnection([
            '220 ready', '250 hi', '250 OK', '250 OK', '354 go', '250 queued', '221 bye',
        ]);
        $mailer = new SmtpMailer(
            new SmtpConfig('h', 25, 'f@x.com', '', 'none'),
            fn(): SmtpConnection => $conn,
        );

        $mailer->send('a@b.com', 'Café ☕', ".leading dot\nnormal");

        $sent = $conn->written();
        // Dot-stuffed: a body line starting with '.' becomes '..'.
        self::assertStringContainsString("\r\n..leading dot\r\n", $sent);
        // RFC 2047-encoded unicode subject.
        self::assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Café ☕') . '?=', $sent);
    }
}

/**
 * Scripted, in-memory {@see SmtpConnection}: returns queued reply lines and
 * records everything written.
 */
final class FakeSmtpConnection implements SmtpConnection
{
    private string $written = '';
    private bool $crypto = false;

    /** @param list<string> $replies */
    public function __construct(private array $replies)
    {
    }

    public function readLine(): string
    {
        if ($this->replies === []) {
            throw new MailException('fake: no more scripted replies');
        }
        return array_shift($this->replies);
    }

    public function write(string $data): void
    {
        $this->written .= $data;
    }

    public function enableCrypto(): void
    {
        $this->crypto = true;
    }

    public function close(): void
    {
    }

    public function written(): string
    {
        return $this->written;
    }

    public function cryptoEnabled(): bool
    {
        return $this->crypto;
    }
}
