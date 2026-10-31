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
    private const COLUMNS = 'id, tenant_id, kind, title, lang, default_view, full_width, page, record_type, blocks, own_fields, theme,
        draft_version, published_version, scope, required_permission, created_by, owner_ou_id, updated_by,
        starter_key, legacy_template_id, created_at, updated_at';

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
     *              scope?: string, required_permission?: ?string, created_by: ?int, owner_ou_id?: ?int,
     *              own_fields?: ?list<array<string, string>>, starter_key?: ?string, legacy_template_id?: ?int,
     *              theme?: ?array<string, mixed>} $rec
     * Columns a later migration added are written only when the record sets
     * them. Migration 165 seeds the starters and converts old templates
     * through this method on a schema that doesn't have them yet (WHIT-803:
     * `theme` comes with migration 171), so a column added here must stay out
     * of the INSERT unless it is given.
     *
     * @return int The new row id.
     */
    public function create(int $tenantId, array $rec): int
    {
        // Added after migration 165 (see above): only when set.
        $later = [];
        if (isset($rec['theme'])) {
            $later['theme'] = $this->encode($rec['theme']);
        }
        $laterColumns = $later === [] ? '' : ', ' . implode(', ', array_keys($later));
        $laterValues = $later === [] ? '' : ', :' . implode(', :', array_keys($later));

        $stmt = $this->db->prepare(
            'INSERT INTO document_sources
                 (tenant_id, kind, title, lang, default_view, full_width, page, record_type, blocks, own_fields, draft_version,
                  scope, required_permission, created_by, owner_ou_id, updated_by, starter_key, legacy_template_id, created_at, updated_at' . $laterColumns . ')
             VALUES (:tenant_id, :kind, :title, :lang, :default_view, :full_width, :page, :record_type, :blocks, :own_fields, 1,
                  :scope, :required_permission, :created_by, :owner_ou_id, :updated_by, :starter_key, :legacy_template_id, NOW(), NOW()' . $laterValues . ')'
        );
        $params = array_combine(array_map(static fn (string $c): string => ':' . $c, array_keys($later)), array_values($later));
        $stmt->execute($params + [
            ':tenant_id'           => $tenantId,
            ':kind'                => $rec['kind'],
            ':title'               => $rec['title'],
            ':lang'                => $rec['lang'],
            ':default_view'        => $rec['default_view'],
            ':full_width'          => ($rec['full_width'] ?? false) ? 1 : 0,
            ':page'                => isset($rec['page']) ? $this->encode($rec['page']) : null,
            ':record_type'         => $rec['record_type'] ?? null,
            ':blocks'              => $this->encode($rec['blocks']),
            ':own_fields'          => isset($rec['own_fields']) ? $this->encode($rec['own_fields']) : null,
            ':scope'               => $rec['scope'] ?? DocumentAccessPolicy::SCOPE_PERSONAL,
            ':required_permission' => $rec['required_permission'] ?? null,
            ':created_by'          => $rec['created_by'],
            ':owner_ou_id'         => $rec['owner_ou_id'] ?? null,
            ':updated_by'          => $rec['created_by'],
            ':starter_key'         => $rec['starter_key'] ?? null,
            ':legacy_template_id'  => $rec['legacy_template_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Save the draft if nobody saved since `$baseVersion`. Returns the new
     * version, or null when the row is at another version (or gone).
     *
     * @param array{title?: string, lang?: string, default_view?: string, full_width?: bool,
     *              page?: ?array<string, mixed>, blocks?: list<array<string, mixed>>,
     *              own_fields?: ?list<array<string, string>>, theme?: ?array<string, mixed>} $fields
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
        if (array_key_exists('own_fields', $fields)) {
            $sets[] = 'own_fields = :own_fields';
            $params[':own_fields'] = $fields['own_fields'] !== null ? $this->encode($fields['own_fields']) : null;
        }
        if (array_key_exists('theme', $fields)) {
            // The document's theme, `{ ref, overrides }` (editor M4d), saved with the draft.
            $sets[] = 'theme = :theme';
            $params[':theme'] = $fields['theme'] !== null ? $this->encode($fields['theme']) : null;
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

    /**
     * The starters a tenant already has, by key; set only by the starter seeder.
     *
     * @return array<string, true>
     */
    public function starterKeys(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT starter_key FROM document_sources WHERE tenant_id = :tenant_id AND starter_key IS NOT NULL');
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * The old designer's templates already converted for a tenant, by id.
     *
     * @return array<int, true>
     */
    public function convertedTemplateIds(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT legacy_template_id FROM document_sources WHERE tenant_id = :tenant_id AND legacy_template_id IS NOT NULL');
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Who can see a document: its scope, the permission it needs and the unit
     * it is filed at. Not part of the draft, so it changes without a publish
     * and without moving the draft version.
     *
     * @param array{scope?: string, required_permission?: ?string, owner_ou_id?: ?int} $fields
     */
    public function updateAudience(int $id, int $tenantId, array $fields): void
    {
        $set = [];
        $params = [':id' => $id, ':tenant_id' => $tenantId];
        foreach (['scope', 'required_permission', 'owner_ou_id'] as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = $column . ' = :' . $column;
                $params[':' . $column] = $fields[$column];
            }
        }
        if ($set === []) {
            return;
        }
        $stmt = $this->db->prepare(
            'UPDATE document_sources SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute($params);
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
        $ownFields = $row['own_fields'] !== null ? json_decode((string) $row['own_fields'], true) : null;
        $theme = $row['theme'] !== null ? json_decode((string) $row['theme'], true) : null;
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
            'own_fields'          => is_array($ownFields) ? array_values($ownFields) : [],
            'theme'               => is_array($theme) ? $theme : null,
            'draft_version'       => (int) $row['draft_version'],
            'published_version'   => $row['published_version'] !== null ? (int) $row['published_version'] : null,
            'scope'               => (string) $row['scope'],
            'required_permission' => $row['required_permission'] !== null ? (string) $row['required_permission'] : null,
            'created_by'          => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'owner_ou_id'         => $row['owner_ou_id'] !== null ? (int) $row['owner_ou_id'] : null,
            'updated_by'          => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            // Whity's starter this came from, and the old designer template it was converted from.
            'starter_key'         => $row['starter_key'] !== null ? (string) $row['starter_key'] : null,
            'legacy_template_id'  => $row['legacy_template_id'] !== null ? (int) $row['legacy_template_id'] : null,
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
