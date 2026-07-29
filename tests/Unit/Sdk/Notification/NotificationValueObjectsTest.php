<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Notification;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Notification\NotificationMessage;
use Whity\Sdk\Notification\SendResult;

/**
 * Unit tests for the SDK notification value objects (b3c362b5):
 * {@see NotificationMessage} field carriage + defaults, and the
 * {@see SendResult} sent/failed factories.
 */
final class NotificationValueObjectsTest extends TestCase
{
    public function testNotificationMessageCarriesAllFields(): void
    {
        $m = new NotificationMessage(
            channel: 'email',
            recipient: 'a@example.test',
            tenantId: 7,
            type: 'user.invited',
            subject: 'Welcome',
            body: 'Hello there',
            bodyHtml: '<p>Hello there</p>',
            data: ['name' => 'A'],
            locale: 'ar',
        );

        self::assertSame('email', $m->channel);
        self::assertSame('a@example.test', $m->recipient);
        self::assertSame(7, $m->tenantId);
        self::assertSame('user.invited', $m->type);
        self::assertSame('Welcome', $m->subject);
        self::assertSame('Hello there', $m->body);
        self::assertSame('<p>Hello there</p>', $m->bodyHtml);
        self::assertSame(['name' => 'A'], $m->data);
        self::assertSame('ar', $m->locale);
    }

    public function testNotificationMessageDefaults(): void
    {
        $m = new NotificationMessage('sms', '+10000000000', 0, 'code.sent');

        self::assertSame('', $m->subject);
        self::assertSame('', $m->body);
        self::assertNull($m->bodyHtml);
        self::assertSame([], $m->data);
        self::assertNull($m->locale);
    }

    public function testSendResultSent(): void
    {
        $withId = SendResult::sent('provider-123');
        self::assertTrue($withId->success);
        self::assertSame('provider-123', $withId->providerId);
        self::assertNull($withId->error);

        $noId = SendResult::sent();
        self::assertTrue($noId->success);
        self::assertNull($noId->providerId);
        self::assertNull($noId->error);
    }

    public function testSendResultFailed(): void
    {
        $r = SendResult::failed('smtp 550 rejected');
        self::assertFalse($r->success);
        self::assertNull($r->providerId);
        self::assertSame('smtp 550 rejected', $r->error);
    }
}
