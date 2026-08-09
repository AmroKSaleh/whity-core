<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Shared seed for the WC-583 i18n admin-management handler tests. Builds the
 * real migration schema (which already seeds 'en'/'ar' via migration 082 and
 * grants languages:manage/translations:manage to the built-in `admin` role via
 * migration 086) and seeds two regular tenants plus the SYSTEM tenant, each
 * with an i18n-manager role, and — for tenant 1 — a read-only viewer role with
 * neither permission. Lets each handler test assert RBAC (manager vs viewer),
 * the system-tenant-only gate on language writes, and cross-tenant/
 * cross-scope isolation of translation rows against a real {@see RoleChecker}.
 *
 *   Profiles: 910 = manager@t1, 911 = viewer@t1, 920 = manager@t2, 930 = manager@system.
 */
final class I18nAdminTestSeed
{
    public static function make(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");

        // Distinct role names — `roles.name` is globally unique.
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (301, 'i18n-manager-a',   '', 1,    datetime('now')),
            (302, 'i18n-viewer-a',    '', 1,    datetime('now')),
            (303, 'i18n-manager-b',   '', 2,    datetime('now')),
            (304, 'i18n-manager-sys', '', 0,    datetime('now'))");

        self::grant($pdo, 301, CorePermissions::LANGUAGES_MANAGE);
        self::grant($pdo, 301, CorePermissions::TRANSLATIONS_MANAGE);
        self::grant($pdo, 303, CorePermissions::LANGUAGES_MANAGE);
        self::grant($pdo, 303, CorePermissions::TRANSLATIONS_MANAGE);
        self::grant($pdo, 304, CorePermissions::LANGUAGES_MANAGE);
        self::grant($pdo, 304, CorePermissions::TRANSLATIONS_MANAGE);
        // 302 (viewer) is granted NEITHER permission, on purpose.

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (910, 'manager-a',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (911, 'viewer-a',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (920, 'manager-b',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (930, 'manager-sys', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (9100, 910, 1, 301, 'active', datetime('now')),
                (9110, 911, 1, 302, 'active', datetime('now')),
                (9200, 920, 2, 303, 'active', datetime('now')),
                (9300, 930, 0, 304, 'active', datetime('now'))
        ");

        return $pdo;
    }

    public static function wrap(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        return $db;
    }

    private static function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pid = (int) $sel->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $pid]);
    }
}
