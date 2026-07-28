<?php

declare(strict_types=1);

namespace Whity\Core\Events;

use PDO;
use Whity\Core\Support\Ulid;

/**
 * Data-access layer for the durable event spine (#154): the append-only
 * `domain_events` log + its `event_outbox` relay bookkeeping. All SQL touching
 * those two tables lives here (project convention — no raw queries in callers).
 *
 * WRITE PATH — {@see self::append()}: inserts the immutable event row AND its
 * `pending` outbox row. If the caller already holds a transaction (the common
 * case: dispatchAsync fired mid-request, right after a business write) the two
 * inserts simply join it, so event + intent-to-relay commit atomically with
 * that write — the transactional-outbox guarantee. If the connection is in
 * autocommit, append wraps its own short transaction so the pair stays atomic
 * with each other. It never begins a transaction over one the caller owns
 * (PDO has no real nesting), which also keeps it safe under the PG test harness
 * that wraps each test in a transaction.
 *
 * RELAY PATH — reserve/markRelayed/markDead/reclaimExpired: the load-bearing
 * claim is an atomic `UPDATE … WHERE event_id = (SELECT … LIMIT 1 [FOR UPDATE
 * SKIP LOCKED]) RETURNING`, so N relay workers each grab a DIFFERENT pending
 * event with no double-relay (Postgres); SQLite serialises writes so the plain
 * form is already race-free there.
 *
 * TENANT SCOPING: both tables are tenant-owned, but the RELAY runs as system
 * infra ACROSS tenants (one worker relays every tenant's events). Those queries
 * carry no tenant predicate and are annotated `@tenant-guard-ignore`; the
 * event's origin tenant travels on `event_outbox.tenant_id` (denormalised) and
 * is what a relay handler restores into TenantContext before tenant-scoped work
 * runs. Append stamps tenant_id from the trusted caller.
 */
