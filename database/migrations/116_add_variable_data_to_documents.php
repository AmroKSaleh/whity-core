<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddVariableDataToDocuments (#947 item 1, completing it) — the values a
 * document was RAISED WITH.
 *
 * WHY A DOCUMENT NEEDED THIS TO BE CREATABLE AT ALL
 * -------------------------------------------------
 * Migration 108 gave documents an identity and its docblock closes with the
 * seam it left for routing and the browser. It left one more, unnamed: a
 * template carries PLACEHOLDERS (`{{reference}}`, `{{date}}` — see the
 * `placeholders` array inside `document_templates.data`), and instantiating one
 * means supplying values for them. Until now the only way to bring a document
 * into existence was `POST /api/document-templates/{id}/render` with
 * `persist: true`, where those values arrive as the render request's `dataRows`,
 * are interpolated into the PDF, and are then GONE — nothing on either table
 * records them.
 *
 * That is survivable only for as long as the artifact is guaranteed to exist,
 * and it is not. `documents.render_enabled` defaults to FALSE, so on a fresh
 * install the render container is absent and a persisted render is a 503: the
 * one existing create path cannot run at all. A create path that works without
 * the render tier has to put the values SOMEWHERE, or the record it writes is a
 * title and a template pointer — a document with no content, which the routing
 * engine would then circulate.
 *
 * The second failure is quieter and worse. `POST /api/documents/{id}/render`
 * (the correction path) falls back to the template's own placeholder SAMPLES
 * when the request supplies no `dataRows` — `DEMO-0001`, `2026-01-15`. So
 * correcting a document a month after it was issued, from a client that did not
 * keep the original values, silently reissues it with sample text where the real
 * reference number was, and the correction looks like a success. Storing the
 * values is what makes a correction a correction.
 *
 * WHY IT IS A FACT ABOUT THE RAISING, AND THEREFORE IMMUTABLE
 * -----------------------------------------------------------
 * This column sits beside `template_name` and `origin_ou_id` and is governed by
 * the same rule they are: it records what was true WHEN THE DOCUMENT WAS
 * RAISED, and nothing rewrites it afterwards. {@see \Whity\Core\Document\DocumentIssuer::appendArtifact()}
 * already states that a correction modifies no part of the document row — not
 * its title, not its provenance, not its `created_at` — and the values it was
 * raised with are provenance. A caller who supplies different `dataRows` to a
 * re-render gets those bytes, in a new artifact, and this column still says what
 * the document was raised with. That is the only reading under which the row and
 * the artifact history cannot contradict each other.
 *
 * It is emphatically NOT a status column. Migration 108 refuses one because
 * routing's state is derivable from an append-only trail and a stored copy
 * drifts from it. Nothing derives these values: they were typed by a person, and
 * the only other place they exist is baked into PDF bytes as pixels. There is no
 * second source to disagree with.
 *
 * WHY THE SHAPE IS `dataRows` VERBATIM
 * ------------------------------------
 * JSONB holding exactly what the render path accepts — a LIST of flat
 * string=>string maps, one per row — rather than a single flattened
 * key/value object. Two reasons, and neither is generality for its own sake:
 *
 *  - A template may be a LABEL SHEET. #947 item 2's serialized device labels
 *    are one document of N rows, and a single-map column would either lose all
 *    but the first row or need a second table to hold the rest.
 *  - It round-trips into {@see \Whity\Core\Document\Render\VariableData} with no
 *    transformation. A stored shape that has to be reassembled before it can be
 *    rendered is a shape two pieces of code can reassemble differently, and the
 *    render of a correction would then differ from the render of the original
 *    for reasons no column records.
 *
 * NULLABLE, and null is not the same as `[]`. NULL means "this document predates
 * the column, or was raised through a path that recorded nothing" — every row
 * migration 108 through 115 wrote, and every row the demo seeder wrote. `[]` and
 * `[{}]` mean "raised with no values", which a template with no placeholders
 * legitimately is. The re-render path reads the difference: NULL falls back to
 * the template's samples exactly as it did before this column existed (so no
 * existing document changes behaviour), and a recorded value is used instead.
 *
 * NO INDEX. Nothing queries on it — it is read only by id, alongside the row it
 * belongs to, and a GIN index on JSONB that no predicate touches is write cost
 * for nothing. The four indexes migration 108 declared are still the four reads
 * that exist.
 *
 * `documents` is already registered in {@see \Whity\Core\Tenant\TenantOwnedTables},
 * and this adds no table, so the tenant-predicate guard's registry is unchanged.
 *
 * Idempotent (ADD COLUMN IF NOT EXISTS) and reversible via down().
 */
class AddVariableDataToDocuments
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS variable_data JSONB');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS variable_data');
    }
}
