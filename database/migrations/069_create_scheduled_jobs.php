<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-scheduler (#a934420e) — the `scheduled_jobs` registry backing the cron-tick
 * scheduler. One row = a recurring job: a cron expression + the job `name` to
 * enqueue (and its `payload`/`queue`) when due, scoped to a tenant.
 *
 * The `schedule:run` tick (exactly-once-per-minute across workers via the shared
 * store) claims rows where `enabled` AND `next_run_at <= now`, enqueues each onto
 * the durable jobs queue under its origin tenant, then advances `next_run_at` to
 * the cron's next occurrence (so a missed tick catches up ONCE, never replays).
 *
 * TENANT-OWNED ({@see \Whity\Core\Tenant\TenantOwnedTables}): `tenant_id` NOT
 * NULL + ON DELETE CASCADE. Tenant-scoped CRUD binds tenant_id; the tick's
 * claim/mark run as system infra ACROSS tenants (annotated @tenant-guard-ignore
 * in ScheduledJobRepository), stamping each enqueue with the row's origin tenant.
 * UNIQUE(tenant_id, name) makes registration idempotent (upsert by name).
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateScheduledJobs
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS scheduled_jobs (
                id              BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id       INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name            VARCHAR(191)  NOT NULL,
                cron_expression VARCHAR(191)  NOT NULL,
                payload         JSONB         NOT NULL DEFAULT '{}'::jsonb,
                queue           VARCHAR(64)   NOT NULL DEFAULT 'default',
                enabled         BOOLEAN       NOT NULL DEFAULT TRUE,
                last_run_at     TIMESTAMP,
                next_run_at     TIMESTAMP     NOT NULL,
                created_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        // One schedule per (tenant, name) — makes register() an idempotent upsert.
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_scheduled_jobs_tenant_name ON scheduled_jobs(tenant_id, name)');
        // The tick's due-claim scan: enabled rows ordered by when they are next due.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_scheduled_jobs_due ON scheduled_jobs(enabled, next_run_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_scheduled_jobs_tenant ON scheduled_jobs(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS scheduled_jobs CASCADE');
    }
}
