<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use PDO;

/**
 * Data-access layer for the durable `jobs` queue (WC-queue). All SQL touching
 * `jobs` lives here so the queue service/runner never issue raw queries
 * (project convention).
 *
 * The reserve (claim) is the load-bearing operation: an atomic
 * `UPDATE … WHERE id = (SELECT … LIMIT 1 [FOR UPDATE SKIP LOCKED]) RETURNING`,
 * which on Postgres lets N competing workers each grab a DIFFERENT runnable job
 * with no blocking and no double-claim. `FOR UPDATE SKIP LOCKED` is appended
 * only on the `pgsql` driver — SQLite (the local test engine) serialises all
 * writes, so a plain single-statement claim is already race-free there.
 *
 * TENANT SCOPING: `jobs` is tenant-owned, but the QUEUE mechanics run as system
 * infra ACROSS tenants (a worker processes every tenant's jobs). Those queries
 * carry no `tenant_id` predicate and are annotated `@tenant-guard-ignore`; the
 * job's origin tenant is restored into TenantContext by {@see JobRunner} before
 * the handler's own (tenant-scoped) queries run. Enqueue stamps the tenant_id
 * from the trusted caller.
 */
final class JobRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Enqueue a job for a tenant. Returns the new id, or null when a live job
     * with the same (tenant, idempotency_key) already exists (deduped).
     *
     * @param array<string, mixed> $payload
     * @param array{queue?: string, priority?: int, max_attempts?: int, delay?: int, idempotency_key?: string|null} $opts
     */
    public function enqueue(int $tenantId, string $name, array $payload, array $opts = []): ?int
    {
        $queue = (string) ($opts['queue'] ?? 'default');
        $priority = (int) ($opts['priority'] ?? 0);
        $maxAttempts = max(1, (int) ($opts['max_attempts'] ?? 3));
        $delaySeconds = max(0, (int) ($opts['delay'] ?? 0));
        $idempotencyKey = isset($opts['idempotency_key']) && (string) $opts['idempotency_key'] !== ''
            ? (string) $opts['idempotency_key']
            : null;
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);

        // A NULL idempotency_key is excluded by the partial unique index, so the
        // ON CONFLICT clause is a no-op for keyless jobs and this one statement
        // serves both the deduped and the plain-insert paths.
        $stmt = $this->pdo->prepare(
            "INSERT INTO jobs (tenant_id, queue, name, payload, idempotency_key, status, priority, attempts, max_attempts, available_at, created_at, updated_at)
             VALUES (:tenant_id, :queue, :name, :payload, :idempotency_key, 'pending', :priority, 0, :max_attempts, :available_at, NOW(), NOW())
             ON CONFLICT (tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL DO NOTHING
             RETURNING id"
        );
        $stmt->execute([
            ':tenant_id'       => $tenantId,
            ':queue'           => $queue,
            ':name'            => $name,
            ':payload'         => self::encode($payload),
            ':idempotency_key' => $idempotencyKey,
            ':priority'        => $priority,
            ':max_attempts'    => $maxAttempts,
            ':available_at'    => $availableAt,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['id'];
    }

    /**
     * Atomically claim the next runnable job on a queue, or null when none is
     * due. `attempts` is incremented on claim, so a worker that crashes before
     * completing still consumes an attempt (and is eventually dead-lettered
     * rather than looping forever). The returned array carries the decoded
     * payload + attempt counters the runner needs.
     *
     * @return array<string, mixed>|null
     */
    public function reserve(string $queue = 'default'): ?array
    {
        // Two complete literals (rather than concatenating the lock clause into
        // one) so each carries the tenant-guard annotation adjacently: on
        // Postgres FOR UPDATE SKIP LOCKED gives contention-free parallel claims;
        // SQLite has no such clause but serialises writes, so the plain form is
        // already race-free there.
        if ($this->driver() === 'pgsql') {
            // @tenant-guard-ignore: the queue worker claims the next runnable job ACROSS all
            // tenants (system infra); the job's origin tenant is restored per-handler via
            // TenantContext before user code runs.
            $sql = "UPDATE jobs
                        SET status = 'reserved', reserved_at = NOW(), attempts = attempts + 1, updated_at = NOW()
                      WHERE id = (
                          SELECT id FROM jobs
                           WHERE status = 'pending' AND queue = :queue AND available_at <= NOW()
                           ORDER BY priority ASC, available_at ASC, id ASC
                           LIMIT 1 FOR UPDATE SKIP LOCKED
                      )
                      RETURNING id, tenant_id, queue, name, payload, attempts, max_attempts";
        } else {
            // @tenant-guard-ignore: the queue worker claims the next runnable job ACROSS all
            // tenants (system infra; single-writer SQLite); the job's origin tenant is
            // restored per-handler via TenantContext before user code runs.
            $sql = "UPDATE jobs
                        SET status = 'reserved', reserved_at = NOW(), attempts = attempts + 1, updated_at = NOW()
                      WHERE id = (
                          SELECT id FROM jobs
                           WHERE status = 'pending' AND queue = :queue AND available_at <= NOW()
                           ORDER BY priority ASC, available_at ASC, id ASC
                           LIMIT 1
                      )
                      RETURNING id, tenant_id, queue, name, payload, attempts, max_attempts";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':queue' => $queue]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Remove a completed job. The queue is transient — a done job leaves no row
     * (audit/history live elsewhere).
     */
    public function markCompleted(int $id): void
    {
        // @tenant-guard-ignore: worker removes a completed job by id (system infra; cross-tenant queue).
        $stmt = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Reschedule a failed job for another attempt after `backoffSeconds`.
     */
    public function retry(int $id, int $backoffSeconds, string $error): void
    {
        $availableAt = date('Y-m-d H:i:s', time() + max(0, $backoffSeconds));
        // @tenant-guard-ignore: worker reschedules a failed job by id (system infra; cross-tenant queue).
        $stmt = $this->pdo->prepare(
            "UPDATE jobs
                SET status = 'pending', reserved_at = NULL, available_at = :available_at, last_error = :error, updated_at = NOW()
              WHERE id = :id"
        );
        $stmt->execute([':available_at' => $availableAt, ':error' => self::clampError($error), ':id' => $id]);
    }

    /**
     * Dead-letter an exhausted job (kept for inspection/replay).
     */
    public function deadLetter(int $id, string $error): void
    {
        // @tenant-guard-ignore: worker dead-letters an exhausted job by id (system infra; cross-tenant queue).
        $stmt = $this->pdo->prepare(
            "UPDATE jobs SET status = 'dead', reserved_at = NULL, last_error = :error, updated_at = NOW() WHERE id = :id"
        );
        $stmt->execute([':error' => self::clampError($error), ':id' => $id]);
    }

    /**
     * Return lease-expired reserved jobs to `pending` (a worker crashed while
     * holding them). Returns how many were reclaimed.
     */
    public function reclaimExpired(int $visibilitySeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(1, $visibilitySeconds));
        // @tenant-guard-ignore: reaper returns lease-expired reserved jobs to pending across all tenants (system infra).
        $stmt = $this->pdo->prepare(
            "UPDATE jobs
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $decoded = json_decode((string) $row['payload'], true);

        return [
            'id'           => (int) $row['id'],
            'tenant_id'    => (int) $row['tenant_id'],
            'queue'        => (string) $row['queue'],
            'name'         => (string) $row['name'],
            'payload'      => is_array($decoded) ? $decoded : [],
            'attempts'     => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
        ];
    }
}
