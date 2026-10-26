<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use JsonException;
use PDO;

/**
 * Reads and writes a document's editable BODY (WHIT-679).
 *
 * The body is the FlowContent a person is drafting, stored one row per
 * document in `document_bodies` (migration 159, whose docblock carries the
 * schema reasoning). This class owns the two rules that give "draft" a
 * meaning, and both are enforced in SQL rather than in PHP:
 *
 *  1. A write only lands on a document THIS TENANT owns.
 *  2. A write only lands while the body is still a draft.
 *
 * WHY BOTH RULES ARE PREDICATES RATHER THAN `if` STATEMENTS
 * ----------------------------------------------------------
 * "Load the row, check it, then write" is a read-then-write race. Two saves
 * either side of an issue both read "still a draft", and the later one writes
 * into a document that has already gone out. The window is small and the
 * consequence — an issued document whose body silently changed after issue —
 * is exactly the thing this subsystem exists to prevent, so the check belongs
 * on the statement that does the writing.
 *
 * The tenant rule has a second, less obvious half. A `WHERE tenant_id = ?` on
 * the UPDATE alone is not enough, because the FIRST save is an INSERT and an
 * INSERT has no existing row to filter: the tenant has to be checked against
 * the DOCUMENT. That is why {@see save()} inserts from a SELECT over
 * `documents` instead of from a VALUES list — the row is only produced if the
 * document matches the tenant, so a cross-tenant write writes nothing rather
 * than creating a body on somebody else's document.
 *
 * REFUSALS ARE A `false` RETURN, NOT AN EXCEPTION
 * ------------------------------------------------
 * "This body is already issued" and "this document is not yours" are ordinary
 * outcomes a caller has to handle and turn into a 409 or a 404, not exceptional
 * conditions. The caller decides which; this class reports only that nothing
 * was written. The affected-row count is the report, so a refusal and a
 * successful write are distinguished by the same mechanism that performs them.
 */
final class DocumentBodyRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Write (or overwrite) the draft body of a document this tenant owns.
     *
     * Returns false — writing nothing — when the document does not belong to
     * the tenant, does not exist, or already has an ISSUED body.
     *
     * @param list<array<string, mixed>> $content FlowContent blocks.
     *
     * @throws JsonException if the body cannot be encoded.
     */
    public function save(int $tenantId, int $documentId, array $content, ?int $updatedBy): bool
    {
        // INSERT ... SELECT over `documents`, so the tenant is checked against
        // the document on the very first write, when there is no body row to
        // filter on. ON CONFLICT ... WHERE issued_at IS NULL is the freeze:
        // once issued, the update matches nothing and the statement reports
        // zero affected rows.
        $statement = $this->db->prepare(
            'INSERT INTO document_bodies (document_id, tenant_id, content, updated_at, updated_by)
             SELECT d.id, d.tenant_id, :content, NOW(), :updated_by
               FROM documents d
              WHERE d.id = :document_id AND d.tenant_id = :tenant_id
             ON CONFLICT (document_id) DO UPDATE
                SET content = EXCLUDED.content,
                    updated_at = NOW(),
                    updated_by = EXCLUDED.updated_by
              WHERE document_bodies.issued_at IS NULL'
        );

        $statement->execute([
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'updated_by' => $updatedBy,
            'document_id' => $documentId,
            'tenant_id' => $tenantId,
        ]);

        return $statement->rowCount() === 1;
    }

    /**
     * The document's body, or null when it has none — which is the ordinary
     * state of every document raised from a template.
     *
     * @return array{content: list<array<string, mixed>>, issued_at: ?string, updated_at: string, updated_by: ?int}|null
     *
     * @throws JsonException if the stored body cannot be decoded.
     */
    public function find(int $tenantId, int $documentId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT content, issued_at, updated_at, updated_by
               FROM document_bodies
              WHERE document_id = :document_id AND tenant_id = :tenant_id'
        );
        $statement->execute(['document_id' => $documentId, 'tenant_id' => $tenantId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        /** @var list<array<string, mixed>> $content */
        $content = json_decode((string) $row['content'], true, 512, JSON_THROW_ON_ERROR);

        return [
            'content' => $content,
            'issued_at' => $row['issued_at'] === null ? null : (string) $row['issued_at'],
            'updated_at' => (string) $row['updated_at'],
            'updated_by' => $row['updated_by'] === null ? null : (int) $row['updated_by'],
        ];
    }

    /**
     * Is this document an unissued draft?
     *
     * False for a document with no body at all, which is deliberate: a
     * template-rendered document is not a draft that nobody has got round to
     * issuing, it is a document of the kind that never had an editable body.
     */
    public function isDraft(int $tenantId, int $documentId): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1
               FROM document_bodies
              WHERE document_id = :document_id AND tenant_id = :tenant_id AND issued_at IS NULL'
        );
        $statement->execute(['document_id' => $documentId, 'tenant_id' => $tenantId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Freeze the body: record that this document has been issued.
     *
     * Returns false when the document has no body, is not this tenant's, or
     * has already been issued. The `issued_at IS NULL` predicate is what makes
     * the second of those a refusal rather than a silent re-stamp: the moment
     * a document went out is a fact about one event, and a second issue must
     * not move it.
     */
    public function issue(int $tenantId, int $documentId): bool
    {
        $statement = $this->db->prepare(
            'UPDATE document_bodies
                SET issued_at = NOW()
              WHERE document_id = :document_id AND tenant_id = :tenant_id AND issued_at IS NULL'
        );
        $statement->execute(['document_id' => $documentId, 'tenant_id' => $tenantId]);

        return $statement->rowCount() === 1;
    }
}
