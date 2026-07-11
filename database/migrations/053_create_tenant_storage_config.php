<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-storage per-tenant storage backend configuration.
 *
 * One row per tenant describing an object-storage backend the tenant owns (an
 * S3-compatible bucket today). A tenant only USES its own backend when it also
 * holds the `storage.custom_backend` entitlement (EntitlementRegistry) — the
 * resolver falls back to the platform-default driver otherwise, so this table is
 * inert until both a row and the entitlement are present.
 *
 * TENANT-OWNED: `tenant_id` NOT NULL + ON DELETE CASCADE, UNIQUE (one config per
 * tenant). Registered in TenantOwnedTables so the predicate guard polices it.
 *
 * Schema notes (mirrors identity_providers' encrypted-secret pattern):
 *   - secret_encrypted: EncryptedSecretStore ciphertext, NEVER plaintext. The
 *     admin API never returns it (reads expose only `has_secret`); only the
 *     resolver decrypts it to build the driver.
 *   - driver: the backend kind ('s3' today); a small enum kept as VARCHAR so a
 *     future driver (e.g. 'google_drive') needs no schema change.
 *   - path_style / public_base_url: S3 addressing knobs, same as the global
 *     storage.s3.* settings.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateTenantStorageConfig
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_storage_config (
                id               BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id        INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                driver           VARCHAR(32)   NOT NULL DEFAULT 's3',
                endpoint         VARCHAR(512)  NOT NULL,
                region           VARCHAR(128)  NOT NULL,
                bucket           VARCHAR(255)  NOT NULL,
                access_key       VARCHAR(512)  NOT NULL,
                secret_encrypted TEXT          NOT NULL,
                path_style       BOOLEAN       NOT NULL DEFAULT TRUE,
                public_base_url  VARCHAR(512),
                created_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_storage_config_tenant_id ON tenant_storage_config(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_storage_config CASCADE');
    }
}
