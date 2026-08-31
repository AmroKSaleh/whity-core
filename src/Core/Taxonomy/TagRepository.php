<?php

declare(strict_types=1);

namespace Whity\Core\Taxonomy;

use PDO;
use Whity\Http\ListQuery;
use Whity\Http\ListSpec;

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
     * The FROM that both the listing and its count share.
     *
     * The join exists so the caller can sort and search by the tag's GROUP,
     * which is the second column the tags screen shows. It is a LEFT join, not
     * an inner one: an inner join would make a tag whose group row is missing
     * vanish from the list entirely, turning a referential oddity into
     * apparently-deleted data. `g.tenant_id = t.tenant_id` is redundant with the
     * FK and kept anyway — the join is the one place a tenant's rows could be
     * widened by another tenant's, so it says so explicitly.
     */
    private const FROM = 'FROM tags t
             LEFT JOIN tag_groups g ON g.id = t.group_id AND g.tenant_id = t.tenant_id
             WHERE t.tenant_id = :tenant_id';

    /**
     * What `GET /api/tags` lets a caller sort and search by.
     *
     * DECLARED HERE, NOT IN THE HANDLER, because the values are SQL column
     * expressions and this class is where the `tags` SQL lives. Only the KEYS
     * are caller-facing; {@see ListQuery} never lets a request value reach SQL.
     *
     * WHAT THE SCREEN SHOWS is the sort menu: `web/app/(protected)/admin/tags`
     * lists Name and Group, and its search box says "Search tags…". `group`
     * sorts by the group's KEY — the group's stable, engine-neutral identity —
     * for the reason {@see TagGroupRepository::listSpec()} records: its display
     * label is JSON with no member extraction common to both engines. Search
     * covers the label anyway via the standard `CAST(… AS TEXT)`, so typing a
     * group's readable name finds its tags.
     *
     * `defaultSort` is null so an unsorted caller keeps today's `ORDER BY id
     * ASC` — the tiebreaker alone — exactly as before this endpoint had a sort.
     */
    public static function listSpec(): ListSpec
    {
        return new ListSpec(
            sortable: [
                'name' => 't.name',
                'group' => 'g.group_key',
                'created' => 't.created_at',
            ],
            tiebreaker: 't.id',
            searchable: ['t.name', 'g.group_key', 'CAST(g.display_name AS TEXT)'],
        );
    }

    /**
     * Every tag for the tenant, optionally narrowed to one group. Oldest first.
     *
     * With a {@see ListQuery} the caller's sort and search apply, and the rows
     * are narrowed to one page WHEN — and only when — that query is paginated;
     * see {@see \Whity\Api\TagsApiHandler::list()} for why that is conditional.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?int $groupId = null, ?ListQuery $query = null): array
    {
        // No query at all is the internal caller's shape: every row, oldest
        // first. It is NOT built from a default ListQuery, because ListQuery
        // reads $_GET — a request's own `q` would leak into a call that never
        // asked for one.
        $sql = 'SELECT t.id, t.tenant_id, t.group_id, t.name, t.created_at, t.updated_at ' . self::FROM;
        if ($groupId !== null) {
            $sql .= ' AND t.group_id = :group_id';
        }
        $sql .= $query === null ? ' ORDER BY t.id ASC' : $query->andSearch($this->db) . ' ' . $query->orderBy();

        $paginated = $query !== null && $query->paginated;
        if ($paginated) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        if ($groupId !== null) {
            $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
        }
        // $paginated is only ever true when $query is non-null, so this branch
        // needs no nullsafe call; the else branch is the one $query may reach as
        // null, and that call stays nullsafe.
        if ($paginated) {
            $query->bindAll($stmt);
        } else {
            $query?->bindSearch($stmt);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'normalizeRow'], $rows);
    }

    /**
     * How many tags the same {@see listForTenant()} call would match.
     *
     * CARRIES THE SEARCH PREDICATE AND THE GROUP FILTER. A count that ignored
     * either would report a total the page cannot fill, and the client would
     * render page controls for pages that come back empty.
     */
    public function countForTenant(int $tenantId, ?int $groupId = null, ?ListQuery $query = null): int
    {
        $sql = 'SELECT COUNT(*) ' . self::FROM;
        if ($groupId !== null) {
            $sql .= ' AND t.group_id = :group_id';
        }
        $sql .= $query === null ? '' : $query->andSearch($this->db);

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        if ($groupId !== null) {
            $stmt->bindValue(':group_id', $groupId, PDO::PARAM_INT);
        }
        $query?->bindSearch($stmt);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
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
