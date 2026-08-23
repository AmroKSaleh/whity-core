<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantDocumentRoutingPermission (#947 item 3).
 *
 * Migration 112 built the routing engine. One capability gates the one act that
 * starts it: `documents:route` — putting a document into circulation.
 *
 * WHAT IS *NOT* GATED BY A PERMISSION, AND WHY
 * --------------------------------------------
 * ACTING on a document that reached you is deliberately self-service. A
 * recipient forwarding, acknowledging or returning their own inbox item needs
 * no grant, because BEING A RECIPIENT IS THE AUTHORIZATION — the route named a
 * rule, the rule resolved to them, and the engine wrote the row. Requiring a
 * second permission on top would mean a route could resolve to somebody who is
 * then unable to answer it: the item sits open forever, the chain never
 * advances, and the person holding it has no way to discover why. That is the
 * same failure a stored recipient list produces (#947), reached from the other
 * direction.
 *
 * This mirrors `/api/me/notifications` and `/api/me/sessions`, which are
 * session-gated and unpermissioned for the same reason: the row already names
 * exactly one person, so the tenant-wide question has no work left to do.
 *
 * READING a document's routes and trail is gated on `documents:read` at the
 * route and row-filtered by {@see \Whity\Core\Document\DocumentVisibilityPolicy}
 * on top — which item 3 widens with the recipient/resource-role disjunct that
 * migration 108's docblock left a home for. No new read permission: `documents:read:all`
 * (migration 109) already answers "may see the tenant's issued output", and a
 * separate `documents:trail:read` would be a second answer to the same question
 * with no case that distinguishes them.
 *
 * WHO IT GRANTS TO, AND WHY NOT `admin`
 * -------------------------------------
 * Every role that already holds `documents:render`, not the `admin` role by
 * name.
 *
 * Migration 110 records why the ~20 `grant_*_to_admin` migrations before it are
 * the wrong pattern (#834): a deployment running a custom administrative role
 * silently LOSES a capability on upgrade, because the migration reached only
 * the seeded role. Here the right audience is nameable without guessing.
 * `documents:render` is what gates `persist: true` on the render routes, so a
 * role holding it is precisely a role that can bring a document into existence
 * — and a deployment that decided those people may ISSUE documents has already
 * made the judgement that they may circulate them. Anything narrower silently
 * strands every document its issuers raise.
 *
 * It is deliberately not "every role holding the `admin` ROLE NAME" either:
 * role-name inheritance is a deployment's private vocabulary, and a grant keyed
 * on a capability the deployment actually granted is the one that survives
 * whatever they called it.
 *
 * Additive and idempotent (`ON CONFLICT DO NOTHING` on both steps). down()
 * removes exactly the grants up() added, and removes the catalogue row only if
 * nothing else still references it.
 */
class GrantDocumentRoutingPermission
{
    /**
     * The one slug this migration introduces.
     *
     * The description is written for the person reading a permission picker, so
     * it says what the permission LETS SOMEBODY DO rather than restating the
     * slug — "documents:route" on its own does not tell an operator that it is
     * what starts a circulation.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::DOCUMENTS_ROUTE => 'Put an issued document into circulation and choose the steps it follows',
    ];

    /**
     * The capability that identifies the roles this migration grants to.
     *
     * See the class docblock: the audience is "whoever may already issue a
     * document", which is a capability the deployment granted rather than a
     * role name this migration would have to guess.
     */
    private const AUDIENCE_PERMISSION = CorePermissions::DOCUMENTS_RENDER;

    public static function up(Database $db): void
    {
        // Step 1: catalogue upsert. Migration 013 seeds the whole CorePermissions
        // list, so on a fresh install this row already exists; the insert is
        // here so the migration stands on its own against a database whose
        // catalogue drifted, and it can never overwrite a human-written
        // description (ON CONFLICT DO NOTHING).
        foreach (self::PERMISSIONS as $name => $description) {
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        $audience = self::rolesHolding($db, self::AUDIENCE_PERMISSION);
        if ($audience === []) {
            // A database where nobody may render documents has nobody to grant
            // to. Not an error: up() on a fresh install runs before whatever
            // seeds that grant, and the catalogue row above is all this
            // migration owes such a database.
            return;
        }

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
        // Resolved the same way up() did. A role granted `documents:render`
        // AFTER this migration ran never received this one, so it has nothing to
        // take back; a role that lost `documents:render` in between keeps it,
        // which is the conservative direction for a down(): it leaves an
        // operator with a permission they may not need rather than removing one
        // they may.
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

            // Only when nothing else holds it. Migration 013 seeded the
            // catalogue and owns its removal; this clause is the safety net for
            // a database where 013's catalogue step did not run.
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
     * The ids of every role holding a permission DIRECTLY.
     *
     * Direct grants only, deliberately — the same rule migration 110 states.
     * Effective resolution walks role inheritance, organisational units and
     * delegations, and a migration that followed those paths would write grant
     * rows onto roles that hold the capability transitively, turning an
     * inherited permission into an independent one and quietly changing what
     * revoking the parent does.
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
