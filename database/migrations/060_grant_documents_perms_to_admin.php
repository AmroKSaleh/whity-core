<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * GrantDocumentsPermsToAdmin migration (WC-docdesigner).
 *
 * Registers the document/label-designer permissions and grants them to the
 * seeded `admin` role, so a tenant admin can manage documents out of the box.
 * Tenant-scoped — the handlers resolve TenantContext and additionally row-filter
 * by scope/required_permission, so these are the baseline capability grants (a
 * gated contracts template is still withheld from a caller lacking its
 * required_permission tag).
 *
 * Mirrors the established catalogue-upsert + grant pattern (migrations 058/056).
 * down() reverses only what up() added.
 */
class GrantDocumentsPermsToAdmin
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::DOCUMENTS_READ    => 'View and list document/label templates and blocks',
        CorePermissions::DOCUMENTS_WRITE   => 'Create, update and delete document/label templates and blocks',
        CorePermissions::DOCUMENTS_PUBLISH => 'Publish a template/block tenant-wide or global, or set its required-permission tag',
        CorePermissions::DOCUMENTS_RENDER  => 'Render a document/label template server-side to PDF/PNG',
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
