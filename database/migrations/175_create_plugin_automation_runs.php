<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Automation runs of workspace plugins (Whity Builder, WHIT-763;
 * docs/design/WHITY-BUILDER.md 2.5).
 *
 *   plugin_automation_runs — one row per run: what triggered it (the event,
 *                            the record, the person who caused it), how many
 *                            automation hops produced that event (`depth`),
 *                            where it stands, and its trace: one entry per
 *                            step with its input and outcome. A run stopped by
 *                            a guard is recorded too, with the reason, so
 *                            "why didn't it run?" has an answer.
 *                            `root_run_id` ties every run a chain of
 *                            automations started back to the first one, for
 *                            the per-write run budget; `automation_hash` is the
 *                            automation as it was when the run was queued, so
 *                            a run whose automation changed stops instead of
 *                            matching its trace against different steps.
 *   tenant_plugins.automations_enabled — the per-plugin off switch.
 *
 * TENANT-OWNED; registered in CoreTables and TenantOwnedTables, and every
 * query binds tenant_id. Idempotent and reversible.
 */
class CreatePluginAutomationRuns
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_automation_runs (
                id                 BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id          INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin_key         VARCHAR(64)   NOT NULL,
                automation_key     VARCHAR(64)   NOT NULL,
                event              VARCHAR(200)  NOT NULL,
                record_type        VARCHAR(64)   NOT NULL,
                -- @reference-lint-ignore: the record the run was about, kept in its trace after the record is deleted
                record_id          BIGINT        NOT NULL,
                depth              SMALLINT      NOT NULL,
                root_run_id        BIGINT        REFERENCES plugin_automation_runs(id) ON DELETE CASCADE,
                automation_hash    VARCHAR(64)   NOT NULL,
                -- @reference-lint-ignore: who caused the run, kept in its trace like the audit trail keeps an actor; it outlives the profile
                actor_profile_id   BIGINT,
                status             VARCHAR(16)   NOT NULL,
                reason             VARCHAR(64),
                trace              JSONB         NOT NULL,
                created_at         TIMESTAMP     NOT NULL DEFAULT NOW(),
                finished_at        TIMESTAMP
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plugin_automation_runs_plugin ON plugin_automation_runs(tenant_id, plugin_key, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plugin_automation_runs_root ON plugin_automation_runs(tenant_id, root_run_id)');
        $db->exec('ALTER TABLE tenant_plugins ADD COLUMN IF NOT EXISTS automations_enabled BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE tenant_plugins DROP COLUMN IF EXISTS automations_enabled');
        $db->exec('DROP TABLE IF EXISTS plugin_automation_runs CASCADE');
    }
}
