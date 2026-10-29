<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Data-access for `document_sources` (editor M2): the documents the block
 * editor writes. A row's `blocks` is the WhityDocument contract's `Block[]`,
 * stored as JSON (on PostgreSQL, jsonb keeps object keys in its own order;
 * nothing in the contract depends on key order).
 *
 * TENANT-OWNED: every statement binds an explicit `tenant_id` predicate
 * (literal SQL so the CI tenant-predicate scanner verifies it).
 *
 * The draft is saved with optimistic concurrency: {@see self::saveDraft()}
 * updates only while `draft_version` still equals the version the caller
 * edited, in the same statement that bumps it, so two saves made against the
 * same version can't both win — the second matches no row and is told so.
 */
final class DocumentSourceRepository
{
    private const COLUMNS = 'id, tenant_id, kind, title, lang, default_view, full_width, page, record_type, blocks,
        draft_version, published_version, scope, required_permission, created_by, owner_ou_id, updated_by,
        created_at, updated_at';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The tenant's documents, newest first, without their blocks (a list never
     * needs them, and they are the bulk of a row).
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?string $kind = null): array
    {
        if ($kind !== null) {
            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNS . '
                 FROM document_sources WHERE tenant_id = :tenant_id AND kind = :kind ORDER BY updated_at DESC, id DESC'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':kind' => $kind]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT ' . self::COLUMNS . '
                 FROM document_sources WHERE tenant_id = :tenant_id ORDER BY updated_at DESC, id DESC'
            );
            $stmt->execute([':tenant_id' => $tenantId]);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->normalizeRow($row, false), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM document_sources WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row, true) : null;
    }

    /**
     * @param array{kind: string, title: string, lang: string, default_view: string, full_width?: bool,
     *              page?: ?array<string, mixed>, record_type?: ?string, blocks: list<array<string, mixed>>,
     *              scope?: string, required_permission?: ?string, created_by: int, owner_ou_id?: ?int} $rec
     * @return int The new row id.
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_sources
                 (tenant_id, kind, title, lang, default_view, full_width, page, record_type, blocks, draft_version,
                  scope, required_permission, created_by, owner_ou_id, updated_by, created_at, updated_at)
             VALUES (:tenant_id, :kind, :title, :lang, :default_view, :full_width, :page, :record_type, :blocks, 1,
                  :scope, :required_permission, :created_by, :owner_ou_id, :updated_by, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id'           => $tenantId,
            ':kind'                => $rec['kind'],
            ':title'               => $rec['title'],
            ':lang'                => $rec['lang'],
            ':default_view'        => $rec['default_view'],
            ':full_width'          => ($rec['full_width'] ?? false) ? 1 : 0,
            ':page'                => isset($rec['page']) ? $this->encode($rec['page']) : null,
            ':record_type'         => $rec['record_type'] ?? null,
            ':blocks'              => $this->encode($rec['blocks']),
            ':scope'               => $rec['scope'] ?? DocumentAccessPolicy::SCOPE_PERSONAL,
            ':required_permission' => $rec['required_permission'] ?? null,
            ':created_by'          => $rec['created_by'],
            ':owner_ou_id'         => $rec['owner_ou_id'] ?? null,
            ':updated_by'          => $rec['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Save the draft if nobody saved since `$baseVersion`. Returns the new
     * version, or null when the row is at another version (or gone).
     *
     * @param array{title?: string, lang?: string, default_view?: string, full_width?: bool,
     *              page?: ?array<string, mixed>, blocks?: list<array<string, mixed>>} $fields
     */
    public function saveDraft(int $id, int $tenantId, int $baseVersion, array $fields, int $updatedBy): ?int
    {
        $sets = ['draft_version = draft_version + 1', 'updated_by = :updated_by', 'updated_at = NOW()'];
        $params = [
            ':id'           => $id,
            ':tenant_id'    => $tenantId,
            ':base_version' => $baseVersion,
            ':updated_by'   => $updatedBy,
        ];
        if (array_key_exists('title', $fields)) {
            $sets[] = 'title = :title';
            $params[':title'] = $fields['title'];
        }
        if (array_key_exists('lang', $fields)) {
            $sets[] = 'lang = :lang';
            $params[':lang'] = $fields['lang'];
        }
        if (array_key_exists('default_view', $fields)) {
            $sets[] = 'default_view = :default_view';
            $params[':default_view'] = $fields['default_view'];
        }
        if (array_key_exists('full_width', $fields)) {
            $sets[] = 'full_width = :full_width';
            $params[':full_width'] = $fields['full_width'] ? 1 : 0;
        }
        if (array_key_exists('page', $fields)) {
            $sets[] = 'page = :page';
            $params[':page'] = $fields['page'] !== null ? $this->encode($fields['page']) : null;
        }
        if (array_key_exists('blocks', $fields)) {
            $sets[] = 'blocks = :blocks';
            $params[':blocks'] = $this->encode($fields['blocks']);
        }

        $stmt = $this->db->prepare(
            'UPDATE document_sources SET ' . implode(', ', $sets) . '
             WHERE id = :id AND tenant_id = :tenant_id AND draft_version = :base_version'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $baseVersion + 1;
    }

    public function delete(int $id, int $tenantId): void
    {
        $stmt = $this->db->prepare('DELETE FROM document_sources WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row, bool $withBlocks): array
    {
        $page = $row['page'] !== null ? json_decode((string) $row['page'], true) : null;
        $out = [
            'id'                  => (int) $row['id'],
            'tenant_id'           => (int) $row['tenant_id'],
            'kind'                => (string) $row['kind'],
            'title'               => (string) $row['title'],
            'lang'                => (string) $row['lang'],
            'default_view'        => (string) $row['default_view'],
            'full_width'          => DbBool::of($row['full_width']),
            'page'                => is_array($page) ? $page : null,
            'record_type'         => $row['record_type'] !== null ? (string) $row['record_type'] : null,
            'draft_version'       => (int) $row['draft_version'],
            'published_version'   => $row['published_version'] !== null ? (int) $row['published_version'] : null,
            'scope'               => (string) $row['scope'],
            'required_permission' => $row['required_permission'] !== null ? (string) $row['required_permission'] : null,
            'created_by'          => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'owner_ou_id'         => $row['owner_ou_id'] !== null ? (int) $row['owner_ou_id'] : null,
            'updated_by'          => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'created_at'          => (string) $row['created_at'],
            'updated_at'          => (string) $row['updated_at'],
        ];
        if ($withBlocks) {
            $blocks = json_decode((string) $row['blocks'], true);
            $out['blocks'] = is_array($blocks) ? $blocks : [];
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
