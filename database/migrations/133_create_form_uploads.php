<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * CreateFormUploads — the STAGING RECORD for a file attached to a form answer.
 *
 * WHAT WAS MISSING
 * ----------------
 * Migration 127 gave `form_fields.field_type` the value `file`, and
 * {@see \Whity\Core\Form\SubmissionValidator} checks such an answer for SHAPE
 * only — its own docblock says it "refuses to become an upload path". Nothing
 * else in core filled the gap, so a tenant could author "upload your paper" and
 * no caller anywhere had a way to produce the reference the field wanted. The
 * field rendered, the form submitted, and the answer was a string somebody had
 * to type. That is the "renders fine, does nothing" failure this subsystem keeps
 * being written against.
 *
 * WHY THE UPLOAD NEEDS A ROW AT ALL, RATHER THAN JUST AN OBJECT IN STORAGE
 * ------------------------------------------------------------------------
 * The obvious design is: write the bytes, hand the caller the storage key, and
 * let the submission carry it. That design has a hole big enough to drive a
 * tenant through, and this table is what closes it.
 *
 * A STORAGE KEY MUST NOT BE A CAPABILITY. `tenants/{id}/…` keys are structured
 * and {@see \Whity\Storage\TenantRoutingStorageDriver} reads the tenant back out
 * of one to pick a backend. If the only check on a `file` answer were its shape,
 * a caller in tenant 7 could submit a form whose answer names a key under
 * `tenants/9/…`; {@see \Whity\Core\Form\SubmissionIssuer} would mint a
 * `document_artifacts` row in tenant 7 pointing at tenant 9's object, and
 * `GET /api/v1/documents/{id}/artifacts/{aid}/content` — which is correctly
 * gated on the DOCUMENT, and takes the key straight off the row — would stream
 * another organisation's file to them. Every gate on that path would report
 * success, because every gate on that path is asking about the document and the
 * document really is theirs.
 *
 * So an answer is not accepted because it LOOKS like a key. It is accepted
 * because a row here says this tenant, on this form, actually uploaded it, and
 * has not spent it yet. The check is a CLAIM — a single `UPDATE … WHERE
 * tenant_id = ? AND form_id = ? AND storage_key = ? AND claimed_at IS NULL`
 * whose `rowCount()` is the verdict. A key belonging to another tenant matches
 * no row, so it is refused as "we cannot find that file" rather than honoured as
 * a pointer across an isolation boundary.
 *
 * SINGLE USE, AND WHY THE CLAIM IS AN UPDATE RATHER THAN A SELECT
 * ---------------------------------------------------------------
 * `claimed_at` turns the check and the spend into ONE statement. A SELECT
 * followed by an UPDATE is two, and between them two concurrent submissions can
 * both see the same unclaimed row and both attach the same object — one upload
 * becoming evidence on two documents, with nothing in the schema to say which
 * was meant. The conditional UPDATE lets the database decide, once.
 *
 * Single use is also what makes the orphan question answerable at all: with it,
 * "nobody will ever reference this object" is exactly `claimed_at IS NULL AND
 * created_at < now - ttl`, which is a query. Without it, it is a guess.
 *
 * WHY THE BYTES ARE NOT MOVED WHEN THE UPLOAD IS CLAIMED
 * ------------------------------------------------------
 * A tidier-looking design would copy the object under the document's own prefix
 * at submit time, so `document_artifacts.storage_key` always reads
 * `tenants/{t}/documents/{docId}/…`. It is the wrong trade, for a reason
 * {@see \Whity\Core\Form\SubmissionIssuer}'s docblock already states about
 * itself: nothing on the submit path touches object storage, so its transaction
 * is TOTAL. A copy inside that transaction is an unjoinable write — roll the
 * transaction back and the row vanishes while the duplicate bytes remain, which
 * is the orphan this table exists to make impossible.
 *
 * The artifact row therefore keeps the key the upload already has. That costs
 * nothing: a storage key is an OPAQUE ADDRESS by
 * {@see \Whity\Storage\StorageDriverInterface}'s own contract, the read path
 * takes it from the row rather than deriving it, and the tenant segment — the
 * one part any code reasons about — is present either way.
 *
 * `submission_id` IS NULLABLE AND `ON DELETE SET NULL`
 * ----------------------------------------------------
 * It records what spent the upload, for an operator asking "where did this
 * object end up". It is not the authority on whether the upload is spent —
 * `claimed_at` is — because a submission row that later went away must not make
 * a claimed upload look reclaimable. Two columns, two questions, neither
 * inferred from the other.
 *
 * `uploaded_by_profile_id` IS NULLABLE, AND NULL IS A REAL STATE
 * --------------------------------------------------------------
 * Migration 132 opened forms to people with no account, and
 * `form_submissions.submitted_by_profile_id` is nullable for exactly that
 * reason. An anonymous upload stores NULL here for the same reason and with the
 * same refusal to invent a sentinel profile. The claim compares the uploader to
 * the submitter — NULL to NULL on the public path — so an upload made by one
 * signed-in member cannot be attached by another.
 *
 * NEW TABLE, so {@see \Whity\Core\Tenant\TenantOwnedTables} and
 * {@see \Whity\Core\Tenant\CoreTables} both gain an entry. Every statement in
 * {@see \Whity\Core\Form\FormUploadRepository} binds `tenant_id` except the
 * retention sweep, which is cross-tenant BY DESIGN and carries the annotated
 * exception the guard requires.
 *
 * Idempotent (IF NOT EXISTS throughout) and reversible via down().
 */
