<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Core\Mail\LogMailer;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\NullMailer;

/**
 * Unit tests for the WC-235 mailer seam: config-driven transport selection and
 * the two shipped transports (no-op default, PSR-3 log).
 */
final class MailerFactoryTest extends TestCase
{
    private function spyLogger(): SpyLogger
    {
        return new SpyLogger();
    }

    public function testDefaultsToNullMailerWhenUnset(): void
    {
        self::assertInstanceOf(NullMailer::class, MailerFactory::fromEnv([], $this->spyLogger()));
    }

    public function testNullAndEmptyTransportsSelectNullMailer(): void
    {
        self::assertInstanceOf(NullMailer::class, MailerFactory::fromEnv(['MAIL_TRANSPORT' => 'null'], $this->spyLogger()));
        self::assertInstanceOf(NullMailer::class, MailerFactory::fromEnv(['MAIL_TRANSPORT' => ' '], $this->spyLogger()));
        self::assertInstanceOf(NullMailer::class, MailerFactory::fromEnv(['MAIL_TRANSPORT' => 'NULL'], $this->spyLogger()));
    }

    public function testLogTransportSelectsLogMailer(): void
    {
        self::assertInstanceOf(LogMailer::class, MailerFactory::fromEnv(['MAIL_TRANSPORT' => 'log'], $this->spyLogger()));
        // Case-insensitive.
        self::assertInstanceOf(LogMailer::class, MailerFactory::fromEnv(['MAIL_TRANSPORT' => 'LOG'], $this->spyLogger()));
    }

    public function testUnknownTransportFailsSafeToNullMailerWithWarning(): void
    {
        $logger = $this->spyLogger();
        $mailer = MailerFactory::fromEnv(['MAIL_TRANSPORT' => 'smtp-typo'], $logger);

        self::assertInstanceOf(NullMailer::class, $mailer);
        self::assertNotEmpty($logger->records, 'an unknown transport must warn');
    }

    public function testNullMailerSendIsANoOpAndNeverThrows(): void
    {
        (new NullMailer())->send('to@acme.test', 'Subject', 'Body');
        $this->addToAssertionCount(1);
    }

    public function testLogMailerWritesTheMessageToTheLogger(): void
    {
        $logger = $this->spyLogger();
        (new LogMailer($logger))->send('to@acme.test', 'Verify your email address', 'Open https://app.test/verify-email?token=abc');

        self::assertCount(1, $logger->records);
        self::assertSame('to@acme.test', $logger->records[0]['context']['to']);
        self::assertSame('Verify your email address', $logger->records[0]['context']['subject']);
        self::assertStringContainsString('token=abc', $logger->records[0]['context']['body']);
    }
}

/**
 * In-memory PSR-3 logger double capturing every record for assertions.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
