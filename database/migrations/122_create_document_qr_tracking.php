<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateDocumentQrTracking (#1036) — the CODE printed on a document, and the
 * APPEND-ONLY record of it being scanned.
 *
 * THE CONSTRAINT EVERYTHING HERE FOLLOWS FROM
 * -------------------------------------------
 * A QR printed on paper is a bearer token in the physical world. Anybody who
 * photographs the sheet holds it permanently, it cannot be recalled, and it
 * ends up in camera rolls, group chats and filing cabinets.
 *
 * So the token IDENTIFIES a document and must never AUTHORISE access to one.
 * Nothing in this schema is a grant. `document_qr_tokens` names a document and
 * says whether the code is still honoured; it names no permission, no role and
 * no profile that could be read as reach. {@see \Whity\Core\Document\DocumentVisibilityPolicy}
 * is still the ONLY answer to "may this caller open this document", and a
 * scanner who does not satisfy it gets the 404 they get today. If holding the
 * paper granted access, photographing a paper would be privilege escalation and
 * the whole RBAC model would be bypassed by a photocopier.
 *
 * TWO TABLES, AND WHY THEY ARE NOT ONE
 * ------------------------------------
 * `document_qr_tokens` — the IDENTITY. One row per code ever minted for a
 *                        document, and whether it is still honoured.
 * `document_qr_scans`  — the TRAIL. Insert-only, one row per scan that
 *                        resolved, modelled on `document_route_events`
 *                        (migration 112).
 *
 * The one-table shape — a `scan_count` and `last_scanned_at` on the token row —
 * is the obvious one and it is a mutable projection of an answer nothing else
 * stores, which is the shape migration 108 refuses a `status` column for. It
 * also cannot answer the question the feature exists to answer ("who opened
 * this, and when"), only "how many times", and a counter incremented by an
 * ANONYMOUS endpoint is a write that a stranger with a photograph controls.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE TOKEN IS STORED AS ITSELF, NOT AS A DIGEST
 * ─────────────────────────────────────────────────────────────────────────
 * This deliberately DISAGREES with `invitations.token_hash` (migration 096),
 * `password_resets` and the 2FA recovery tokens, all of which persist only a
 * sha256 digest. That is the right call there and the wrong one here, and the
 * difference is not stylistic:
 *
 *   Those tokens are CREDENTIALS. Possession of one changes what the holder may
 *   do — join a tenant, replace a password. A database leak that yielded them
 *   would be an escalation on top of the leak.
 *
 *   This token is an IDENTIFIER, and the whole design premise above is that
 *   possession grants nothing. It is printed on paper, photographed and
 *   forwarded by construction. Hashing a value that is published on the artifact
 *   itself buys no confidentiality; a reader who has the database already has
 *   the documents table, which is strictly more than any token discloses.
 *
 * And hashing costs something real. The raw value would exist only for the
 * instant it was minted, so a CORRECTION — the same document re-rendered, which
 * migration 108 exists to keep possible — could not reproduce the code that is
 * already on the paper in somebody's hand. Every correction would have to mint a
 * fresh token and silently void every circulated copy. That is the opposite of
 * what #1036 asks for: a QR that stops verifying for a reason nobody chose is
 * as bad as one that keeps verifying after a withdrawal.
 *
 * The rejected third option was deriving the token from a server secret
 * (`HMAC(app_key, tenant, document, nonce)`), which is rederivable AND leaks
 * nothing to a database reader. It fails on operations: rotating `APP_KEY` —
 * routine, and correct, security hygiene — would silently invalidate every QR
 * ever printed, with no error anywhere and no way to recover the values.
 *
 * WHAT DOES PROTECT THE TOKEN is that it is unguessable and that it is never
 * echoed to a caller who could not already read the document:
 * {@see \Whity\Core\Document\Qr\DocumentQrService::mint()} takes 32 bytes from a
 * CSPRNG (`random_bytes`, never `rand()`/`uniqid()`) and hex-encodes them to 64
 * characters, and `UNIQUE (token)` makes the public lookup a single indexed
 * equality rather than a scan.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * `revoked_at` IS A LATCH, NOT A STATUS COLUMN
 * ─────────────────────────────────────────────────────────────────────────
 * Migration 108 refuses a `status` on `documents` and migration 112 refuses one
 * on the trail, both because a stored copy of a derivable answer drifts from the
 * thing it copies and the copy is what screens read. Those two arguments are
 * kept here, and this column is not the shape they refuse:
 *
 *  - It is DERIVED FROM NOTHING. No trail says a code was revoked; revocation is
 *    an administrative act with no other record, so there is no second source
 *    for it to disagree with. That is the same test migration 118 applies to
 *    `documents.variable_data` and passes for the same reason.
 *  - It is ONE-WAY and it is a TIMESTAMP, not a vocabulary. NULL → a time, once.
 *    There is no state machine to get wrong and no verb to typo.
 *  - The alternative — deriving "is this code still honoured" from an
 *    append-only lifecycle table — puts an unbounded read on the ANONYMOUS
 *    public path, which is the one read that must stay cheap and constant-cost.
 *    It would let a stranger holding a photograph decide how much work the
 *    server does per request. That is exactly the trade
 *    `document_route_recipients.closed_by_event_id` records one subsystem over.
 *
 * `sessions.revoked_at` (045), `invitations.revoked_at` (096) and
 * `permission_delegations.revoked_at` (014) are the same latch on the same
 * reasoning.
 *
 * `revoked_reason` IS CHECK-CONSTRAINED to a closed vocabulary
 * ({@see \Whity\Core\Document\Qr\QrRevocationReason}) for the reason migration
 * 112 closes `document_route_events.action`: a column that accepts any string is
 * a column whose readers must handle any string, and the first typo is a
 * permanent row nothing renders. Two verbs exist and both are real:
 *
 *   withdrawn  — an operator stopped honouring this code. The document itself
 *                may be fine; the paper is not to be trusted.
 *   superseded — a NEW code was minted for the same document, so the older paper
 *                is no longer the current one.
 *
 * The paired CHECK also refuses the half-revoked row (`revoked_at` set with no
 * reason, or the reverse), which is the only way this latch could be ambiguous.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE SCAN TRAIL RECORDS THE ACT, AND NOTHING ABOUT THE SCANNER
 * ─────────────────────────────────────────────────────────────────────────
 * `document_qr_scans` has `scanner_profile_id` and NOTHING ELSE about who
 * scanned. No IP address, no user agent, no coarse location, no device id — and
 * their absence is the decision, not an omission to be filled in later.
 *
 * An anonymous scan is a member of the PUBLIC holding a piece of paper: a
 * courier, a ministry clerk, a citizen checking a decision is real. They are not
 * a user of the tenant, they never agreed to anything, they have no account
 * through which to ask what is held about them, and there is no screen anywhere
 * that could offer them erasure. Storing their address against a specific
 * document, timestamped, would build a movement-and-interest record about people
 * who have no relationship with the organisation at all — and it would do it as
 * a side effect of them checking that a document is genuine, which is the
 * behaviour the feature is trying to encourage.
 *
 * So the row records THAT a scan happened, never WHO by, unless the scanner was
 * an authenticated principal of the tenant — in which case they are a user
 * acting inside the system, already named in `audit_log` and in the routing
 * trail for far more consequential acts, and naming them here is consistent with
 * that rather than new.
 *
 * The tenant still gets the tracking the feature is named for — "this document
 * has been verified publicly eleven times, and opened by these four people" —
 * because the count and the timestamps are the part that answers "is this
 * circulating", and the address was never the part that did.
 *
 * `outcome` is CHECK-constrained for the same reason `revoked_reason` is
 * ({@see \Whity\Core\Document\Qr\QrScanOutcome}). It is derived at insert from
 * the token row, never revised: a scan that was refused because the code had
 * been withdrawn STAYS refused in the trail even after a new code is minted,
 * which is the fact somebody investigating a disputed document actually needs.
 *
 * NO ROW FOR AN UNKNOWN TOKEN. `qr_token_id` is `NOT NULL`, so a scan of a code
 * that resolves to nothing cannot be recorded — there is nowhere to put it.
 * That is deliberate twice over: an unknown token names no document, so no
 * tenant owns the row; and recording them would hand an anonymous caller an
 * unbounded write, one row per guess, on an endpoint whose whole job is to be
 * cheap for strangers.
 *
 * FOREIGN KEYS, AND THE `ON DELETE` EACH ONE GETS
 * -----------------------------------------------
 * scripts/ci-undeclared-reference-guard.php lints core's migrations since #751,
 * and none of these actions is a default:
 *
 *  - `tenant_id → tenants ON DELETE CASCADE` on both tables. What every
 *    tenant-owned table does.
 *  - `document_id → documents ON DELETE CASCADE` on both. A code for a document
 *    that no longer exists is not a code; nothing else names it. Note this is
 *    the same reading migration 112 gives the trail, and it is safe for the same
 *    reason: the row is meaningless without its parent, unlike an ARTIFACT,
 *    whose bytes outlive the row on purpose.
 *  - `qr_token_id → document_qr_tokens ON DELETE CASCADE`. A scan is a scan OF a
 *    code. This is the only reference in the subsystem whose parent is never
 *    deleted in ordinary operation — revocation is a latch, not a delete — so
 *    the cascade only ever fires transitively from a document or a tenant.
 *  - `issued_by` / `revoked_by` / `scanner_profile_id → profiles ON DELETE SET
 *    NULL`. Migration 108's reading of `documents.created_by`, for its reason: an
 *    organisational record outlives its author, and a leaver must not silently
 *    delete part of the tenant's history. The pointer IS the personal datum, so
 *    nulling it is the erasure rather than an evasion of it.
 *
 * `document_id` ON THE SCAN ROW TOO
 * ---------------------------------
 * Denormalised from the token it names, and redundant by construction. It is
 * what lets the CI tenant-predicate guard police the scan reads directly rather
 * than trusting a join, and it is what makes "this document's scans, newest
 * first" a single indexed read instead of a join through the token table on the
 * record page. The same trade `document_artifacts.tenant_id` records one
 * subsystem over. Both tables are registered in
 * {@see \Whity\Core\Tenant\TenantOwnedTables} and in
 * {@see \Whity\Core\Tenant\CoreTables}.
 *
 * NO PARTIAL UNIQUE INDEX ON "the active token for a document"
 * ------------------------------------------------------------
 * PostgreSQL would express "at most one un-revoked token per document" as
 * `UNIQUE (tenant_id, document_id) WHERE revoked_at IS NULL`, and SQLite — the
 * engine the unit suite builds its schema on — supports partial indexes too. It
 * is still left out, because the invariant it would enforce is not one this
 * subsystem actually wants at the DATABASE level: minting is a two-statement
 * sequence (revoke the old, insert the new) that
 * {@see \Whity\Core\Document\Qr\DocumentQrService::mint()} runs inside a
 * transaction, and a unique index would make an interleaved second mint fail
 * with a constraint violation on a path whose correct answer is "the other one
 * won, use it". The service reads the active row back after committing and that
 * is the answer, on both engines, without a dialect-specific index that
 * MigrationSchemaTest would then have to reason about.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateDocumentQrTracking
{
    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement per table, never a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest
        // re-derive their registries by scanning this source, so every name has
        // to appear literally. Migrations 059, 108 and 112 carry the same note,
        // and spell the keyword hyphenated in prose for the same reason: the
        // schema test scans the raw file text for the create keyword and would
        // read a plain one inside a comment as a real table declaration.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_qr_tokens (
                id             BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id      INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id    BIGINT       NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                token          VARCHAR(128) NOT NULL,
                issued_by      INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                issued_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
                revoked_at     TIMESTAMP,
                revoked_by     INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                revoked_reason VARCHAR(32),
                UNIQUE (token),
                CHECK (
                    (revoked_at IS NULL AND revoked_reason IS NULL)
                    OR (revoked_at IS NOT NULL
                        AND revoked_reason IN ('withdrawn', 'superseded'))
                )
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_qr_tokens_tenant_id ON document_qr_tokens(tenant_id)');
        // "this document's codes, newest first" — the record panel and the
        // mint path's own read of the code currently in force, both entered
        // through the tenant as the predicate guard requires. The public
        // lookup does NOT use these: it is served by the UNIQUE (token) index,
        // which is the only read in the subsystem that arrives without a
        // tenant to enter through.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_qr_tokens_tenant_document
                ON document_qr_tokens(tenant_id, document_id, id)'
        );

        // THE TRAIL. Insert-only: no updated_at, no soft-delete, no mutable
        // column. See the class docblock for what it deliberately does not
        // record about the person who scanned.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_qr_scans (
                id                 BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id          INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                document_id        BIGINT      NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
                qr_token_id        BIGINT      NOT NULL REFERENCES document_qr_tokens(id) ON DELETE CASCADE,
                scanner_profile_id INTEGER     REFERENCES profiles(id) ON DELETE SET NULL,
                outcome            VARCHAR(32) NOT NULL,
                scanned_at         TIMESTAMP   NOT NULL DEFAULT NOW(),
                CHECK (outcome IN ('verified', 'refused'))
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_qr_scans_tenant_id ON document_qr_scans(tenant_id)');
        // The record panel's list: this document's scans, newest first.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_qr_scans_tenant_document
                ON document_qr_scans(tenant_id, document_id, id)'
        );
        // The coalescing read on the PUBLIC path: "has this same code already
        // been recorded for this same principal in the last minute". It runs on
        // every scan that resolves, so it gets its own composite rather than
        // filtering the one above.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_document_qr_scans_tenant_token
                ON document_qr_scans(tenant_id, qr_token_id, id)'
        );
    }

    public static function down(Database $db): void
    {
        // Children first: `document_qr_scans` names `document_qr_tokens`.
        // CASCADE on the DROP covers it on PostgreSQL, but SQLite (the
        // test-schema engine) has no such clause, and ordering costs nothing.
        $db->exec('DROP INDEX IF EXISTS idx_document_qr_scans_tenant_token');
        $db->exec('DROP INDEX IF EXISTS idx_document_qr_scans_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_document_qr_scans_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_qr_scans CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_document_qr_tokens_tenant_document');
        $db->exec('DROP INDEX IF EXISTS idx_document_qr_tokens_tenant_id');
        $db->exec('DROP TABLE IF EXISTS document_qr_tokens CASCADE');
    }
}
