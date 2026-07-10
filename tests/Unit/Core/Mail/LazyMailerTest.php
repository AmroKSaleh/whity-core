<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Whity\Core\Mail\LazyMailer;
use Whity\Core\Mail\Mailer;

/**
 * {@see LazyMailer} builds the concrete transport per send (WC-email), so the
 * mailer always reflects current settings and boot never depends on the DB.
 */
final class LazyMailerTest extends TestCase
{
    public function testBuildsTransportOncePerSendAndDelegates(): void
    {
        $inner = new RecordingMailer();
        $builds = 0;
        $mailer = new LazyMailer(function () use ($inner, &$builds): Mailer {
            $builds++;
            return $inner;
        });

        self::assertSame(0, $builds, 'factory must not run until a send happens');

        $mailer->send('a@b.com', 'S1', 'B1');
        $mailer->send('c@d.com', 'S2', 'B2', '<p>html</p>');

        self::assertSame(2, $builds, 'the transport is rebuilt on every send');
        self::assertSame(
            [['a@b.com', 'S1', 'B1', null], ['c@d.com', 'S2', 'B2', '<p>html</p>']],
            $inner->sent,
        );
    }

    public function testPropagatesTransportError(): void
    {
        // A build that itself never throws (the factory contract) but yields a
        // mailer whose send() fails still surfaces the error to the caller, which
        // treats mail as best-effort.
        $mailer = new LazyMailer(static fn (): Mailer => new class implements Mailer {
            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                throw new \RuntimeException('transport down');
            }
        });

        $this->expectException(\RuntimeException::class);
        $mailer->send('a@b.com', 'S', 'B');
    }
}

final class RecordingMailer implements Mailer
{
    /** @var list<array{0:string,1:string,2:string,3:?string}> */
    public array $sent = [];

    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        $this->sent[] = [$toEmail, $subject, $textBody, $htmlBody];
    }
}
