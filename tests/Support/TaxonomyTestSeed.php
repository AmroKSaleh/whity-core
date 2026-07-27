<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Shared seed for the WC-621 taxonomy handler tests. Builds the real
 * migration schema and seeds two tenants, each with a tag-manager role
 * (tags:read + tags:manage) and — for tenant 1 — a read-only viewer role, plus
 * one profile per role with an active membership. Lets each handler test assert
 * RBAC (tags:read vs tags:manage) and cross-tenant isolation against a real
 * {@see RoleChecker}.
 *
 *   Profiles: 10 = manager@t1, 11 = viewer@t1, 20 = manager@t2.
 */
final class TaxonomyTestSeed
{
    public static function make(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");

        // Distinct role names — `roles.name` is globally unique, so the two
        // tenants' manager roles cannot share a name.
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'tag-manager-a', '', 1, datetime('now')),
            (102, 'tag-viewer-a',  '', 1, datetime('now')),
            (201, 'tag-manager-b', '', 2, datetime('now'))");

        self::grant($pdo, 101, CorePermissions::TAGS_READ);
        self::grant($pdo, 101, CorePermissions::TAGS_MANAGE);
        self::grant($pdo, 102, CorePermissions::TAGS_READ);
        self::grant($pdo, 201, CorePermissions::TAGS_READ);
        self::grant($pdo, 201, CorePermissions::TAGS_MANAGE);

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'manager-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'viewer-a',  'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'manager-b', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 101, 'active', datetime('now')),
                (1001, 11, 1, 102, 'active', datetime('now')),
                (1002, 20, 2, 201, 'active', datetime('now'))
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
