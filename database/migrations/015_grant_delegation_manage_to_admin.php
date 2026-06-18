<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantDelegationManageToAdmin migration (WC-34)
 *
 * Seeds the new `delegation:manage` permission ({@see CorePermissions::DELEGATION_MANAGE})
 * into the persisted catalogue and grants it to the seeded `admin` role, so the
 * platform administrator can reach the delegation API the moment the feature
 * ships — mirroring the seed-and-grant approach migration 013 uses for the
 * `plugins:*` per-action permissions.
 *
 * Why a separate, seed-style migration
 * ------------------------------------
 * Structural schema (the `permission_delegations` table) lives in migration 014;
 * this migration carries only DATA (a catalogue row + a role grant), keeping
 * seeders separate from structural migrations per project convention.
 *
 * What it does (two idempotent, additive steps)
 * --------------------------------------------
 *  1. Catalogue catch-up: INSERT `delegation:manage` if absent (ON CONFLICT
 *     (name) DO NOTHING) so a grant can reference a real `permissions.id`.
 *  2. Conservative grant: grant `delegation:manage` to the seeded `admin` role
 *     via `role_permissions` (ON CONFLICT (role_id, permission_id) DO NOTHING).
 *
 * Reversibility
 * -------------
 * down() removes exactly what up() added: the `admin` grant, then the catalogue
 * row — but only when no `role_permissions` grant still references it, so a
 * delegation:manage grant another role/migration may have added is never
 * orphaned.
 */
class GrantDelegationManageToAdmin
{
    /**
     * The single permission this migration grants to the seeded admin role.
     */
    private const GRANTED_PERMISSION = CorePermissions::DELEGATION_MANAGE;

    public static function up(Database $db): void
    {
        // Step 1: catalogue catch-up (idempotent via ON CONFLICT (name)).
        $db->query(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, NOW())
             ON CONFLICT (name) DO NOTHING',
            [
                ':name' => self::GRANTED_PERMISSION,
                ':description' => 'Core permission (' . self::GRANTED_PERMISSION . ')',
            ]
        );

        // Step 2: conservative grant. No-op if either id is missing (e.g. a
        // partially-seeded database) rather than erroring.
        $adminRoleId = self::adminRoleId($db);
        $permissionId = self::permissionId($db, self::GRANTED_PERMISSION);

        if ($adminRoleId === null || $permissionId === null) {
            return;
        }

        $db->query(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             VALUES (:role_id, :permission_id, NOW())
             ON CONFLICT (role_id, permission_id) DO NOTHING',
            [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
        );
    }

    public static function down(Database $db): void
    {
        // Reverse step 2: drop the admin grant.
        $adminRoleId = self::adminRoleId($db);
        $permissionId = self::permissionId($db, self::GRANTED_PERMISSION);

        if ($adminRoleId !== null && $permissionId !== null) {
            $db->query(
                'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
            );
        }

        // Reverse step 1: remove the catalogue row only if nothing else grants it.
        $db->query(
            'DELETE FROM permissions
             WHERE name = :name
               AND NOT EXISTS (
                   SELECT 1 FROM role_permissions rp
                   WHERE rp.permission_id = permissions.id
               )',
            [':name' => self::GRANTED_PERMISSION]
        );
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
