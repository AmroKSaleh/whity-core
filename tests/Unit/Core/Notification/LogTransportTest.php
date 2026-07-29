<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Notification;

use Psr\Log\AbstractLogger;
use PHPUnit\Framework\TestCase;
use Whity\Core\Notification\LogTransport;
use Whity\Sdk\Notification\NotificationMessage;

/**
 * Unit tests for the built-in {@see LogTransport} (da54220a): it reports a
 * successful send, logs routing metadata (but NOT the body), and accepts any
 * config.
 */
final class LogTransportTest extends TestCase
{
    public function testSendReportsSentAndLogsRoutingMetadata(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string|\Stringable, context: array<mixed>}> */
            public array $records = [];

            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        };

        $transport = new LogTransport('email', $logger);
        $message = new NotificationMessage('email', 'a@example.test', 5, 'user.invited', 'Welcome', 'secret body', null, ['x' => 1]);

        $result = $transport->send($message);

        self::assertTrue($result->success);
        self::assertSame('log:email', $result->providerId);

        self::assertCount(1, $logger->records);
        $ctx = $logger->records[0]['context'];
        self::assertSame('email', $ctx['channel']);
        self::assertSame('user.invited', $ctx['type']);
        self::assertSame(5, $ctx['tenant_id']);
        self::assertSame('a@example.test', $ctx['recipient']);
        self::assertArrayNotHasKey('body', $ctx, 'the body (possible PII/secrets) is never logged');
    }

    public function testChannelAndValidateConfig(): void
    {
        $transport = new LogTransport('sms');
        self::assertSame('sms', $transport->channel());
        self::assertSame([], $transport->validateConfig(['anything' => true]), 'the log transport needs no config');
    }
}
