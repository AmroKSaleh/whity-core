<?php

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddTenantIdToRoles migration (WC-110)
 *
 * Gives the roles table an explicit owning-tenant column so a role's tenant
 * association no longer has to be inferred from a `user_roles` seed row for the
 * acting user. That inference (the WC-16 tenant-visibility hack) made every
 * role created through the Roles API undeletable: the create-time auto-assignment
 * was counted by the deletion guard, so DELETE always returned 409.
 *
 * With this column the Roles API stamps each new role with the current tenant id
 * and scopes list/get/update/delete/visibility by `roles.tenant_id` directly,
 * dropping the acting-user auto-assignment entirely. Tenant isolation is
 * preserved (a role created by tenant A is invisible to tenant B); the SYSTEM
 * tenant (id 0) continues to see every role.
 *
 * The column is NULLABLE on purpose: it is additive over an existing table that
 * may already hold rows (the seeded `admin`/`user` roles and any pre-WC-110
 * roles), which must not be forced to adopt a tenant. ON DELETE CASCADE keeps it
 * consistent with the other tenant-scoped foreign keys (user_roles,
 * organizational_units) so removing a tenant cleans up its roles.
 *
 * This migration is additive, idempotent (IF NOT EXISTS) and fully reversible
 * via down().
 */
class AddTenantIdToRoles
{
    public static function up(Database $db): void
    {
        // Add the nullable owning-tenant column. ON DELETE CASCADE matches the
        // other tenant-scoped FKs so a tenant's roles are removed with the tenant.
        $db->exec('
            ALTER TABLE roles
            ADD COLUMN IF NOT EXISTS tenant_id INTEGER NULL
                REFERENCES tenants(id) ON DELETE CASCADE
        ');

        // Index the column to keep tenant-scoped role lookups cheap.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_roles_tenant_id ON roles(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_roles_tenant_id');
        $db->exec('ALTER TABLE roles DROP COLUMN IF EXISTS tenant_id');
    }
}
