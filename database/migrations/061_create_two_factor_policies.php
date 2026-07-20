<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-525 admin-enforced 2FA policy registry.
 *
 * One row per policy scope: tenant-wide (`scope_type = 'tenant'`,
 * `scope_id = NULL`), a single organizational unit and everything beneath it
 * (`scope_type = 'ou'`, `scope_id` = the OU id), or a single profile
 * (`scope_type = 'user'`, `scope_id` = the profile id). A profile in scope of
 * ANY applicable policy (tenant-wide, its own OU chain, or itself directly)
 * without `profiles.two_factor_enabled` is subject to the STRICTEST
 * (earliest) enrollment deadline across every applicable row — see
 * {@see \Whity\Auth\TwoFactorPolicyResolver}.
 *
 * The deadline itself is computed, not stored: `created_at + grace_period_days`.
 * A grace period of 0 means "enforce immediately, no grace."
 *
 * TENANT-OWNED: `tenant_id` NOT NULL + ON DELETE CASCADE, one row per
 * (tenant, scope_type, scope_id) via the UNIQUE constraint. Registered in
 * TenantOwnedTables so the predicate guard polices it.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateTwoFactorPolicies
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS two_factor_policies (
                id                 BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id          INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                scope_type         VARCHAR(16)   NOT NULL,
                scope_id           INTEGER,
                grace_period_days  INTEGER       NOT NULL DEFAULT 0,
                created_by         INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                created_at         TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMP     NOT NULL DEFAULT NOW(),
                CHECK (scope_type IN ('tenant', 'ou', 'user')),
                CHECK ((scope_type = 'tenant') = (scope_id IS NULL))
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_two_factor_policies_tenant_id ON two_factor_policies(tenant_id)');

        // Postgres UNIQUE treats each NULL as distinct, so a plain
        // UNIQUE(tenant_id, scope_type, scope_id) would let multiple
        // scope_id-less 'tenant' rows through for the same tenant. Two partial
        // unique indexes instead: one tenant-wide policy per tenant, and one
        // policy per (ou/user) scope target per tenant.
        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_two_factor_policies_tenant_scope
            ON two_factor_policies(tenant_id) WHERE scope_type = 'tenant'
        ");
        $db->exec('
            CREATE UNIQUE INDEX IF NOT EXISTS uq_two_factor_policies_scoped_target
            ON two_factor_policies(tenant_id, scope_type, scope_id) WHERE scope_id IS NOT NULL
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS two_factor_policies CASCADE');
    }
}
