<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateDocumentRouteTemplates (#1027) — the record a node-based flow EDITOR
 * edits: a reusable, BRANCHING route design, authored once and instantiated many
 * times.
 *
 * THIS IS THE SEAM MIGRATION 112 NAMED, TAKEN
 * -------------------------------------------
 * Migration 112's "what is deliberately deferred" section names three seams and
 * this migration fills the first of them, in the shape it specified: "a
 * `document_route_templates` / `document_route_template_steps` pair".
 *
 * `document_routes.document_id` is NOT NULL, so a route is always an INSTANCE —
 * one circulation of one document. The thing DESIGNED and the thing that
 * HAPPENED have different lifetimes, exactly as `document_templates` is a
 * different record from `documents`, and the append-only trail hangs off the
 * second.
 *
 * The other two seams are NOT taken here, and both omissions are deliberate:
 *
 *  - BRANCHING on the ENGINE side is migration 118's (#1014). This migration
 *    adds the AUTHORING side of the same idea and mirrors 118's vocabulary
 *    exactly rather than inventing a parallel one — see below.
 *
 *  - CONFIGURED EFFECTS ("send an email at this stage") are still absent, and
 *    migration 112 says why: "an effect declaration with no engine to run it is a
 *    stored intention that silently does nothing". There is still no effect
 *    engine. A `send_email` flag an author could tick and nothing would ever read
 *    is precisely the failure class the routing subsystem is written against, so
 *    it is filed rather than half-built.
 *
 * WHY TEMPLATE STEPS ARE A SECOND TABLE AND NOT A FLAG ON `document_route_steps`
 * ------------------------------------------------------------------------------
 * Because the two have different owners and different lifetimes. A template step
 * is edited — dragged, relabelled, deleted — for as long as the design is in use.
 * An instance step is written once when a document is issued and must never
 * change afterwards, because the trail's `step_id` points at it and an
 * append-only trail whose targets are mutable is not append-only in any useful
 * sense. One table with a nullable `document_id` would put an editable row and an
 * immutable row under one set of constraints, and the constraint that protects
 * the trail would have to be dropped to let the editor work.
 *
 * It also keeps this migration ADDITIVE in the strongest sense: it creates three
 * new tables and alters none. Nothing in migrations 108, 112 or 118 changes type,
 * nullability or meaning, so it cannot conflict with the engine work in flight
 * beside it.
 *
 * A STEP NAMES A RULE, NEVER A PERSON — AND A TEMPLATE MAKES THAT LOAD-BEARING
 * ---------------------------------------------------------------------------
 * `rule_kind` + `rule_config`, and NO profile column — the same shape, and for
 * the same reason, as `document_route_steps`. A template is where the argument
 * gets its sharpest test: a design authored in March and instantiated in November
 * must reach the people who hold the role in November. A stored roster would be
 * eight months stale, and it would still render, and still report success.
 *
 * So "all 1,000 instructors" is ONE row here, and stays one row. There is no
 * table in this migration that could hold a thousand of anything, which is not an
 * accident: the editor cannot materialise a group into a thousand nodes because
 * there is nowhere to put them.
 *
 * `position` IS AN AUTHORING ORDINAL — AND ALSO THE UNCONDITIONAL EDGE
 * --------------------------------------------------------------------
 * It is carried over from `document_route_steps` and means the same thing there
 * and here: a stable, unique-per-template handle for a step. It does NOT mean
 * "runs third".
 *
 * It carries one further meaning that migration 118 fixed for the engine and this
 * table therefore adopts: where a step has no edge for the verdict it received,
 * an approval falls through to THE NEXT AUTHORING ORDINAL. That is why there is
 * no "unconditional" edge row in the table below and no sentinel verdict meaning
 * "always". A plain linear route is a template with steps and no edges at all,
 * and the editor draws its forward arrows from `position` rather than storing
 * them — so the picture on the canvas and the path the engine will take are
 * derived from one fact instead of two that can disagree.
 *
 * THE VOCABULARY IS #1014's, MIRRORED — NOT A SECOND ONE
 * ------------------------------------------------------
 * Three names here are taken verbatim from migration 118 and the classes beside
 * it, because a template that spelled them differently would be a design the
 * engine could not run:
 *
 *   `decision`        BOOLEAN — is this step a GATE? Mirrors
 *                     `document_route_steps.decision`, default FALSE for the
 *                     same reason: a step that demands nothing is the ordinary
 *                     circulation step.
 *   `decision_quorum` `all` | `any` | `majority`, NULLABLE. Mirrors
 *                     `document_route_steps.decision_quorum`, including the
 *                     meaning of NULL — NOT "no quorum" but "ask the settings
 *                     chain", which is what lets a tenant change the rule for
 *                     every step at once.
 *   `verdict`         `approved` | `rejected`, NOT NULL on an edge. Mirrors
 *                     `document_route_edges.verdict`.
 *
 * {@see \Whity\Core\Document\RouteTemplate\RouteTemplateContract} is the single
 * PHP declaration of all three on the authoring side, and its docblock records
 * that it exists ONLY until #1014 is on the same branch, at which point it should
 * collapse into a direct reference to `RouteVerdict` and `RouteQuorum`.
 * {@see \Tests\Unit\Core\Document\RouteTemplate\RouteTemplateVocabularyTest}
 * reads BOTH migration sources and fails the moment the two disagree — so the
 * mirroring is checked by CI rather than by whoever remembers.
 *
 * WHAT "THE INSTRUCTORS NODE APPROVED" MEANS
 * -------------------------------------------
 * #1014 names this as the single most consequential decision in the feature, and
 * it is invisible until somebody's document is approved by one instructor out of
 * a thousand. The rule and its default (`all`, chosen for its failure mode) are
 * argued in {@see \Whity\Core\Document\Routing\RouteQuorum}; this migration adds
 * nothing to that argument and deliberately does not restate it as a second
 * default.
 *
 * What the AUTHORING side adds is the thing that makes the default survivable: an
 * editor that shows the RESOLVED COUNT beside the quorum, so an author leaving
 * `all` on a 1,043-person node reads "all 1,043 must approve" while authoring
 * rather than discovering it in November.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
final class CreateDocumentRouteTemplates
{
    /**
     * The two slugs this migration introduces.
     *
     * Descriptions are written for somebody reading a permission picker, so they
     * say what the permission LETS A PERSON DO rather than restating the slug.
     *
     * Separate from `documents:route` on purpose. Routing a document is an
     * everyday act many people perform; DESIGNING the flow every document of a
     * kind will follow is an act of organisational policy, and a clerk who may
     * send a form onward should not thereby be able to rewrite where every form
     * goes.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::ROUTE_TEMPLATES_READ =>
            'See the tenant\'s document route templates and how many people each stage currently reaches',
        CorePermissions::ROUTE_TEMPLATES_WRITE =>
            'Design, rename and delete document route templates — the reusable flows a document travels',
    ];

    /**
     * Which existing capability identifies the audience for each new slug.
     *
     * A capability rather than a role name, for the reason migration 110 gives: a
     * deployment running a custom administrative role silently LOSES a capability
     * on upgrade when the grant is keyed on the name `admin`, and a grant keyed on
     * a capability the deployment actually granted is the one that survives
     * whatever they called it.
     *
     * `route_templates:read` has two audiences because two different jobs need
     * it — the people who design flows, and the people who will pick one when
     * routing a document.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        CorePermissions::ROUTE_TEMPLATES_WRITE => [CorePermissions::ROLES_WRITE],
        CorePermissions::ROUTE_TEMPLATES_READ => [CorePermissions::ROLES_WRITE, CorePermissions::DOCUMENTS_ROUTE],
    ];

    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement per table, never a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest re-derive
        // their registries by scanning this source, so every name has to appear
        // literally. Migrations 059, 108, 112, 114, 116 and 118 carry the same
        // note, and spell the keyword hyphenated in prose for the same reason:
        // the schema test scans for the create keyword case-insensitively and
        // would read one inside a comment as a real table declaration.
        //
        // `name` is 160, matching `user_groups.name` (migration 116) and
        // `document_collections.name` (114): it is a label a person types into a
        // picker, and the width that fits one is the width worth storing. The API
        // refuses longer, so the column and the validator agree instead of one
        // truncating what the other accepted.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_templates (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name        VARCHAR(160) NOT NULL,
                description TEXT,
                created_by  INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, name)
            )
        ");

        // `rule_config` defaults to an empty JSON OBJECT rather than an empty
        // array: PHP cannot tell an empty map from an empty list, `[]` is not a
        // valid jsonb object, and a row that decoded to a list where every
        // resolver expects a map would fail at resolution time rather than at
        // write time. Migrations 112 and 116 make the same choice for the same
        // reason.
        //
        // `decision` and `decision_quorum` mirror the columns migration 118 adds
        // to `document_route_steps`, down to the default and to what NULL means.
        // See the class docblock: a template that spelled them differently would
        // be a design the engine could not run.
        //
        // `canvas_x` / `canvas_y` store where the author PUT the node. They are
        // presentation and nothing but the editor reads them — but they belong on
        // the row rather than in browser storage, because a flow is a SHARED
        // design and a colleague opening it should see the arrangement its author
        // made, not a re-computed layout that scrambles the meaning encoded in the
        // positions. INTEGER because a canvas coordinate is a pixel; sub-pixel
        // precision would be stored noise.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_template_steps (
                id              BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id       INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                template_id     BIGINT       NOT NULL REFERENCES document_route_templates(id) ON DELETE CASCADE,
                position        INTEGER      NOT NULL,
                rule_kind       VARCHAR(128) NOT NULL,
                rule_config     JSONB        NOT NULL DEFAULT '{}'::jsonb,
                label           VARCHAR(160),
                decision        BOOLEAN      NOT NULL DEFAULT FALSE,
                decision_quorum VARCHAR(16),
                canvas_x        INTEGER      NOT NULL DEFAULT 0,
                canvas_y        INTEGER      NOT NULL DEFAULT 0,
                created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (template_id, position),
                CHECK (decision_quorum IS NULL OR decision_quorum IN ('all', 'any', 'majority'))
            )
        ");

        // "This template's steps, in authoring order" is served outright by the
        // unique constraint's own index, so there is no second index on
        // (template_id, position). This one exists for the TENANT PREDICATE: every
        // read binds `tenant_id`, and the guard polices a step read directly
        // rather than trusting a join it cannot see.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_template_steps_tenant
                ON document_route_template_steps(tenant_id, template_id)'
        );

        // `verdict` is NOT NULL and CHECK-constrained to migration 118's two
        // values. There is no row here meaning "always go here": the
        // unconditional transition is the NEXT AUTHORING ORDINAL, which is what
        // the engine falls through to and therefore what the editor draws. See
        // the class docblock.
        //
        // `from_step_id <> to_step_id` refuses a self-loop at the schema. A step
        // that leads to itself is not a design anybody means to author; it is a
        // drag that landed back where it started, and catching it here means the
        // editor, the API and any future importer all get the same answer.
        //
        // Both endpoints CASCADE from the step, so deleting a node in the editor
        // takes its edges with it and cannot leave an arrow pointing at nothing.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_template_edges (
                id           BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id    INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                template_id  BIGINT      NOT NULL REFERENCES document_route_templates(id) ON DELETE CASCADE,
                from_step_id BIGINT      NOT NULL REFERENCES document_route_template_steps(id) ON DELETE CASCADE,
                to_step_id   BIGINT      NOT NULL REFERENCES document_route_template_steps(id) ON DELETE CASCADE,
                verdict      VARCHAR(16) NOT NULL,
                created_at   TIMESTAMP   NOT NULL DEFAULT NOW(),
                CHECK (from_step_id <> to_step_id),
                CHECK (verdict IN ('approved', 'rejected'))
            )
        ");

        // One destination per verdict per node — the same uniqueness migration 118
        // puts on `document_route_edges`. A second approve edge from the same step
        // is not a richer design, it is two answers to one question, and the
        // editor would draw two arrows for one transition.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_document_route_template_edges_from_verdict
                ON document_route_template_edges(from_step_id, verdict)'
        );

        // Reading a template draws every edge in it at once, so the index that
        // matters is by template — and it carries `tenant_id` first for the
        // predicate guard, as the steps index does.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_template_edges_tenant
                ON document_route_template_edges(tenant_id, template_id)'
        );

        foreach (self::PERMISSIONS as $name => $description) {
            // Migration 013 seeds the whole CorePermissions list, so on a fresh
            // install these rows already exist by the time this runs; the insert
            // is here so the migration stands on its own against a database whose
            // catalogue drifted, and it can never overwrite a human-written
            // description (ON CONFLICT DO NOTHING).
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
        // Grants first: `role_permissions` has a foreign key to `permissions`, so
        // a catalogue row cannot go while a grant still points at it.
        //
        // The audience is re-resolved the way up() resolved it. A role granted
        // `roles:write` AFTER this migration ran never received these, so it has
        // nothing to take back; a role that LOST `roles:write` in between keeps
        // them, which is the conservative direction for a down() — it leaves an
        // operator holding a permission they may not need rather than removing one
        // they do.
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

            // Only when nothing else holds it. Migration 013 seeded the catalogue
            // and owns its removal; this clause is the safety net for a database
            // where 013's catalogue step did not run.
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $slug]
            );
        }

        // Edges before steps before templates: each holds a foreign key into the
        // one after it.
        $db->exec('DROP TABLE IF EXISTS document_route_template_edges CASCADE');
        $db->exec('DROP TABLE IF EXISTS document_route_template_steps CASCADE');
        $db->exec('DROP TABLE IF EXISTS document_route_templates CASCADE');
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
     * De-duplicated, because the two audiences for `route_templates:read` overlap
     * on every ordinary install (one `admin` role holds both), and inserting twice
     * would rely on the conflict clause to hide a bug here.
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
