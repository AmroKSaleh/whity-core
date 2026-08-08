<?php

declare(strict_types=1);

namespace Whity\Core\Taxonomy;

use PDO;

/**
 * Data-access layer for `tags` — an individual tag inside a tag group (WC-621).
 * All SQL touching `tags` lives here so API handlers never issue raw queries
 * (project convention).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every row
 * belongs to one tenant and every SELECT/UPDATE/DELETE binds an explicit
 * `tenant_id` predicate, so a tag written under one tenant can never be read or
 * mutated under another.
 */
final class TagRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Every tag for the tenant, optionally narrowed to one group. Oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?int $groupId = null): array
    {
        if ($groupId !== null) {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, group_id, name, created_at, updated_at
                 FROM tags
                 WHERE tenant_id = :tenant_id AND group_id = :group_id
                 ORDER BY id ASC'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':group_id' => $groupId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, group_id, name, created_at, updated_at
                 FROM tags
                 WHERE tenant_id = :tenant_id
                 ORDER BY id ASC'
            );
            $stmt->execute([':tenant_id' => $tenantId]);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * A single tag scoped to the tenant, or null when absent (including a
     * foreign-tenant id — indistinguishable from "does not exist").
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, group_id, name, created_at, updated_at
             FROM tags
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Create a tag in a group. The caller must have verified the group belongs
     * to the tenant. Returns the new id, or null when a tag with the same name
     * already exists in this (tenant, group) (UNIQUE rejects the insert → 409).
     */
    public function create(int $tenantId, int $groupId, string $name): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO tags (tenant_id, group_id, name, created_at, updated_at)
                 VALUES (:tenant_id, :group_id, :name, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':group_id'  => $groupId,
                ':name'      => $name,
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
     * Rename a tag. Returns true when a row matched, false when none did (wrong
     * id or foreign tenant), or null on a name-uniqueness conflict (→ 409).
     */
    public function rename(int $tenantId, int $id, string $name): bool|null
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE tags SET name = :name, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt->execute([':name' => $name, ':tenant_id' => $tenantId, ':id' => $id]);

            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            if (self::isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * How many `entity_tags` associations would be destroyed by deleting this
     * tag (WC-714 §5). Those rows belong to other plugins' records, so the
     * delete guard reports this count instead of cascading silently.
     */
    public function countAssociations(int $tenantId, int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM entity_tags
             WHERE tenant_id = :tenant_id AND tag_id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete a tag together with its entity associations. Returns false when no
     * row matched.
     *
     * DESTRUCTIVE — every `entity_tags` row referencing this tag goes with it,
     * and those rows belong to other plugins' records. Callers MUST consult
     * {@see countAssociations()} first and refuse unless the operator
     * explicitly forced it; see {@see \Whity\Api\TagsApiHandler::delete()}
     * (WC-714 §5).
     *
     * Both levels are deleted EXPLICITLY inside one transaction rather than via
     * the `ON DELETE CASCADE` FK — same reasoning as
     * {@see TagGroupRepository::delete()}: destruction that names itself is
     * auditable, and SQLite does not enforce the cascade at all unless
     * `PRAGMA foreign_keys = ON`.
     */
    public function delete(int $tenantId, int $id): bool
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                'DELETE FROM entity_tags WHERE tenant_id = :tenant_id AND tag_id = :id'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

            $stmt = $this->db->prepare(
                'DELETE FROM tags WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
            $deleted = $stmt->rowCount() > 0;

            if ($ownTransaction) {
                $this->db->commit();
            }

            return $deleted;
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private static function isUniqueViolation(\PDOException $e): bool
    {
        return $e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'tenant_id'  => (int) $row['tenant_id'],
            'group_id'   => (int) $row['group_id'],
            'name'       => (string) $row['name'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
