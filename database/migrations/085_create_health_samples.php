<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-status-page — `health_samples`, the time series behind the public status
 * page at /status.
 *
 * One row = one observation of one component (`database`, `queue`, `scheduler`,
 * `render`, `web`, …) at a point in time. The probe writes a row per component
 * per tick; the status API aggregates them into a current state plus a rolling
 * uptime percentage, and derives incidents from contiguous runs of non-
 * operational samples. Keeping raw samples rather than a mutable "current
 * status" row is what makes uptime history and incident timelines possible at
 * all, and makes the writer append-only (no read-modify-write races between the
 * in-app probe and the external watchdog).
 *
 * DELIBERATELY NOT TENANT-OWNED: service health is a property of the
 * DEPLOYMENT, not of a tenant — every tenant on an instance shares one database
 * and one render service. There is no `tenant_id` column, so this table is
 * correctly absent from {@see \Whity\Core\Tenant\TenantOwnedTables} and out of
 * scope for the tenant-predicate guard. The public status API exposes only
 * component name, state and timing — never hostnames, versions, counts or error
 * text, so an anonymous reader learns nothing about the deployment's shape.
 *
 * `source` records WHO observed it: 'internal' (the scheduled probe, which can
 * see the database and queue) or 'external' (the watchdog container, which
 * probes the public URL from outside the app process and is therefore the only
 * thing that can record the app itself being unreachable).
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateHealthSamples
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS health_samples (
                id          BIGSERIAL     NOT NULL PRIMARY KEY,
                component   VARCHAR(64)   NOT NULL,
                status      VARCHAR(16)   NOT NULL,
                source      VARCHAR(16)   NOT NULL DEFAULT 'internal',
                latency_ms  INTEGER,
                detail      TEXT,
                observed_at TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");

        // The two access patterns: "latest state per component" and "all samples
        // for a component within a window" (uptime + incidents) are both served
        // by this descending composite.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_health_samples_component_time ON health_samples(component, observed_at DESC)');
        // Retention GC prunes by age across all components.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_health_samples_observed_at ON health_samples(observed_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS health_samples CASCADE');
    }
}
