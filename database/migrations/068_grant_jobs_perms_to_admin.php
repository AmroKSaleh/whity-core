<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantJobsPermsToAdmin migration (WC-jobs-api, #3fb69c97).
 *
 * Upserts the async-job API permissions into the catalogue and grants them to
 * the built-in `admin` role. A forward, idempotent grant migration (not an edit
 * to a prior one) so it also reaches long-lived databases — see the project
 * rule on persistent-DB migration drift. Mirrors 064's catalogue-upsert + grant
 * pattern; down() reverses only what up() added.
 */
class GrantJobsPermsToAdmin
{
    /**
     * Permission => catalogue description.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::JOBS_SUBMIT => 'Submit async jobs via the API and read their status/result',
        CorePermissions::JOBS_READ   => 'Read async job status, progress and result',
    ];

    public static function up(Database $db): void
    {
        $adminRoleId = self::adminRoleId($db);

        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );

            if ($adminRoleId === null) {
                continue;
            }

            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            $db->query(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 VALUES (:role_id, :permission_id, NOW())
                 ON CONFLICT (role_id, permission_id) DO NOTHING',
                [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
            );
        }
    }

    public static function down(Database $db): void
    {
        $adminRoleId = self::adminRoleId($db);

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);

            if ($adminRoleId !== null && $permissionId !== null) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
                );
            }

            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $name]
            );
        }
    }

    private static function adminRoleId(Database $db): ?int
    {
        $result = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin'])->fetch();
        return $result === false ? null : (int) $result['id'];
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();
        return $result === false ? null : (int) $result['id'];
    }
}
