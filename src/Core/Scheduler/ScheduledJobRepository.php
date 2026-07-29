<?php

declare(strict_types=1);

namespace Whity\Core\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Data-access for the `scheduled_jobs` registry (WC-scheduler). All SQL touching
 * `scheduled_jobs` lives here (project convention).
 *
 * TENANT SCOPING: tenant-scoped CRUD (register/find/list/setEnabled/delete)
 * binds an explicit `tenant_id` predicate kept in the SQL LITERAL so the static
 * tenant-guard scanner sees the scope. The TICK path (claimDue/markRan) runs as
 * system infra ACROSS tenants — one scheduler services every tenant — so those
 * queries carry no tenant predicate and are annotated `@tenant-guard-ignore`;
 * the row's origin `tenant_id` is what the tick stamps onto each enqueue.
 */
final class ScheduledJobRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Register (upsert by tenant+name) a recurring job. Validates the cron
     * expression and computes the first `next_run_at`. Returns the row id.
     *
     * @param array<string, mixed> $payload
     * @param array{queue?: string, enabled?: bool} $opts
     * @throws \InvalidArgumentException on an invalid cron expression.
     */
    public function register(int $tenantId, string $name, string $cronExpression, array $payload = [], array $opts = []): int
    {
        $cron = new CronExpression($cronExpression); // throws on invalid
        $nextRun = $cron->nextRunAfter(self::nowUtc())->format('Y-m-d H:i:s');
        $queue = (string) ($opts['queue'] ?? 'default');
        // Boolean as a SQL LITERAL (not a bound param) so it types correctly on
        // both Postgres and the SQLite test engine (a trusted derived flag).
        $enabled = ($opts['enabled'] ?? true) ? 'TRUE' : 'FALSE';

        $stmt = $this->pdo->prepare(
            "INSERT INTO scheduled_jobs (tenant_id, name, cron_expression, payload, queue, enabled, next_run_at, created_at, updated_at)
             VALUES (:tenant_id, :name, :cron, :payload, :queue, {$enabled}, :next_run_at, NOW(), NOW())
             ON CONFLICT (tenant_id, name) DO UPDATE SET
                 cron_expression = EXCLUDED.cron_expression,
                 payload         = EXCLUDED.payload,
                 queue           = EXCLUDED.queue,
                 enabled         = EXCLUDED.enabled,
                 next_run_at     = EXCLUDED.next_run_at,
                 updated_at      = NOW()
             RETURNING id"
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':name'        => $name,
            ':cron'        => $cronExpression,
            ':payload'     => self::encode($payload),
            ':queue'       => $queue,
            ':next_run_at' => $nextRun,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_jobs WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::mapRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_jobs WHERE tenant_id = :tenant_id ORDER BY name ASC');
        $stmt->execute([':tenant_id' => $tenantId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => self::mapRow($r), $rows));
    }

    /**
     * Enable/disable a schedule. Returns false if no such row for this tenant.
     */
    public function setEnabled(int $tenantId, int $id, bool $enabled): bool
    {
        $literal = $enabled ? 'TRUE' : 'FALSE';
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_jobs SET enabled = {$literal}, updated_at = NOW() WHERE tenant_id = :tenant_id AND id = :id"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete a schedule. Returns false if no such row for this tenant.
     */
    public function delete(int $tenantId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM scheduled_jobs WHERE tenant_id = :tenant_id AND id = :id');
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Claim the schedules due to run at `$nowUtc` (enabled + next_run_at due),
     * ACROSS all tenants — this is the system-infra tick path.
     *
     * @return list<array<string, mixed>>
     */
    public function claimDue(string $nowUtc): array
    {
        // @tenant-guard-ignore: the scheduler tick claims due schedules ACROSS all tenants (system infra); each enqueue is stamped with the row's origin tenant_id.
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scheduled_jobs WHERE enabled = TRUE AND next_run_at <= :now ORDER BY next_run_at ASC, id ASC'
        );
        $stmt->execute([':now' => $nowUtc]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => self::mapRow($r), $rows));
    }

    /**
     * Record a run: set last_run_at and advance next_run_at (system infra, by id).
     */
    public function markRan(int $id, string $lastRunUtc, string $nextRunUtc): void
    {
        // @tenant-guard-ignore: the scheduler advances a schedule's run bookkeeping by id (system infra; cross-tenant tick).
        $stmt = $this->pdo->prepare(
            'UPDATE scheduled_jobs SET last_run_at = :last, next_run_at = :next, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':last' => $lastRunUtc, ':next' => $nextRunUtc, ':id' => $id]);
    }

    private static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRow(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);

        return [
            'id'              => (int) $row['id'],
            'tenant_id'       => (int) $row['tenant_id'],
            'name'            => (string) $row['name'],
            'cron_expression' => (string) $row['cron_expression'],
            'payload'         => is_array($payload) ? $payload : [],
            'queue'           => (string) $row['queue'],
            'enabled'         => (bool) $row['enabled'],
            'last_run_at'     => isset($row['last_run_at']) && $row['last_run_at'] !== null ? (string) $row['last_run_at'] : null,
            'next_run_at'     => isset($row['next_run_at']) && $row['next_run_at'] !== null ? (string) $row['next_run_at'] : null,
        ];
    }
}
