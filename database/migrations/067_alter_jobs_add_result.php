<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * WC-jobs-api (#3fb69c97) — extend the durable `jobs` table so the async-job
 * submission/status API (POST /api/jobs, GET /api/jobs/{id}) can report a job's
 * progress and RETURN ITS RESULT after completion.
 *
 * The queue stayed transient by default (a completed fire-and-forget job is
 * DELETED — see 065). But an API caller that submits a job needs to poll it and
 * read its result, which a deleted row cannot provide. So this adds:
 *   - progress       INT   0–100, advanced by the handler / set to 100 on done
 *   - result         JSONB the handler's return value (null until completed)
 *   - completed_at    when the job finished
 *   - retain_result  BOOL  opt-in: when TRUE the job is KEPT as status
 *                    'completed' (with its result) instead of deleted, so it can
 *                    be polled. API-submitted jobs set this; internal
 *                    fire-and-forget jobs leave it FALSE and stay transient.
 * plus the new terminal status 'completed' in the status CHECK.
 *
 * DRIVER-AWARE (mirrors 017/041): Postgres ALTERs the live table in place
 * (preserving queued rows) and swaps the CHECK constraint; the SQLite test
 * engine — which cannot ALTER a CHECK — rebuilds the table (empty at migration
 * time, since the schema is built fresh before any test seeds data). Reversible.
 */
class AlterJobsAddResult
{
    public static function up(Database $db): void
    {
        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $db->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS progress INTEGER NOT NULL DEFAULT 0');
            $db->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS result JSONB');
            $db->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS completed_at TIMESTAMP');
            $db->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS retain_result BOOLEAN NOT NULL DEFAULT FALSE');
            $db->exec('ALTER TABLE jobs DROP CONSTRAINT IF EXISTS jobs_status_check');
            $db->exec("ALTER TABLE jobs ADD CONSTRAINT jobs_status_check CHECK (status IN ('pending', 'reserved', 'dead', 'completed'))");

            return;
        }

        self::rebuildSqlite($db);
    }

    public static function down(Database $db): void
    {
        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $db->exec('ALTER TABLE jobs DROP CONSTRAINT IF EXISTS jobs_status_check');
            // Terminal 'completed' rows would violate the narrowed CHECK; drop them first.
            $db->exec("DELETE FROM jobs WHERE status = 'completed'");
            $db->exec("ALTER TABLE jobs ADD CONSTRAINT jobs_status_check CHECK (status IN ('pending', 'reserved', 'dead'))");
            $db->exec('ALTER TABLE jobs DROP COLUMN IF EXISTS retain_result');
            $db->exec('ALTER TABLE jobs DROP COLUMN IF EXISTS completed_at');
            $db->exec('ALTER TABLE jobs DROP COLUMN IF EXISTS result');
            $db->exec('ALTER TABLE jobs DROP COLUMN IF EXISTS progress');

            return;
        }

        // SQLite: rebuild back to the 065 shape.
        $db->exec('ALTER TABLE jobs RENAME TO jobs_post067');
        self::createBaseJobsTable($db);
        $db->exec(
            'INSERT INTO jobs (id, tenant_id, queue, name, payload, idempotency_key, status, priority, attempts, max_attempts, available_at, reserved_at, last_error, created_at, updated_at)
             SELECT id, tenant_id, queue, name, payload, idempotency_key, status, priority, attempts, max_attempts, available_at, reserved_at, last_error, created_at, updated_at
               FROM jobs_post067 WHERE status <> \'completed\''
        );
        $db->exec('DROP TABLE jobs_post067');
        self::createJobsIndexes($db);
    }

    /**
     * Rebuild the SQLite `jobs` table with the four new columns and the widened
     * status CHECK, copying the (migration-time empty) existing rows over.
     */
    private static function rebuildSqlite(Database $db): void
    {
        $db->exec('ALTER TABLE jobs RENAME TO jobs_pre067');
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
                progress        INTEGER       NOT NULL DEFAULT 0,
                result          JSONB,
                retain_result   BOOLEAN       NOT NULL DEFAULT FALSE,
                available_at    TIMESTAMP     NOT NULL DEFAULT NOW(),
                reserved_at     TIMESTAMP,
                completed_at    TIMESTAMP,
                last_error      TEXT,
                created_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                CHECK (status IN ('pending', 'reserved', 'dead', 'completed'))
            )
        ");
        $db->exec(
            'INSERT INTO jobs (id, tenant_id, queue, name, payload, idempotency_key, status, priority, attempts, max_attempts, available_at, reserved_at, last_error, created_at, updated_at)
             SELECT id, tenant_id, queue, name, payload, idempotency_key, status, priority, attempts, max_attempts, available_at, reserved_at, last_error, created_at, updated_at
               FROM jobs_pre067'
        );
        $db->exec('DROP TABLE jobs_pre067');
        self::createJobsIndexes($db);
    }

    /** The original 065 `jobs` table shape (used by down()'s SQLite rebuild). */
    private static function createBaseJobsTable(Database $db): void
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
    }

    private static function createJobsIndexes(Database $db): void
    {
        $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_claim ON jobs(queue, status, priority, available_at)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_tenant_id ON jobs(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_jobs_reclaim ON jobs(status, reserved_at)');
        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_jobs_idempotency
            ON jobs(tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL
        ");
    }
}
