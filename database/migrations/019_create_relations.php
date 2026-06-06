<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;
use Whity\Core\RBAC\CorePermissions;

/**
 * CreateRelations migration (WC-65 — Family Relations Management System)
 *
 * Creates the `relations` edge table and seeds the feature's RBAC permissions
 * (ADR 0002). An edge is always `person → person`; the reciprocal direction is
 * NEVER persisted — it is derived at read time through the relationship type's
 * `inverse_type_id` (see 018_create_relationship_types). This single-row-per-
 * relationship design avoids the dual-row drift that duplicated state has
 * repeatedly caused in this codebase.
 *
 * Schema notes
 * ------------
 *  - `tenant_id` is NOT NULL and FK-cascades with the tenant; both endpoints
 *    must live in the same tenant (enforced at the API boundary — a cross-tenant
 *    reference is treated as not-found rather than disclosed).
 *  - `from_person_id` / `to_person_id` FK `persons` with ON DELETE CASCADE so
 *    deleting a (non-user) person removes its edges automatically.
 *  - `relationship_type_id` FK `relationship_types`; deletion is RESTRICTed
 *    implicitly because the seeded vocabulary is never deleted in v1.
 *  - UNIQUE (tenant_id, from_person_id, to_person_id, relationship_type_id)
 *    backs the no-duplicate integrity rule at the database level, complementing
 *    the typed DuplicateRelationException raised at the boundary.
 *
 * RBAC seed
 * ---------
 * Seeds the `relations:read` and `relations:manage` permissions (idempotent
 * ON CONFLICT) and grants both to the seeded `admin` role, matching the
 * `audit:read` / `delegation:manage` pattern so a fresh database has the feature
 * usable by administrators out of the box.
 *
 * Additive, idempotent and reversible: down() reverses the grants, removes only
 * the catalogue rows this migration introduced (never orphaning a grant), and
 * drops the table.
 */
class CreateRelations
{
    /**
     * The permissions this migration seeds and grants to admin.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::RELATIONS_READ => 'Read family relations and persons',
        CorePermissions::RELATIONS_MANAGE => 'Create, edit and delete family relations and persons',
    ];

    public static function up(Database $db): void
    {
        // The relationship edges. One row per relationship; the reciprocal is
        // derived at read time via the type's inverse_type_id.
        $db->exec('
            CREATE TABLE IF NOT EXISTS relations (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                from_person_id INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
                to_person_id INTEGER NOT NULL REFERENCES persons(id) ON DELETE CASCADE,
                relationship_type_id INTEGER NOT NULL REFERENCES relationship_types(id),
                created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, from_person_id, to_person_id, relationship_type_id)
            )
        ');

        // Listing a node's relations reads edges where it is either endpoint, so
        // index both directions within the tenant.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_relations_tenant_from
            ON relations (tenant_id, from_person_id)
        ');
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_relations_tenant_to
            ON relations (tenant_id, to_person_id)
        ');

        // Seed the relations:* permissions so the RBAC catalogue matches the
        // in-memory CorePermissions registry, then grant them to the admin role.
        // Every step is idempotent.
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
            // Partially-seeded database: skip the grant rather than error. The
            // permissions still exist for a later grant.
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
        // Reverse the grants first so the catalogue rows are no longer referenced.
        $adminRoleId = self::adminRoleId($db);

        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permissionId = self::permissionId($db, $name);

            if ($adminRoleId !== null && $permissionId !== null) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $adminRoleId, ':permission_id' => $permissionId]
                );
            }

            // Remove the catalogue row this migration introduced, but only when no
            // grant still references it (defensive: never orphan a grant).
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $name]
            );
        }

        // Drop the edge table (CASCADE removes its indexes).
        $db->exec('DROP TABLE IF EXISTS relations CASCADE');
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
