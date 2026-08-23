<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;

/**
 * Data-access for `documents` (#947 item 1) — the RECORD half of an issued
 * document: its identity, the template it came from, who raised it, from which
 * unit, and when. The bytes live one table over, in `document_artifacts`
 * ({@see DocumentArtifactRepository}), because a record survives a correction
 * and a set of bytes must not be rewritten by one — see migration 106.
 *
 * TENANT-OWNED. Every statement binds an explicit `tenant_id` predicate, spelled
 * out in literal SQL so scripts/ci-tenant-predicate-guard.php can verify it by
 * reading this file. A document issued under one tenant can never be read under
 * another, and the id in the path is never trusted on its own.
 *
 * VISIBILITY IS FILTERED IN SQL, NOT IN PHP
 * -----------------------------------------
 * {@see DocumentVisibilityPolicy} decides whether a caller sees only their own
 * documents or all of the tenant's, and the answer is pushed down into the
 * WHERE clause here as `$onlyCreatedBy` rather than applied to a fetched page.
 * The template/block repositories filter in PHP and can afford to — a tenant
 * holds a few dozen templates. Documents accumulate without bound, so filtering
 * a page after LIMIT returns short pages (25 rows fetched, 3 visible, "page 2"
 * of a total the caller cannot see) and a total that does not match what is
 * listed. The count below applies the same predicate for the same reason.
 */
final class DocumentRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a document record and return its id.
     *
     * `template_name` is a SNAPSHOT taken at issue time, not a join: the
     * template may be renamed or deleted (the foreign key is ON DELETE SET
     * NULL, deliberately — see migration 106), and a browser listing a document
     * whose origin was retired should still be able to say what it was.
     *
     * @param array{document_template_id?: ?int, template_name: string, title: string,
     *              origin_ou_id?: ?int, created_by?: ?int} $rec
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO documents
                 (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (:tenant_id, :document_template_id, :template_name, :title, :origin_ou_id, :created_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id'            => $tenantId,
            ':document_template_id' => $rec['document_template_id'] ?? null,
            ':template_name'        => $rec['template_name'],
            ':title'                => $rec['title'],
            ':origin_ou_id'         => $rec['origin_ou_id'] ?? null,
            ':created_by'           => $rec['created_by'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * One document, tenant-scoped. Null when it does not exist OR belongs to
     * another tenant — the caller cannot tell the two apart, which is the point.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at
             FROM documents WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * A page of the tenant's documents, newest first.
     *
     * @param int|null $onlyCreatedBy When set, restricts to documents this
     *        profile raised — the SQL half of {@see DocumentVisibilityPolicy}.
     *        Null means the caller holds the tenant-wide read grant.
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?int $onlyCreatedBy, int $limit, int $offset): array
    {
        // Two literal statements rather than one built by concatenation: the
        // tenant predicate guard reads this source, and a WHERE clause assembled
        // from fragments is one it cannot verify. The duplication is four lines
        // and buys a check that actually runs.
        if ($onlyCreatedBy === null) {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at
                 FROM documents WHERE tenant_id = :tenant_id
                 ORDER BY id DESC LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at
                 FROM documents WHERE tenant_id = :tenant_id AND created_by = :created_by
                 ORDER BY id DESC LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            $stmt->bindValue(':created_by', $onlyCreatedBy, PDO::PARAM_INT);
        }

        // LIMIT/OFFSET are bound as INT explicitly. PDO's default is PARAM_STR,
        // which emulated prepares quote — `LIMIT '25'` is a syntax error on
        // PostgreSQL and silently accepted on SQLite, so the SQLite unit run
        // would pass and the real engine would not.
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * How many documents the same predicate as {@see listForTenant()} matches,
     * so the pagination envelope reports a total the caller can actually reach.
     */
    public function countForTenant(int $tenantId, ?int $onlyCreatedBy): int
    {
        if ($onlyCreatedBy === null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM documents WHERE tenant_id = :tenant_id');
            $stmt->execute([':tenant_id' => $tenantId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM documents WHERE tenant_id = :tenant_id AND created_by = :created_by'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':created_by' => $onlyCreatedBy]);
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Map a raw row to the typed shape the handler serialises.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'tenant_id'            => (int) $row['tenant_id'],
            'document_template_id' => $row['document_template_id'] !== null ? (int) $row['document_template_id'] : null,
            'template_name'        => (string) $row['template_name'],
            'title'                => (string) $row['title'],
            'origin_ou_id'         => $row['origin_ou_id'] !== null ? (int) $row['origin_ou_id'] : null,
            'created_by'           => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at'           => (string) $row['created_at'],
        ];
    }
}
