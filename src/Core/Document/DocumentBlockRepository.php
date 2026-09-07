<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Db\DbBool;

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
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, owner_ou_id, starter_key, created_at, updated_at
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
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, owner_ou_id, starter_key, created_at, updated_at
             FROM document_blocks WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array{name: string, data: array<string,mixed>|list<mixed>, scope?: string,
     *              required_permission?: ?string, is_system?: bool, created_by?: ?int,
     *              owner_ou_id?: ?int, starter_key?: ?string} $rec
     * @return int The new row id.
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_blocks
                 (tenant_id, name, data, scope, required_permission, is_system, created_by, owner_ou_id, starter_key, created_at, updated_at)
             VALUES (:tenant_id, :name, :data, :scope, :required_permission, :is_system, :created_by, :owner_ou_id, :starter_key, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id'           => $tenantId,
            ':name'                => $rec['name'],
            ':data'                => $this->encodeData($rec['data']),
            ':scope'               => $rec['scope'] ?? 'personal',
            ':required_permission' => $rec['required_permission'] ?? null,
            ':is_system'           => ($rec['is_system'] ?? false) ? 1 : 0,
            ':created_by'          => $rec['created_by'] ?? null,
            ':owner_ou_id'         => $rec['owner_ou_id'] ?? null,
            ':starter_key'         => $rec['starter_key'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The seeder-only "which starters has this tenant already got" lookup —
     * mirrors {@see DocumentTemplateRepository::starterKeysForTenant()} (see
     * its docblock for the rationale: a stable identity distinct from the
     * user-renameable `name`, and why it stays keys-only now that
     * {@see DocumentRecordTrait::normalizeRow()} carries `starter_key` on the
     * rows themselves).
     *
     * @return list<string>
     */
    public function starterKeysForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT starter_key FROM document_blocks WHERE tenant_id = :tenant_id AND starter_key IS NOT NULL'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        /** @var list<string> */
        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array{name?: string, data?: array<string,mixed>|list<mixed>, scope?: string,
     *              required_permission?: ?string, owner_ou_id?: ?int} $fields
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
        if (array_key_exists('owner_ou_id', $fields)) {
            $set[] = 'owner_ou_id = :owner_ou_id';
            $params[':owner_ou_id'] = $fields['owner_ou_id'];
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

    /**
     * Does any OTHER block in the tenant hold a live `blockInstance` pointer at
     * $blockId? (#1186 slice 3.)
     *
     * The sibling of {@see DocumentTemplateRepository::referencesBlock()}, and
     * the half that did not exist while nesting did not. That guard asks only
     * whether a TEMPLATE points at the block, which was a complete question
     * when a block could not contain another block. It is not any more: a logo
     * used by nothing but the letterhead block would have answered "not
     * referenced", been deleted, and left the letterhead pointing at a row that
     * is gone — precisely the orphaned pointer the 409 exists to prevent.
     *
     * $blockId is excluded from its own scan. A block that contains itself is
     * malformed, and letting it veto its own deletion would make the one row
     * somebody most wants to remove the one row they cannot.
     *
     * Two engines, one answer, mirroring the template scan exactly: a
     * `jsonb_path_exists` recursive descent on Postgres, and a decode-and-walk
     * in PHP on SQLite, where `data` is TEXT and jsonpath does not exist.
     */
    public function referencesBlock(int $blockId, int $tenantId): bool
    {
        if ($this->driver() === 'pgsql') {
            // ::text for the reason the template scan records — Postgres cannot
            // infer a bound parameter's type inside jsonb_build_object()'s
            // variadic "any" signature and raises 42P18 without the cast.
            $stmt = $this->db->prepare(
                "SELECT EXISTS (
                     SELECT 1 FROM document_blocks
                      WHERE tenant_id = :tenant_id
                        AND id <> :self_id
                        AND jsonb_path_exists(
                            data,
                            '\$.** ? (@.type == \"blockInstance\" && @.blockId == \$bid)',
                            jsonb_build_object('bid', :block_id::text)
                        )
                 ) AS referenced"
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':self_id'   => $blockId,
                ':block_id'  => (string) $blockId,
            ]);

            return DbBool::of($stmt->fetchColumn());
        }

        $stmt = $this->db->prepare(
            'SELECT data FROM document_blocks WHERE tenant_id = :tenant_id AND id <> :self_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':self_id' => $blockId]);

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
     * WHICH blocks in the tenant nest $blockId — the list form of the yes/no
     * {@see self::referencesBlock()} answers (#1186 slice 3).
     *
     * The sibling of {@see DocumentTemplateRepository::referencingTemplates()},
     * and it exists for the reason that one does: "is it referenced?" is enough
     * to refuse a delete but is the wrong thing to put in front of a person.
     * A usage screen that reported no users over a delete the server then
     * refuses with 409 is the exact client/server disagreement the reference
     * scanners are kept in parity to avoid — and nesting would have created it,
     * because a block held only by another block is invisible to the template
     * list while being perfectly visible to the delete guard.
     *
     * Rows carry the governance columns {@see DocumentAccessPolicy} reads, so
     * the caller can filter this list the way it filters the template one: a
     * viewer must not learn the names of blocks they cannot see.
     *
     * @return list<array<string, mixed>>
     */
    public function referencingBlocks(int $blockId, int $tenantId): array
    {
        if ($this->driver() === 'pgsql') {
            $stmt = $this->db->prepare(
                "SELECT id, tenant_id, name, scope, required_permission, is_system, created_by, owner_ou_id, starter_key, created_at, updated_at
                   FROM document_blocks
                  WHERE tenant_id = :tenant_id
                    AND id <> :self_id
                    AND jsonb_path_exists(
                        data,
                        '\$.** ? (@.type == \"blockInstance\" && @.blockId == \$bid)',
                        jsonb_build_object('bid', :block_id::text)
                    )
                  ORDER BY updated_at DESC, id DESC"
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':self_id'   => $blockId,
                ':block_id'  => (string) $blockId,
            ]);
            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(self::normalizeReferenceRow(...), $rows);
        }

        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, owner_ou_id, starter_key, created_at, updated_at
             FROM document_blocks WHERE tenant_id = :tenant_id AND id <> :self_id ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':self_id' => $blockId]);

        $needle = (string) $blockId;
        $out = [];
        /** @var array<string, mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) $row['data'], true);
            if (is_array($decoded) && self::treeReferencesBlock($decoded, $needle)) {
                $out[] = self::normalizeReferenceRow($row);
            }
        }

        return $out;
    }

    /**
     * Map a reference row to the governance shape, mirroring
     * {@see DocumentTemplateRepository::normalizeReferenceRow()} exactly. `data`
     * is keyed as an empty array so the row stays assignable wherever a block
     * row is expected.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeReferenceRow(array $row): array
    {
        return [
            'id'                  => (int) $row['id'],
            'tenant_id'           => (int) $row['tenant_id'],
            'name'                => (string) $row['name'],
            'data'                => [],
            'scope'               => (string) $row['scope'],
            'required_permission' => $row['required_permission'] !== null ? (string) $row['required_permission'] : null,
            'is_system'           => DbBool::of($row['is_system']),
            'created_by'          => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'owner_ou_id'         => ($row['owner_ou_id'] ?? null) !== null ? (int) $row['owner_ou_id'] : null,
            'starter_key'         => ($row['starter_key'] ?? null) !== null ? (string) $row['starter_key'] : null,
            'created_at'          => (string) $row['created_at'],
            'updated_at'          => (string) $row['updated_at'],
        ];
    }

    /**
     * Recursive-descent shape check, identical to the template repository's and
     * to `collectBlockIds` in packages/ui: `{type: 'blockInstance', blockId}` at
     * any depth under any key. Deliberately does not assume the element shape,
     * so it stays correct across schema changes.
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
