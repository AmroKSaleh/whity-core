<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantLicensingPermissions — who may see, sell and provision licensed devices.
 *
 * GRANTED BY CAPABILITY, NEVER BY ROLE NAME. A deployment's roles are its own:
 * "Sales" may not exist, may be called something else, or may be three
 * different roles in three regions. What a migration can rely on is what a role
 * can already DO, so each slug anchors on an existing capability and follows it
 * wherever a deployment has put it.
 *
 *   licensing:view   ← settings:read, settings:write
 *   licensing:issue  ← settings:write
 *   licensing:manage ← settings:write
 *
 * THE ASYMMETRY IS THE POINT. `view` anchors on the weaker capability, because
 * a finance viewer auditing what is billable has every reason to see the unit
 * list and no reason to mint codes. `issue` and `manage` anchor on the stronger
 * one, so a read-only administrator does not silently acquire the ability to
 * give away licences.
 *
 * WHY `issue` AND `manage` ARE SEPARATE SLUGS even though both land on the same
 * anchor today. They are different jobs: issuing is the COMMERCIAL act — a
 * salesperson hands over a code because something was sold — while managing is
 * inventory and destruction, revoking codes and retiring hardware. Granting
 * them together now is a deployment's default, not a law; splitting them here
 * means a deployment that wants a salesperson who cannot retire a customer's
 * devices can express that by revoking one slug, instead of asking for a schema
 * change.
 *
 * REDEMPTION HAS NO SLUG, deliberately. It is authorised by possession of the
 * code, by somebody who may have no account and no relationship to the tenant
 * at the moment they type it. There is no capability to hold and nobody to hold
 * it — which is exactly why the redemption route is rate-limited and the code
 * is a checksummed 50-bit secret rather than something guessable.
 */
final class GrantLicensingPermissions
{
    /** @var array<string, string> */
    private const PERMISSIONS = [
        CorePermissions::LICENSING_VIEW => 'View this tenant\'s licensed devices and activation codes',
        CorePermissions::LICENSING_ISSUE => 'Issue activation codes for this tenant\'s licensed devices',
        CorePermissions::LICENSING_MANAGE => 'Provision, revoke and retire this tenant\'s licensed devices',
    ];

    /** @var array<string, list<string>> */
    private const AUDIENCE_FOR = [
        CorePermissions::LICENSING_VIEW => [CorePermissions::SETTINGS_READ, CorePermissions::SETTINGS_WRITE],
        CorePermissions::LICENSING_ISSUE => [CorePermissions::SETTINGS_WRITE],
        CorePermissions::LICENSING_MANAGE => [CorePermissions::SETTINGS_WRITE],
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

        foreach (self::AUDIENCE_FOR as $slug => $anchors) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            // A database where nobody may administer settings has nobody to
            // grant to. Not an error: on a fresh install this runs before
            // whatever seeds that grant, and the catalogue row above is all
            // this migration owes such a database.
            foreach (self::rolesHoldingAny($db, $anchors) as $roleId) {
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
        foreach (self::AUDIENCE_FOR as $slug => $anchors) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            foreach (self::rolesHoldingAny($db, $anchors) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }

        // The catalogue rows go too: these slugs are new here, so removing them
        // restores the catalogue rather than deleting something a deployment
        // granted deliberately.
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $db->query('DELETE FROM permissions WHERE name = :name', [':name' => $name]);
        }
    }

    /**
     * @param list<string> $permissions
     *
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];

        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();

            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_keys($roleIds);
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $row = $db->query(
            'SELECT id FROM permissions WHERE name = :name',
            [':name' => $name]
        )->fetch();

        return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
    }
}
