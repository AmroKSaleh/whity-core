<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantDocumentsReadAllToAdmin (#947 item 1) — registers `documents:read:all`
 * and grants it to the seeded `admin` role.
 *
 * WHY A SECOND PERMISSION AND NOT JUST `documents:read`
 * -----------------------------------------------------
 * `documents:read` (migration 060) is the designer's capability: it means "may
 * see templates and blocks", and every tenant member who opens the designer
 * holds it. Migration 108 introduces something that permission was never
 * scoped for — ISSUED DOCUMENTS, which are ordinary business records and are
 * not all everyone's business. Reusing `documents:read` for them would hand
 * every template author the tenant's entire issued output the moment this
 * shipped, silently, as a side effect of a capability they already had.
 *
 * So the row-level rule is: you see a document you raised, and
 * `documents:read:all` is the tenant-wide override an auditor, a compliance
 * reviewer or an administrator needs. It FAILS CLOSED — a caller with neither
 * gets a 404, never a 403, which would confirm the document exists (the same
 * posture {@see \Whity\Core\Document\DocumentAccessPolicy} already takes for a
 * gated template).
 *
 * THIS IS THE INTERIM RULE, ON PURPOSE
 * ------------------------------------
 * "Creator, or a tenant-wide reader" is not the final answer and is not
 * pretending to be. #947 item 3 brings recipients and per-document
 * `resource_role_assignments`, at which point "may I see this document?" gains
 * its real third clause — I am a recipient, or I hold a role granted on this
 * resource. {@see \Whity\Core\Document\DocumentVisibilityPolicy} is one method
 * with one decision in it so that clause has an obvious place to land.
 *
 * What this migration deliberately avoids is guessing that clause NOW, while
 * the rule kinds it depends on do not exist. The cost of the interim rule is
 * that a colleague cannot yet see a document raised beside them without the
 * tenant-wide grant; the cost of guessing would be a visibility model item 3
 * has to unpick.
 *
 * Mirrors migration 060's catalogue-upsert + grant shape exactly. down()
 * reverses only what up() added, and leaves the permission row alone if any
 * other role has since been granted it.
 */
class GrantDocumentsReadAllToAdmin
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::DOCUMENTS_READ_ALL =>
            'Read every issued document in the tenant, not only the ones you raised',
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
