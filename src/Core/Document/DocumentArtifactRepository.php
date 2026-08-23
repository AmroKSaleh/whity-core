<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;

/**
 * Data-access for `document_artifacts` (#947 item 1) — the stored bytes of one
 * render, recorded exactly once.
 *
 * APPEND-ONLY, BY OMISSION
 * ------------------------
 * There is no update() and no delete() on this class, and that absence is the
 * design rather than an unfinished sketch. An artifact is the document that was
 * ISSUED: correcting it means rendering again and appending a second row, never
 * rewriting the first. #947 makes the same argument for routing's event trail
 * one layer up — "the moment somebody most wants to tidy history is exactly
 * when it must be immutable" — and a store that offers an UPDATE is a store
 * where someone eventually calls it, at which point the guarantee is a comment.
 *
 * Removal happens only through the parent: `ON DELETE CASCADE` from `documents`
 * and from `tenants` (migration 106). Note what that does NOT reclaim — the
 * OBJECT in storage outlives the row, which is why `storage_key` is recorded
 * here at all: an operator sweep needs to read the keys before the rows go.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate (the CI
 * tenant-predicate guard reads this source), and every artifact lookup ALSO
 * binds `document_id` — an artifact id alone is never enough to fetch bytes,
 * so a guessed id cannot be walked across documents inside a tenant.
 */
final class DocumentArtifactRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Record one stored artifact and return its id.
     *
     * `storage_key` carries a UNIQUE constraint, so a caller that somehow
     * derived a key already in use gets an integrity error rather than a second
     * row silently claiming the same object. {@see DocumentArtifactStore} mints
     * a fresh random key per write and refuses to overwrite an existing object,
     * so the two halves of the immutability guarantee fail independently.
     *
     * @param array{document_id: int, storage_key: string, content_type: string,
     *              byte_size: int, checksum_sha256: string, rendered_by?: ?int} $rec
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_artifacts
                 (tenant_id, document_id, storage_key, content_type, byte_size, checksum_sha256, rendered_by, rendered_at)
             VALUES (:tenant_id, :document_id, :storage_key, :content_type, :byte_size, :checksum_sha256, :rendered_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id'       => $tenantId,
            ':document_id'     => $rec['document_id'],
            ':storage_key'     => $rec['storage_key'],
            ':content_type'    => $rec['content_type'],
            ':byte_size'       => $rec['byte_size'],
            ':checksum_sha256' => $rec['checksum_sha256'],
            ':rendered_by'     => $rec['rendered_by'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Every artifact of one document, NEWEST FIRST.
     *
     * Ordered by `id` rather than `rendered_at`: the surrogate key is
     * monotonic, a timestamp is not unique, and two corrections issued in the
     * same second must still have a defined order. Migration 106 explains why
     * there is no `revision` column to order by instead.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $documentId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, storage_key, content_type, byte_size, checksum_sha256, rendered_by, rendered_at
             FROM document_artifacts
             WHERE tenant_id = :tenant_id AND document_id = :document_id
             ORDER BY id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * The CURRENT artifact of a document — the most recently appended one.
     *
     * "Current" is a derived answer, not a stored pointer: a
     * `documents.current_artifact_id` column would be a second copy of a fact
     * the artifact rows already state, and the copy is what drifts.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForDocument(int $documentId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, storage_key, content_type, byte_size, checksum_sha256, rendered_by, rendered_at
             FROM document_artifacts
             WHERE tenant_id = :tenant_id AND document_id = :document_id
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * One specific artifact of one specific document.
     *
     * This is the endpoint that makes immutability OBSERVABLE: a superseded
     * artifact stays fetchable at its own id forever, so "the version that was
     * issued in March" is a request, not an archaeology exercise.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $documentId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, storage_key, content_type, byte_size, checksum_sha256, rendered_by, rendered_at
             FROM document_artifacts
             WHERE id = :id AND document_id = :document_id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':document_id' => $documentId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'tenant_id'       => (int) $row['tenant_id'],
            'document_id'     => (int) $row['document_id'],
            'storage_key'     => (string) $row['storage_key'],
            'content_type'    => (string) $row['content_type'],
            'byte_size'       => (int) $row['byte_size'],
            'checksum_sha256' => (string) $row['checksum_sha256'],
            'rendered_by'     => $row['rendered_by'] !== null ? (int) $row['rendered_by'] : null,
            'rendered_at'     => (string) $row['rendered_at'],
        ];
    }
}
