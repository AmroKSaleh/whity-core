<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateForms — tenant-authored forms, the fields that compose them, and the
 * submissions people make against them.
 *
 * WHAT THIS IS FOR
 * ----------------
 * An organisation needs to collect structured information from its own people
 * and from outsiders: a request, an application, a declaration, a return. Today
 * that is done by mailing a spreadsheet around, and the reason it is done that
 * way is not that nobody thought of a form — it is that a form on its own is a
 * dead end. Somebody still has to circulate it, approve it, file it and be able
 * to prove later what was submitted and when.
 *
 * So the interesting decision in this migration is not the three tables. It is
 * `form_submissions.document_id`.
 *
 * WHY A SUBMISSION POINTS AT A DOCUMENT INSTEAD OF GROWING ITS OWN LIFECYCLE
 * --------------------------------------------------------------------------
 * Everything a submitted form needs after the moment of submission already
 * exists in this codebase, built for documents:
 *
 *   - CIRCULATION AND APPROVAL — `document_routes` + `document_route_steps` +
 *     the append-only `document_route_events` trail (migrations 112/118/119/125),
 *     with quorums, branching on verdict, and a satisfaction rule. Reusable
 *     branching designs live in `document_route_templates` (migration 120).
 *   - AN INBOX — `DocumentRoutingInboxSource` already feeds `src/Core/Inbox/`,
 *     so a submission awaiting somebody lands in front of them with no new
 *     surface (#978/#947 item 5 refused routing a screen of its own precisely
 *     so this stays one inbox).
 *   - VERIFIABILITY — `document_qr_tokens` / `document_qr_scans` (migration 122).
 *   - IMMUTABLE ARTIFACTS — `document_artifacts`, one row per rendering, the
 *     previous one never touched (migration 108).
 *   - ROW-LEVEL VISIBILITY — `DocumentVisibilityPolicy` + `DocumentAccessPolicy`,
 *     including the `documents:read:all` escape hatch and origin-unit folders.
 *
 * A `form_submissions.status` column would have been the beginning of a second,
 * poorer copy of all of it. Worse, it would have been a copy that DISAGREES:
 * `documents` deliberately has NO status column (migration 108) because state
 * is DERIVED from the append-only trail, and a submission carrying its own
 * status would reintroduce exactly the mutable-state-beside-an-append-only-trail
 * shape the routing subsystem was written against. There is no status column
 * here either, and for the same reason.
 *
 * So a submission is a document plus the values that produced it. The pointer is
 * NULLABLE, which is not hedging — see below.
 *
 * WHY `document_id` IS NULLABLE
 * ------------------------------
 * Two ordinary states need it:
 *
 *   1. A submission to a form nobody has attached a route template to is still a
 *      submission. It is recorded, it is listable, its values are queryable. It
 *      simply is not circulating. Requiring a document row would mean minting a
 *      record for the sole purpose of satisfying a foreign key.
 *   2. `ON DELETE SET NULL` rather than CASCADE: destroying the document must
 *      not destroy the evidence of what a person submitted. A submission whose
 *      document has gone still says who submitted what and when, which is the
 *      part that matters to the person who submitted it.
 *
 * The row therefore records the SUBMISSION as the durable fact and the document
 * as the (usual, but not guaranteed) vehicle.
 *
 * WHY `form_version` IS COPIED ONTO THE SUBMISSION
 * ------------------------------------------------
 * A form is edited. Fields are added, renamed, made required. A submission read
 * back through TODAY's field list is a submission read through the wrong lens:
 * a field added last week appears as "missing" on a submission made last month,
 * and a renamed `field_key` makes an answer vanish entirely.
 *
 * `forms.version` increments on publish, and the submission stamps the version
 * it was made against. That does not by itself reconstruct the old field list —
 * `form_fields` is edited in place, so it cannot — and pretending otherwise
 * would be worse than admitting it. What the stamp DOES give is the ability to
 * say "this was answered against version 3" beside an answer that no longer
 * lines up, so a reader knows they are looking at drift rather than at a bug.
 * A full point-in-time field snapshot is a real feature and a bigger one; this
 * column is the seam it would attach to, not a half-built version of it.
 *
 * WHY `prefill_source` IS A STRING AND NOT A FOREIGN KEY
 * ------------------------------------------------------
 * It names a RULE for reaching the submitter's own saved information
 * ({@see \Whity\Core\Form\PrefillSource}), resolved server-side at render time
 * against whoever is looking. It never names a person and never stores a value.
 *
 * That is the same argument `document_route_steps.rule_kind` makes about
 * audiences, applied to values instead: a form authored in March and filled in
 * in November must show November's job details, and a stored copy would show
 * March's — silently, and while still rendering. There is nothing in this table
 * that could hold a snapshot of somebody's contact details, which is not an
 * accident.
 *
 * NAME AND LABEL ARE `TEXT` HOLDING JSON, NOT `JSONB`
 * ----------------------------------------------------
 * Deliberate, and the odd one out in this migration — `options` and `validation`
 * below ARE jsonb. The difference is what reads them. `name` and `label` hold a
 * `{ar, en}` localized string that is only ever fetched whole and handed to a
 * renderer; nothing filters or indexes inside it. `options` and `validation` are
 * inspected by the server on every submit, which is what jsonb is for.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down(). It creates three tables
 * and alters none, so it cannot conflict with work in flight beside it. The
 * permission catalogue and its grants are migration 128's, kept separate so a
 * deployment can see the schema change and the authorization change as two
 * reviewable acts.
 */
