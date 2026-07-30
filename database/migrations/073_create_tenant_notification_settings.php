<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-notifications (#d70c6083) — per-tenant SENDER configuration: how a tenant's
 * notifications go out on each channel — the from/reply-to identity, the chosen
 * transport, non-secret provider config, and the ENCRYPTED provider credentials.
 *
 * One row per (tenant, channel). `transport` names the transport the tenant
 * selected for the channel (null = the core default). `config` holds non-secret
 * provider settings (host, port, region, …). `credentials_encrypted` holds the
 * provider secret ENCRYPTED at rest (via EncryptedSecretStore) — it is write-only
 * over the API and never returned; the API exposes only a `has_credentials` flag.
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): `tenant_id`
 * NOT NULL + ON DELETE CASCADE; every read/write binds tenant_id so a tenant can
 * only see or edit its OWN sender config. UNIQUE(tenant_id, channel) makes the
 * upsert idempotent.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateTenantNotificationSettings
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_notification_settings (
                id                    BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                channel               VARCHAR(64)   NOT NULL,
                transport             VARCHAR(64),
                from_address          VARCHAR(255),
                from_name             VARCHAR(255),
                reply_to              VARCHAR(255),
                config                JSONB         NOT NULL DEFAULT '{}'::jsonb,
                credentials_encrypted TEXT,
                enabled               BOOLEAN       NOT NULL DEFAULT TRUE,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        // One sender config per (tenant, channel) — makes the upsert idempotent.
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_tenant_notification_settings ON tenant_notification_settings(tenant_id, channel)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_notification_settings CASCADE');
    }
}
