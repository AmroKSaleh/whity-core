<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateUserGroups (#999) — named user groups, and the load-bearing decision is
 * that a group is a RULE WITH A NAME, not a membership table.
 *
 * ONE NODE SAYING "INSTRUCTORS", NOT A THOUSAND SAYING EACH INSTRUCTOR
 * -------------------------------------------------------------------
 * The ask was "user groups or user types in addition to roles and OUs, allowing
 * complex customizable grouping by plugins or admins", with the clarifying
 * example of one node for the instructors rather than a thousand nodes for a
 * thousand instructors. This table is that node. There is no
 * `user_group_members`, no join table, and nowhere on this row to put a person
 * — the same absence migration 112 relies on for route steps, and for the same
 * reason.
 *
 * THE REJECTED ALTERNATIVE: A GROUPS TABLE WITH MEMBERSHIP ROWS
 * ------------------------------------------------------------
 * That was the obvious design and it fails the way stored recipient lists fail.
 * Hire an instructor next week: a list-based group silently omits them, still
 * renders, still resolves, and still reports success. Nothing anywhere says a
 * person was left out of the circular addressed to instructors. A rule includes
 * them because they exist, not because somebody remembered to add them.
 *
 * It is the same argument in three places now, which is why it is the platform's
 * answer rather than this feature's: rules-not-people in routing (#989),
 * folders-as-queries in the organizer (#987, migration 114), and here. And it is
 * what keeps a route composer drawable at five nodes instead of a thousand.
 *
 * WHY NOT A FOURTH INDEPENDENT CONCEPT
 * ------------------------------------
 * Three mechanisms already partly express "a set of people": roles (a permission
 * bundle), organizational units (a position in the tree) and taxonomy tags on
 * profiles (`entity_tags.entity_type` is an opaque string, so tagging a profile
 * works today). A fourth thing with its own vocabulary would be exactly what
 * #714 warns about — "all three should share one registry, not define it three
 * times". So a group speaks the vocabulary #989 already shipped:
 * `rule_kind` + `rule_config`, resolved through
 * {@see \Whity\Core\Document\Routing\RoutingRuleRegistry}, which is where core's
 * kinds and every plugin's kinds already live.
 *
 * THE COLUMNS ARE `document_route_steps`' COLUMNS, ON PURPOSE
 * ----------------------------------------------------------
 * `rule_kind VARCHAR(128)` + `rule_config JSONB`, spelled and sized exactly as
 * migration 112 spelled them. A group and a route step are the same expression
 * written in two places — one stored under a name for reuse, one stored inline
 * for a single circulation — and giving them different column widths or
 * different JSON conventions would be two dialects of one language, free to
 * drift the first time a resolver was taught something new.
 *
 * THE ENUMERATED CASE IS A RULE KIND TOO
 * --------------------------------------
 * A hand-picked set of people is `rule_kind = 'explicit'` with
 * `rule_config = {"profile_ids": [...]}`. That is deliberate and it is what
 * makes the whole thing usable: an admin creating "the tender committee" and an
 * admin creating "all instructors" are creating the SAME KIND OF OBJECT, and
 * neither has to know which sort they made. Everything downstream — preview,
 * routing, permissions, deletion — has one code path.
 *
 * A hand-picked list living in JSONB is not the membership table this migration
 * rejects, and the difference is not cosmetic. It is ONE ROW PER GROUP, opaque
 * to SQL, with no index over it and nothing joining it; and it is resolved
 * through the same resolver-then-filter path as every other kind, so a profile
 * that has since left the tenant drops out of the answer instead of lingering
 * as a row. The rejected design was one row PER PERSON PER GROUP, which is the
 * thing that goes stale in the other direction — it accumulates people who
 * should no longer be there and nothing prompts anyone to prune it.
 *
 * NO FOREIGN KEY OUT OF `rule_config`, AND THAT IS THE SAME ARGUMENT AS 112'S
 * ---------------------------------------------------------------------------
 * `{"role_id": 7}`, `{"profile_ids": [11, 12]}` and whatever an
 * `acme:committee` rule needs are all in one opaque column, so none of them can
 * carry a constraint. The consequence is faced rather than hidden: a rule naming
 * a role that has been deleted resolves to NOBODY, and a group whose id has been
 * deleted makes a route step naming it fail LOUDLY by name
 * ({@see \Whity\Core\Group\GroupRuleResolver}) rather than be skipped. Silently
 * skipping is the one outcome forbidden — it drops a whole class of people from
 * a distribution and reports success.
 *
 * The alternative — refusing to delete a group while any route step's JSONB
 * mentions it — would mean scanning `rule_config` across every step on every
 * delete, in two SQL dialects (`->>` on PostgreSQL, `json_extract` on the
 * offline SQLite engine), to protect against a state the resolver already
 * reports honestly.
 *
 * WHY THE TABLE IS `user_groups` AND NOT `groups`
 * ----------------------------------------------
 * Two reasons, either sufficient. `GROUPS` is a keyword on PostgreSQL 11+
 * (window frames), so a bare name would need quoting forever. And the table
 * namespace is FLAT and shared with every plugin that ships a migration —
 * {@see \Whity\Core\Tenant\TableOwnershipRegistry} exists precisely because
 * plugins claim table names, and `groups` is a name a dozen plugins would reach
 * for on their first day. `user_groups` also matches what the feature is called
 * out loud.
 *
 * FOREIGN KEYS, AND WHY `created_by` DOES NOT CASCADE
 * --------------------------------------------------
 *  - `tenant_id -> tenants ON DELETE CASCADE`. What every tenant-owned table
 *    does. A group is a statement about one tenant's people and means nothing
 *    without it.
 *
 *  - `created_by -> profiles ON DELETE SET NULL`, agreeing with
 *    `documents.created_by` (migration 108) and DISAGREEING with
 *    `document_collections.profile_id` (migration 114), which cascades. The
 *    distinction is whether the row is the person's own or the organisation's. A
 *    collection is one person's private filing and means nothing to anybody once
 *    they are gone. A group is the opposite: "instructors" is the institution's
 *    definition, it is referenced by routes that are still running, and the
 *    person who first typed it leaving the university does not delete the
 *    instructors. Cascading here would silently destroy live configuration as a
 *    side effect of offboarding.
 *
 * `UNIQUE (tenant_id, name)` — two groups called "Instructors" in one tenant are
 * indistinguishable to everyone who has to pick one from a list, and picking the
 * wrong one addresses a document to the wrong people. Scoped to the tenant, not
 * global: two tenants naming their groups identically is expected.
 *
 * THE PERMISSIONS, AND WHY TWO OF THEM EARN THEIR EXISTENCE
 * --------------------------------------------------------
 * #987 declined to add `documents:organize` on the grounds that a permission
 * nobody would revoke separately is a second name for an existing one. Applying
 * the same test here gives the opposite answer twice:
 *
 *  - `groups:write` — defining a group is naming a query, not granting
 *    authority, so it is genuinely separable from `roles:write` in BOTH
 *    directions. A departmental coordinator who curates circulation audiences
 *    must not be able to edit RBAC; a security administrator who edits RBAC has
 *    no business inventing audiences. Neither is a rephrasing of the other.
 *
 *  - `groups:read` — a route composer must be able to SEE the groups they may
 *    name without being able to define one, so read has to be grantable without
 *    write. And it is withholdable on its own: the NAMES of a tenant's groups
 *    are informative in their own right ("Under investigation" is a sentence),
 *    which is why enumerating them is a capability rather than a courtesy.
 *
 * A CATALOGUE ROW IS NOT A GRANT — so this migration GRANTS
 * --------------------------------------------------------
 * `roles:read` sat in the catalogue held by nobody for months, and gating a
 * route on it would have been a lockout (#977). The lesson is applied here
 * rather than restated: both slugs are granted to a nameable audience in the
 * same migration that introduces them, and the audience is a CAPABILITY the
 * deployment already granted rather than the `admin` role by name — migration
 * 110 records why the ~20 `grant_*_to_admin` migrations before it are the wrong
 * pattern (#834), since a deployment running a custom administrative role
 * silently loses the capability on upgrade.
 *
 *  - `groups:write` goes to whoever holds `roles:write`. A group's rules are
 *    phrased in roles and units; the people who defined those roles are the
 *    people who can say what "all instructors" means here. (`ous:write` was the
 *    near-miss and was rejected: a group is not a position in the tree, and a
 *    rule may not mention a unit at all.)
 *
 *  - `groups:read` goes to that same audience AND to whoever holds
 *    `documents:route` (migration 113) — the people who compose circulations
 *    and would otherwise be shown a group picker they are forbidden to read.
 *
 * Both steps are additive and idempotent. A database where the audience
 * permission is held by nobody gets the catalogue rows and no grants, which is
 * correct rather than an error: on a fresh install this runs before whatever
 * grants them, and the rows are what lets an operator grant later.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
final class CreateUserGroups
{
    /**
     * The two slugs this migration introduces.
     *
     * Descriptions are written for somebody reading a permission picker, so they
     * say what the permission LETS A PERSON DO rather than restating the slug.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::GROUPS_READ =>
            'See the tenant\'s user groups and how many people each one currently resolves to',
        CorePermissions::GROUPS_WRITE =>
            'Define, rename and delete user groups — the named rules that say which people a set contains',
    ];

    /**
     * Which existing capability identifies the audience for each new slug.
     *
     * A capability rather than a role name, for the reason migration 110 gives.
     * `groups:read` has two audiences because two different jobs need it: the
     * people who define groups, and the people who USE one in a route they are
     * composing.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        CorePermissions::GROUPS_WRITE => [CorePermissions::ROLES_WRITE],
        CorePermissions::GROUPS_READ => [CorePermissions::ROLES_WRITE, CorePermissions::DOCUMENTS_ROUTE],
    ];

    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement, not a loop over an
        // interpolated name — TenantOwnedTablesTest and CoreTablesTest re-derive
        // their registries by SCANNING this source, so the name must appear
        // literally. Migrations 059, 108, 112 and 114 carry the same note, and
        // spell the keyword in lowercase in prose for the same reason:
        // MigrationSchemaTest would read a capitalised one in a comment as a
        // real table declaration.
        //
        // `name` is 160, matching `document_collections.name` (migration 114):
        // it is a label a person types into a picker, and the width that fits
        // one is the width worth storing. The API refuses longer, so the column
        // and the validator agree instead of one truncating what the other
        // accepted.
        //
        // `description` is TEXT and optional. It exists because the NAME of a
        // rule cannot carry its intent — "Instructors" does not say whether
        // visiting lecturers count — and a group that many people will address
        // documents to needs somewhere to say so. The API bounds its length for
        // the same reason the routing note is bounded.
        //
        // `rule_config` defaults to an empty JSON OBJECT rather than an empty
        // array: PHP cannot tell an empty map from an empty list, `[]` is not a
        // valid jsonb object, and a row that decoded to a list where every
        // resolver expects a map would fail at resolution time rather than at
        // write time. Migration 112 makes the same choice for the same reason.
        $db->exec("
            CREATE TABLE IF NOT EXISTS user_groups (
                id          BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id   INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name        VARCHAR(160)  NOT NULL,
                description TEXT,
                rule_kind   VARCHAR(128)  NOT NULL,
                rule_config JSONB         NOT NULL DEFAULT '{}'::jsonb,
                created_by  INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                created_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, name)
            )
        ");

        // The one read that exists — "this tenant's groups, by name" — is served
        // outright by the unique constraint's own index, so there is no second
        // index here. Nothing queries by `rule_kind`: the kind is read off a row
        // already fetched, and "every group defined as a role rule" is not a
        // question any surface asks.
        //
        // There is deliberately NO index supporting "which groups is this person
        // in". That question cannot be answered by an index over this table at
        // all — the membership is computed, not stored — and adding one that
        // looked as though it might would be worse than admitting it.

        foreach (self::PERMISSIONS as $name => $description) {
            // Migration 013 seeds the whole CorePermissions list, so on a fresh
            // install these rows already exist by the time this runs; the insert
            // is here so the migration stands on its own against a database
            // whose catalogue drifted, and it can never overwrite a
            // human-written description (ON CONFLICT DO NOTHING).
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
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
        // Grants first: `role_permissions` has a foreign key to `permissions`,
        // so a catalogue row cannot go while a grant still points at it.
        //
        // The audience is re-resolved the way up() resolved it. A role granted
        // `roles:write` AFTER this migration ran never received these, so it has
        // nothing to take back; a role that LOST `roles:write` in between keeps
        // them, which is the conservative direction for a down() — it leaves an
        // operator holding a permission they may not need rather than removing
        // one they do.
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
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
                [':name' => $slug]
            );
        }

        $db->exec('DROP TABLE IF EXISTS user_groups CASCADE');
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
     * De-duplicated, because the two audiences for `groups:read` overlap on
     * every ordinary install (one `admin` role holds both), and inserting twice
     * would rely on the conflict clause to hide a bug in this method.
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
