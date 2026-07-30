<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-notifications (#2aa3411a) — the notification TEMPLATE store: the rendered
 * subject/text/HTML for a notification type on a channel in a locale.
 *
 * A row is one template keyed by (tenant_id, type, channel, locale). `tenant_id`
 * follows the platform's global-vs-tenant convention: **0 = the global default
 * core set** (operator-managed, applies to every tenant); **> 0 = a tenant's
 * override**. `locale` '' is the default/fallback locale. Resolution
 * (NotificationTemplateRepository) prefers a tenant override over a global
 * default, and an exact locale over the default locale; with no match the
 * renderer falls back to the caller-supplied inline subject/body.
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every read
 * binds tenant_id (a caller reads its own overrides + the global 0 set); a
 * regular tenant can only write its OWN rows, never the global set (the same
 * asymmetry as global base roles). UNIQUE(tenant_id, type, channel, locale)
 * makes the upsert idempotent.
 *
 * Structural only — the default core set is seeded separately
 * ({@see \Whity\Core\Notification\NotificationTemplateSeeder}). Idempotent
 * (IF NOT EXISTS) and reversible via down().
 */
class CreateNotificationTemplates
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS notification_templates (
                id         BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id  INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                type       VARCHAR(191)  NOT NULL,
                channel    VARCHAR(64)   NOT NULL,
                locale     VARCHAR(35)   NOT NULL DEFAULT '',
                subject    VARCHAR(255)  NOT NULL DEFAULT '',
                body_text  TEXT          NOT NULL DEFAULT '',
                body_html  TEXT,
                created_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        // One template per (tenant, type, channel, locale) — makes the upsert idempotent.
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_notification_templates ON notification_templates(tenant_id, type, channel, locale)');
        // Resolution scan: a (type, channel) across the caller's tenant + the global 0 set.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_templates_lookup ON notification_templates(type, channel, tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS notification_templates CASCADE');
    }
}
