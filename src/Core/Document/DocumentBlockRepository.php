<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;

/**
 * Data-access for `document_blocks` (WC-docdesigner) — reusable designer blocks
 * (Gutenberg synced-pattern model). A block's `data` is a DocElement[] fragment;
 * documents reference it by POINTER (blockInstance), never an inline copy, so
 * editing a block propagates to every instance.
 *
 * TENANT-OWNED: every statement binds an explicit `tenant_id` predicate (literal
 * SQL so the CI tenant-predicate scanner verifies it). Reference-integrity policy
 * (live-latest + a delete guard when instances exist) is enforced at the
 * service/handler layer; this repo is the tenant-scoped store.
 */
final class DocumentBlockRepository
{
    use DocumentRecordTrait;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, created_at, updated_at
             FROM document_blocks WHERE tenant_id = :tenant_id ORDER BY updated_at DESC, id DESC'
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
             FROM document_blocks WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array{name: string, data: array<string,mixed>|list<mixed>, scope?: string,
     *              required_permission?: ?string, is_system?: bool, created_by?: ?int} $rec
     * @return int The new row id.
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_blocks
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
     * @param array{name?: string, data?: array<string,mixed>|list<mixed>, scope?: string,
     *              required_permission?: ?string} $fields
     * @return int Rows affected.
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
            'UPDATE document_blocks SET ' . implode(', ', $set) . ' WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Delete a block, scoped to the tenant. Returns rows affected. The caller is
     * responsible for the reference-integrity guard (refuse when instances exist).
     */
    public function delete(int $id, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM document_blocks WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);

        return $stmt->rowCount();
    }
}
