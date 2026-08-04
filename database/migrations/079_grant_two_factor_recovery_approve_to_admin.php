<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantTwoFactorRecoveryApproveToAdmin migration (WC-password-reset-2fa-recovery).
 *
 * Registers the `two_factor_recovery:approve` permission in the `permissions`
 * catalogue and grants it to the seeded `admin` role, so a fresh database can
 * review + approve pending "lost my 2FA device" recovery requests out of the
 * box. Approving CLEARS the target profile's 2FA and issues a fresh
 * password-reset link — an account-takeover-adjacent capability, kept as its
 * own narrow permission (never folded into `password_resets:approve` or
 * `security:manage`).
 *
 * NOT system-tenant-restricted (mirrors `password_resets:approve`, migration
 * 078) — the handler scopes the queue to tenants where the target profile
 * holds an active membership.
 *
 * Mirrors the established core seeding pattern (migrations 026/022/016/043):
 * catalogue upsert (ON CONFLICT DO NOTHING) then grant (ON CONFLICT DO NOTHING),
 * resolving ids defensively so a partially-seeded database is skipped rather
 * than errored. down() reverses only what up() added.
 */
class GrantTwoFactorRecoveryApproveToAdmin
{
    /**
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::TWO_FACTOR_RECOVERY_APPROVE => 'Review and approve/reject "lost my 2FA device" recovery requests; clears the target profile\'s 2FA on approval (own tenant)',
    ];

    public static function up(Database $db): void
    {
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

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