final class DomainEventStore
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Append a domain event (+ its pending outbox row) and return the new ULID.
     *
     * @param array<string, mixed> $payload
     * @param array{aggregate_type?: string|null, aggregate_id?: string|null, actor_user_id?: int|null, occurred_at?: string|null} $meta
     */
    public function append(int $tenantId, string $eventName, array $payload, array $meta = []): string
    {
        $id = Ulid::generate();
        $occurredAt = isset($meta['occurred_at']) && (string) $meta['occurred_at'] !== ''
            ? (string) $meta['occurred_at']
            : date('Y-m-d H:i:s');

        // Only wrap when the caller is NOT already transactional — see class doc.
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $event = $this->pdo->prepare(
                "INSERT INTO domain_events (id, tenant_id, event_name, aggregate_type, aggregate_id, actor_user_id, payload, occurred_at, created_at)
                 VALUES (:id, :tenant_id, :event_name, :aggregate_type, :aggregate_id, :actor_user_id, :payload, :occurred_at, NOW())"
            );
            $event->execute([
                ':id'             => $id,
                ':tenant_id'      => $tenantId,
                ':event_name'     => $eventName,
                ':aggregate_type' => $meta['aggregate_type'] ?? null,
                ':aggregate_id'   => isset($meta['aggregate_id']) ? (string) $meta['aggregate_id'] : null,
                ':actor_user_id'  => isset($meta['actor_user_id']) ? (int) $meta['actor_user_id'] : null,
                ':payload'        => self::encode($payload),
                ':occurred_at'    => $occurredAt,
            ]);

            $outbox = $this->pdo->prepare(
                "INSERT INTO event_outbox (event_id, tenant_id, status, attempts, available_at, created_at, updated_at)
                 VALUES (:event_id, :tenant_id, 'pending', 0, NOW(), NOW(), NOW())"
            );
            $outbox->execute([':event_id' => $id, ':tenant_id' => $tenantId]);

            if ($ownTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $id;
    }

    /**
     * Atomically claim the next relayable event, or null when none is due.
     * Returns the outbox counters joined with the event content a relay handler
     * needs (name, payload, tenant, aggregate).
     *
     * @return array<string, mixed>|null
     */
    public function reserve(): ?array
    {
        if ($this->driver() === 'pgsql') {
            // @tenant-guard-ignore: the relay claims the next pending event ACROSS all tenants
            // (system infra); the event's origin tenant travels on event_outbox.tenant_id and is
            // restored into TenantContext before any tenant-scoped relay handler runs.
            $sql = "UPDATE event_outbox
                        SET status = 'reserved', reserved_at = NOW(), attempts = attempts + 1, updated_at = NOW()
                      WHERE event_id = (
                          SELECT event_id FROM event_outbox
                           WHERE status = 'pending' AND available_at <= NOW()
                           ORDER BY available_at ASC, event_id ASC
                           LIMIT 1 FOR UPDATE SKIP LOCKED
                      )
                      RETURNING event_id, tenant_id, attempts, max_attempts";
        } else {
            // @tenant-guard-ignore: the relay claims the next pending event ACROSS all tenants
            // (system infra; single-writer SQLite); the origin tenant travels on
            // event_outbox.tenant_id and is restored into TenantContext before relay handlers run.
            $sql = "UPDATE event_outbox
                        SET status = 'reserved', reserved_at = NOW(), attempts = attempts + 1, updated_at = NOW()
                      WHERE event_id = (
                          SELECT event_id FROM event_outbox
                           WHERE status = 'pending' AND available_at <= NOW()
                           ORDER BY available_at ASC, event_id ASC
                           LIMIT 1
                      )
                      RETURNING event_id, tenant_id, attempts, max_attempts";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($claim === false) {
            return null;
        }

        // @tenant-guard-ignore: fetch the just-claimed event's content by id (system infra;
        // cross-tenant relay). The row's tenant_id is returned to the caller for the restore.
        $eventStmt = $this->pdo->prepare(
            'SELECT event_name, aggregate_type, aggregate_id, actor_user_id, payload, occurred_at
               FROM domain_events WHERE id = :id'
        );
        $eventStmt->execute([':id' => (string) $claim['event_id']]);
        $event = $eventStmt->fetch(PDO::FETCH_ASSOC);
        if ($event === false) {
            $event = [];
        }

        return self::normalizeRow($claim, $event);
    }

    /**
     * Mark a relayed event done. The outbox row is kept (status = 'relayed')
     * for inspection/audit; the immutable domain_events row always remains.
     */
    public function markRelayed(string $eventId): void
    {
        // @tenant-guard-ignore: relay marks an outbox row done by event_id (system infra; cross-tenant).
        $stmt = $this->pdo->prepare(
            "UPDATE event_outbox SET status = 'relayed', relayed_at = NOW(), reserved_at = NULL, updated_at = NOW() WHERE event_id = :id"
        );
        $stmt->execute([':id' => $eventId]);
    }

    /**
     * Reschedule a failed relay for another attempt after `backoffSeconds`,
     * or dead-letter it once it has exhausted `max_attempts`.
     */
    public function fail(string $eventId, int $attempts, int $maxAttempts, int $backoffSeconds, string $error): void
    {
        if ($attempts >= $maxAttempts) {
            // @tenant-guard-ignore: relay dead-letters an exhausted outbox row by event_id (system infra).
            $stmt = $this->pdo->prepare(
                "UPDATE event_outbox SET status = 'dead', reserved_at = NULL, last_error = :error, updated_at = NOW() WHERE event_id = :id"
            );
            $stmt->execute([':error' => self::clampError($error), ':id' => $eventId]);

            return;
        }

        $availableAt = date('Y-m-d H:i:s', time() + max(0, $backoffSeconds));
        // @tenant-guard-ignore: relay reschedules a failed outbox row by event_id (system infra; cross-tenant).
        $stmt = $this->pdo->prepare(
            "UPDATE event_outbox
                SET status = 'pending', reserved_at = NULL, available_at = :available_at, last_error = :error, updated_at = NOW()
              WHERE event_id = :id"
        );
        $stmt->execute([':available_at' => $availableAt, ':error' => self::clampError($error), ':id' => $eventId]);
    }

    /**
     * Return lease-expired reserved outbox rows to `pending` (a relay worker
     * crashed while holding them). Returns how many were reclaimed.
     */
    public function reclaimExpired(int $visibilitySeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $visibilitySeconds));
        // @tenant-guard-ignore: reaper returns lease-expired reserved outbox rows to pending across all tenants (system infra).
        $stmt = $this->pdo->prepare(
            "UPDATE event_outbox
                SET status = 'pending', reserved_at = NULL, updated_at = NOW()
              WHERE status = 'reserved' AND reserved_at <= :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function driver(): string
    {
        $name = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return is_string($name) ? $name : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function clampError(string $error): string
    {
        return mb_substr($error, 0, 2000);
    }

    /**
     * @param array<string, mixed> $claim  the reserved event_outbox row
     * @param array<string, mixed> $event  the joined domain_events row (may be empty)
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $claim, array $event): array
    {
        $decoded = isset($event['payload']) ? json_decode((string) $event['payload'], true) : [];

        return [
            'event_id'       => (string) $claim['event_id'],
            'tenant_id'      => (int) $claim['tenant_id'],
            'attempts'       => (int) $claim['attempts'],
            'max_attempts'   => (int) $claim['max_attempts'],
            'event_name'     => isset($event['event_name']) ? (string) $event['event_name'] : '',
            'aggregate_type' => isset($event['aggregate_type']) ? (string) $event['aggregate_type'] : null,
            'aggregate_id'   => isset($event['aggregate_id']) ? (string) $event['aggregate_id'] : null,
            'actor_user_id'  => isset($event['actor_user_id']) ? (int) $event['actor_user_id'] : null,
            'payload'        => is_array($decoded) ? $decoded : [],
            'occurred_at'    => isset($event['occurred_at']) ? (string) $event['occurred_at'] : null,
        ];
    }
}
