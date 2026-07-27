<?php

declare(strict_types=1);

namespace Whity\Core\Taxonomy;

use PDO;

/**
 * Data-access layer for `tag_groups` — a named bucket of tags scoped to one
 * tenant (WC-621). All SQL touching `tag_groups` lives here so API handlers
 * never issue raw queries (project convention).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every row
 * belongs to one tenant and every SELECT/UPDATE/DELETE binds an explicit
 * `tenant_id` predicate, so a group written under one tenant can never be read
 * or mutated under another. `display_name` is a bilingual {ar,en} object stored
 * as JSONB (json_encode on write, json_decode on read).
 */
final class TagGroupRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Every tag group for the tenant, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, group_key, display_name, created_at, updated_at
             FROM tag_groups
             WHERE tenant_id = :tenant_id
             ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * A single group scoped to the tenant, or null when absent (including when
     * the id belongs to a DIFFERENT tenant — the tenant_id predicate makes that
     * indistinguishable from "does not exist", never a cross-tenant leak).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, group_key, display_name, created_at, updated_at
             FROM tag_groups
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Create a group. Returns the new id, or null when a group with the same
     * key already exists for this tenant (UNIQUE(tenant_id, group_key) rejects
     * the insert; the caller should translate that to a 409).
     *
     * @param array<string, string> $displayName {ar?, en?}
     */
    public function create(int $tenantId, string $groupKey, array $displayName): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO tag_groups (tenant_id, group_key, display_name, created_at, updated_at)
                 VALUES (:tenant_id, :group_key, :display_name, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id'    => $tenantId,
                ':group_key'    => $groupKey,
                ':display_name' => self::encodeDisplayName($displayName),
            ]);

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            if (self::isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Update a group's key and/or display name. Only the supplied fields change.
     * Returns true when a row matched, false when none did (wrong id or foreign
     * tenant), or null on a key-uniqueness conflict (→ 409).
     *
     * @param array{group_key?: string, display_name?: array<string, string>} $fields
     */
    public function update(int $tenantId, int $id, array $fields): bool|null
    {
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];

        if (array_key_exists('group_key', $fields)) {
            $sets[] = 'group_key = :group_key';
            $params[':group_key'] = $fields['group_key'];
        }
        if (array_key_exists('display_name', $fields)) {
            $sets[] = 'display_name = :display_name';
            $params[':display_name'] = self::encodeDisplayName($fields['display_name']);
        }
        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';

        try {
            $stmt = $this->db->prepare(
                'UPDATE tag_groups SET ' . implode(', ', $sets) .
                ' WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt->execute($params);

            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            if (self::isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Delete a group (its tags + their entity associations cascade). Returns
     * false when no row matched.
     */
    public function delete(int $tenantId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tag_groups WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, string> $displayName
     */
    private static function encodeDisplayName(array $displayName): string
    {
        // JSON_UNESCAPED_UNICODE so Arabic round-trips without \uXXXX escaping.
        return json_encode($displayName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function isUniqueViolation(\PDOException $e): bool
    {
        // Postgres 23505 / SQLite "UNIQUE constraint failed".
        return $e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $decoded = json_decode((string) $row['display_name'], true);

        return [
            'id'           => (int) $row['id'],
            'tenant_id'    => (int) $row['tenant_id'],
            'key'          => (string) $row['group_key'],
            'display_name' => is_array($decoded) ? $decoded : [],
            'created_at'   => (string) $row['created_at'],
            'updated_at'   => (string) $row['updated_at'],
        ];
    }
}
