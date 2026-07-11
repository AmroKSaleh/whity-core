<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-plans subscription plans: the tier catalog + per-tenant plan assignment.
 *
 * Three tables (see ADR 0010):
 *   - plans            : global catalog of named tiers (Free/Pro/...). No price
 *                        columns yet — it is the ANCHOR future billing tables
 *                        (plan_prices, promo_codes, subscriptions) reference.
 *                        Global (no tenant_id), like `permissions`.
 *   - plan_entitlements: a plan's BUNDLE — (plan_id, entitlement_key, value),
 *                        validated against EntitlementRegistry. Global.
 *   - tenant_plan      : which plan a tenant is currently on. TENANT-OWNED
 *                        (tenant_id PK) → registered in TenantOwnedTables so the
 *                        predicate guard polices it.
 *
 * Applying a plan MATERIALISES its bundle into tenant_entitlements (ADR 0010),
 * so the runtime gate (EntitlementService) is unchanged.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreatePlans
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS plans (
                id          BIGSERIAL     NOT NULL PRIMARY KEY,
                plan_key    VARCHAR(64)   NOT NULL UNIQUE,
                name        VARCHAR(255)  NOT NULL,
                description TEXT,
                is_active   BOOLEAN       NOT NULL DEFAULT TRUE,
                sort_order  INTEGER       NOT NULL DEFAULT 0,
                created_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS plan_entitlements (
                id              BIGSERIAL     NOT NULL PRIMARY KEY,
                plan_id         BIGINT        NOT NULL REFERENCES plans(id) ON DELETE CASCADE,
                entitlement_key VARCHAR(128)  NOT NULL,
                value           TEXT          NOT NULL,
                UNIQUE (plan_id, entitlement_key)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_plan_entitlements_plan_id ON plan_entitlements(plan_id)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS tenant_plan (
                tenant_id   INTEGER    NOT NULL PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
                plan_id     BIGINT     REFERENCES plans(id) ON DELETE SET NULL,
                assigned_by BIGINT,
                assigned_at TIMESTAMP  NOT NULL DEFAULT NOW()
            )
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS tenant_plan CASCADE');
        $db->exec('DROP TABLE IF EXISTS plan_entitlements CASCADE');
        $db->exec('DROP TABLE IF EXISTS plans CASCADE');
    }
}
