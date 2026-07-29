<?php

declare(strict_types=1);

namespace Whity\Sdk\Notification;

/**
 * An immutable notification to deliver over ONE channel (SDK v1.x).
 *
 * The host's notification service builds one NotificationMessage per
 * (recipient, channel) pair and hands it to that channel's
 * {@see NotificationTransport::send()}. The SDK depends on nothing but PHP —
 * this is a plain value object with no behaviour.
 */
final class NotificationMessage
{
    /**
     * @param string               $channel   Channel id (e.g. 'email', 'sms', 'inapp', 'push').
     * @param string               $recipient Channel-specific address (email, phone, profile id, device token).
     * @param int                  $tenantId  Origin tenant (0 = system).
     * @param string               $type      Notification type key (e.g. 'user.invited', 'password.reset').
     * @param string               $subject   Subject/title ('' for channels without one).
     * @param string               $body      Plain-text body.
     * @param string|null          $bodyHtml  Optional HTML body.
     * @param array<string, mixed> $data      Structured context / template variables.
     * @param string|null          $locale    Recipient locale (BCP-47), if known.
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $recipient,
        public readonly int $tenantId,
        public readonly string $type,
        public readonly string $subject = '',
        public readonly string $body = '',
        public readonly ?string $bodyHtml = null,
        public readonly array $data = [],
        public readonly ?string $locale = null,
    ) {
    }
}
