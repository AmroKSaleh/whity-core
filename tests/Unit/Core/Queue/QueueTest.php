<?php

namespace Tests\Unit\Core\Queue;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Whity\Core\Queue\Queue;

/**
 * Tests for Queue class
 */
class QueueTest extends TestCase
{
    /**
     * Test push() does not throw exception
     */
    public function testPushDoesNotThrowException(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        Queue::setLogger($logger);

        // Should not throw exception
        Queue::push('default', ['job' => 'test']);

        $this->assertTrue(true);
    }

    /**
     * Test push() accepts any queue name
     */
    public function testPushAcceptsAnyQueueName(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        Queue::setLogger($logger);

        $queueNames = ['default', 'high-priority', 'emails', 'sms', 'custom-queue'];

        foreach ($queueNames as $queueName) {
            // Should not throw exception for any queue name
            Queue::push($queueName, ['test' => true]);
        }

        $this->assertTrue(true);
    }

    /**
     * Test setLogger() allows custom logging
     */
    public function testSetLoggerAllowsCustomLogging(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $queueName = 'test-queue';
        $payload = ['action' => 'send_email', 'user_id' => 123];

        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->equalTo('Async Job Queued on [' . $queueName . ']'),
                $this->callback(function($context) use ($queueName, $payload) {
                    return isset($context['queue']) &&
                           $context['queue'] === $queueName &&
                           isset($context['payload']) &&
                           $context['payload'] === $payload &&
                           isset($context['timestamp']);
                })
            );

        Queue::setLogger($logger);
        Queue::push($queueName, $payload);
    }
}
