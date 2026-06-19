<?php

declare(strict_types=1);

namespace UiKitShowcase\Migrations;

use Whity\Sdk\MigrationInterface;

/**
 * GrantUiKitViewToAdmin migration (WC-228)
 *
 * Makes the UiKitShowcase block screen reachable out-of-the-box. The showcase
 * contributes ONE `screen: 'blocks'` frontend feature gated on `uikit:view`,
 * and the host only exposes a feature descriptor to callers that actually hold
 * its `requiredPermission` (server-side filtered `GET /api/frontend/features`).
 * Plugin permissions are registered in the in-memory PermissionRegistry at load
 * time, but RBAC grants are persisted rows — without this migration no role
 * holds `uikit:view` and even the platform admin never sees the reference
 * screen.
 *
 * What it does (idempotent, additive):
 *  1. Seeds `uikit:view` into the `permissions` catalogue
 *     (ON CONFLICT (name) DO NOTHING). The row carries a plugin-marker
 *     description so down() can tell it apart from a row an operator created
 *     independently.
 *  2. Grants `uikit:view` to every role named `admin`, ON CONFLICT DO NOTHING.
 *     (`roles.name` is globally UNIQUE in the current schema, so in practice
 *     that is one role; the set-based INSERT..SELECT stays correct if
 *     per-tenant admin roles ever arrive.)
 *
 * down() removes exactly what up() added: the admin grant, then the
 * plugin-marked catalogue row only if no remaining grant references it — a row
 * still granted to another role, or a row the migration did not create
 * (different description), survives. Tracked by the host migration runner as
 * `plugin:UiKitShowcase:GrantUiKitViewToAdmin`. Mirrors the HelloWorld grant
 * migration, adapted to a single permission.
 */
final class GrantUiKitViewToAdmin implements MigrationInterface
{
    /**
     * The single permission this migration seeds and grants.
     */
    private const PERMISSION = 'uikit:view';

    /**
     * Description marker identifying the catalogue row this migration owns.
     */
    private const DESCRIPTION = 'UiKitShowcase plugin permission (uikit:view)';

    /**
     * Marker prefix used by the down() ownership guard.
     */
    private const DESCRIPTION_MARKER = 'UiKitShowcase plugin permission%';

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
        $insertPermission->execute([
            ':name' => self::PERMISSION,
            ':description' => self::DESCRIPTION,
        ]);

        // Grant to every admin role; resolves ids via the engine so a
        // partially-seeded database (no admin role yet) is a no-op, not an error.
        $grant = $pdo->prepare(
            "INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT r.id, p.id, CURRENT_TIMESTAMP
             FROM roles r, permissions p
             WHERE r.name = 'admin' AND p.name = :permission
             ON CONFLICT (role_id, permission_id) DO NOTHING"
        );
        $grant->execute([':permission' => self::PERMISSION]);
    }

    /**
     * Revert the migration.
     *
     * @param \PDO $pdo Live database connection.
     * @return void
     */
    public function down(\PDO $pdo): void
    {
        // Drop the admin grant for the plugin's permission.
        $dropGrants = $pdo->prepare(
            "DELETE FROM role_permissions
             WHERE role_id IN (SELECT id FROM roles WHERE name = 'admin')
               AND permission_id IN (SELECT id FROM permissions WHERE name = :permission)"
        );
        $dropGrants->execute([':permission' => self::PERMISSION]);

        // Remove the catalogue row ONLY if this migration created it (marker
        // description) AND no remaining grant references it — never orphan
        // another role's grant, never delete an operator-created row.
        $dropCatalogue = $pdo->prepare(
            'DELETE FROM permissions
             WHERE name = :permission
               AND description LIKE :marker
               AND NOT EXISTS (
                   SELECT 1 FROM role_permissions rp
                   WHERE rp.permission_id = permissions.id
               )'
        );
        $dropCatalogue->execute([
            ':permission' => self::PERMISSION,
            ':marker' => self::DESCRIPTION_MARKER,
        ]);
    }
}
