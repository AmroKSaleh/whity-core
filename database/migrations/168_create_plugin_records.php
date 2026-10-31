<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * The shared record store for workspace plugins (Whity Builder, WHIT-761;
 * docs/design/WHITY-BUILDER.md 2.3, option A, decided by the owner).
 *
 * One TENANT-OWNED table for every record of every workspace plugin, so a
 * plugin's record types change without DDL: adding a field is a new key in
 * `data`, and rolling a manifest back loses nothing. The record type's
 * declaration in the plugin's manifest is what gives `data` its shape; the
 * store validates every write against it.
 *
 *   plugin_key / record_type — which plugin and which of its record types.
 *   state                    — the lifecycle state, when the type has one.
 *   data                     — the fields, as JSON. Money is minor units.
 *
 * Registered in CoreTables and TenantOwnedTables; every query binds tenant_id.
 * Idempotent and reversible.
 */
class CreatePluginRecords
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plugin_records (
                id                 BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id          INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                plugin_key         VARCHAR(64)   NOT NULL,
                record_type        VARCHAR(64)   NOT NULL,
                state              VARCHAR(64),
                data               JSONB         NOT NULL,
                created_by         BIGINT,
                updated_by         BIGINT,
                created_at         TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plugin_records_type ON plugin_records(tenant_id, plugin_key, record_type, id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plugin_records_state ON plugin_records(tenant_id, plugin_key, record_type, state)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS plugin_records CASCADE');
    }
}
