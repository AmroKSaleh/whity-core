<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Notification;

use PHPUnit\Framework\TestCase;
use Whity\Core\Notification\LogTransport;
use Whity\Core\Notification\TransportRegistry;
use Whity\Sdk\Notification\NotificationMessage;
use Whity\Sdk\Notification\NotificationTransport;
use Whity\Sdk\Notification\SendResult;

/**
 * Unit tests for {@see TransportRegistry} (da54220a): channel registration,
 * resolution per (tenant, channel), FAIL-CLOSED on an unknown channel, and
 * last-registration-wins override.
 */
final class TransportRegistryTest extends TestCase
{
    public function testRegisterResolveAndHas(): void
    {
        $registry = new TransportRegistry();
        $email = new LogTransport('email');
        $registry->register($email);

        self::assertTrue($registry->has('email'));
        self::assertSame($email, $registry->resolve(1, 'email'));
        self::assertSame(['email'], $registry->channels());
    }

    public function testUnknownChannelResolvesToNullFailClosed(): void
    {
        $registry = new TransportRegistry();
        $registry->register(new LogTransport('email'));

        self::assertFalse($registry->has('sms'));
        self::assertNull($registry->resolve(1, 'sms'), 'an unregistered channel resolves to null (fail-closed)');
    }

    public function testLastRegistrationWinsPerChannel(): void
    {
        $registry = new TransportRegistry();
        $registry->register(new LogTransport('email'));

        // A plugin overriding the 'email' channel with its own transport.
        $override = new class implements NotificationTransport {
            public function channel(): string
            {
                return 'email';
            }

            public function send(NotificationMessage $message): SendResult
            {
                return SendResult::sent('override');
            }

            /**
             * @param array<string, mixed> $config
             * @return list<string>
             */
            public function validateConfig(array $config): array
            {
                return [];
            }
        };
        $registry->register($override);

        self::assertSame($override, $registry->resolve(1, 'email'), 'the later registration for a channel wins');
        self::assertCount(1, $registry->channels());
    }
}
