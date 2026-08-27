<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;

/**
 * Data-access for `form_uploads` (migration 134) — the staging record for a file
 * attached to a `file` answer.
 *
 * THREE OPERATIONS, AND THE MIDDLE ONE IS THE SECURITY BOUNDARY
 * -------------------------------------------------------------
 *   {@see self::record()} — an upload happened; here is where the bytes went.
 *   {@see self::claim()}  — this submission is spending that upload. THE CHECK.
 *   {@see self::sweepUnclaimed()} — nobody ever spent these; forget them.
 *
 * There is deliberately no `find()` and no `listForForm()`. Every read anybody
 * needs is one of the three above, and a general lookup by storage key is the
 * exact shape that would let a future caller resolve a key WITHOUT the tenant
 * and form predicates that make the claim safe. Migration 134's docblock spells
 * out what goes wrong then. An absent method cannot be misused.
 *
 * THE CLAIM IS ONE STATEMENT ON PURPOSE
 * --------------------------------------
 * `UPDATE … WHERE tenant_id = ? AND form_id = ? AND storage_key = ? AND
 * uploaded_by … AND claimed_at IS NULL`, and `rowCount()` IS the verdict. A
 * SELECT-then-UPDATE would let two concurrent submissions both see the same
 * unclaimed row and both attach the same object. The database decides once, and
 * the losing caller is told the file could not be found — which is true: there
 * is no longer an unspent upload at that address.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate — the CI
 * tenant-predicate guard reads this source — except {@see self::sweepUnclaimed()},
 * which is cross-tenant by design and carries the annotated exception.
 */
