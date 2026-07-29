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
     * @param array{queue?: string, priority?: int, max_attempts?: int, delay?: int, idempotency_key?: string|null, retain_result?: bool} $opts
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
        // Retain-on-complete opt-in (API-submitted, pollable jobs). Injected as a
        // SQL boolean LITERAL (not a bound param) so it types correctly on both
        // Postgres (native boolean) and the SQLite test engine — it is a trusted
        // derived flag, never user text.
        $retainResult = !empty($opts['retain_result']) ? 'TRUE' : 'FALSE';

        // A NULL idempotency_key is excluded by the partial unique index, so the
        // ON CONFLICT clause is a no-op for keyless jobs and this one statement
        // serves both the deduped and the plain-insert paths.
        $stmt = $this->pdo->prepare(
            "INSERT INTO jobs (tenant_id, queue, name, payload, idempotency_key, status, priority, attempts, max_attempts, retain_result, available_at, created_at, updated_at)
             VALUES (:tenant_id, :queue, :name, :payload, :idempotency_key, 'pending', :priority, 0, :max_attempts, {$retainResult}, :available_at, NOW(), NOW())
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
     * Complete a job. A job that opted into result retention (`retain_result`,
     * set by the submission API) is KEPT as status 'completed' with its result +
     * progress 100 so a caller can poll it; a transient fire-and-forget job is
     * DELETED — the queue leaves no row for it (audit/history live elsewhere).
     * The two guarded statements are mutually exclusive (a row matches exactly
     * one branch by its `retain_result` flag), so the caller need not know it.
     *
     * @param array<string, mixed> $result The handler's return value (stored for retained jobs).
     */
    public function markCompleted(int $id, array $result = []): void
    {
        // @tenant-guard-ignore: worker completes a retained job by id (system infra; cross-tenant queue).
        $retain = $this->pdo->prepare(
            "UPDATE jobs
                SET status = 'completed', progress = 100, result = :result, completed_at = NOW(), reserved_at = NULL, updated_at = NOW()
              WHERE id = :id AND retain_result = TRUE"
        );
        $retain->execute([':result' => self::encode($result), ':id' => $id]);

        // @tenant-guard-ignore: worker removes a transient completed job by id (system infra; cross-tenant queue).
        $discard = $this->pdo->prepare('DELETE FROM jobs WHERE id = :id AND retain_result = FALSE');
        $discard->execute([':id' => $id]);
    }

    /**
     * Read one job scoped to a tenant (the status API). Returns null for a
     * missing id OR another tenant's job — never leaking cross-tenant existence.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapJob($row);
    }

    /**
     * Find a tenant's job by its idempotency key (the submit API's dedupe
     * response), or null if none. Tenant-scoped, so it cannot surface another
     * tenant's job even on a key collision across tenants.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdempotencyKey(int $tenantId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE tenant_id = :tenant_id AND idempotency_key = :key');
        $stmt->execute([':tenant_id' => $tenantId, ':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapJob($row);
    }

    /**
     * List a tenant's jobs, newest first, optionally filtered by queue/status,
     * with limit/offset for pagination.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?string $queue, ?string $status, int $limit, int $offset): array
    {
        [$filterSql, $params] = self::tenantFilter($tenantId, $queue, $status);
        // tenant_id lives in the SQL LITERAL (not a builder var) so the static
        // ci-tenant-predicate-guard scanner can see the tenant scope; optional
        // filters are appended after it.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM jobs WHERE tenant_id = :tenant_id' . $filterSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', max(0, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => self::mapJob($r), $rows));
    }

    /**
     * Count a tenant's jobs matching the same optional filters (pagination total).
     */
    public function countForTenant(int $tenantId, ?string $queue, ?string $status): int
    {
        [$filterSql, $params] = self::tenantFilter($tenantId, $queue, $status);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM jobs WHERE tenant_id = :tenant_id' . $filterSql);
        $stmt->execute($params);
        $count = $stmt->fetchColumn();

        return $count === false ? 0 : (int) $count;
    }

    /**
     * Delete completed jobs whose `completed_at` is older than `$olderThanSeconds`
     * — retention GC the scheduler runs so retained jobs do not accumulate
     * forever. Returns how many were pruned.
     */
    public function pruneCompleted(int $olderThanSeconds): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(0, $olderThanSeconds));
        // @tenant-guard-ignore: retention GC prunes terminal completed jobs across all tenants (system infra).
        $stmt = $this->pdo->prepare(
            "DELETE FROM jobs WHERE status = 'completed' AND completed_at IS NOT NULL AND completed_at <= :cutoff"
        );
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Build the OPTIONAL filter SQL — appended AFTER the caller's literal
     * `WHERE tenant_id = :tenant_id` — plus the bound params for list/count. The
     * tenant_id predicate itself stays in the caller's SQL literal so the static
     * tenant-guard scanner (scripts/ci-tenant-predicate-guard.php) can see the
     * tenant scope; keeping it here in a builder array hid it from the scanner.
     *
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function tenantFilter(int $tenantId, ?string $queue, ?string $status): array
    {
        $sql = '';
        $params = [':tenant_id' => $tenantId];
        if ($queue !== null && $queue !== '') {
            $sql .= ' AND queue = :queue';
            $params[':queue'] = $queue;
        }
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        return [$sql, $params];
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

    /**
     * Map a full `jobs` row to the status-API shape (typed; JSON decoded). The
     * internal `idempotency_key` / `retain_result` columns are intentionally not
     * exposed.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapJob(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $result = isset($row['result']) && $row['result'] !== null
            ? json_decode((string) $row['result'], true)
            : null;

        return [
            'id'           => (int) $row['id'],
            'tenant_id'    => (int) $row['tenant_id'],
            'queue'        => (string) $row['queue'],
            'name'         => (string) $row['name'],
            'status'       => (string) $row['status'],
            'progress'     => (int) ($row['progress'] ?? 0),
            'attempts'     => (int) $row['attempts'],
            'max_attempts' => (int) $row['max_attempts'],
            'payload'      => is_array($payload) ? $payload : [],
            'result'       => is_array($result) ? $result : null,
            'last_error'   => isset($row['last_error']) && $row['last_error'] !== null ? (string) $row['last_error'] : null,
            'available_at' => isset($row['available_at']) && $row['available_at'] !== null ? (string) $row['available_at'] : null,
            'completed_at' => isset($row['completed_at']) && $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'created_at'   => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
        ];
    }
}
