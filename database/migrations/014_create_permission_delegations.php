<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreatePermissionDelegations migration (WC-34, issue #34 — role delegation half)
 *
 * Creates the `permission_delegations` table that lets a role-holder grant a
 * SUBSET of their OWN effective permissions to a role or a user, tenant- and
 * optionally OU-scoped, with a revocable lifecycle.
 *
 * Schema design
 * -------------
 *  - `grantor_user_id` — the user who created the delegation. Their effective
 *    permission set bounds what they may delegate; the subset invariant is
 *    enforced in {@see \Whity\Core\Delegation\DelegationService}, not in the DB.
 *  - Polymorphic grantee, modelled cleanly with a discriminator + id pair:
 *      `grantee_type` ∈ {'role','user'} and `grantee_id`. A CHECK constraint
 *    pins `grantee_type` to the two legal values. Modelling it this way (rather
 *    than two nullable FK columns) keeps the resolution query a single equality
 *    match and avoids "exactly one of two columns is non-null" gymnastics.
 *  - `permission` — the granted `resource:action` string. One row per delegated
 *    permission keeps the grant atomic and individually revocable.
 *  - `tenant_id` — every delegation is tenant-scoped and fails closed: a
 *    delegation written under tenant A can never be resolved under tenant B.
 *  - `ou_id` — OPTIONAL OU-subtree scope. When NULL the delegation applies
 *    tenant-wide; when set it applies only to grantees within that OU or its
 *    descendants (descendant resolution mirrors the OU role-inheritance walk).
 *  - `granted_at` / `revoked_at` — lifecycle timestamps. A delegation is LIVE
 *    when `revoked_at IS NULL`; revocation is non-destructive (sets the
 *    timestamp) so the historical grant is preserved for the audit trail
 *    (audit logging itself is owned by the parallel half of #34).
 *
 * Indexing
 * --------
 * The hot path is the resolution lookup in RoleChecker: "for this grantee
 * (type+id) in this tenant, which permissions are live?". `idx_pd_resolution`
 * covers `(tenant_id, grantee_type, grantee_id, revoked_at)` for exactly that.
 * Secondary indexes support listing by grantor and by OU scope.
 *
 * Reversibility
 * -------------
 * down() drops the table (CASCADE) — it owns the table outright, so a clean
 * reversal. Verified by a dedicated migration cycle test.
 */
class CreatePermissionDelegations
{
    public static function up(Database $db): void
    {
        // The delegated permission is referenced by its `resource:action` STRING
        // (not a permissions.id FK) on purpose: a delegation must survive a
        // permission catalogue row being re-seeded, and RoleChecker resolution
        // compares against the in-memory registry by name anyway. A live
        // delegation for an unregistered permission is harmless — hasPermission()
        // rejects unknown permissions at the registry gate before consulting
        // delegations.
        $db->exec('
            CREATE TABLE IF NOT EXISTS permission_delegations (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                grantor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                grantee_type VARCHAR(16) NOT NULL,
                grantee_id INTEGER NOT NULL,
                permission VARCHAR(255) NOT NULL,
                ou_id INTEGER NULL REFERENCES organizational_units(id) ON DELETE CASCADE,
                granted_at TIMESTAMP NOT NULL DEFAULT NOW(),
                revoked_at TIMESTAMP NULL,
                CONSTRAINT chk_permission_delegations_grantee_type
                    CHECK (grantee_type IN (\'role\', \'user\'))
            )
        ');

        // Resolution lookup: live delegations for a (tenant, grantee) pair. This
        // is the index RoleChecker hits on every permission check that falls
        // through to the delegation layer.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_pd_resolution
            ON permission_delegations (tenant_id, grantee_type, grantee_id, revoked_at)
        ');

        // Listing/management lookups.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_pd_grantor
            ON permission_delegations (tenant_id, grantor_user_id)
        ');
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_pd_ou
            ON permission_delegations (ou_id)
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS permission_delegations CASCADE');
    }
}
