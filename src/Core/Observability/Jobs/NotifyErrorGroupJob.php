<?php

declare(strict_types=1);

namespace Whity\Core\Observability\Jobs;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Observability\ErrorGroupRepository;
use Whity\Sdk\JobInterface;

/**
 * Emails platform operators about a NEW or REGRESSED error
 * (WC-error-tracking).
 *
 * Enqueued by {@see \Whity\Core\Observability\InternalErrorTracker}, never run
 * inline. Capture happens on the error path, often while the system is already
 * struggling; talking to SMTP there would add latency exactly when it hurts
 * most, and a broken mail server would then break error capture itself. A durable
 * job also gets retry-with-backoff for free, so a transient SMTP failure does not
 * lose the alert.
 *
 * WHO gets it: active members of the SYSTEM tenant — the platform operators.
 * Deliberately not tenant admins: error tracking is configured operator-only and
 * captures the whole deployment, and most errors (boot, queue, cron) belong to no
 * tenant at all.
 *
 * WHAT it does NOT do: page anyone per occurrence. Only new and regressed errors
 * reach this job, and `notified_at` is stamped so the same group never mails
 * twice. Alert fatigue is how real alerts stop being read.
 *
 * Internal (not API-submittable).
 */
final class NotifyErrorGroupJob implements JobInterface
{
    public const NAME = 'core.error_tracking.notify';

    /** The operator tenant. */
    private const SYSTEM_TENANT_ID = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ErrorGroupRepository $groups,
        private readonly NotificationDispatcher $notifications,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $appUrl = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $groupId = (int) ($payload['group_id'] ?? 0);
        $reason = (string) ($payload['reason'] ?? 'new');
        if ($groupId <= 0) {
            return ['skipped' => 'missing group_id'];
        }

        $group = $this->groups->find($groupId);
        if ($group === null) {
            return ['skipped' => 'group no longer exists'];
        }

        // Belt and braces against a double enqueue: a group that has already
        // been announced does not announce again.
        if ($reason === 'new' && ($group['notified_at'] ?? null) !== null) {
            return ['skipped' => 'already notified'];
        }

        $recipients = $this->operatorProfiles();
        if ($recipients === []) {
            $this->logger?->warning('[error-tracking] no system-tenant operators to notify');

            return ['skipped' => 'no recipients'];
        }

        $subject = sprintf(
            '[%s] %s error: %s',
            $group['environment'] ?: 'whity',
            $reason === 'regressed' ? 'Regressed' : 'New',
            (string) $group['type']
        );

        $body = $this->body($group, $reason);

        $sent = 0;
        foreach ($recipients as $profileId) {
            try {
                $this->notifications->dispatch(
                    self::SYSTEM_TENANT_ID,
                    $profileId,
                    'error_tracking.error_detected',
                    [
                        'subject' => $subject,
                        'body' => $body,
                        'channels' => ['email', 'in_app'],
                        'data' => [
                            'group_id' => $groupId,
                            'reason' => $reason,
                            'type' => (string) $group['type'],
                            'occurrences' => (int) $group['occurrences'],
                        ],
                    ]
                );
                $sent++;
            } catch (\Throwable $e) {
                $this->logger?->error('[error-tracking] notify failed: ' . $e->getMessage());
            }
        }

        $this->groups->markNotified($groupId);

        return ['notified' => $sent, 'reason' => $reason];
    }

    /**
     * Active members of the system tenant.
     *
     * Binds tenant_id explicitly, so this is tenant-scoped in the ordinary way —
     * no guard exemption needed even though the subject is operator-wide.
     *
     * @return list<int>
     */
    private function operatorProfiles(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT profile_id FROM memberships
              WHERE tenant_id = :tenant_id AND status = 'active'"
        );
        $stmt->execute([':tenant_id' => self::SYSTEM_TENANT_ID]);

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /** @param array<string, mixed> $group */
    private function body(array $group, string $reason): string
    {
        $lines = [
            $reason === 'regressed'
                ? 'An error that was marked resolved has occurred again.'
                : 'A new error has been recorded on this deployment.',
            '',
            'Type:        ' . (string) $group['type'],
            'Message:     ' . (string) $group['message'],
            'Location:    ' . (string) ($group['file'] ?? 'unknown') . ':' . (string) ($group['line'] ?? '0'),
            'Environment: ' . (string) ($group['environment'] ?: 'unknown'),
            'Occurrences: ' . (string) $group['occurrences'],
            'First seen:  ' . (string) $group['first_seen_at'],
            'Last seen:   ' . (string) $group['last_seen_at'],
        ];

        if ($this->appUrl !== null && $this->appUrl !== '') {
            $lines[] = '';
            $lines[] = 'Details: ' . rtrim($this->appUrl, '/') . '/admin/errors/' . (string) $group['id'];
        }

        // The message and location are already scrubbed — they were scrubbed at
        // capture, before ever being stored, so nothing unredacted can reach an
        // inbox from here.
        return implode("\n", $lines);
    }
}
