<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-notifications (#d89dcc2c) — the persistence spine for the notification
 * subsystem: `notifications` (the logical, tenant-scoped notification that also
 * backs the in-app inbox) and `notification_deliveries` (one row per channel
 * attempt: status, provider id, error, attempt count, timestamps).
 *
 * A `notifications` row is one message for one recipient profile in one tenant;
 * the dispatcher fans it out into one `notification_deliveries` row per channel
 * (in_app / email / push / …). The delivery status walks
 * queued -> sent | failed | bounced; a retryable failure stays `queued` with
 * `attempts` incremented and `available_at` pushed out (backoff), so the relay's
 * sweep index (status, available_at) finds it — the same model as `jobs`.
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): BOTH tables
 * carry `tenant_id` NOT NULL + ON DELETE CASCADE. `notification_deliveries`
 * DENORMALISES the tenant from its parent notification so the sweep index and
 * the predicate guard sit on one row (the same pattern as `event_outbox` /
 * `entity_tags`). Tenant-scoped reads/writes bind tenant_id; the eventual relay
 * sweep runs as system infra across tenants (annotated @tenant-guard-ignore in
 * its own slice) and restores each delivery's origin tenant before any
 * tenant-scoped handler runs.
 *
 * Indexes: an INBOX index for the per-recipient newest-first listing + a partial
 * UNREAD index for the badge count; a SWEEP index for the relay's due-delivery
 * scan + a per-notification index for a message's delivery history.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateNotifications
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                id                   BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id            INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                recipient_profile_id BIGINT,
                type                 VARCHAR(191)  NOT NULL,
                subject              VARCHAR(255)  NOT NULL DEFAULT '',
                body                 TEXT          NOT NULL DEFAULT '',
                data                 JSONB         NOT NULL DEFAULT '{}'::jsonb,
                read_at              TIMESTAMP,
                created_at           TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        // Inbox listing: a recipient's notifications in one tenant, newest first.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notifications_inbox ON notifications(tenant_id, recipient_profile_id, created_at)');
        // Unread-badge count: only the still-unread rows (partial index).
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notifications_unread ON notifications(tenant_id, recipient_profile_id) WHERE read_at IS NULL');
        // Tenant scan / predicate-guard anchor.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notifications_tenant ON notifications(tenant_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS notification_deliveries (
                id              BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id       INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                notification_id BIGINT        NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
                channel         VARCHAR(64)   NOT NULL,
                status          VARCHAR(16)   NOT NULL DEFAULT 'queued',
                provider_id     VARCHAR(255),
                error           TEXT,
                attempts        INTEGER       NOT NULL DEFAULT 0,
                available_at    TIMESTAMP     NOT NULL DEFAULT NOW(),
                sent_at         TIMESTAMP,
                created_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                CHECK (status IN ('queued', 'sent', 'failed', 'bounced'))
            )
        ");
        // Relay sweep: due deliveries awaiting (re)send, oldest-available first.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_deliveries_sweep ON notification_deliveries(status, available_at)');
        // A message's per-channel delivery history.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_deliveries_notification ON notification_deliveries(notification_id)');
        // Tenant scan / predicate-guard anchor.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_notification_deliveries_tenant ON notification_deliveries(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS notification_deliveries CASCADE');
        $db->exec('DROP TABLE IF EXISTS notifications CASCADE');
    }
}
