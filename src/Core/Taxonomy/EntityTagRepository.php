<?php

declare(strict_types=1);

namespace Whity\Core\Taxonomy;

use PDO;

/**
 * Data-access layer for `entity_tags` — the polymorphic association between a
 * tag and any entity in any plugin (WC-621). `entity_type` is an opaque
 * plugin-supplied string with no FK, so any resource is taggable. All SQL
 * touching `entity_tags` lives here so API handlers never issue raw queries
 * (project convention).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every
 * SELECT/DELETE binds an explicit `tenant_id` predicate; attach stamps the
 * tenant_id from the trusted arg (the caller having first verified the tag
 * belongs to that tenant), so an association can never cross tenants.
 */
final class EntityTagRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Attach a tag to an entity. Idempotent: a repeated attach is a no-op (the
     * (entity_type, entity_id, tag_id) primary key absorbs it). The caller must
     * have verified the tag belongs to the tenant. Returns true when a new row
     * was created, false when the association already existed.
     */
    public function attach(int $tenantId, string $entityType, int $entityId, int $tagId): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO entity_tags (tenant_id, entity_type, entity_id, tag_id, created_at)
             VALUES (:tenant_id, :entity_type, :entity_id, :tag_id, NOW())
             ON CONFLICT (entity_type, entity_id, tag_id) DO NOTHING'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':tag_id'      => $tagId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Detach a tag from an entity. Returns false when no matching association
     * existed for this tenant.
     */
    public function detach(int $tenantId, string $entityType, int $entityId, int $tagId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM entity_tags
             WHERE tenant_id = :tenant_id
               AND entity_type = :entity_type
               AND entity_id = :entity_id
               AND tag_id = :tag_id'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':tag_id'      => $tagId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The tags attached to one entity (full tag rows), oldest association first.
     *
     * @return list<array<string, mixed>>
     */
    public function tagsForEntity(int $tenantId, string $entityType, int $entityId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.tenant_id, t.group_id, t.name, t.created_at, t.updated_at
             FROM entity_tags et
             JOIN tags t ON t.id = et.tag_id
             WHERE et.tenant_id = :tenant_id
               AND et.entity_type = :entity_type
               AND et.entity_id = :entity_id
             ORDER BY et.created_at ASC, t.id ASC'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'normalizeTagRow'], $rows);
    }

    /**
     * The entities of a given type that carry a given tag (the reverse lookup).
     *
     * @return list<array<string, mixed>>
     */
    public function entitiesForTag(int $tenantId, string $entityType, int $tagId): array
    {
        $stmt = $this->db->prepare(
            'SELECT entity_type, entity_id
             FROM entity_tags
             WHERE tenant_id = :tenant_id
               AND entity_type = :entity_type
               AND tag_id = :tag_id
             ORDER BY entity_id ASC'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':entity_type' => $entityType,
            ':tag_id'      => $tagId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [
            'entity_type' => (string) $row['entity_type'],
            'entity_id'   => (int) $row['entity_id'],
        ], $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeTagRow(array $row): array
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
