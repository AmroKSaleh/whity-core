<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mail\MailException;

/**
 * {@see MailException::publicHint()} maps the (safe) internal message to a
 * curated operator-facing hint (WC-email) — shown on a failed "send test"
 * without leaking transport internals.
 */
final class MailExceptionTest extends TestCase
{
    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function cases(): array
    {
        return [
            'auth 535' => ['unexpected SMTP reply 535 (expected 235)', 'authentication failed'],
            'auth at LOGIN step' => ['unexpected SMTP reply 535 (expected 235)', 'username and password'],
            'connect' => ['SMTP connect failed to mail.example.com:465 (111)', 'could not reach the mail server'],
            'starttls' => ['SMTP STARTTLS negotiation failed', 'TLS negotiation failed'],
            'recipient' => ['unexpected SMTP reply 550 (expected 250/251)', 'rejected the sender or recipient'],
            'io read' => ['SMTP read failed: timeout', 'lost the connection'],
            'crlf' => ['invalid recipient address: contains a line break', 'invalid characters'],
            'unknown' => ['some unmapped condition', 'rejected the request'],
        ];
    }

    /**
     * @dataProvider cases
     */
    public function testPublicHintCategorises(string $message, string $expectedFragment): void
    {
        self::assertStringContainsString($expectedFragment, (new MailException($message))->publicHint());
    }

    public function testPublicHintNeverEmpty(): void
    {
        self::assertNotSame('', (new MailException(''))->publicHint());
    }
}
