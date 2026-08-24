<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;
use Whity\Core\Db\DbBool;

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
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, owner_ou_id, created_at, updated_at
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
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, owner_ou_id, created_at, updated_at
             FROM document_templates WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * @param array{name: string, data: array<string,mixed>, scope?: string,
     *              required_permission?: ?string, is_system?: bool, created_by?: ?int,
     *              owner_ou_id?: ?int, starter_key?: ?string} $rec
     * @return int The new row id.
     */
    public function create(int $tenantId, array $rec): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_templates
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
     * The seeder-only "which starters has this tenant already got" lookup
     * (WC-515 REMAINING #3): the non-null `starter_key`s already present for
     * the tenant, across BOTH user-visible and any other rows — a stable
     * identity distinct from the (user-renameable) `name`, so the seeder can
     * insert-if-missing per starter without duplicating or clobbering a row a
     * user has since edited. Seeder-internal; not part of the public API
     * response shape (see {@see DocumentRecordTrait::normalizeRow}).
     *
     * @return list<string>
     */
    public function starterKeysForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT starter_key FROM document_templates WHERE tenant_id = :tenant_id AND starter_key IS NOT NULL'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        /** @var list<string> */
        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Update the mutable fields of a template, scoped to the tenant.
     *
     * @param array{name?: string, data?: array<string,mixed>, scope?: string,
     *              required_permission?: ?string, owner_ou_id?: ?int} $fields
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
        if (array_key_exists('owner_ou_id', $fields)) {
            $set[] = 'owner_ou_id = :owner_ou_id';
            $params[':owner_ou_id'] = $fields['owner_ou_id'];
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
     *
     * DOCUMENTS ISSUED FROM IT ARE DETACHED, NOT DELETED (#947 item 1). The
     * whole point of storing a rendered document is that it survives the
     * template it came from — `documents.document_template_id` is therefore
     * `ON DELETE SET NULL` (migration 108) and `documents.template_name` holds
     * the snapshot that keeps the record legible afterwards.
     *
     * The detach is done EXPLICITLY here rather than left to the constraint,
     * for the reason migration 102 records for `organizational_units.ou_type_id`:
     * SQLite honours `ON DELETE` only under `PRAGMA foreign_keys = ON`, which is
     * off by default, so on the offline/desktop engine the column would keep
     * pointing at a template that no longer exists. Doing it in SQL here means
     * both engines finish in the same state and the CI tenant-predicate scanner
     * can see the statement is scoped. The constraint stays as the backstop for
     * anything that deletes a template by another route.
     */
    public function delete(int $id, int $tenantId): int
    {
        $detach = $this->db->prepare(
            'UPDATE documents SET document_template_id = NULL
              WHERE document_template_id = :id AND tenant_id = :tenant_id'
        );
        $detach->execute([':id' => $id, ':tenant_id' => $tenantId]);

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

            return DbBool::of($stmt->fetchColumn());
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
     * WHICH templates in the tenant hold a live `blockInstance` pointer at
     * $blockId — the same question {@see self::referencesBlock()} answers as a
     * yes/no, answered as a list so a caller can say WHAT would break.
     *
     * This exists because "is it referenced?" is the wrong question to put in
     * front of a person. `referencesBlock()` is enough to REFUSE a delete, but a
     * block is pointer-referenced with Gutenberg synced-pattern semantics — an
     * EDIT propagates to every instance and is never refused by anything. So the
     * genuinely destructive action (editing a block twelve templates depend on)
     * has no guard at all, and the only way to give one is to be able to name
     * the twelve.
     *
     * `data` is deliberately NOT selected. A usage answer needs identity and the
     * governance columns the visibility policy reads, not every referencing
     * template's entire body — the caller of this is rendering a list, and
     * fetching N full template trees to display N names is how a management
     * screen becomes the slowest page in the app. The returned rows therefore
     * carry `data => []`, which is why {@see self::normalizeRow()} is not reused
     * here.
     *
     * Row-level visibility is NOT applied here — this is the tenant-scoped store,
     * and the policy is the handler's to apply (the same split every other method
     * on this repository observes). The handler needs the unfiltered total on
     * purpose: see {@see \Whity\Api\DocumentBlocksApiHandler::usage()}.
     *
     * Two engines, one answer — the same split, and for the same reasons, as
     * {@see self::referencesBlock()}.
     *
     * @return list<array<string, mixed>> Newest-updated first, matching listForTenant()'s order.
     */
    public function referencingTemplates(int $blockId, int $tenantId): array
    {
        if ($this->driver() === 'pgsql') {
            // ::text cast for the reason given in referencesBlock().
            $stmt = $this->db->prepare(
                "SELECT id, tenant_id, name, scope, required_permission, is_system, created_by, owner_ou_id, created_at, updated_at
                   FROM document_templates
                  WHERE tenant_id = :tenant_id
                    AND jsonb_path_exists(
                        data,
                        '\$.** ? (@.type == \"blockInstance\" && @.blockId == \$bid)',
                        jsonb_build_object('bid', :block_id::text)
                    )
                  ORDER BY updated_at DESC, id DESC"
            );
            $stmt->execute([':tenant_id' => $tenantId, ':block_id' => (string) $blockId]);
            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return array_map(self::normalizeReferenceRow(...), $rows);
        }

        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, name, data, scope, required_permission, is_system, created_by, owner_ou_id, created_at, updated_at
             FROM document_templates WHERE tenant_id = :tenant_id ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

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
     * Map a reference row: identity, the governance columns
     * {@see DocumentAccessPolicy} reads, and `data => []`.
     *
     * Separate from {@see DocumentRecordTrait::normalizeRow()} rather than a flag
     * on it, because the two answer different questions and share only their
     * casts. `data` is still keyed (as an empty array) so the shape stays
     * assignable wherever a template row is expected — a caller that reached for
     * `$row['data']` would otherwise get an undefined-key warning on a row that
     * simply was not asked to carry one.
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
            'created_at'          => (string) $row['created_at'],
            'updated_at'          => (string) $row['updated_at'],
        ];
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
