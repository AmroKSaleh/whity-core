<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDocuments (#947 item 1) — documents as RECORDS, and their rendered
 * output as IMMUTABLE ARTIFACTS.
 *
 * WHY THIS EXISTS
 * ---------------
 * Core has the template half of the document subsystem and nothing else.
 * Migration 059 gave it `document_templates` and `document_blocks`; the
 * designer edits them and `POST /api/document-templates/{id}/render` streams
 * a PDF straight back to the caller. Nothing is written, so nothing has an
 * identity: there is no id to route, no url to send, and no row to audit.
 *
 * Worse, the only way to see a document again is to render it again — from a
 * template that may have been edited, from blocks that may have been edited,
 * against data that may have moved. That is a DERIVATION, not a record. An
 * auditable document must be re-fetchable AS THE ARTIFACT THAT WAS ISSUED,
 * byte for byte, whatever has happened to the template since. Everything the
 * rest of #947 builds — routing (item 3) routes documents, the viewer (item 4)
 * views them, the browser (item 5) browses them — needs a durable thing with
 * an identity first.
 *
 * TWO TABLES, NOT ONE
 * -------------------
 * `documents`          — the RECORD. Stable identity, tenant, provenance
 *                        (which template, under whose name), who raised it,
 *                        from which unit, when.
 * `document_artifacts` — the BYTES. One APPEND-ONLY row per render that was
 *                        actually stored, naming its storage key, its size and
 *                        its SHA-256.
 *
 * The one-table shape (a `storage_key` column on `documents`) was the obvious
 * one and it cannot hold both halves of the requirement. A correction — the
 * same document re-rendered because a figure was wrong — has to either
 *
 *   (a) UPDATE the key, which destroys the artifact that was actually issued
 *       and circulated. The moment somebody most wants to overwrite the
 *       earlier PDF is precisely the moment it must survive: that is the same
 *       reasoning that makes item 3's event trail append-only, and it should
 *       not be decided differently one table apart; or
 *   (b) insert a SECOND document, which mints a new id — and every route step,
 *       recipient row and trail event item 3 will hang off the old id now
 *       points at the superseded thing, with nothing joining the two.
 *
 * Splitting them costs one join and buys both: the record keeps its identity
 * across corrections, and each set of bytes is written exactly once.
 *
 * WHAT IS DELIBERATELY ABSENT: A STATUS COLUMN
 * --------------------------------------------
 * There is no `status` on `documents`, and that is a decision rather than an
 * omission. Routing's state is what its append-only trail SAYS happened; a
 * status column beside it is a second copy of that answer which can disagree
 * with the trail, and the copy is the one screens read. #947 rejects a stored
 * folder tree in item 5 for exactly this reason — a derivable fact that is
 * stored instead has to be maintained, and drifts when it is not. Item 3 is
 * free to add its own columns or tables; it should not inherit a half-defined
 * lifecycle vocabulary guessed at here, before its rule kinds exist.
 *
 * THE SEAM LEFT FOR ROUTING AND THE BROWSER
 * -----------------------------------------
 * `documents.id` is the only thing item 3 needs: `routes`, `route_steps`, the
 * event trail and `recipients` all key off it with an ordinary
 * `REFERENCES documents(id) ON DELETE CASCADE`.
 *
 * `origin_ou_id` is here rather than left to item 3 because it is a fact about
 * the RAISING, not about the routing: it is true of a document that is never
 * routed at all, and it is the answer to the browser's first derived folder
 * ("raised by my unit" = origin unit within my subtree). Capturing it later
 * would mean back-filling it from the creator's membership as it stands THEN,
 * which is a different fact — people move units.
 *
 * FOREIGN KEYS, AND THE `ON DELETE` EACH ONE GETS
 * -----------------------------------------------
 * Every column here that names another table carries a constraint. #751 landed
 * because two core tables named a profile with none, and
 * scripts/ci-undeclared-reference-guard.php now lints core's own migrations for
 * it. The actions differ per column and none of them is a default:
 *
 *  - `tenant_id → tenants ON DELETE CASCADE`. What every tenant-owned table
 *    does; removing a tenant removes its data.
 *
 *  - `document_template_id → document_templates ON DELETE SET NULL`, NULLABLE.
 *    CASCADE here would be a data-loss bug wearing the right-looking keyword:
 *    tidying the template library would silently delete every document ever
 *    issued from those templates, which is the one outcome this whole table
 *    exists to prevent. RESTRICT would be safe and unusable — a template used
 *    once in 2026 could never be retired. SET NULL keeps the artifact and
 *    loses only the pointer, and `template_name` below is why that is
 *    survivable rather than merely non-fatal.
 *
 *  - `origin_ou_id → organizational_units ON DELETE SET NULL`, NULLABLE. The
 *    same choice `memberships.ou_id` (migration 030) makes, for the same
 *    reason: a reorganisation dissolves units routinely and must not delete
 *    the records those units raised.
 *
 *  - `created_by → profiles ON DELETE SET NULL`, NULLABLE — and this one
 *    deliberately DISAGREES with migration 105, which cascaded. That is not an
 *    inconsistency; the two columns hold different things. 105 is about
 *    `notifications.subject`/`body`: free text written ABOUT a specific person,
 *    where keeping the row and nulling the pointer preserves every private word
 *    of it and removes only the means of finding it. A document is an
 *    ORGANISATIONAL record that outlives its author — an invoice raised by an
 *    employee who has since left is still the organisation's invoice, and
 *    cascading would let a leaver's departure quietly delete part of the
 *    tenant's audit history. The pointer IS the personal datum core stores
 *    here, so nulling it is the erasure, not an evasion of it.
 *
 *  - `document_artifacts.document_id → documents ON DELETE CASCADE`. An
 *    artifact is meaningless without its record; nothing else names it.
 *
 *  - `document_artifacts.rendered_by → profiles ON DELETE SET NULL`, as above.
 *
 * Note what CASCADE does NOT do: it does not delete the BYTES. A cascade
 * reclaims rows, and the object in storage outlives them. Reclaiming storage
 * is the operator's sweep to run against `document_artifacts`, and doing it in
 * the reverse order (drop the row, then the bytes are unreachable) is why the
 * key is recorded on the row at all.
 *
 * `tenant_id` ON THE ARTIFACT TOO
 * -------------------------------
 * `document_artifacts.tenant_id` is denormalised from its parent document. It
 * is redundant by construction and it is what lets the CI tenant-predicate
 * guard police artifact reads directly rather than trusting a join — the same
 * trade `notification_deliveries`, `entity_tags` and `event_outbox` already
 * make, each recorded in {@see \Whity\Core\Tenant\TenantOwnedTables}. Both
 * tables are registered there.
 *
 * WHY THERE IS NO `revision` COLUMN
 * ---------------------------------
 * An explicit per-document revision number reads well and is a read-then-write
 * race: two concurrent renders both compute max+1 and one loses. Fixing that
 * properly means a counter — which core has, in `sequence_counters` (migration
 * 092) — for an ordering the surrogate key already provides for free. Artifacts
 * order by `id`; the newest is the current one, and `rendered_at` is what a
 * viewer shows.
 *
 * IMMUTABILITY, AND WHERE IT IS ENFORCED
 * --------------------------------------
 * `UNIQUE (storage_key)` is the schema's half: a key is claimed exactly once,
 * so a second write to the same address cannot be recorded even if some future
 * caller tries. The other half is in
 * {@see \Whity\Core\Document\DocumentArtifactStore}, which mints a fresh random
 * key per artifact and refuses to write over an existing object, and in
 * {@see \Whity\Core\Document\DocumentArtifactRepository}, which exposes no
 * update or delete path at all. `checksum_sha256` makes the guarantee
 * checkable after the fact rather than merely asserted.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateDocuments
{
    public static function up(Database $db): void
    {
        // NOTE: one literal CREATE TABLE per table, not a loop over interpolated
        // names — TenantOwnedTablesTest and CoreTablesTest re-derive their
        // registries by scanning this source, so the names must appear literally.
        // Migration 059 carries the same note for the same reason.
        $db->exec("
            CREATE TABLE IF NOT EXISTS documents (
                id                   BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id            INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_template_id BIGINT        REFERENCES document_templates(id) ON DELETE SET NULL,
                template_name        VARCHAR(255)  NOT NULL,
                title                VARCHAR(255)  NOT NULL,
                origin_ou_id         INTEGER       REFERENCES organizational_units(id) ON DELETE SET NULL,
                created_by           INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                created_at           TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");

        // Every read starts from the tenant (the predicate guard requires it),
        // so the bare tenant index serves the list and the composites serve the
        // two derived folders that already exist as facts: "raised by me" and
        // "raised by my unit" (#947 item 5 adds the subtree expansion on top —
        // it needs an index on this column, not a new table).
        $db->exec('CREATE INDEX IF NOT EXISTS idx_documents_tenant_id ON documents(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_documents_tenant_created_by ON documents(tenant_id, created_by)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_documents_tenant_origin_ou ON documents(tenant_id, origin_ou_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_documents_tenant_template ON documents(tenant_id, document_template_id)');

        // `storage_key` is the opaque, tenant-prefixed address the configured
        // storage driver understands ({@see \Whity\Storage\StorageKey}) — never a
        // filesystem path and never a bucket URL, so it stays correct when a
        // tenant is moved between backends. 512 chars is well past what
        // StorageKey::build() can produce for this subsystem.
        //
        // `content_type` is stored rather than assumed: the render service emits
        // PDF today and #947 item 6 adds tabular exports, and a viewer that
        // guesses the type from the extension is a viewer that guesses wrong once.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_artifacts (
                id              BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id       INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id     BIGINT        NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                storage_key     VARCHAR(512)  NOT NULL,
                content_type    VARCHAR(128)  NOT NULL,
                byte_size       BIGINT        NOT NULL,
                checksum_sha256 VARCHAR(64)   NOT NULL,
                rendered_by     INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                rendered_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (storage_key)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_artifacts_tenant_id ON document_artifacts(tenant_id)');
        // The two reads that exist: "this document's artifacts, newest first"
        // and the artifact-by-id fetch, both entered through the tenant.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_artifacts_tenant_document
                ON document_artifacts(tenant_id, document_id, id)'
        );
    }

    public static function down(Database $db): void
    {
        // Children first: `document_artifacts` names `documents`. CASCADE on the
        // DROP covers it on PostgreSQL, but SQLite (the test-schema engine) has
        // no such clause, and ordering costs nothing.
        $db->exec('DROP INDEX IF EXISTS idx_document_artifacts_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_document_artifacts_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_artifacts CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_template');
        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_origin_ou');
        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_created_by');
        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_id');
        $db->exec('DROP TABLE IF EXISTS documents CASCADE');
    }
}