final class CreateFormUploads
{
    public static function up(Database $db): void
    {
        // `storage_key` is VARCHAR(512) to match the ceiling
        // {@see \Whity\Core\Form\SubmissionValidator::storageReference()} already
        // enforces on a `file` answer, so the column and the validator agree
        // rather than the database silently truncating what the validator let
        // through.
        //
        // `checksum_sha256` is the hash of the bytes as RECEIVED, computed once
        // at upload. It is copied onto `document_artifacts.checksum_sha256` when
        // the upload is claimed, which is what lets anybody later prove the
        // artifact is the file the applicant sent rather than something that
        // replaced it.
        //
        // `content_type` is what the server SNIFFED, never what the client
        // declared — see {@see \Whity\Core\Form\FormUploadPolicy}. Storing the
        // declared value would put an attacker-chosen string on an object that
        // is later served with it.
        $db->exec("
            CREATE TABLE IF NOT EXISTS form_uploads (
                id                     BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id              INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                form_id                BIGINT       NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
                storage_key            VARCHAR(512) NOT NULL,
                content_type           VARCHAR(128) NOT NULL,
                byte_size              BIGINT       NOT NULL,
                checksum_sha256        VARCHAR(64)  NOT NULL,
                client_filename        VARCHAR(255),
                uploaded_by_profile_id INTEGER      REFERENCES profiles(id) ON DELETE SET NULL,
                created_at             TIMESTAMP    NOT NULL DEFAULT NOW(),
                claimed_at             TIMESTAMP,
                submission_id          BIGINT       REFERENCES form_submissions(id) ON DELETE SET NULL,
                UNIQUE (storage_key),
                CHECK (byte_size > 0)
            )
        ");

        // The CLAIM's access path, and the shape the tenant-predicate guard
        // wants: every claim binds `tenant_id` and `form_id` before it looks at
        // the key. `UNIQUE (storage_key)` above is the correctness half — a key
        // is minted once and may be recorded once — and this index is the read
        // half, so neither is load-bearing for the other.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_uploads_tenant_form
                 ON form_uploads(tenant_id, form_id)'
        );

        // The SWEEP's access path: "uploads nobody ever submitted, oldest
        // first". PARTIAL on `claimed_at IS NULL` because the sweep is only ever
        // interested in that side and, in a healthy install, that side is the
        // small one — the index stays the size of the pending uploads rather
        // than the size of every upload ever made.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_form_uploads_unclaimed
                 ON form_uploads(created_at) WHERE claimed_at IS NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_form_uploads_unclaimed');
        $db->exec('DROP INDEX IF EXISTS idx_form_uploads_tenant_form');
        $db->exec('DROP TABLE IF EXISTS form_uploads CASCADE');
    }
}
