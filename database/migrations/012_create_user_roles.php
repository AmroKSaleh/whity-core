<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateUserRoles migration
 *
 * Creates the user_roles junction table, providing a many-to-many
 * relationship between users and roles. This complements the existing
 * single-role column (users.role_id) without replacing it, so existing
 * authentication and authorization code continues to work unchanged.
 *
 * The table is tenant-scoped and uses ON DELETE CASCADE on every foreign
 * key so that removing a tenant, user, or role automatically cleans up the
 * corresponding assignments and never leaves orphaned rows.
 *
 * This migration is additive, idempotent (IF NOT EXISTS) and fully
 * reversible via down().
 */
class CreateUserRoles
{
    public static function up(Database $db): void
    {
        // Create user_roles junction table (users <-> roles, tenant scoped).
        $db->exec('
            CREATE TABLE IF NOT EXISTS user_roles (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE(user_id, role_id)
            )
        ');

        // Indexes for efficient lookups during authorization checks.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_roles_tenant_id ON user_roles(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_roles_user_id ON user_roles(user_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_roles_role_id ON user_roles(role_id)');

        // Backfill existing direct role assignments so the junction table is a
        // complete representation of current grants. Idempotent via ON CONFLICT.
        $db->exec('
            INSERT INTO user_roles (tenant_id, user_id, role_id, created_at)
            SELECT u.tenant_id, u.id, u.role_id, NOW()
            FROM users u
            WHERE u.role_id IS NOT NULL
            ON CONFLICT (user_id, role_id) DO NOTHING
        ');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS user_roles CASCADE');
    }
}
