<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-error-tracking — `error_groups`, the storage behind the built-in error
 * inbox (the `internal` error-tracking provider).
 *
 * ONE ROW PER DISTINCT ERROR, not per occurrence. Capture UPSERTs on
 * `fingerprint` and increments `occurrences`, so a 500-storm that throws the
 * same exception ten thousand times costs one row and ten thousand counter
 * bumps. That bound is the whole reason this can live in the app's own database
 * with no extra infrastructure: growth tracks the number of DISTINCT bugs, which
 * is small, rather than traffic, which is not.
 *
 * The trade-off is deliberate: there is no per-occurrence timeline, so this
 * cannot answer "show me every instance with its own request context" — it keeps
 * the FIRST and LAST sighting and the latest context. A deployment that needs
 * full event history switches the provider to `sentry` and points the DSN at
 * Sentry (or a self-hosted Sentry-protocol backend); the app code is identical.
 *
 * DELIBERATELY NOT TENANT-OWNED: error tracking is configured operator-only and
 * captures the whole deployment — a boot failure, a queue worker crash or a cron
 * error belongs to no tenant at all, so a `tenant_id` column would be a lie for
 * a large share of rows. Where an error DID happen inside a tenant request, the
 * tenant id is recorded in `context` as diagnostics, not as ownership. The table
 * is therefore correctly absent from {@see \Whity\Core\Tenant\TenantOwnedTables}.
 *
 * Everything stored here has been through
 * {@see \Whity\Core\Observability\ErrorScrubber} first.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateErrorGroups
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS error_groups (
                id             BIGSERIAL     NOT NULL PRIMARY KEY,
                fingerprint    VARCHAR(64)   NOT NULL,
                level          VARCHAR(16)   NOT NULL DEFAULT 'error',
                type           VARCHAR(255)  NOT NULL,
                message        TEXT          NOT NULL,
                file           VARCHAR(512),
                line           INTEGER,
                stack          TEXT,
                context        JSONB         NOT NULL DEFAULT '{}'::jsonb,
                environment    VARCHAR(64),
                occurrences    INTEGER       NOT NULL DEFAULT 1,
                status         VARCHAR(16)   NOT NULL DEFAULT 'unresolved',
                notified_at    TIMESTAMP,
                first_seen_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                last_seen_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                CHECK (status IN ('unresolved', 'resolved', 'ignored'))
            )
        ");

        // The UPSERT target: capture writes by fingerprint on every error, so
        // this index is on the hottest path in the system.
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_error_groups_fingerprint ON error_groups(fingerprint)');
        // The inbox: unresolved first, most recently seen at the top.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_error_groups_status_seen ON error_groups(status, last_seen_at DESC)');
        // Retention GC prunes by age.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_error_groups_last_seen ON error_groups(last_seen_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS error_groups CASCADE');
    }
}
