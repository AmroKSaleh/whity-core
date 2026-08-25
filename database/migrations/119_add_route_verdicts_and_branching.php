<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddRouteVerdictsAndBranching (#1014) — approval as a DISTINCT ACT: a verdict
 * on the trail event, a step that can demand one, and edges that send a
 * rejection somewhere an approval does not go.
 *
 * WHY THIS EXISTS
 * ---------------
 * Migration 112 gave routing one outcome per act. `acknowledged` means "I saw
 * this", and a route continues regardless of what the recipient thought — which
 * is exactly right for CIRCULATION and cannot express SIGN-OFF. #1014 states the
 * missing third line and it is the load-bearing one:
 *
 *     acknowledged  "I saw this"        -> the chain ends here
 *     approved      "I authorise this"  -> the route continues
 *     rejected      "I refuse this"     -> the route goes SOMEWHERE ELSE
 *
 * A rejection that merely records dissent and lets the document proceed is not
 * approval. The verdict has to change where the document goes, which is what the
 * edges table below is for.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DECISION 1: A VERDICT COLUMN, NOT TWO MORE ACTION VERBS
 * ─────────────────────────────────────────────────────────────────────────
 * The obvious move is to widen `document_route_events.action` with `approved`
 * and `rejected`. It is rejected for a reason about MEANING and one about
 * SAFETY, and either alone would be enough.
 *
 * MEANING. Every verb in migration 112's vocabulary names a fixed routing
 * effect: {@see \Whity\Core\Document\Routing\RouteAction} documents each one as
 * "what it does to the inbox". With a QUORUM (decision 3) that property cannot
 * hold for approval — the first approval of three closes one row and opens
 * nothing, the third closes one row and opens the next step. `approved` would be
 * the one member of a closed vocabulary whose effect is not determined by the
 * verb, which quietly retires the guarantee the vocabulary was closed to buy.
 * Two orthogonal facts want two columns: `action` is HOW THE ACTOR LEFT THE
 * STEP, `verdict` is WHAT THEY DECIDED, and the engine's routing is derived from
 * both.
 *
 * SAFETY. `document_route_events` is APPEND-ONLY and it is the system of record.
 * Widening an inline CHECK is `DROP CONSTRAINT` + `ADD CONSTRAINT` on
 * PostgreSQL — cheap — but SQLite (the offline/desktop engine, and the engine
 * {@see \Tests\Support\SchemaFromMigrations} builds the test schema on) has no
 * `ALTER TABLE … DROP CONSTRAINT` at all. The only way to widen an inline CHECK
 * there is the twelve-step rebuild: copy every row into a new table, drop the
 * original, rename. On the one table in this subsystem whose whole value is that
 * it is never rewritten. A new column with its own CHECK is a plain
 * `ADD COLUMN` on both engines, touches no existing row, and leaves every
 * historical event reading exactly as it did.
 *
 * The vocabulary is still CLOSED — `verdict` is CHECK-constrained to two values
 * and mirrored by {@see \Whity\Core\Document\Routing\RouteVerdict}, with
 * {@see \Tests\Core\Document\Routing\RouteVerdictVocabularyTest} pinning the two
 * together the way migration 112's action vocabulary is pinned. What is refused
 * here is a free-form outcome string, not a second constrained column.
 *
 * NULL ON EVERY PRE-EXISTING ROW, and that is the honest value: those acts
 * carried no verdict, and back-filling one would be inventing an authorisation
 * nobody gave. `verdict IS NULL` therefore reads as "this act said nothing about
 * approval", never as "not approved".
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DECISION 2: A STEP DECLARES WHETHER IT IS A GATE
 * ─────────────────────────────────────────────────────────────────────────
 * `document_route_steps.decision` says whether the step demands a verdict. It
 * cannot be inferred from the presence of an edge: an approval step at the END
 * of a route has no outgoing edge and still must demand one, and inferring it
 * would make deleting an edge silently turn a sign-off into a circulation.
 *
 * FALSE on every existing row and on every row nobody sets, so this migration
 * changes the behaviour of exactly zero routes that were authored before it.
 *
 * `decision_quorum` is the PER-STEP OVERRIDE and is NULLABLE, where NULL means
 * "whatever this tenant's setting resolves to" rather than a hardcoded value.
 * The full chain is step ?? per-tenant ?? global ?? registry default, which is
 * the platform's standing rule for a tunable with one extra layer on top: a
 * deployment that never sets anything gets the registry default, and a tenant
 * that sets one gets it everywhere without a single step being rewritten.
 *
 * Neither column touches `position`, which stays a unique 1-based AUTHORING
 * ORDINAL meaning exactly what migration 112 says it means.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * DECISION 3: THE EDGES TABLE MIGRATION 112 LEFT A SEAM FOR
 * ─────────────────────────────────────────────────────────────────────────
 * Migration 112 named this precisely: "an edges table (`from_step_id`,
 * `to_step_id`, condition) and one rewritten method,
 * `RouteStepRepository::findNext()`", and deliberately did not guess the
 * condition vocabulary because "it is the thing an editor constrains, and
 * inventing it before the editor exists is how it ends up with a verb the editor
 * cannot draw". The editor now exists (#1027) and the verb it draws is the
 * verdict, so that is what the column holds.
 *
 * `UNIQUE (from_step_id, verdict)` — one destination per verdict per node. Two
 * approve edges from one node is not a branch, it is an ambiguity, and the
 * engine would have to pick one by an ordering nobody authored.
 *
 * WHAT IS DELIBERATELY NOT IN THE VOCABULARY. There is no `forwarded` edge. A
 * plain forward still finds its successor by ORDINAL
 * ({@see \Whity\Core\Document\Routing\RouteStepRepository::findNext()}), so
 * every route authored before this migration behaves identically after it. An
 * edge kind for unconditional continuation would change the meaning of existing
 * rows the moment somebody drew one, and it belongs with the editor rather than
 * here. The seam for it is this table's CHECK, which is on a SMALL, NEW table —
 * rebuildable on SQLite at no risk, unlike the trail's.
 *
 * NO `document_id`. Every other routing table denormalises it so the tenant
 * predicate guard can police a read directly; an edge is not addressable except
 * through its route, is never listed per-document, and carrying a fourth
 * copy of the same fact would be a fourth place for it to be wrong.
 *
 * FOREIGN KEYS. `tenant_id`, `route_id`, `from_step_id` and `to_step_id` all
 * CASCADE, for the reason migration 112 gives: a route's structure is reachable
 * for deletion only through its document's own removal, and RESTRICT would make
 * a tenant undeletable the moment anything was routed.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE COHORT INDEX
 * ─────────────────────────────────────────────────────────────────────────
 * A quorum is evaluated over the recipient rows ONE act opened — the set sharing
 * a `created_by_event_id`. That is the cohort, it needs no new column because
 * migration 112 already made that pointer NOT NULL, and it keeps chains
 * independent: two chains reaching the same step each decide for themselves,
 * which is semantic 2 preserved rather than traded away.
 *
 * It was not an indexed access path, though, so one is added here. Without it
 * every act on a decision step is a scan of the tenant's whole recipient table.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class AddRouteVerdictsAndBranching
{
    public static function up(Database $db): void
    {
        // THE VERDICT. Nullable, and NULL on every row written before today —
        // "this act said nothing about approval", never "not approved". The
        // CHECK is inline on the ADD COLUMN so it lands on BOTH engines in one
        // statement: SQLite accepts a column constraint on an added column even
        // though it cannot alter an existing one, which is the whole reason this
        // is a new column rather than a widened vocabulary.
        $db->exec("
            ALTER TABLE document_route_events
                ADD COLUMN IF NOT EXISTS verdict VARCHAR(16)
                CHECK (verdict IS NULL OR verdict IN ('approved', 'rejected'))
        ");

        // #1014's two new derived folders: "approved by me" and "rejected by
        // me". The existing `(tenant_id, actor_profile_id, id)` index answers
        // "acted on by me" and would answer these too, at the cost of visiting
        // every act the person ever made to test the verdict — and the verdict
        // rows are the sparse minority of a busy trail. `actor_profile_id` leads
        // because both folders are asked about ONE person.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_events_tenant_verdict
                ON document_route_events(tenant_id, actor_profile_id, verdict)'
        );

        // IS THIS STEP A GATE? Default FALSE, so every route authored before
        // this migration keeps behaving exactly as it did. Read through
        // {@see \Whity\Core\Db\DbBool} rather than a bare cast, because the same
        // BOOLEAN comes back as bool(false) or as '0' depending on
        // ATTR_STRINGIFY_FETCHES.
        $db->exec(
            'ALTER TABLE document_route_steps
                 ADD COLUMN IF NOT EXISTS decision BOOLEAN NOT NULL DEFAULT FALSE'
        );

        // The per-step override. NULL is not "no quorum" — it is "ask the
        // settings chain", which is what lets a tenant change the rule for every
        // step at once. See the class docblock.
        $db->exec("
            ALTER TABLE document_route_steps
                ADD COLUMN IF NOT EXISTS decision_quorum VARCHAR(16)
                CHECK (decision_quorum IS NULL OR decision_quorum IN ('all', 'any', 'majority'))
        ");

        // NOTE: one literal create-table statement, never a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest re-derive
        // their registries by scanning this source, so the name has to appear
        // literally. Migrations 059, 108 and 112 carry the same note, and spell
        // the keyword hyphenated in prose for the same reason: the schema test
        // scans for the create keyword case-insensitively and would read one
        // inside a comment as a real table declaration.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_route_edges (
                id           BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id    INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                route_id     BIGINT      NOT NULL REFERENCES document_routes(id) ON DELETE CASCADE,
                from_step_id BIGINT      NOT NULL REFERENCES document_route_steps(id) ON DELETE CASCADE,
                to_step_id   BIGINT      NOT NULL REFERENCES document_route_steps(id) ON DELETE CASCADE,
                verdict      VARCHAR(16) NOT NULL,
                created_at   TIMESTAMP   NOT NULL DEFAULT NOW(),
                CHECK (verdict IN ('approved', 'rejected'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_route_edges_tenant_id ON document_route_edges(tenant_id)');
        // "the edges of this route", for reading a whole graph back into an
        // editor in one query.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_edges_tenant_route
                ON document_route_edges(tenant_id, route_id, from_step_id)'
        );
        // THE ENGINE'S OWN LOOKUP: "where does this verdict send this step?",
        // asked once per decided act.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_edges_tenant_from
                ON document_route_edges(tenant_id, from_step_id, verdict)'
        );
        // One destination per verdict per node. A second approve edge from the
        // same node is not a branch, it is an ambiguity the engine would have to
        // resolve by an ordering nobody authored.
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_document_route_edges_from_verdict
                ON document_route_edges(from_step_id, verdict)'
        );

        // THE COHORT. See the class docblock: a quorum is counted over the rows
        // one act opened, which is `created_by_event_id`, which had no index of
        // its own because nothing asked that question until now.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_route_recipients_tenant_cohort
                ON document_route_recipients(tenant_id, created_by_event_id, closed_by_event_id)'
        );
    }

    /**
     * Reverse everything, in dependency order.
     *
     * Dropping `verdict` LOSES the verdicts, and there is no way around that: a
     * verdict is a fact the events carry and nothing else records it. That is
     * the same trade migration 104 states for `profiles.auth_method` — a
     * migration that cannot be rolled back is worse than one that can — and the
     * loss is bounded to data this migration itself made storable. Nothing that
     * existed before it is touched.
     */
    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_document_route_recipients_tenant_cohort');

        $db->exec('DROP INDEX IF EXISTS uq_document_route_edges_from_verdict');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_edges_tenant_from');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_edges_tenant_route');
        $db->exec('DROP INDEX IF EXISTS idx_document_route_edges_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_route_edges CASCADE');

        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS decision_quorum');
        $db->exec('ALTER TABLE document_route_steps DROP COLUMN IF EXISTS decision');

        $db->exec('DROP INDEX IF EXISTS idx_document_route_events_tenant_verdict');
        $db->exec('ALTER TABLE document_route_events DROP COLUMN IF EXISTS verdict');
    }
}