final class CreateForms
{
    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement per table, never a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest re-derive
        // their registries by scanning this source, so every name has to appear
        // literally. Migrations 059, 108, 112, 114, 116, 118 and 120 carry the
        // same note, and spell the keyword hyphenated in prose for the same
        // reason: the schema test scans for the create keyword case-insensitively
        // and would read one inside a comment as a real table declaration.
        //
        // `form_key` is the stable identifier code and links bind to, and it is
        // 128 rather than 160 because it is a slug somebody types into a URL, not
        // a label they type into a picker. `name` is TEXT because it carries a
        // localized `{ar, en}` object (see the class docblock) and the width of
        // one is the sum of two labels plus JSON punctuation, which is not a
        // number worth guessing at.
        //
        // `status` is CHECK-constrained rather than left to the application. It
        // has exactly three values, all three are meaningful to a reader of the
        // table, and a fourth arriving from a bad write is the kind of thing that
        // renders fine and behaves wrongly.
        //
        // `version` starts at 1 and only ever increases; see the class docblock
        // for what the submission-side stamp does and does not promise.
        $db->exec("
            CREATE TABLE IF NOT EXISTS forms (
                id                    BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id             INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                form_key              VARCHAR(128) NOT NULL,
                name                  TEXT         NOT NULL,
                description           TEXT,
                status                VARCHAR(16)  NOT NULL DEFAULT 'draft',
                version               INTEGER      NOT NULL DEFAULT 1,
                route_template_id     BIGINT       REFERENCES document_route_templates(id) ON DELETE SET NULL,
                created_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, form_key),
                CHECK (status IN ('draft', 'published', 'archived')),
                CHECK (version >= 1)
            )
        ");

        // "This tenant's forms, and which of them are published" is the list
        // every catalogue read asks for, and it starts from `tenant_id` because
        // the predicate guard requires every read to bind one.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_forms_tenant_status ON forms(tenant_id, status)');

