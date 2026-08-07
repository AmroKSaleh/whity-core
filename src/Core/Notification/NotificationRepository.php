<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;

/**
 * Data-access layer for the notification persistence spine (WC-notifications):
 * the tenant-scoped `notifications` table (the logical notification + in-app
 * inbox row) and its per-channel `notification_deliveries` attempt rows. All SQL
 * touching those tables lives here so the dispatcher/relay/inbox-API never issue
 * raw queries (project convention).
 *
 * These are the PRIMITIVES the higher layers compose: the dispatcher creates a
 * notification and records one delivery per channel; the relay walks delivery
 * status; the inbox API reads a recipient's notifications. Every method here is
 * TENANT-SCOPED — `create`/`recordDelivery` STAMP tenant_id from the trusted
 * caller, and `find`/`listDeliveries` BIND it in the SQL literal so a caller can
 * never read another tenant's row (proven in CrossTenantRejectionRealEngineTest).
 */
final class NotificationRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert a notification for a recipient in a tenant and return its id. The
     * tenant_id is STAMPED from the trusted caller (never client input).
     *
     * @param array{subject?: string, body?: string, data?: array<string, mixed>} $attrs
     */
    public function create(int $tenantId, ?int $recipientProfileId, string $type, array $attrs = []): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO notifications (tenant_id, recipient_profile_id, type, subject, body, data, created_at, updated_at)
             VALUES (:tenant_id, :recipient, :type, :subject, :body, :data, NOW(), NOW())
             RETURNING id"
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':recipient' => $recipientProfileId,
            ':type'      => $type,
            ':subject'   => (string) ($attrs['subject'] ?? ''),
            ':body'      => (string) ($attrs['body'] ?? ''),
            ':data'      => self::encode($attrs['data'] ?? []),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['id'];
    }

    /**
     * Read one notification scoped to a tenant. Returns null for a missing id OR
     * another tenant's notification — never leaking cross-tenant existence.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapNotification($row);
    }

    /**
     * List a recipient's inbox for a tenant, newest first, optionally unread-only,
     * with limit/offset paging. Doubly scoped (tenant + recipient) so a caller
     * only ever sees their OWN notifications; the tenant_id predicate lives in the
     * SQL literal (guard-visible) and the fixed unread filter is appended.
     *
     * @return list<array<string, mixed>>
     */
    public function listForRecipient(int $tenantId, int $recipientProfileId, bool $unreadOnly, int $limit, int $offset): array
    {
        $filter = $unreadOnly ? ' AND read_at IS NULL' : '';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notifications WHERE tenant_id = :tenant_id AND recipient_profile_id = :recipient' . $filter
            . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':recipient', $recipientProfileId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(0, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => self::mapNotification($r), $rows));
    }

    /**
     * Count a recipient's inbox rows (pagination total), optionally unread-only.
     */
    public function countForRecipient(int $tenantId, int $recipientProfileId, bool $unreadOnly): int
    {
        $filter = $unreadOnly ? ' AND read_at IS NULL' : '';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE tenant_id = :tenant_id AND recipient_profile_id = :recipient' . $filter
        );
        $stmt->execute([':tenant_id' => $tenantId, ':recipient' => $recipientProfileId]);
        $count = $stmt->fetchColumn();

        return $count === false ? 0 : (int) $count;
    }

    /**
     * The recipient's unread-notification count (the inbox badge).
     */
    public function unreadCount(int $tenantId, int $recipientProfileId): int
    {
        return $this->countForRecipient($tenantId, $recipientProfileId, true);
    }

    /**
     * Mark ONE of the recipient's notifications read (idempotent — an already-read
     * row keeps its original read_at). Returns false when the id is missing or not
     * owned by this (tenant, recipient), so the caller can 404 without leaking
     * cross-tenant/cross-user existence.
     */
    public function markRead(int $tenantId, int $recipientProfileId, int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notifications SET read_at = COALESCE(read_at, NOW()), updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant_id AND recipient_profile_id = :recipient'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':recipient' => $recipientProfileId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Mark ALL of the recipient's still-unread notifications read. Returns how
     * many were flipped.
     */
    public function markAllRead(int $tenantId, int $recipientProfileId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notifications SET read_at = NOW(), updated_at = NOW()
              WHERE tenant_id = :tenant_id AND recipient_profile_id = :recipient AND read_at IS NULL'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':recipient' => $recipientProfileId]);

        return $stmt->rowCount();
    }

    /**
     * Record a per-channel delivery attempt row for a notification, stamped with
     * the (trusted) owning tenant, and return its id. Starts `queued` with zero
     * attempts; the relay slice walks it to sent/failed/bounced.
     *
     * @param array{status?: string, available_at?: string} $opts
     */
    public function recordDelivery(int $tenantId, int $notificationId, string $channel, array $opts = []): int
    {
        $status = (string) ($opts['status'] ?? 'queued');
        $availableAt = isset($opts['available_at']) ? (string) $opts['available_at'] : date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO notification_deliveries (tenant_id, notification_id, channel, status, attempts, available_at, created_at, updated_at)
             VALUES (:tenant_id, :notification_id, :channel, :status, 0, :available_at, NOW(), NOW())
             RETURNING id"
        );
        $stmt->execute([
            ':tenant_id'       => $tenantId,
            ':notification_id' => $notificationId,
            ':channel'         => $channel,
            ':status'          => $status,
            ':available_at'    => $availableAt,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['id'];
    }

    /**
     * List a notification's per-channel delivery rows, scoped to a tenant so a
     * caller can never read another tenant's delivery history. Oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listDeliveries(int $tenantId, int $notificationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM notification_deliveries WHERE tenant_id = :tenant_id AND notification_id = :notification_id ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':notification_id' => $notificationId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => self::mapDelivery($r), $rows));
    }

    /**
     * Read one delivery scoped to a tenant. Returns null for a missing id OR
     * another tenant's delivery.
     *
     * @return array<string, mixed>|null
     */
    public function findDelivery(int $tenantId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notification_deliveries WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapDelivery($row);
    }

    /**
     * Mark a delivery SENT (clearing any prior error) and store the provider's
     * message id. `attempts` is incremented so the row records how many send
     * attempts occurred.
     */
    public function markDeliverySent(int $id, ?string $providerId): void
    {
        // @tenant-guard-ignore: the delivery worker marks a delivery sent BY ID (system infra); the job's origin tenant is restored into TenantContext by JobRunner before the handler runs.
        $stmt = $this->pdo->prepare(
            "UPDATE notification_deliveries
                SET status = 'sent', provider_id = :provider_id, error = NULL, attempts = attempts + 1, sent_at = NOW(), updated_at = NOW()
              WHERE id = :id"
        );
        $stmt->execute([':provider_id' => $providerId, ':id' => $id]);
    }

    /**
     * Mark a delivery FAILED (default) or BOUNCED with the last error, bumping
     * `attempts`. An unrecognised status is coerced to 'failed' so the row can
     * never violate the CHECK constraint.
     */
    public function markDeliveryFailed(int $id, string $error, string $status = 'failed'): void
    {
        $status = in_array($status, ['failed', 'bounced'], true) ? $status : 'failed';
        // @tenant-guard-ignore: the delivery worker marks a delivery failed/bounced BY ID (system infra); the job's origin tenant is restored into TenantContext by JobRunner before the handler runs.
        $stmt = $this->pdo->prepare(
            "UPDATE notification_deliveries
                SET status = :status, error = :error, attempts = attempts + 1, updated_at = NOW()
              WHERE id = :id"
        );
        $stmt->execute([':status' => $status, ':error' => self::clampError($error), ':id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function clampError(string $error): string
    {
        return mb_substr($error, 0, 2000);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapNotification(array $row): array
    {
        $data = json_decode((string) ($row['data'] ?? '{}'), true);

        return [
            'id'                   => (int) $row['id'],
            'tenant_id'            => (int) $row['tenant_id'],
            'recipient_profile_id' => isset($row['recipient_profile_id']) && $row['recipient_profile_id'] !== null
                ? (int) $row['recipient_profile_id']
                : null,
            'type'                 => (string) $row['type'],
            'subject'              => (string) $row['subject'],
            'body'                 => (string) $row['body'],
            'data'                 => is_array($data) ? $data : [],
            'read_at'              => isset($row['read_at']) && $row['read_at'] !== null ? (string) $row['read_at'] : null,
            'created_at'           => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapDelivery(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'tenant_id'       => (int) $row['tenant_id'],
            'notification_id' => (int) $row['notification_id'],
            'channel'         => (string) $row['channel'],
            'status'          => (string) $row['status'],
            'provider_id'     => isset($row['provider_id']) && $row['provider_id'] !== null ? (string) $row['provider_id'] : null,
            'error'           => isset($row['error']) && $row['error'] !== null ? (string) $row['error'] : null,
            'attempts'        => (int) $row['attempts'],
            'available_at'    => isset($row['available_at']) && $row['available_at'] !== null ? (string) $row['available_at'] : null,
            'sent_at'         => isset($row['sent_at']) && $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            'created_at'      => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
        ];
    }
}
