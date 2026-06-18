<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateTenantSettings migration (Website Settings).
 *
 * Creates `tenant_settings` — the PER-TENANT website-settings overrides. Every
 * row belongs to exactly one tenant; the platform's #1 isolation invariant
 * requires every query to bind a `tenant_id` predicate, so this table is
 * enumerated in {@see \Whity\Core\Tenant\TenantOwnedTables} and the
 * {@see \Whity\Core\Settings\TenantSettingsRepository} carries that predicate on
 * every statement. A tenant's override shadows the global default
 * (`app_settings`, migration 024); clearing it falls back to global → registry
 * default.
 *
 * Schema notes
 * ------------
 *  - `tenant_id` is NOT NULL and FK-cascades with the tenant (consistent with
 *    the other tenant-owned tables — deleting a tenant removes its overrides).
 *  - `UNIQUE (tenant_id, setting_key)` — one override per (tenant, key); the
 *    upsert relies on this for ON CONFLICT (tenant_id, setting_key).
 *  - `value` is TEXT and nullable; the registry owns the typed contract.
 *  - The composite index `(tenant_id, setting_key)` is provided by the UNIQUE
 *    constraint, which backs the per-tenant lookups.
 *
 * Additive, idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateTenantSettings
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_settings (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                setting_key VARCHAR(100) NOT NULL,
                value TEXT,
                updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, setting_key)
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_settings CASCADE');
    }
}
