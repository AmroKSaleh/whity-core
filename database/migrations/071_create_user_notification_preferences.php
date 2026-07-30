<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-notifications (#c56a6455) — per-user notification preferences: which
 * notification TYPES a profile wants delivered on which CHANNELS.
 *
 * One row = a (tenant, profile, type, channel) toggle. `type` is a notification
 * type key (e.g. 'user.invited') OR the sentinel '*' meaning "all types" — a
 * channel-wide toggle. Resolution (in NotificationPreferenceResolver) is
 * opt-OUT: absent row => enabled; an exact (type, channel) row wins over a
 * ('*', channel) channel-wide row; a TRANSACTIONAL type (security/account/auth/
 * password/billing) always delivers regardless of any stored row.
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): `tenant_id`
 * NOT NULL + ON DELETE CASCADE; every read/write binds tenant_id (+ profile_id
 * for self-scoping). UNIQUE(tenant_id, profile_id, type, channel) makes the
 * upsert idempotent.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateUserNotificationPreferences
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_notification_preferences (
                id         BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id  INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                profile_id BIGINT        NOT NULL,
                type       VARCHAR(191)  NOT NULL,
                channel    VARCHAR(64)   NOT NULL,
                enabled    BOOLEAN       NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        // One toggle per (tenant, profile, type, channel) — makes the upsert idempotent.
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_user_notification_preferences ON user_notification_preferences(tenant_id, profile_id, type, channel)');
        // Resolution / listing scans a profile's toggles in one tenant.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_notification_preferences_profile ON user_notification_preferences(tenant_id, profile_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS user_notification_preferences CASCADE');
    }
}
