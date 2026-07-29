<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Sdk\Notification\NotificationMessage;
use Whity\Sdk\Notification\NotificationTransport;
use Whity\Sdk\Notification\SendResult;

/**
 * Built-in null/log transport (da54220a). It "delivers" by writing a structured
 * log line and reporting success — the safe default for dev/tests and for any
 * channel with no real provider configured, so notifications never hard-fail
 * merely for lack of a transport.
 *
 * One instance serves ONE channel (its `channel` is set at construction), so a
 * LogTransport can stand in for 'email' / 'sms' / 'push' / … in a dev stack.
 * The logged line carries no message BODY (which may contain PII/secrets) —
 * only routing metadata.
 */
final class LogTransport implements NotificationTransport
{
    private string $channel;
    private LoggerInterface $logger;

    public function __construct(string $channel, ?LoggerInterface $logger = null)
    {
        $this->channel = $channel;
        $this->logger = $logger ?? new NullLogger();
    }

    public function channel(): string
    {
        return $this->channel;
    }

    public function send(NotificationMessage $message): SendResult
    {
        $this->logger->info('[notifications] log-transport delivery', [
            'channel'   => $this->channel,
            'type'      => $message->type,
            'tenant_id' => $message->tenantId,
            'recipient' => $message->recipient,
            'subject'   => $message->subject,
        ]);

        return SendResult::sent('log:' . $this->channel);
    }

    /**
     * The log transport needs no configuration, so any config is valid.
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public function validateConfig(array $config): array
    {
        return [];
    }
}
