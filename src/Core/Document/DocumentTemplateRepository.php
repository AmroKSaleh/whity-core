<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;

/**
 * Data-access for `document_templates` (WC-docdesigner) — saved designer
 * templates. TENANT-OWNED: every statement binds an explicit `tenant_id`
 * predicate (literal SQL so the CI tenant-predicate scanner verifies it), so a
 * template written under one tenant can never be read or mutated under another.
 *
 * The whole client DocTemplate JSON is stored verbatim in `data`; RBAC-filtered
 * visibility (scope / required_permission) is applied by the service/handler
 * layer, not here — this repo is the tenant-scoped store.
 */
final class DocumentTemplateRepository
{
    use DocumentRecordTrait;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * All templates for a tenant (newest first). Visibility/RBAC filtering is the
     * caller's concern.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, created_at, updated_at
             FROM document_templates WHERE tenant_id = :tenant_id ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, created_at, updated_at
             FROM document_templates WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array{name: string, data: array<string,mixed>, scope?: string,
     *              required_permission?: ?string, is_system?: bool, created_by?: ?int} $rec
     * @return int The new row id.
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_templates
                 (tenant_id, name, data, scope, required_permission, is_system, created_by, created_at, updated_at)
             VALUES (:tenant_id, :name, :data, :scope, :required_permission, :is_system, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id'           => $tenantId,
            ':name'                => $rec['name'],
            ':data'                => $this->encodeData($rec['data']),
            ':scope'               => $rec['scope'] ?? 'personal',
            ':required_permission' => $rec['required_permission'] ?? null,
            ':is_system'           => ($rec['is_system'] ?? false) ? 1 : 0,
            ':created_by'          => $rec['created_by'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update the mutable fields of a template, scoped to the tenant.
     *
     * @param array{name?: string, data?: array<string,mixed>, scope?: string,
     *              required_permission?: ?string} $fields
     * @return int Rows affected (0 when not found / wrong tenant).
     */
    public function update(int $id, int $tenantId, array $fields): int
    {
        $set = [];
        $params = [':id' => $id, ':tenant_id' => $tenantId];
        if (array_key_exists('name', $fields)) {
            $set[] = 'name = :name';
            $params[':name'] = $fields['name'];
        }
        if (array_key_exists('data', $fields)) {
            $set[] = 'data = :data';
            $params[':data'] = $this->encodeData($fields['data']);
        }
        if (array_key_exists('scope', $fields)) {
            $set[] = 'scope = :scope';
            $params[':scope'] = $fields['scope'];
        }
        if (array_key_exists('required_permission', $fields)) {
            $set[] = 'required_permission = :required_permission';
            $params[':required_permission'] = $fields['required_permission'];
        }
        if ($set === []) {
            return 0;
        }
        $set[] = 'updated_at = NOW()';

        $stmt = $this->db->prepare(
            'UPDATE document_templates SET ' . implode(', ', $set) . ' WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Delete a template, scoped to the tenant. Returns rows affected.
     */
    public function delete(int $id, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM document_templates WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount();
    }

    /**
     * The block delete-guard (WC-521): whether ANY template in the tenant still
     * holds a live `blockInstance` pointer ({type:'blockInstance', blockId}) at
     * $blockId, anywhere in its `data` JSON (any page/element, at any depth —
     * this deliberately does not assume the exact pages/elements shape, so it
     * stays correct across template-schema changes). Callers use this to REFUSE
     * deleting a referenced block rather than silently orphaning the pointer.
     *
     * Two engines, one answer:
     *  - Postgres: a `jsonb_path_exists` recursive-descent (`.**`) scan done
     *    entirely in the database — efficient, no need to fetch template bodies.
     *  - SQLite (the default unit-test engine): the `data` column is plain TEXT
     *    (see SchemaFromMigrations' JSONB->TEXT translation) and SQLite's JSON
     *    functions differ from Postgres's jsonpath, so rows are fetched and the
     *    decoded tree is walked in PHP instead. Same semantics, same answer.
     */
    public function referencesBlock(int $blockId, int $tenantId): bool
    {
        if ($this->driver() === 'pgsql') {
            // The parameter is cast explicitly (::text) because it is only ever
            // consumed inside jsonb_build_object()'s variadic "any" signature —
            // Postgres cannot infer a bound parameter's type from a polymorphic
            // argument position and raises "indeterminate datatype" (42P18)
            // without the cast.
            $stmt = $this->db->prepare(
                "SELECT EXISTS (
                     SELECT 1 FROM document_templates
                      WHERE tenant_id = :tenant_id
                        AND jsonb_path_exists(
                            data,
                            '\$.** ? (@.type == \"blockInstance\" && @.blockId == \$bid)',
                            jsonb_build_object('bid', :block_id::text)
                        )
                 ) AS referenced"
            );
            $stmt->execute([':tenant_id' => $tenantId, ':block_id' => (string) $blockId]);

            return (bool) $stmt->fetchColumn();
        }

        $stmt = $this->db->prepare(
            'SELECT data FROM document_templates WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        $needle = (string) $blockId;
        while (($raw = $stmt->fetchColumn()) !== false) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded) && self::treeReferencesBlock($decoded, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively walk a decoded template JSON tree for a blockInstance element
     * pointing at $needle (the block id, as a string — the client's blockId
     * field is a string, {@see web/lib/documents/types.ts}).
     *
     * @param array<int|string, mixed> $node
     */
    private static function treeReferencesBlock(array $node, string $needle): bool
    {
        if (
            ($node['type'] ?? null) === 'blockInstance'
            && array_key_exists('blockId', $node)
            && (string) $node['blockId'] === $needle
        ) {
            return true;
        }
        foreach ($node as $value) {
            if (is_array($value) && self::treeReferencesBlock($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function driver(): string
    {
        $name = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        return is_string($name) ? $name : '';
    }
}
