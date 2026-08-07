<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-queue durable job runner — the `jobs` table backing the platform's
 * durable, tenant-aware async queue (replaces the log-only Queue stub).
 *
 * A row is one unit of work: a named handler (`name`) + a JSON `payload`,
 * scoped to the tenant that enqueued it (`tenant_id`, restored into
 * TenantContext before the handler runs). Lifecycle via `status`:
 *   pending  → runnable once `available_at` <= now (delay + exponential backoff)
 *   reserved → claimed by a worker (`reserved_at` set); reclaimed to pending if
 *              its lease expires (a crashed worker)
 *   dead     → exhausted `max_attempts` (dead-letter; kept for inspect/replay)
 * A completed job is DELETED (the queue is transient; audit lives elsewhere).
 *
 * The atomic claim (reserve) is a `UPDATE … WHERE id = (SELECT … FOR UPDATE
 * SKIP LOCKED LIMIT 1) RETURNING` in the repository — so `FOR UPDATE SKIP
 * LOCKED` is deliberately NOT in this DDL (it is a query clause, and the SQLite
 * test engine has no equivalent). `idempotency_key` is deduped per tenant via a
 * partial unique index (enqueue uses ON CONFLICT DO NOTHING).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): carries
 * `tenant_id` NOT NULL + ON DELETE CASCADE. The queue mechanics (reserve/
 * complete/fail/reclaim) run as SYSTEM infra ACROSS tenants — those repository
 * queries are annotated `@tenant-guard-ignore`; per-handler user code runs
 * under the job's restored tenant, so its queries stay tenant-scoped.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateJobs
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id              BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id       INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                queue           VARCHAR(64)   NOT NULL DEFAULT 'default',
                name            VARCHAR(191)  NOT NULL,
                payload         JSONB         NOT NULL DEFAULT '{}'::jsonb,
                idempotency_key VARCHAR(191),
                status          VARCHAR(16)   NOT NULL DEFAULT 'pending',
                priority        INTEGER       NOT NULL DEFAULT 0,
                attempts        INTEGER       NOT NULL DEFAULT 0,
                max_attempts    INTEGER       NOT NULL DEFAULT 3,
                available_at    TIMESTAMP     NOT NULL DEFAULT NOW(),
                reserved_at     TIMESTAMP,
                last_error      TEXT,
                created_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                CHECK (status IN ('pending', 'reserved', 'dead'))
            )
        ");

        // Claim ordering: runnable pending jobs on a queue, best (lowest)
        // priority + oldest availability first.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_claim ON jobs(queue, status, priority, available_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_tenant_id ON jobs(tenant_id)');
        // Reaper scan for lease-expired reserved jobs.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_reclaim ON jobs(status, reserved_at)');

        // One LIVE job per (tenant, idempotency_key). NULL keys are never
        // deduped (Postgres + SQLite both treat NULLs as distinct, and the
        // partial predicate excludes them explicitly).
        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_jobs_idempotency
            ON jobs(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS jobs CASCADE');
    }
}
