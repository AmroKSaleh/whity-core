<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateResourceRoleAssignments — polymorphic, resource-scoped role grants
 * (WC-712 §2).
 *
 * Generalises `ou_role_assignments` (migration 008) from one hardcoded resource
 * kind to any registered {@see \Whity\Core\RBAC\ResourceTypeRegistry} type, so a
 * plugin needing per-record authority stops maintaining its own grant table.
 * Every private grant table is a second source of truth for the SAME
 * authorization question, which is the defect `PermissionResolver` was
 * introduced to eliminate.
 *
 * Shape
 * -----
 *   (tenant_id, resource_type, resource_id, role_id, profile_id NULL)
 *
 * `profile_id` is NULLABLE and that nullability carries the meaning:
 *   - NULL       — everyone with access to this resource gets role R here.
 *                  This is what `ou_role_assignments` always expressed, so OU
 *                  rows backfill to NULL.
 *   - a profile  — this ONE profile gets role R at this resource.
 *
 * `resource_id` is INTEGER, matching every core primary key. It is deliberately
 * NOT a foreign key: the target table varies by `resource_type`, and no single
 * FK can express that. Referential integrity is therefore the owner's job —
 * `resource_type='ou'` rows are kept honest by the ON DELETE CASCADE carried in
 * the OU backfill below, and a plugin type must clean up after its own deletes.
 *
 * Tenant scoping is explicit rather than inferred through the resource, so the
 * tenant-predicate scanner can see it and a cross-tenant row cannot be written
 * even if a caller supplies another tenant's resource_id.
 */
class CreateResourceRoleAssignments
{
    public static function up(Database $db): void
    {
        $db->exec('
            CREATE TABLE IF NOT EXISTS resource_role_assignments (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                resource_type VARCHAR(64) NOT NULL,
                resource_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ');

        // The resolution query filters on exactly this tuple, so it is the
        // covering index as well as the shape of the uniqueness rule.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_rra_lookup
             ON resource_role_assignments(tenant_id, resource_type, resource_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_rra_profile
             ON resource_role_assignments(profile_id)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_rra_role_id
             ON resource_role_assignments(role_id)'
        );

        // Uniqueness needs TWO partial indexes, not one constraint: in SQL, NULL
        // is not equal to itself, so a plain UNIQUE(...) over a nullable
        // profile_id would happily admit the same everyone-grant twice.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_rra_profile_grant
             ON resource_role_assignments(tenant_id, resource_type, resource_id, role_id, profile_id)
             WHERE profile_id IS NOT NULL'
        );
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_rra_everyone_grant
             ON resource_role_assignments(tenant_id, resource_type, resource_id, role_id)
             WHERE profile_id IS NULL'
        );

        // NO BACKFILL of ou_role_assignments, deliberately.
        //
        // The OU is the natural `resource_type='ou'` case and folding it in is
        // the intended end state, but that is a SEPARATE change: resolution
        // still reads OU grants from `ou_role_assignments`, so copying them here
        // would create a second copy that nothing reads and that drifts the
        // moment anyone assigns an OU role. Two rows claiming authority over the
        // same question, one of them stale, is precisely the defect
        // resource-scoped grants exist to remove.
        //
        // The fold is a pure storage move when it happens — the OU ancestor walk
        // lives in RoleChecker, not in the table — and it needs its own change
        // because ~9 test fixtures seed ou_role_assignments directly.
    }

    public static function down(Database $db): void
    {
        // Nothing to preserve: this table holds only grants that could not have
        // existed before it, and OU grants were never moved into it.
        $db->exec('DROP TABLE IF EXISTS resource_role_assignments CASCADE');
    }
}
