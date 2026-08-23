<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Storage\StorageException;

/**
 * Issues a document: the one place where a rendered payload becomes a durable
 * record plus an immutable artifact (#947 item 1).
 *
 * Two entry points, and the difference between them is the whole point of the
 * two-table schema:
 *
 *   {@see issue()}          — a NEW record. New id, new artifact.
 *   {@see appendArtifact()} — a correction. SAME id, one more artifact; the
 *                             previous one is untouched and still fetchable.
 *
 * ORDER OF OPERATIONS, AND WHICH FAILURE IS THE ACCEPTABLE ONE
 * ------------------------------------------------------------
 * A storage write cannot join a database transaction, so one of the two
 * possible torn states has to be chosen deliberately:
 *
 *   1. INSERT the document row (inside a transaction) — the id is needed to
 *      address the object.
 *   2. WRITE the bytes.
 *   3. INSERT the artifact row, and COMMIT.
 *
 * If (2) or (3) fails the transaction rolls back, so a failed issue leaves NO
 * rows — and possibly an object in storage that nothing references. That is the
 * chosen loss: unreferenced bytes are inert and a sweep can reclaim them from
 * the fact that no `document_artifacts` row names them, whereas the opposite
 * ordering (row first, bytes second) leaves a record that a viewer, a route
 * step and an audit query all believe in and that 500s when anyone opens it. A
 * record must never promise bytes that are not there.
 *
 * The transaction is entered only if one is not already open, mirroring
 * migration 105 and the rest of the codebase: a caller that has wrapped a
 * larger unit of work keeps control of it.
 *
 * WHY THE RENDER IS NOT DONE HERE
 * -------------------------------
 * The bytes arrive as an argument. {@see Render\DocumentRenderer} produces
 * them, and keeping the two apart is what lets the ephemeral preview path use
 * the renderer alone and write nothing at all — which is the default and is
 * required to stay so (a designer previewing every keystroke must not fill a
 * bucket).
 */
final class DocumentIssuer
{
    public function __construct(
        private readonly PDO $db,
        private readonly DocumentRepository $documents,
        private readonly DocumentArtifactRepository $artifacts,
        private readonly DocumentArtifactStore $store,
    ) {
    }

    /**
     * Create a document record from a template and store its first artifact.
     *
     * @param array<string, mixed> $template A normalized `document_templates` row.
     * @param string               $bytes    The rendered payload.
     *
     * @return array{document: array<string, mixed>, artifact: array<string, mixed>}
     *
     * @throws StorageException When the object cannot be written; nothing is recorded.
     */
    public function issue(
        int $tenantId,
        ?int $actorId,
        array $template,
        string $title,
        string $bytes,
        string $contentType = 'application/pdf',
        string $extension = 'pdf',
    ): array {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $documentId = $this->documents->create($tenantId, [
                'document_template_id' => isset($template['id']) ? (int) $template['id'] : null,
                // Snapshot, not a join — the template may be renamed or deleted
                // (ON DELETE SET NULL), and the record still has to be able to
                // say what it came from.
                'template_name'        => (string) ($template['name'] ?? ''),
                'title'                => $title,
                'origin_ou_id'         => $actorId !== null ? $this->originOuFor($tenantId, $actorId) : null,
                'created_by'           => $actorId,
            ]);

            $artifactId = $this->storeArtifact($tenantId, $documentId, $actorId, $bytes, $contentType, $extension);

            $document = $this->documents->findById($documentId, $tenantId);
            $artifact = $this->artifacts->findById($artifactId, $documentId, $tenantId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Both were just written inside the same transaction; a null here would
        // mean the row vanished between insert and read, which is not a state
        // this method can report meaningfully.
        if ($document === null || $artifact === null) {
            throw new \RuntimeException('Document was issued but could not be read back.');
        }

        return ['document' => $document, 'artifact' => $artifact];
    }

    /**
     * Append a corrected artifact to an existing document.
     *
     * The document row is NOT modified — not its title, not its provenance, not
     * its `created_at`. A correction is a new set of bytes attached to the same
     * record, so everything already pointing at that record (and, once #947
     * item 3 lands, every route step, recipient and trail event) keeps pointing
     * at the right thing.
     *
     * @param array<string, mixed> $document A normalized `documents` row.
     *
     * @return array<string, mixed> The new artifact row.
     *
     * @throws StorageException When the object cannot be written; nothing is recorded.
     */
    public function appendArtifact(
        int $tenantId,
        ?int $actorId,
        array $document,
        string $bytes,
        string $contentType = 'application/pdf',
        string $extension = 'pdf',
    ): array {
        $documentId = (int) $document['id'];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $artifactId = $this->storeArtifact($tenantId, $documentId, $actorId, $bytes, $contentType, $extension);
            $artifact = $this->artifacts->findById($artifactId, $documentId, $tenantId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($artifact === null) {
            throw new \RuntimeException('Artifact was stored but could not be read back.');
        }

        return $artifact;
    }

    /**
     * Write the bytes, then record them. Never the other way round — see the
     * class docblock.
     */
    private function storeArtifact(
        int $tenantId,
        int $documentId,
        ?int $actorId,
        string $bytes,
        string $contentType,
        string $extension,
    ): int {
        $stored = $this->store->put($tenantId, $documentId, $bytes, $contentType, $extension);

        return $this->artifacts->create($tenantId, [
            'document_id'     => $documentId,
            'storage_key'     => $stored->storageKey,
            'content_type'    => $stored->contentType,
            'byte_size'       => $stored->byteSize,
            'checksum_sha256' => $stored->checksumSha256,
            'rendered_by'     => $actorId,
        ]);
    }

    /**
     * The unit the document is raised FROM: the actor's primary active
     * membership OU in this tenant, or null when they belong to no unit.
     *
     * Captured at issue time rather than derived on read, because it is a fact
     * about the raising and people move units — a document raised by the
     * Registry last year did not become a Faculty document when its author
     * transferred. #947 item 5's "raised by my unit" folder is a subtree query
     * over this column; deriving it live would answer a different question and
     * would silently change the answer for historical rows.
     *
     * Primary membership first, then the oldest one holding a unit: a profile
     * can legitimately hold several memberships in one tenant, and picking an
     * arbitrary row would make the origin depend on insertion order.
     */
    private function originOuFor(int $tenantId, int $profileId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT ou_id FROM memberships
              WHERE tenant_id = :tenant_id
                AND profile_id = :profile_id
                AND status = 'active'
                AND ou_id IS NOT NULL
              ORDER BY is_primary DESC, id ASC
              LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $ouId = $stmt->fetchColumn();

        return $ouId === false || $ouId === null ? null : (int) $ouId;
    }
}
