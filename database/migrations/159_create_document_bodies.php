<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDocumentBodies (WHIT-679) — a document gets a body of its own, so it
 * can be DRAFTED and REVISED before it is issued.
 *
 * WHAT WAS MISSING
 * ----------------
 * Migration 108 made a `documents` row the RECORD of something already issued,
 * and migration 118 gave it the values it was raised with. Neither gave it
 * CONTENT. A document's content lived on its TEMPLATE, and the only thing the
 * document itself held was an immutable rendered artifact. So a document came
 * into existence already finished: there was no state in which somebody could
 * write it, read it back, change a paragraph and only then send it.
 *
 * Downstream products worked around this by keeping the real thing — a letter,
 * a set of minutes — in their own tables and creating a core document only at
 * approval time, purely to carry the routing. The document was a vessel rather
 * than the thing. This table is what lets it be the thing.
 *
 * A SIDE TABLE, NOT A COLUMN ON `documents`
 * -----------------------------------------
 * Two reasons, and neither is tidiness:
 *
 *  - The record stays lean. `documents` is read on every list, every routing
 *    query and every browse; a body is read when somebody opens ONE document.
 *    A wide JSON column on the hot table is paid for by every query that does
 *    not want it.
 *  - Versions (WHIT-685) need somewhere to live. A history table keyed by
 *    `document_id` is the obvious neighbour of a current-body table keyed the
 *    same way; a column on `documents` would have nothing to be the history OF.
 *
 * HOW DRAFT-NESS IS REPRESENTED, AND WHY IT IS NOT A STATUS COLUMN
 * -----------------------------------------------------------------
 * Migration 108 refuses a `status` on `documents`, because routing's state is
 * what its append-only trail SAYS happened and a stored copy beside it is a
 * second answer that drifts. That decision is kept here in full: this migration
 * adds NOTHING to `documents`. Draft-ness is a property of the body.
 *
 *   no row here             an ordinary template-rendered document. Behaves
 *                           exactly as it did before this table existed.
 *   row, `issued_at` NULL   a DRAFT: editable, and not yet a document anybody
 *                           has been sent.
 *   row, `issued_at` set    issued. The body is frozen from that moment.
 *
 * Because "no row" is the pre-existing case, EVERY document that already exists
 * keeps its exact current behaviour and NO BACKFILL IS NEEDED. That is the main
 * practical argument for putting the flag here rather than on the record: an
 * `issued_at` on `documents` would have been NULL for every historical row,
 * making the entire existing corpus read as draft until a data migration fixed
 * it — a data change riding along inside a structural one, which this project's
 * migration rules separate deliberately.
 *
 * `issued_at` is a timestamp recording an EVENT, not a mutable status word. It
 * has two states, it is written once, and nothing has to maintain it. A status
 * enum invites a third value next quarter and a disagreement with the trail;
 * "when did this happen" cannot disagree with anything.
 *
 * THE DERIVATION THAT LOOKS CORRECT AND IS NOT
 * ---------------------------------------------
 * The tempting version adds no column at all: a document is issued once it HAS
 * AN ARTIFACT. It is wrong, and quietly.
 * {@see \Whity\Core\Document\DocumentIssuer} records that
 * `documents.render_enabled` defaults to FALSE — on a fresh install the render
 * container does not exist, no document can ever have an artifact, and that
 * class explicitly calls a record with no artifact "an ORDINARY state of this
 * system, not a degraded one". Deriving issued-ness that way would mark every
 * document on a default install as a permanent draft. Having bytes and having
 * been issued are different facts, and the render tier is optional.
 *
 * THE BODY SHAPE IS `FlowContent`, REUSED
 * ---------------------------------------
 * `content` is a JSON LIST of flow blocks — the same shape
 * `packages/ui/src/documents/flow.ts` declares and the render service already
 * paginates (heading, paragraph, table, figure, pageBreak, spacer, and the
 * inline runs flow gained in WHIT-681). Inventing a second body format would
 * mean two things to validate, two to render and two to export, and the render
 * service would have to learn the new one before a draft could ever be printed.
 *
 * Stored as JSONB for the same reason `documents.variable_data` is, and with
 * the same caveat worth repeating: PostgreSQL stores JSONB parsed and does NOT
 * preserve a JSON OBJECT's key order, while the SQLite engine the unit suite
 * builds on stores this column as TEXT, verbatim. Anything that compares this
 * column order-sensitively passes on one engine and reds the dialect shard on
 * the other. Compare bodies BY VALUE. Array order — the sequence of blocks — is
 * preserved on both, and that is the half that matters: block 3 is the third
 * thing on the page.
 *
 * NOT NULL, because a body row exists only once somebody has written a body. An
 * empty document is `[]`, which is a real and different thing from "this
 * document has no body row at all" — the latter is the template-rendered case
 * above, and collapsing the two would make a draft indistinguishable from a
 * classic document.
 *
 * FOREIGN KEYS AND THEIR `ON DELETE`
 * ----------------------------------
 *  - `document_id -> documents ON DELETE CASCADE`, and it is also the PRIMARY
 *    KEY. One current body per document, enforced by the schema rather than by
 *    a repository that remembers to check: an upsert cannot accidentally create
 *    a second. A body is meaningless without its document and nothing else
 *    names it, so it goes when the document goes — the same choice
 *    `document_artifacts` makes.
 *  - `tenant_id -> tenants ON DELETE CASCADE`, denormalised from the parent
 *    document. Redundant by construction, and it is what lets the CI
 *    tenant-predicate guard police body reads directly instead of trusting a
 *    join — the trade `document_artifacts`, `notification_deliveries` and
 *    `entity_tags` already make. Registered in
 *    {@see \Whity\Core\Tenant\TenantOwnedTables}.
 *  - `updated_by -> profiles ON DELETE SET NULL`. A document is an
 *    organisational record that outlives its author, exactly as migration 108
 *    argues for `created_by`: a leaver's departure must not delete part of the
 *    tenant's history, and nulling the pointer IS the erasure of the personal
 *    datum rather than an evasion of it.
 *
 * ONE INDEX, on `tenant_id`. The primary key already serves the only other read
 * ("the body of this document"), which is how every caller arrives — nobody
 * lists bodies. The tenant index exists because the predicate guard requires
 * every read to bind `tenant_id`, so that column is in every WHERE clause.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateDocumentBodies
{
    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement, not an interpolated name —
        // TenantOwnedTablesTest and CoreTablesTest re-derive their registries by
        // scanning this source, so the name must appear literally. Migrations
        // 059 and 108 carry the same note, and spell the keyword in lowercase
        // in prose for the same reason this does: MigrationSchemaTest scans for
        // the create keyword and would read a capitalised one in a comment as a
        // real table declaration.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_bodies (
                document_id BIGINT    NOT NULL PRIMARY KEY REFERENCES documents(id) ON DELETE CASCADE,
                tenant_id   INTEGER   NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                content     JSONB     NOT NULL,
                issued_at   TIMESTAMP,
                updated_at  TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_by  INTEGER   REFERENCES profiles(id) ON DELETE SET NULL
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_bodies_tenant_id ON document_bodies(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_document_bodies_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_bodies');
    }
}
