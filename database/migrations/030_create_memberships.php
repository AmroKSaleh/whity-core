<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateMemberships migration (WC-101 — Phase B, migration 030).
 *
 * Creates `memberships` — the explicit profile-to-tenant binding that replaces
 * the implicit `users.tenant_id` relationship (ADR 0005).
 *
 * Design notes
 * ------------
 *  - tenant_id NOT NULL + ON DELETE CASCADE: a membership is tenant-scoped.
 *    Deleting a tenant removes all its memberships. This table MUST be listed
 *    in TenantOwnedTables so the predicate guard enforces tenant_id on every
 *    query that is not the intentional global cross-tenant login-flow scan.
 *  - profile_id ON DELETE CASCADE: removing a profile removes all its
 *    memberships across every tenant.
 *  - role_id ON DELETE CASCADE: removing a role removes the membership
 *    assignment; the membership owner must re-assign a role before accessing
 *    the tenant again.
 *  - ou_id ON DELETE SET NULL: the OU assignment is optional; removing an OU
 *    clears the field rather than removing the membership.
 *  - status: one of 'active' | 'invited' | 'suspended'.
 *    active    — full access; the profile can log in to this tenant.
 *    invited   — account pending; the profile cannot log in until accepted.
 *    suspended — access revoked without deleting the row; re-activatable.
 *  - UNIQUE(profile_id, tenant_id): a profile can have at most one membership
 *    per tenant; a second invite to the same tenant is an idempotent upsert,
 *    not a duplicate row.
 *  - idx_memberships_profile_id: backs the login-flow cross-tenant scan
 *    (SELECT * FROM memberships WHERE profile_id = ?).
 *  - idx_memberships_tenant_id: backs tenant-scoped list/count queries.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateMemberships
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS memberships (
                id         SERIAL PRIMARY KEY,
                profile_id INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                tenant_id  INTEGER      NOT NULL REFERENCES tenants(id)  ON DELETE CASCADE,
                role_id    INTEGER      NOT NULL REFERENCES roles(id)    ON DELETE CASCADE,
                ou_id      INTEGER               REFERENCES organizational_units(id) ON DELETE SET NULL,
                status     VARCHAR(32)  NOT NULL DEFAULT 'active',
                created_at TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (profile_id, tenant_id)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_memberships_profile_id ON memberships(profile_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_memberships_tenant_id  ON memberships(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS memberships CASCADE');
    }
}
