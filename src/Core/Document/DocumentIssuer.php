<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Ou\PrimaryMembershipOu;
use Whity\Storage\StorageException;

/**
 * Issues a document: the one place where a rendered payload becomes a durable
 * record plus an immutable artifact (#947 item 1).
 *
 * Three entry points, and the differences between them are the whole point of
 * the two-table schema:
 *
 *   {@see raise()}          — a NEW record and NOTHING ELSE. New id, no bytes.
 *   {@see issue()}          — a NEW record. New id, new artifact, one transaction.
 *   {@see appendArtifact()} — a correction. SAME id, one more artifact; the
 *                             previous one is untouched and still fetchable.
 *
 * WHY `raise()` EXISTS, GIVEN THIS CLASS'S OWN RULE ABOUT MISSING BYTES
 * ---------------------------------------------------------------------
 * The order-of-operations note below says a record must never promise bytes
 * that are not there, and `raise()` writes a record with no bytes at all. Those
 * are not in conflict, and the distinction is the whole reason `POST
 * /api/documents` can exist:
 *
 *   A record whose artifact row says there are bytes, and there are not, is a
 *   LIE — a viewer, a route step and an audit query all believe it and it 500s
 *   when anyone opens it. That is what the ordering below prevents, and
 *   `raise()` does not produce it: no artifact row is written, so nothing claims
 *   anything, and `DocumentPresenter` reports `content_url: null` — a shape the
 *   read path has always handled.
 *
 *   A record with no artifact is an ORDINARY state of this system, not a
 *   degraded one. `documents.render_enabled` defaults to FALSE, so on a fresh
 *   install the render container does not exist and no document can ever have
 *   an artifact. Requiring bytes to create a record would mean the only way to
 *   own a document is to run an optional headless-Chromium service — and the
 *   value of a document in this system is that it has an identity to route,
 *   which needs no PDF. The values it was raised with are recorded on the row
 *   (migration 118), so the document has CONTENT even when it has no rendering,
 *   and `POST /api/documents/{id}/render` can mint the artifact later from
 *   exactly those values.
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
     * @param array<string, mixed>              $template     A normalized `document_templates` row.
     * @param string                            $bytes        The rendered payload.
     * @param list<array<string, string>>|null   $variableData The values the document is raised with,
     *                                                        already normalized by
     *                                                        {@see \Whity\Core\Document\Render\VariableData}.
     *                                                        Null records nothing — see
     *                                                        {@see DocumentRepository::create()} for why that
     *                                                        is not the same as `[]`.
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
        ?array $variableData = null,
    ): array {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $documentId = $this->insertRecord($tenantId, $actorId, $template, $title, $variableData);

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
     * Raise a document record with no artifact.
     *
     * The entry point `POST /api/documents` uses, and the only one that can run
     * on an instance where the render tier is off — which is every fresh
     * install, because `documents.render_enabled` defaults to false.
     *
     * ONE TRANSACTION, AND IT IS COMMITTED BEFORE ANY RENDER IS ATTEMPTED. The
     * create route renders AFTER this returns and appends through
     * {@see appendArtifact()}, rather than going through {@see issue()}. That
     * ordering is chosen, and the failure it prefers is the opposite of
     * `issue()`'s:
     *
     *   `issue()` is for a caller who asked for BYTES and got them — the
     *   persisted render. If the write fails there is nothing worth keeping, so
     *   it rolls the record back.
     *
     *   A create is for a caller who asked for a DOCUMENT. The values they typed
     *   are on the row and the id is what routing needs. Rolling that back
     *   because an optional headless-Chromium service is restarting would
     *   discard a person's work over a component the system is designed to run
     *   without — and it would do it on exactly the deployments where that
     *   service is least reliable. So the record survives, the response says the
     *   artifact was not stored and why, and `POST /api/documents/{id}/render`
     *   is the retry.
     *
     * @param array<string, mixed>             $template     A normalized `document_templates` row.
     * @param list<array<string, string>>|null $variableData Already normalized; see {@see issue()}.
     *
     * @return array<string, mixed> The document row.
     */
    public function raise(
        int $tenantId,
        ?int $actorId,
        array $template,
        string $title,
        ?array $variableData = null,
    ): array {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $documentId = $this->insertRecord($tenantId, $actorId, $template, $title, $variableData);
            $document = $this->documents->findById($documentId, $tenantId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($document === null) {
            throw new \RuntimeException('Document was raised but could not be read back.');
        }

        return $document;
    }

    /**
     * The record insert, written ONCE and shared by {@see raise()} and
     * {@see issue()}.
     *
     * Two copies of this would be two readings of a document's provenance: which
     * fields are snapshots, which are derived from the actor's membership, and
     * what an actor with no unit produces. A create route that stamped
     * `origin_ou_id` differently from the persisted-render route would make the
     * organizer's "raised by my unit" folder answer differently depending on
     * which button the document came from, with nothing on the row to say so.
     *
     * @param array<string, mixed>             $template
     * @param list<array<string, string>>|null $variableData
     */
    private function insertRecord(
        int $tenantId,
        ?int $actorId,
        array $template,
        string $title,
        ?array $variableData,
    ): int {
        return $this->documents->create($tenantId, [
            'document_template_id' => isset($template['id']) ? (int) $template['id'] : null,
            // Snapshot, not a join — the template may be renamed or deleted
            // (ON DELETE SET NULL), and the record still has to be able to
            // say what it came from.
            'template_name'        => (string) ($template['name'] ?? ''),
            'title'                => $title,
            'origin_ou_id'         => $actorId !== null ? $this->originOuFor($tenantId, $actorId) : null,
            'created_by'           => $actorId,
            // The values a person typed, and the only place they survive when
            // there is no artifact to bake them into. Migration 118.
            'variable_data'        => $variableData,
        ]);
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
        // Delegated to {@see PrimaryMembershipOu} since #947 item 3, which needs
        // the identical answer to stamp `document_route_events.from_ou_id`. Two
        // copies differing by one ORDER BY would make a document's origin unit
        // and the unit its own issue event records disagree — for the same
        // person, in the same request — with nothing to flag it.
        return PrimaryMembershipOu::forProfile($this->db, $tenantId, $profileId);
    }
}
