<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * RemoveLegacyCrudPermissionSlugs — forward migration (#990, migration 114).
 *
 * Removes eight permission slugs that nothing consults and nothing can act on.
 *
 * WHAT THEY ARE
 * -------------
 * A `create`/`update` vocabulary seeded by migration 002 and re-upserted by 013,
 * shadowing the `write` form that the platform actually adopted:
 *
 *     users:create   users:update      shadow users:write
 *     roles:create   roles:update      shadow roles:write
 *     tenants:create tenants:update    shadow tenants:write
 *     ous:create     ous:update        shadow ous:write
 *
 * None of the eight has a {@see \Whity\Core\RBAC\CorePermissions} constant — the
 * modern vocabulary is read/write/delete (plus `ous:assign`, `roles:manage`) —
 * and a repository-wide search finds **no route, handler, middleware or resolver
 * that consults any of them**. They are catalogue rows and nothing else.
 *
 * WHY REMOVE THEM RATHER THAN LEAVE THEM
 * --------------------------------------
 * A permission that can be granted and does nothing is a trap in both
 * directions, and it is the permissions screen that tells the lie:
 *
 *   - An operator building a least-privilege role grants `users:create`, sees it
 *     accepted, and reasonably believes they have authorised something. Nothing
 *     consults it. The mistake runs in the safe direction — less access than
 *     intended — but it is still an authorisation the system pretended to make.
 *   - Someone auditing a role sees `users:create` ABSENT and may conclude the
 *     role cannot create users, when `users:write` grants exactly that. That
 *     mistake runs the unsafe way.
 *
 * It is also how two real gaps stayed hidden: `roles:read` (#977) and
 * `tenants:read` (#990) sat in the catalogue held by nobody, and nothing
 * revealed it, because an un-consulted slug produces no symptom until a route
 * starts consulting it — at which point it is a lockout.
 *
 * NO ACCESS CHANGES
 * -----------------
 * Verified on a freshly migrated database before writing this: six of the eight
 * are held by NOBODY, and the two that are held — `ous:create` and `ous:update`,
 * both on `admin` — are consulted by nothing, so removing the grant removes no
 * capability. Every operation those names suggest is gated on `ous:write`, which
 * `admin` keeps.
 *
 * WHAT THIS DELIBERATELY DOES NOT TOUCH
 * -------------------------------------
 * `tenants:read`, which is the seventh orphan in the same survey and looks like
 * a ninth candidate. It is NOT dead vocabulary: it is the slug
 * `GET /api/tenants` is to be re-gated onto (#990), exactly as `roles:read` was
 * in #977. Deleting it now and re-adding it then would be churn on the one
 * catalogue row that has a named future.
 *
 * Migrations 002 and 013 are left alone. A recorded migration never re-runs, so
 * editing them would reach only fresh databases — the persistent-DB drift rule.
 */
final class RemoveLegacyCrudPermissionSlugs
{
    /**
     * The dead slugs, each with the live slug that covers what it suggests.
     *
     * The mapping is documentation rather than logic: nothing is re-pointed,
     * because nothing consulted these in the first place. It is here so a reader
     * of `down()` can see that reversing this restores names, not authority.
     */
    private const REMOVED = [
        'users:create'   => 'users:write',
        'users:update'   => 'users:write',
        'roles:create'   => 'roles:write',
        'roles:update'   => 'roles:write',
        'tenants:create' => 'tenants:write',
        'tenants:update' => 'tenants:write',
        'ous:create'     => 'ous:write',
        'ous:update'     => 'ous:write',
    ];

    public static function up(Database $db): void
    {
        foreach (array_keys(self::REMOVED) as $name) {
            $permissionId = self::permissionId($db, $name);
            if ($permissionId === null) {
                continue;
            }

            // Grants first: role_permissions has an FK to permissions, so the
            // catalogue row cannot go while a grant still points at it. Only
            // `ous:create` / `ous:update` have any, and only on `admin`.
            $db->query(
                'DELETE FROM role_permissions WHERE permission_id = :id',
                [':id' => $permissionId]
            );

            $db->query(
                'DELETE FROM permissions WHERE id = :id',
                [':id' => $permissionId]
            );
        }
    }

    /**
     * Restore the catalogue rows, and nothing else.
     *
     * The GRANTS are deliberately not restored. Reversing a migration should put
     * back what it removed, and what it removed was inert: `admin` held
     * `ous:create` and `ous:update` and could do nothing with either. Re-granting
     * them would recreate the misleading state rather than the capability, and
     * `down()` is not the place to reintroduce a trap. A deployment that wants
     * them back can grant them.
     */
    public static function down(Database $db): void
    {
        foreach (self::REMOVED as $name => $supersededBy) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [
                    ':name' => $name,
                    ':description' => sprintf('Legacy alias; superseded by %s', $supersededBy),
                ]
            );
        }
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
