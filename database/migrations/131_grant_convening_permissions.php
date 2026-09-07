<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * GrantConveningPermissions — who holds the three slugs migration 130 put in the
 * catalogue.
 *
 * The split mirrors 108/109 and 112/113: one migration builds the schema and
 * registers the vocabulary, the next one decides the audience. They are separate
 * because they answer to different people. The schema is a platform decision; who
 * may minute a body's decisions is an organisational one, and a deployment that
 * wants to change the second should be able to read exactly one file to see what
 * was granted and why.
 *
 * GRANTED BY CAPABILITY, NEVER TO THE ROLE LITERALLY NAMED `admin`
 * ---------------------------------------------------------------
 * Migration 110 records the hazard (#834): a deployment running a custom
 * administrative role silently LOSES a capability on upgrade, because a
 * `grant_*_to_admin` migration reaches only the seeded role. A grant keyed on a
 * capability the deployment actually granted is the one that survives whatever
 * they called it.
 *
 * THE THREE AUDIENCES
 * -------------------
 * `convening:read` goes to two audiences, because two different jobs need it and
 * neither is a subset of the other:
 *
 *   - `settings:read` — whoever a deployment already trusts to see how it is
 *     configured. A body's existence, its membership and its calendar are
 *     organisational structure of the same kind as its units and its periods.
 *   - `documents:route` — whoever already circulates documents. The moment a
 *     document is put in front of a body, "which meeting is this waiting for,
 *     and what did they decide" is a question the person circulating it has to
 *     be able to answer, and a read gate they do not hold turns an ordinary
 *     status check into an escalation.
 *
 * `convening:manage` anchors on `settings:write` — the same authority that
 * defines the tenant's units and its period vocabulary. Constituting a standing
 * body is that kind of act.
 *
 * `convening:decide` anchors on `documents:route` ALONE, and this is the one
 * choice in the file worth arguing:
 *
 *   It is deliberately NOT `settings:write`. Recording a decision is not
 *   configuration — it is the act that can approve or reject somebody's
 *   document, and it reaches the routing engine through exactly the same call a
 *   recipient's own approval makes. `documents:route` is what a deployment
 *   grants to the people whose job is moving documents through sign-off, which
 *   is precisely the population that minutes the sign-off when a body is what
 *   does it. Migration 120 uses the same anchor for `route_templates:read` for a
 *   related reason.
 *
 *   It is also deliberately not narrower still — there is no existing slug that
 *   means "may minute a meeting", and inventing an anchor that nobody holds
 *   would ship the capability held by NOBODY, which is a lockout dressed as
 *   caution. {@see \scripts\ci-permission-holder-guard.php} would catch that on
 *   the day the routes ship; better to ship an honest audience an operator can
 *   narrow than an empty one they have to discover.
 *
 * WHAT THIS DOES NOT GRANT, AND WHY
 * ---------------------------------
 * RESPONDING TO AN INVITATION is not gated by any of the three. A person
 * accepting or declining a sitting they were invited to needs no grant, because
 * BEING INVITED IS THE AUTHORIZATION — the same argument migration 113 makes
 * about acting on a route that reached you, and the same one `/api/me/inbox` and
 * `/api/me/sessions` make. Requiring a permission on top would mean a body could
 * invite somebody who is then unable to answer, and the chair would count them
 * as silent for ever with no way to find out why. The handler enforces that the
 * responder is answering THEIR OWN invitation, which is the whole of the
 * question a tenant-wide slug could not have asked.
 *
 * Additive and idempotent (`ON CONFLICT DO NOTHING` on every step). down()
 * removes only the grants it made and leaves the catalogue rows to migration
 * 130, which owns them.
 */
final class GrantConveningPermissions
{
    /**
     * Which existing capability identifies the audience for each slug.
     *
     * Every anchor is verified to have holders on a database that has only been
     * MIGRATED — not seeded — which is the state this migration runs in.
     * `settings:read` / `settings:write` are granted by migration 026 and
     * `documents:route` by migration 113 (to every holder of `documents:render`,
     * which migration 060 grants), all of which run before this one. An
     * anchor granted later by the seeder would find an empty audience here and
     * the slug would ship held by nobody.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        CorePermissions::CONVENING_READ => [
            CorePermissions::SETTINGS_READ,
            CorePermissions::DOCUMENTS_ROUTE,
        ],
        CorePermissions::CONVENING_MANAGE => [CorePermissions::SETTINGS_WRITE],
        CorePermissions::CONVENING_DECIDE => [CorePermissions::DOCUMENTS_ROUTE],
    ];

    public static function up(Database $db): void
    {
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                // Migration 130 puts the row there. A database where it is
                // missing is one whose catalogue drifted, and inventing the row
                // here would put a description this file does not own beside a
                // slug 130 is responsible for.
                continue;
            }

            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
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
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            // The audience is re-resolved the way up() resolved it. A role
            // granted the anchor AFTER this migration ran never received the
            // slug, so it has nothing to take back; a role that LOST the anchor
            // in between keeps it, which is the conservative direction for a
            // down() — it leaves an operator holding a permission they may not
            // need rather than removing one they do.
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    /**
     * The ids of every role holding ANY of the given permissions DIRECTLY.
     *
     * Direct grants only, the rule migration 110 states: effective resolution
     * walks role inheritance, organizational units and delegations, and a
     * migration that followed those paths would write grant rows onto roles that
     * hold the capability transitively — turning an inherited permission into an
     * independent one and quietly changing what revoking the parent does.
     *
     * De-duplicated, because the two audiences for `convening:read` overlap on
     * every ordinary install (one administrative role holds both), and inserting
     * twice would rely on the conflict clause to hide a bug here.
     *
     * @param list<string> $permissions
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

            if ($rows === false) {
                continue;
            }
            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
