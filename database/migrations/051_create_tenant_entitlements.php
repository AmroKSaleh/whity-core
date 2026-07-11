<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-ent per-tenant entitlements: operator-granted capabilities/limits.
 *
 * Each row is one entitlement override for one tenant — e.g. "tenant 42 may
 * configure a custom storage backend" (a bool feature flag) or "tenant 42's
 * member cap is 25" (an int limit). A key that is NOT overridden falls back to
 * the baseline (free-tier) default in EntitlementRegistry; the operator raises
 * it per tenant to sell a higher subscription tier. Entitlements GATE what a
 * tenant may configure — they are not settings the tenant itself edits.
 *
 * TENANT-OWNED: `tenant_id` NOT NULL + ON DELETE CASCADE. Registered in
 * TenantOwnedTables so the predicate guard polices every query. The one
 * deliberately cross-tenant caller is the operator entitlements API, which is
 * system-tenant gated and still flows a concrete target `tenant_id` into every
 * statement (the RegistrationsApiHandler precedent).
 *
 * Schema notes:
 *   - UNIQUE(tenant_id, entitlement_key): one override per key per tenant.
 *   - value: TEXT; EntitlementRegistry owns the typed contract (bool | int).
 *   - updated_by: the operator profile id who last changed this (audit); a plain
 *     nullable BIGINT, not an FK — the row must survive operator-profile deletion
 *     and is only informational (mirrors external_identities.provider_id).
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateTenantEntitlements
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_entitlements (
                id              BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id       INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                entitlement_key VARCHAR(128)  NOT NULL,
                value           TEXT          NOT NULL,
                updated_by      BIGINT,
                updated_at      TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, entitlement_key)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_tenant_entitlements_tenant_id ON tenant_entitlements(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_entitlements CASCADE');
    }
}
