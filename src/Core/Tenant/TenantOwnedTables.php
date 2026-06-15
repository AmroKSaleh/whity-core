<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Single source of truth for the platform's TENANT-OWNED tables (WC-192).
 *
 * A tenant-owned table carries a `tenant_id` column and holds rows that belong
 * to exactly one tenant. The platform's #1 isolation invariant is "every
 * SELECT/UPDATE/DELETE on a tenant-owned table binds a `tenant_id` predicate"
 * (see docs/wiki/TENANT_ISOLATION.md). The CI tenant-predicate guard
 * ({@see \Whity\Core\Tenant\TenantPredicateGuard}, wired through
 * scripts/ci-tenant-predicate-guard.php) consumes THIS list to know which
 * tables it must police, and {@see SanctionedGlobalTables} to know which tables
 * are exempt.
 *
 * The set is DERIVED from database/migrations/ — every table whose CREATE TABLE
 * declares a `tenant_id` column. It is pinned here (and by
 * TenantOwnedTablesTest, which re-derives it from the migrations) so the guard
 * cannot silently drift from the schema: add a tenant-owned table in a migration
 * and the test fails until this list is updated.
 *
 * NOT in this list (deliberately):
 *  - `tenants` — the tenant registry itself; its primary key IS the tenant id,
 *    so a `tenant_id` predicate would be meaningless.
 *  - `permissions`, `relationship_types` — platform-global catalogues/vocabulary
 *    with no `tenant_id` column.
 *  - `role_permissions`, `backup_codes` — they carry NO `tenant_id` column and
 *    scope TRANSITIVELY via a parent (`role_permissions` via `roles`,
 *    `backup_codes` via `users.user_id`). They are not directly scannable for a
 *    `tenant_id` predicate; isolation for them is enforced at the parent join /
 *    by the owning user id, not by a column on the row. Listing them as
 *    tenant-owned would force false positives on every correct
 *    `WHERE role_id = ?` / `WHERE user_id = ?` access, so they are intentionally
 *    excluded and covered by their parent's scoping instead.
 *  - `revoked_tokens`, `core_schema_migrations` — sanctioned global tables
 *    enumerated in {@see SanctionedGlobalTables}.
 */
final class TenantOwnedTables
{
    /**
     * Tables that carry a `tenant_id` column and are therefore tenant-owned.
     *
     * Keyed by table name with the migration that introduces its `tenant_id`
     * column, so the provenance of every entry is auditable. Re-derived from the
     * migrations by TenantOwnedTablesTest.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'users' => '001_create_users_roles.php',
        'roles' => '001_create_users_roles.php',
        'deployments' => '004_create_deployment_tables.php',
        'migration_rollbacks' => '004_create_deployment_tables.php',
        'organizational_units' => '005_create_organizational_units.php',
        'ou_role_assignments' => '008_create_ou_role_assignments.php',
        'user_roles' => '012_create_user_roles.php',
        'permission_delegations' => '014_create_permission_delegations.php',
        'audit_log' => '016_create_audit_log.php',
        'persons' => '018_create_persons.php',
        'relations' => '020_create_relations.php',
    ];

    /**
     * The tenant-owned table names.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Whether the given table carries a `tenant_id` column and must therefore
     * bind a tenant predicate on every SELECT/UPDATE/DELETE.
     */
    public static function isTenantOwned(string $table): bool
    {
        return array_key_exists(strtolower($table), self::TABLES);
    }
}
