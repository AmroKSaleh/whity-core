<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantDocumentIssuePermission (editor M4c).
 *
 * Issuing a document — raising the record, minting its QR code, storing its
 * PDF, and correcting it later — moves from `documents:render` to its own
 * `documents:issue` (the owner's call on 31 Oct, DOCUMENT-AUTOMATION.md).
 * `documents:render` keeps the PDF preview and label printing, which store
 * nothing.
 *
 * WITHOUT THE GRANT THIS IS A LOCKOUT, not a tightening: every role that can
 * issue a document today does so through `documents:render`, and would find
 * New document refused the day this ships. So the slug is granted to every
 * role that holds `documents:render` directly — a capability the deployment
 * granted, never a role name this migration would have to guess (#834; the
 * pattern of migrations 110 and 113). A deployment can then take issuing away
 * from a role that should only preview.
 *
 * Additive and idempotent (`ON CONFLICT DO NOTHING`). down() removes exactly
 * the grants up() added, and the catalogue row only when nothing holds it.
 */
class GrantDocumentIssuePermission
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::DOCUMENTS_ISSUE => 'Issue a document from a template: the record, its QR code and its PDF, and correct it',
    ];

    /** Whoever may issue a document today. */
    private const AUDIENCE_PERMISSION = CorePermissions::DOCUMENTS_RENDER;

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

        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }
            foreach ($audience as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }
            foreach ($audience as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
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

    /**
     * The ids of every role holding a permission DIRECTLY (as migration 113:
     * an inherited grant is not copied into an independent one).
     *
     * @return list<int>
     */
    private static function rolesHolding(Database $db, string $permission): array
    {
        $rows = $db->query(
            'SELECT rp.role_id
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :name',
            [':name' => $permission]
        )->fetchAll();

        if ($rows === false) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['role_id'], $rows);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
