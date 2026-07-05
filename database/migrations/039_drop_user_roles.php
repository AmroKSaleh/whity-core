<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * DropUserRoles — forward migration (WC-idcut-B, migration 039).
 *
 * The `user_roles` many-to-many junction table (created by migration 012) is no
 * longer used for RBAC: {@see \Whity\Auth\RoleChecker} resolves roles exclusively
 * through `memberships.role_id`. This migration drops the dead table so it cannot
 * be queried or written by accident.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Schema changes (up)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Drop `user_roles` (with CASCADE on PG so any residual FK references are
 *    removed cleanly; plain DROP on SQLite which does not support CASCADE on DROP).
 *
 * Idempotent: IF NOT EXISTS / IF EXISTS guards throughout.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() recreates `user_roles` exactly as migration 012 defined it (same
 * columns, FKs, indexes) and backfills from `users.role_id` — the same
 * backfill logic migration 012 originally ran.  The `users` table is still
 * present at this point (it is retired in a later step of the identity cutover).
 */
class DropUserRoles
{
    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            // CASCADE removes dependent FK constraints on other tables (if any).
            $db->exec('DROP TABLE IF EXISTS user_roles CASCADE');
        } else {
            // SQLite has no CASCADE on DROP TABLE; the table has no dependants
            // on this schema so a plain DROP is correct.
            $db->exec('DROP TABLE IF EXISTS user_roles');
        }
    }

    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Recreate the table exactly as migration 012 defined it.
        if ($driver === 'pgsql') {
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
        } else {
            $db->exec('
                CREATE TABLE IF NOT EXISTS user_roles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                    created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
                    UNIQUE(user_id, role_id)
                )
            ');
        }

        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_roles_tenant_id ON user_roles(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_roles_user_id ON user_roles(user_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_user_roles_role_id ON user_roles(role_id)');

        // Backfill from users.role_id (same as migration 012's original backfill).
        // ON CONFLICT guard makes this idempotent if run more than once.
        if ($driver === 'pgsql') {
            $db->exec('
                INSERT INTO user_roles (tenant_id, user_id, role_id, created_at)
                SELECT u.tenant_id, u.id, u.role_id, NOW()
                FROM users u
                WHERE u.role_id IS NOT NULL
                ON CONFLICT (user_id, role_id) DO NOTHING
            ');
        } else {
            $db->exec("
                INSERT OR IGNORE INTO user_roles (tenant_id, user_id, role_id, created_at)
                SELECT u.tenant_id, u.id, u.role_id, datetime('now')
                FROM users u
                WHERE u.role_id IS NOT NULL
            ");
        }
    }
}
