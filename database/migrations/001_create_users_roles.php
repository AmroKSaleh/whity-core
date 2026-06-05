<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateUsersRoles migration
 *
 * Creates the foundational tenants, roles and users tables in their FINAL form.
 *
 * This migration is the single place where these three tables are created, with
 * every column, index, constraint and cascade they ultimately carry — there is
 * no later "patch" migration that alters them. Specifically it folds in what used
 * to be spread across several incremental migrations:
 *   - tenants.slug                          (was a later ADD COLUMN patch)
 *   - roles.description                     (was a later ADD COLUMN patch)
 *   - roles.parent_id + self-parent CHECK   (role hierarchy, was a later patch)
 *   - roles.tenant_id                       (owning-tenant column, was a later patch)
 *   - ON DELETE CASCADE on users' tenant_id / role_id foreign keys
 *                                           (was a later constraint-rewrite patch)
 *
 * Notes on ordering — two user columns are added by LATER migrations on purpose,
 * because each has a dependency that does not yet hold at this point:
 *   - users.ou_id (FK -> organizational_units) is added by 006_add_ou_to_users
 *     once that table exists.
 *   - the two-factor columns + backup_codes table are added by
 *     007_add_two_factor_support, which runs after 006 so the final users column
 *     order (… created_at, ou_id, two_factor_*) is preserved exactly.
 *
 * The up() is idempotent (IF NOT EXISTS / ON CONFLICT) and down() reverses
 * exactly this migration.
 */
class CreateUsersRoles
{
    public static function up(Database $db): void
    {
        // Tenants — final form (includes slug).
        $db->exec('
            CREATE TABLE IF NOT EXISTS tenants (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                slug VARCHAR(255) UNIQUE
            )
        ');

        // Roles — final form (description, self-referential parent_id for the
        // inheritance hierarchy, owning tenant_id, and the no-self-parent guard).
        // Column order mirrors the historical create-then-alter sequence so the
        // resulting table is byte-for-byte identical to the incremental history.
        $db->exec('
            CREATE TABLE IF NOT EXISTS roles (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                description TEXT DEFAULT \'\',
                parent_id INTEGER NULL REFERENCES roles(id) ON DELETE SET NULL,
                tenant_id INTEGER NULL REFERENCES tenants(id) ON DELETE CASCADE,
                CONSTRAINT chk_roles_no_self_parent CHECK (parent_id IS NULL OR parent_id <> id)
            )
        ');

        // Index parent_id (cheap upward hierarchy traversal) and tenant_id
        // (tenant-scoped role lookups).
        $db->exec('CREATE INDEX IF NOT EXISTS idx_roles_parent_id ON roles(parent_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_roles_tenant_id ON roles(tenant_id)');

        // Users — tenant_id / role_id foreign keys carry ON DELETE CASCADE so
        // removing a tenant or role cleans up dependent users. ou_id (006) and the
        // two-factor columns (007) are added later for the dependency/ordering
        // reasons described in the class docblock.
        $db->exec('
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                email VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(tenant_id, email)
            )
        ');

        // Indexes for performance.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_tenant_id ON users(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_role_id ON users(role_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)');

        // Seed the two built-in roles (idempotent via ON CONFLICT).
        $db->query(
            'INSERT INTO roles (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING',
            [':name' => 'admin']
        );

        $db->query(
            'INSERT INTO roles (name, created_at) VALUES (:name, NOW()) ON CONFLICT (name) DO NOTHING',
            [':name' => 'user']
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS users CASCADE');
        $db->exec('DROP TABLE IF EXISTS roles CASCADE');
        $db->exec('DROP TABLE IF EXISTS tenants CASCADE');
    }
}
