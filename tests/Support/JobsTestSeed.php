<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Shared seed for the WC-jobs-api handler tests. Builds the real migration
 * schema and seeds two tenants with roles/profiles that let each test assert
 * RBAC (jobs:submit vs jobs:read vs neither) and cross-tenant isolation against
 * a real {@see \Whity\Auth\RoleChecker}.
 *
 *   Profiles: 10 = submitter@t1 (submit+read), 11 = reader@t1 (read only),
 *             12 = nobody@t1 (no job perms), 20 = submitter@t2 (submit+read).
 */
final class JobsTestSeed
{
    public const SUBMITTER_A = 10;
    public const READER_A    = 11;
    public const NOBODY_A     = 12;
    public const SUBMITTER_B = 20;

    public const TENANT_A = 1;
    public const TENANT_B = 2;

    public static function make(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");

        // roles.name is globally unique, so the two tenants' roles get distinct names.
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'job-submitter-a', '', 1, datetime('now')),
            (102, 'job-reader-a',    '', 1, datetime('now')),
            (103, 'job-none-a',      '', 1, datetime('now')),
            (201, 'job-submitter-b', '', 2, datetime('now'))");

        self::grant($pdo, 101, CorePermissions::JOBS_SUBMIT);
        self::grant($pdo, 101, CorePermissions::JOBS_READ);
        self::grant($pdo, 102, CorePermissions::JOBS_READ);
        self::grant($pdo, 201, CorePermissions::JOBS_SUBMIT);
        self::grant($pdo, 201, CorePermissions::JOBS_READ);
        // role 103 (job-none-a) is deliberately granted nothing.

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'submitter-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'reader-a',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'nobody-a',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'submitter-b', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 101, 'active', datetime('now')),
                (1001, 11, 1, 102, 'active', datetime('now')),
                (1002, 12, 1, 103, 'active', datetime('now')),
                (1003, 20, 2, 201, 'active', datetime('now'))
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
