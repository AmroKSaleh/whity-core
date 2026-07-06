<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * DropUsersTable — forward migration (WC-idcut-F, migration 042).
 *
 * Retires the legacy `users` table and the migration-035 artefacts
 * (`migration_035_profile_ids`, `migration_035_collision_log`) now that all
 * identity has fully canonicalised on `profiles` + `profile_emails` +
 * `memberships` (ADR 0005). No user base exists, so data loss is explicitly
 * acceptable and stated by design.
 *
 * up():  DROP TABLE IF EXISTS users, migration_035_profile_ids,
 *        migration_035_collision_log
 *        (all drops are idempotent; CASCADE on PG removes any residual FKs).
 *
 * down(): Recreates the structural DDL only. Row data is NOT restorable —
 *         this is intentional and acceptable (see WC-idcut epic context).
 */
class DropUsersTable
{
    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $db->exec('DROP TABLE IF EXISTS users CASCADE');
            $db->exec('DROP TABLE IF EXISTS migration_035_profile_ids CASCADE');
            $db->exec('DROP TABLE IF EXISTS migration_035_collision_log CASCADE');
        } else {
            $db->exec('DROP TABLE IF EXISTS users');
            $db->exec('DROP TABLE IF EXISTS migration_035_profile_ids');
            $db->exec('DROP TABLE IF EXISTS migration_035_collision_log');
        }
    }

    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'pgsql') {
            $db->exec('
                CREATE TABLE IF NOT EXISTS users (
                    id SERIAL PRIMARY KEY,
                    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    email VARCHAR(255) NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    role_id INTEGER REFERENCES roles(id) ON DELETE SET NULL,
                    ou_id INTEGER REFERENCES organizational_units(id) ON DELETE SET NULL,
                    two_factor_secret TEXT,
                    two_factor_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                    two_factor_backup_codes_version INTEGER NOT NULL DEFAULT 0,
                    token_epoch INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    UNIQUE(tenant_id, email)
                )
            ');
            $db->exec('
                CREATE TABLE IF NOT EXISTS migration_035_profile_ids (
                    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                    profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE
                )
            ');
            $db->exec('
                CREATE TABLE IF NOT EXISTS migration_035_collision_log (
                    id SERIAL PRIMARY KEY,
                    email TEXT NOT NULL,
                    kept_user_id INTEGER,
                    dropped_ids TEXT,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW()
                )
            ');
        } else {
            $db->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    email TEXT NOT NULL,
                    password TEXT NOT NULL,
                    role_id INTEGER REFERENCES roles(id) ON DELETE SET NULL,
                    ou_id INTEGER REFERENCES organizational_units(id) ON DELETE SET NULL,
                    two_factor_secret TEXT,
                    two_factor_enabled INTEGER NOT NULL DEFAULT 0,
                    two_factor_backup_codes_version INTEGER NOT NULL DEFAULT 0,
                    token_epoch INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (datetime('now')),
                    UNIQUE(tenant_id, email)
                )
            ");
            $db->exec("
                CREATE TABLE IF NOT EXISTS migration_035_profile_ids (
                    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                    profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE
                )
            ");
            $db->exec("
                CREATE TABLE IF NOT EXISTS migration_035_collision_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    email TEXT NOT NULL,
                    kept_user_id INTEGER,
                    dropped_ids TEXT,
                    created_at TEXT NOT NULL DEFAULT (datetime('now'))
                )
            ");
        }
    }
}
