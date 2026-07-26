<?php

declare(strict_types=1);

namespace DemoCatalog\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * GrantDemoCatalogPermissionsToAdmin migration.
 *
 * Makes the DemoCatalog frontend feature work out-of-the-box, mirroring
 * HelloWorld's GrantGreetingsPermissionsToAdmin migration: plugin
 * permissions are registered in the in-memory PermissionRegistry at load
 * time, but RBAC grants are persisted rows — without this migration no role
 * holds them and even the platform admin gets 403s on the demo screen.
 *
 * What it does (idempotent, additive):
 *  1. Seeds `demo_catalog:view` and `demo_catalog:manage` into the
 *     `permissions` catalogue (ON CONFLICT (name) DO NOTHING). Rows created
 *     here carry a plugin-marker description so down() can tell them apart
 *     from rows an operator created independently.
 *  2. Grants both permissions to every role named `admin`, ON CONFLICT DO
 *     NOTHING.
 *
 * down() removes exactly what up() added: the admin grants, then any
 * plugin-marked catalogue row that no remaining grant references.
 */
final class GrantDemoCatalogPermissionsToAdmin implements MigrationInterface
{
    /**
     * The permissions this migration seeds and grants.
     *
     * @var list<string>
     */
    private const PERMISSIONS = ['demo_catalog:view', 'demo_catalog:manage'];

    /**
     * Description marker identifying catalogue rows this migration owns.
     */
    private const DESCRIPTION_PREFIX = 'DemoCatalog plugin permission';

    /**
     * Apply the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function up(\PDO $pdo): void
    {
        $insertPermission = $pdo->prepare(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, :description, CURRENT_TIMESTAMP)
             ON CONFLICT (name) DO NOTHING'
        );

        foreach (self::PERMISSIONS as $permission) {
            $insertPermission->execute([
                ':name' => $permission,
                ':description' => self::DESCRIPTION_PREFIX . ' (' . $permission . ')',
            ]);
        }

        // Grant to every admin role; resolves ids via the engine so a
        // partially-seeded database (no admin role yet) is a no-op, not an error.
        $grant = $pdo->prepare(
            "INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT r.id, p.id, CURRENT_TIMESTAMP
             FROM roles r, permissions p
             WHERE r.name = 'admin' AND p.name = :permission
             ON CONFLICT (role_id, permission_id) DO NOTHING"
        );

        foreach (self::PERMISSIONS as $permission) {
            $grant->execute([':permission' => $permission]);
        }
    }

    /**
     * Revert the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function down(\PDO $pdo): void
    {
        $dropGrants = $pdo->prepare(
            "DELETE FROM role_permissions
             WHERE role_id IN (SELECT id FROM roles WHERE name = 'admin')
               AND permission_id IN (SELECT id FROM permissions WHERE name = :permission)"
        );

        foreach (self::PERMISSIONS as $permission) {
            $dropGrants->execute([':permission' => $permission]);
        }

        $dropCatalogue = $pdo->prepare(
            'DELETE FROM permissions
             WHERE name = :permission
               AND description LIKE :marker
               AND NOT EXISTS (
                   SELECT 1 FROM role_permissions rp
                   WHERE rp.permission_id = permissions.id
               )'
        );

        foreach (self::PERMISSIONS as $permission) {
            $dropCatalogue->execute([
                ':permission' => $permission,
                ':marker' => self::DESCRIPTION_PREFIX . '%',
            ]);
        }
    }
}
