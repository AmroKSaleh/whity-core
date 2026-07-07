<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantRegistrationsApproveToAdmin migration (WC-235 admin-approval activation).
 *
 * Registers the `registrations:approve` permission in the `permissions`
 * catalogue and grants it to the seeded `admin` role, so a fresh database can
 * approve pending self-service registrations out of the box. The permission is
 * necessary but not sufficient: the RegistrationsApiHandler additionally
 * requires the caller to be acting in the SYSTEM tenant (id 0) — a regular
 * tenant's admin holds the permission within its own tenant but must never be
 * able to approve another tenant's owner.
 *
 * Mirrors the established core seeding pattern (migrations 026/022/016):
 * catalogue upsert (ON CONFLICT DO NOTHING) then grant (ON CONFLICT DO NOTHING),
 * resolving ids defensively so a partially-seeded database is skipped rather
 * than errored. down() reverses only what up() added: it removes the grant,
 * then removes the catalogue row ONLY when no other role_permissions row still
 * references it.
 */
class GrantRegistrationsApproveToAdmin
{
    /**
     * The permissions this migration seeds and grants to admin.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::REGISTRATIONS_APPROVE => 'Review and approve/reject pending self-service registrations (system tenant)',
    ];

    public static function up(Database $db): void
    {
        // Step 1: catalogue upsert — idempotent via ON CONFLICT (name).
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        // Step 2: grant each permission to the admin role. Resolve ids
        // defensively — skip rather than fail on a partially-seeded database.
        $adminRoleId = self::adminRoleId($db);
        if ($adminRoleId === null) {
            return;
        }

        foreach (array_keys(self::PERMISSIONS) as $name) {
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

            // Reverse the grant first.
            if ($adminRoleId !== null && $permissionId !== null) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
                );
            }

            // Remove the catalogue row only when no grant still references it
            // (defensive: never orphan a grant, never remove a row another
            // migration owns that still has references).
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

    /**
     * Resolve the seeded admin role id, or null when it is absent.
     */
    private static function adminRoleId(Database $db): ?int
    {
        $result = $db->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin'])->fetch();

        return $result === false ? null : (int) $result['id'];
    }

    /**
     * Resolve a permission id by name, or null when it is absent.
     */
    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
