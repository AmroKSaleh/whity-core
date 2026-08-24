<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * Data-access for `document_routes` (#947 item 3) — one circulation of one
 * document.
 *
 * A document may be routed more than once over its life: a correction is
 * appended as a new artifact (migration 108) and circulated again, and the
 * second circulation is a second route rather than an edit of the first. That
 * is why this is a table keyed on `document_id` and not a set of columns on
 * `documents`.
 *
 * NO UPDATE, NO DELETE — AND NO DRAFT STATE
 * -----------------------------------------
 * A route is created COMPLETE: its steps arrive with it, its first step is
 * resolved, and the `issued` trail event is written, all inside one transaction
 * ({@see DocumentRouter::issue()}). There is no half-built route to amend and
 * therefore no mutation path here.
 *
 * The rejected alternative was an authoring state — create a route, add steps,
 * then "send" it. It reads naturally and it reintroduces the exact thing
 * migration 108 refused: a lifecycle column (`draft` / `sent`) sitting beside
 * an append-only trail, free to disagree with it, and the copy screens read. It
 * would also make every other table here conditional — steps mutable until
 * sent, recipients absent until sent, a trail that starts mid-story — for the
 * sake of a client-side convenience a form already provides by not submitting
 * until the author is done.
 *
 * Amending a circulation that has already started is therefore a NEW route on
 * the same document, which is honest: the people already reached were reached
 * under the old plan, and the trail says so.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file. A route id from another tenant resolves to null, never to a row.
 */
final class RouteRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Create a route and return its id.
     *
     * Called only from inside {@see DocumentRouter::issue()}'s transaction — a
     * route with no steps and no `issued` event is not a state this subsystem
     * has a meaning for, so nothing else may create one.
     */
    public function create(int $tenantId, int $documentId, string $title, ?int $createdBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_routes (tenant_id, document_id, title, created_by, created_at)
             VALUES (:tenant_id, :document_id, :title, :created_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':document_id' => $documentId,
            ':title' => $title,
            ':created_by' => $createdBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * One route, tenant-scoped. Null when it does not exist OR belongs to
     * another tenant — the caller cannot tell the two apart, which is the point.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, title, created_by, created_at
               FROM document_routes
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * Every route on a document, newest first.
     *
     * Unpaginated, deliberately: a document accumulates artifacts without bound
     * but not circulations — a handful over its life is a lot, and the alternative
     * is a paginated sub-resource on a detail view where nobody would ever reach
     * page two. If that assumption ever breaks, the index
     * `idx_document_routes_tenant_document` already orders it.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $documentId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, title, created_by, created_at
               FROM document_routes
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * Map a raw row to the typed shape the presenter serialises.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'document_id' => (int) $row['document_id'],
            'title' => (string) $row['title'],
            'created_by' => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