        // `route_template_id` is how a form says where its submissions GO. It is
        // a pointer at migration 120's authoring-side record and nothing more —
        // no routing logic lives in this subsystem, and none should. A form with
        // no template collects; a form with one collects AND circulates.
        //
        // ON DELETE SET NULL rather than RESTRICT: deleting a flow design is an
        // ordinary act of housekeeping and it must not be blocked by a form that
        // happens to name it. The form then behaves exactly like one that never
        // had a template, which is a defined state rather than a broken one.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_forms_tenant_route_template
                ON forms(tenant_id, route_template_id)'
        );

        // `options` defaults to an empty JSON ARRAY and `validation` to an empty
        // JSON OBJECT, and the asymmetry is the point. Options are an ordered
        // LIST of choices; a rule set is a MAP of named rules. PHP cannot tell an
        // empty map from an empty list, so a column that defaulted both to the
        // same literal would hand one of the two readers a value it has to
        // special-case. Migrations 112, 116 and 120 make the map half of this
        // choice for the same reason.
        //
        // `field_type` is CHECK-constrained to the ten kinds
        // {@see \Whity\Core\Form\FieldType} declares. The constraint and the PHP
        // whitelist are two spellings of one fact, which is a real duplication —
        // FieldTypeTest reads this file and fails the moment they disagree, so
        // the duplication is checked rather than trusted.
        //
        // `prefill_source` is nullable and unconstrained at the schema: the
        // vocabulary is a code-side registry that plugins may extend, and a CHECK
        // listing today's five would have to be altered by a migration every time
        // one is added. An unknown source resolves to no value and says so, which
        // is the same failure a CHECK would produce minus the schema change.
        //
        // There is deliberately NO `UNIQUE (form_id, position)`, and this is the
        // one place this migration departs from migration 120's shape. A template
        // step's ordinal is assigned once by an editor that rebuilds the set; a
        // field's position is DRAGGED, repeatedly, and a non-deferrable unique
        // index turns a two-field swap into a three-statement dance through a
        // sentinel value. Reads order by `position ASC, id ASC` so the sequence
        // stays total even when two fields tie — the same explicit tie-break
        // {@see \Whity\Core\Ou\PrimaryMembershipOu} spells out, and for the same
        // reason: an arbitrary row order is stable in one database and different
        // in a restore of it.
        $db->exec("
            CREATE TABLE IF NOT EXISTS form_fields (
                id             BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id      INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                form_id        BIGINT       NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                field_key      VARCHAR(128) NOT NULL,
                field_type     VARCHAR(32)  NOT NULL,
                label          TEXT         NOT NULL,
                help_text      TEXT,
                is_required    BOOLEAN      NOT NULL DEFAULT FALSE,
                options        JSONB        NOT NULL DEFAULT '[]'::jsonb,
                validation     JSONB        NOT NULL DEFAULT '{}'::jsonb,
                prefill_source VARCHAR(128),
                section_key    VARCHAR(128),
                position       INTEGER      NOT NULL DEFAULT 0,
                created_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (form_id, field_key),
                CHECK (field_type IN (
                    'text', 'textarea', 'number', 'date', 'select',
                    'multiselect', 'checkbox', 'file', 'profile_ref', 'ou_ref'
                ))
            )
        ");

        // Reading a form draws every field in it at once, so the index that
        // matters is by form — and it carries `tenant_id` first for the predicate
        // guard, exactly as migration 120's step and edge indexes do: every read
        // binds `tenant_id`, and the guard polices a field read directly rather
        // than trusting a join it cannot see.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_fields_tenant_form
                ON form_fields(tenant_id, form_id, position)'
        );

        // `data` holds the answers, keyed by `field_key`. jsonb rather than TEXT
        // because a submission IS queried into: "every submission where the
        // request kind was X" is the first question anybody asks of a table like
        // this, and it is a jsonb containment query rather than an application
        // that fetches every row and filters in PHP.
        //
        // `submitted_at` is separate from `created_at` even though they are equal
        // on every row this code writes. They answer different questions — when
        // the person says they submitted, versus when this row was written — and
        // an import of historical submissions needs to set the first without
        // lying about the second. Migration 108 keeps the same distinction
        // between a document's `created_at` and its artifact's `rendered_at`.
        $db->exec("
            CREATE TABLE IF NOT EXISTS form_submissions (
                id                      BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id               INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                form_id                 BIGINT      NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                form_version            INTEGER     NOT NULL DEFAULT 1,
                submitted_by_profile_id INTEGER     REFERENCES profiles(id) ON DELETE SET NULL,
                document_id             BIGINT      REFERENCES documents(id) ON DELETE SET NULL,
                data                    JSONB       NOT NULL DEFAULT '{}'::jsonb,
                submitted_at            TIMESTAMP   NOT NULL DEFAULT NOW(),
                created_at              TIMESTAMP   NOT NULL DEFAULT NOW()
            )
        ");

        // The three reads this table serves, each starting from `tenant_id`:
        // "submissions to this form", "submissions I made", and the reverse
        // lookup from a document back to the submission that produced it — which
        // is what lets a document viewer say what was answered, rather than only
        // that something was.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_tenant_form
                ON form_submissions(tenant_id, form_id, submitted_at DESC)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_tenant_submitter
                ON form_submissions(tenant_id, submitted_by_profile_id, submitted_at DESC)'
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_submissions_tenant_document
                ON form_submissions(tenant_id, document_id)'
        );
    }

    public static function down(Database $db): void
    {
        // Submissions before fields before forms: each holds a foreign key into
        // the one after it.
        $db->exec('DROP TABLE IF EXISTS form_submissions CASCADE');
        $db->exec('DROP TABLE IF EXISTS form_fields CASCADE');
        $db->exec('DROP TABLE IF EXISTS forms CASCADE');
    }
}