final class FormUploadRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Record one completed upload and return its id.
     *
     * Called AFTER the bytes are in storage, never before: a row written first
     * would claim an object that does not exist, and a submission arriving in
     * the window between the two would attach an address with nothing at it.
     * The reverse failure — bytes with no row — is the orphan the sweep exists
     * to clean up, and is the one worth having.
     *
     * @param array{form_id: int, storage_key: string, content_type: string,
     *              byte_size: int, checksum_sha256: string,
     *              client_filename?: ?string, uploaded_by_profile_id?: ?int} $rec
     */
    public function record(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO form_uploads
                 (tenant_id, form_id, storage_key, content_type, byte_size,
                  checksum_sha256, client_filename, uploaded_by_profile_id, created_at)
             VALUES (:tenant_id, :form_id, :storage_key, :content_type, :byte_size,
                     :checksum_sha256, :client_filename, :uploaded_by_profile_id, NOW())'
        );
        $stmt->execute([
            ':tenant_id'              => $tenantId,
            ':form_id'                => $rec['form_id'],
            ':storage_key'            => $rec['storage_key'],
            ':content_type'           => $rec['content_type'],
            ':byte_size'              => $rec['byte_size'],
            ':checksum_sha256'        => $rec['checksum_sha256'],
            ':client_filename'        => $rec['client_filename'] ?? null,
            ':uploaded_by_profile_id' => $rec['uploaded_by_profile_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Spend one upload against one submission, and describe what was spent.
     *
     * Returns null for EVERY reason the answer is not a live upload of this
     * caller's on this form: no such key, a key belonging to another tenant, a
     * key minted for a different form, a key somebody else uploaded, and a key
     * already attached to an earlier submission. One null for all of them, on
     * purpose — a caller who could tell "wrong tenant" from "already used" could
     * ask this method which keys exist elsewhere.
     *
     * The uploader predicate is written as two branches rather than one
     * `IS NOT DISTINCT FROM`: that operator is PostgreSQL's and SQLite does not
     * have it, and a predicate that silently degrades on the engine the unit
     * shard runs on is a check that is only enforced in one of the two places it
     * is tested.
     *
     * @return array{id: int, storage_key: string, content_type: string,
     *               byte_size: int, checksum_sha256: string,
     *               client_filename: ?string}|null
     */
    public function claim(
        int $tenantId,
        int $formId,
        string $storageKey,
        ?int $uploaderProfileId,
        int $submissionId,
    ): ?array {
        $uploaderPredicate = $uploaderProfileId === null
            ? 'uploaded_by_profile_id IS NULL'
            : 'uploaded_by_profile_id = :uploaded_by';

        $params = [
            ':tenant_id'     => $tenantId,
            ':form_id'       => $formId,
            ':storage_key'   => $storageKey,
            ':submission_id' => $submissionId,
        ];
        if ($uploaderProfileId !== null) {
            $params[':uploaded_by'] = $uploaderProfileId;
        }

        $stmt = $this->db->prepare(
            'UPDATE form_uploads
                SET claimed_at = NOW(), submission_id = :submission_id
              WHERE tenant_id = :tenant_id
                AND form_id = :form_id
                AND storage_key = :storage_key
                AND ' . $uploaderPredicate . '
                AND claimed_at IS NULL'
        );
        $stmt->execute($params);

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        // Read back INSIDE the caller's transaction, after the claim has already
        // succeeded. The row's own metadata — not the request's — is what goes
        // onto the artifact: a byte size or checksum taken from the submitting
        // client would be a number the client chose, and the whole point of the
        // checksum is that it was computed by the server over the bytes it
        // actually stored.
        $read = $this->db->prepare(
            'SELECT id, storage_key, content_type, byte_size, checksum_sha256, client_filename
               FROM form_uploads
              WHERE tenant_id = :tenant_id AND storage_key = :storage_key'
        );
        $read->execute([':tenant_id' => $tenantId, ':storage_key' => $storageKey]);
        $row = $read->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id'              => (int) $row['id'],
            'storage_key'     => (string) $row['storage_key'],
            'content_type'    => (string) $row['content_type'],
            'byte_size'       => (int) $row['byte_size'],
            'checksum_sha256' => (string) $row['checksum_sha256'],
            'client_filename' => $row['client_filename'] !== null ? (string) $row['client_filename'] : null,
        ];
    }

    /**
     * Delete every upload nobody ever submitted, older than the TTL, and return
     * the storage keys that were holding bytes.
     *
     * ROWS FIRST, OBJECTS SECOND, and the order is the whole design. The caller
     * ({@see FormUploadSweeper}) deletes each returned object afterwards, so the
     * two failure modes are: a row deleted and the object left behind (which a
     * later sweep will not find — see the sweeper for why that is the accepted
     * side), or nothing deleted at all. What CANNOT happen is a row surviving an
     * object it points at, which would leave a claimable upload whose bytes are
     * gone.
     *
     * A KEY IS RETURNED ONLY IF ITS ROW WAS ACTUALLY DELETED. The deletes are
     * per-row, re-asserting `claimed_at IS NULL`, and a row that lost that race —
     * a submission claimed it between the SELECT and the DELETE — is skipped and
     * its key is NOT returned. Returning the SELECT's keys wholesale would hand
     * the sweeper the address of an object a live `document_artifacts` row now
     * depends on, and it would delete somebody's evidence. Per-row is a few
     * hundred small statements once per schedule tick; the alternative is a
     * data-loss race.
     *
     * A LIMIT rather than "everything": a sweep that woke up to a million rows
     * would work through all of them in one pass. It runs on a schedule, so
     * falling behind means catching up next time rather than blocking.
     *
     * @return list<string> Storage keys whose rows this call actually deleted.
     */
    public function sweepUnclaimed(int $olderThanSeconds, int $limit = 500): array
    {
        $cutoff = date('Y-m-d H:i:s', time() - max(0, $olderThanSeconds));

        // @tenant-guard-ignore: retention sweep — deliberately cross-tenant. It
        // is an operator job with no tenant context (no session, no request), and
        // narrowing it to one tenant would mean either enumerating tenants here
        // or leaving every tenant but one accumulating orphaned objects forever.
        // The predicate that makes it safe is `claimed_at IS NULL AND created_at <
        // cutoff`: an upload nobody submitted, from before the TTL, is unreachable
        // by construction — the only path that could ever reference it is
        // FormUploadRepository::claim(), which refuses a claimed row and cannot
        // resurrect a deleted one.
        $select = $this->db->prepare(
            'SELECT id, storage_key FROM form_uploads
              WHERE claimed_at IS NULL AND created_at < :cutoff
              ORDER BY created_at ASC
              LIMIT ' . max(1, $limit)
        );
        $select->execute([':cutoff' => $cutoff]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return [];
        }

        // @tenant-guard-ignore: retention sweep — same cross-tenant job as the
        // SELECT above, one row at a time by primary key.
        $delete = $this->db->prepare(
            'DELETE FROM form_uploads WHERE id = :id AND claimed_at IS NULL'
        );

        $deleted = [];
        foreach ($rows as $row) {
            $delete->execute([':id' => (int) $row['id']]);
            if ($delete->rowCount() === 1) {
                $deleted[] = (string) $row['storage_key'];
            }
        }

        return $deleted;
    }
}
