<?php

declare(strict_types=1);

namespace Whity\Core\Relations;

use PDO;

/**
 * Data-access layer for the `relations` edge table and the `relationship_types`
 * vocabulary (WC-65).
 *
 * All SQL touching `relations` / `relationship_types` lives here so API handlers
 * never issue raw queries (project convention). Every relation method is
 * tenant-scoped and fails closed. The system tenant (id 0) may see/act across
 * all tenants, consistent with the other admin repositories.
 *
 * Reciprocal derivation: ONE row per relationship is stored. Listing a node's
 * relations in {@see self::listForPerson()} returns each edge from THAT node's
 * perspective — when the node is the `from` endpoint the edge reads as its own
 * type; when it is the `to` endpoint the edge reads as the type's
 * `inverse_type_id` (so "Alice Parent-of Bob" reads as "Child of Alice" from
 * Bob). Symmetric types (Spouse/Sibling) are their own inverse, so they read
 * the same from either end.
 *
 * Type discipline (real-Postgres parity): integer columns are returned as PHP
 * strings by the Postgres PDO driver, so every id is `(int)`-cast on read.
 */
class RelationRepository
{
    private PDO $db;

    /**
     * @param PDO $db Database connection.
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Return the full seeded relationship-type vocabulary, normalised.
     *
     * Not tenant-scoped — the vocabulary is global (v1). Drives the UI picker.
     *
     * @return array<int, array{id: int, name: string, inverseTypeId: int|null, symmetric: bool}>
     */
    public function listTypes(): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, inverse_type_id, is_symmetric FROM relationship_types ORDER BY name ASC'
        );
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'inverseTypeId' => isset($row['inverse_type_id']) && $row['inverse_type_id'] !== null
                    ? (int) $row['inverse_type_id']
                    : null,
                'symmetric' => self::toBool($row['is_symmetric'] ?? false),
            ],
            $rows
        );
    }

    /**
     * Fetch a relationship type by id, or null when absent.
     *
     * @param int $id The relationship type id.
     * @return array{id: int, name: string, inverseTypeId: int|null, symmetric: bool}|null
     */
    public function findType(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, inverse_type_id, is_symmetric FROM relationship_types WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'inverseTypeId' => isset($row['inverse_type_id']) && $row['inverse_type_id'] !== null
                ? (int) $row['inverse_type_id']
                : null,
            'symmetric' => self::toBool($row['symmetric'] ?? false),
        ];
    }

    /**
     * Insert one relation edge and return the new id.
     *
     * @param int $tenantId           The owning tenant.
     * @param int $fromPersonId       The edge's from-person.
     * @param int $toPersonId         The edge's to-person.
     * @param int $relationshipTypeId The relationship type.
     * @return int The new relation id.
     */
    public function insert(int $tenantId, int $fromPersonId, int $toPersonId, int $relationshipTypeId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO relations (tenant_id, from_person_id, to_person_id, relationship_type_id, created_at)
             VALUES (:tenant_id, :from_id, :to_id, :type_id, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':from_id' => $fromPersonId,
            ':to_id' => $toPersonId,
            ':type_id' => $relationshipTypeId,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Whether the exact directed edge (tenant + from + to + type) already exists.
     *
     * Backs the no-duplicate integrity check before insert (the UNIQUE constraint
     * is the backstop).
     *
     * @param int $tenantId           The acting tenant.
     * @param int $fromPersonId       The from-person.
     * @param int $toPersonId         The to-person.
     * @param int $relationshipTypeId The relationship type.
     * @return bool True when an identical edge already exists.
     */
    public function exists(int $tenantId, int $fromPersonId, int $toPersonId, int $relationshipTypeId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM relations
             WHERE tenant_id = :tenant_id
               AND from_person_id = :from_id
               AND to_person_id = :to_id
               AND relationship_type_id = :type_id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':from_id' => $fromPersonId,
            ':to_id' => $toPersonId,
            ':type_id' => $relationshipTypeId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Fetch a single relation edge by id, scoped to the tenant.
     *
     * @param int $id       The relation id.
     * @param int $tenantId The acting tenant (0 = system).
     * @return array{id: int, tenant_id: int, from_person_id: int, to_person_id: int, relationship_type_id: int}|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('SELECT * FROM relations WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM relations WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'from_person_id' => (int) $row['from_person_id'],
            'to_person_id' => (int) $row['to_person_id'],
            'relationship_type_id' => (int) $row['relationship_type_id'],
        ];
    }

    /**
     * Delete a relation edge by id, tenant-scoped.
     *
     * @param int $id       The relation id.
     * @param int $tenantId The acting tenant (0 = system).
     * @return int Rows affected (0 when not found / not visible).
     */
    public function delete(int $id, int $tenantId): int
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('DELETE FROM relations WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM relations WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        return $stmt->rowCount();
    }

    /**
     * List a person's relations FROM THAT PERSON'S PERSPECTIVE (reciprocal-derived).
     *
     * Returns one entry per edge touching the person. For an edge where the
     * person is the `from` endpoint the relationship reads as the edge's own
     * type, and the "other" person is the `to` endpoint. For an edge where the
     * person is the `to` endpoint the relationship reads as the type's INVERSE
     * (so a stored "Parent" edge reads as "Child" from the child's side), and the
     * "other" person is the `from` endpoint.
     *
     * Each entry carries the underlying edge id + direction so the UI can render
     * and delete the correct row, the reciprocal-derived type (id + name), and
     * the other person's id + display name.
     *
     * @param int $personId The node whose relations to list.
     * @param int $tenantId The acting tenant (0 = system).
     * @return array<int, array{
     *     relationId: int,
     *     otherPersonId: int,
     *     otherPersonName: string,
     *     otherPersonProfileId: int|null,
     *     typeId: int,
     *     typeName: string,
     *     direction: string
     * }>
     */
    public function listForPerson(int $personId, int $tenantId): array
    {
        // Outgoing edges (person is `from`): read as the edge's own type; other
        // = the to-person.
        $outgoingSql = "
            SELECT r.id AS relation_id,
                   p.id AS other_id,
                   p.display_name AS other_name,
                   p.profile_id AS other_profile_id,
                   rt.id AS type_id,
                   rt.name AS type_name,
                   'outgoing' AS direction
            FROM relations r
            JOIN persons p ON p.id = r.to_person_id AND p.tenant_id = r.tenant_id
            JOIN relationship_types rt ON rt.id = r.relationship_type_id
            WHERE r.from_person_id = :person_id";

        // Incoming edges (person is `to`): read as the type's INVERSE; other =
        // the from-person. inv is the inverse type; fall back to the original
        // type when no inverse is wired (defensive — the seed always wires one).
        $incomingSql = "
            SELECT r.id AS relation_id,
                   p.id AS other_id,
                   p.display_name AS other_name,
                   p.profile_id AS other_profile_id,
                   COALESCE(inv.id, rt.id) AS type_id,
                   COALESCE(inv.name, rt.name) AS type_name,
                   'incoming' AS direction
            FROM relations r
            JOIN persons p ON p.id = r.from_person_id AND p.tenant_id = r.tenant_id
            JOIN relationship_types rt ON rt.id = r.relationship_type_id
            LEFT JOIN relationship_types inv ON inv.id = rt.inverse_type_id
            WHERE r.to_person_id = :person_id";

        // The incoming branch uses distinct placeholders (:person_id2 /
        // :tenant_id2) because emulated-prepares drivers do not allow a named
        // placeholder to be reused across the two halves of a UNION ALL.
        $incomingSql = str_replace(':person_id', ':person_id2', $incomingSql);

        // Compose as a single subquery so the ORDER BY applies to the whole
        // compound result. SQLite rejects parenthesised SELECTs around a UNION,
        // so the two branches are concatenated bare and wrapped once.
        if ($tenantId !== 0) {
            $union = $outgoingSql . ' AND r.tenant_id = :tenant_id'
                . ' UNION ALL '
                . $incomingSql . ' AND r.tenant_id = :tenant_id2';
            $params = [
                ':person_id' => $personId,
                ':person_id2' => $personId,
                ':tenant_id' => $tenantId,
                ':tenant_id2' => $tenantId,
            ];
        } else {
            $union = $outgoingSql . ' UNION ALL ' . $incomingSql;
            $params = [':person_id' => $personId, ':person_id2' => $personId];
        }

        $sql = 'SELECT * FROM (' . $union . ') AS rel ORDER BY type_name ASC, other_name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'relationId' => (int) $row['relation_id'],
                'otherPersonId' => (int) $row['other_id'],
                'otherPersonName' => (string) $row['other_name'],
                'otherPersonProfileId' => isset($row['other_profile_id']) && $row['other_profile_id'] !== null
                    ? (int) $row['other_profile_id']
                    : null,
                'typeId' => (int) $row['type_id'],
                'typeName' => (string) $row['type_name'],
                'direction' => (string) $row['direction'],
            ],
            $rows
        );
    }

    /**
     * Count all relation edges visible to the tenant.
     *
     * @param int $tenantId The acting tenant (0 = system sees all).
     * @return int Total edges.
     */
    public function countEdges(int $tenantId): int
    {
        $sql    = 'SELECT COUNT(*) AS cnt FROM relations r';
        $params = [];
        if ($tenantId !== 0) {
            $sql .= ' WHERE r.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int)($row['cnt'] ?? 0) : 0;
    }

    /**
     * List ALL relation edges for a tenant, shaped for the family graph view.
     *
     * Returns the raw stored edges (one row per relationship) with the type name,
     * for rendering the react-flow graph. The graph derives reciprocal labels
     * client-side per viewing node; this returns the canonical stored direction.
     *
     * @param int      $tenantId The acting tenant (0 = system sees all).
     * @param int|null $limit    Max rows to return, or null for all.
     * @param int      $offset   Zero-based row offset (default 0).
     * @return array<int, array{
     *     id: int,
     *     fromPersonId: int,
     *     toPersonId: int,
     *     typeId: int,
     *     typeName: string,
     *     inverseTypeName: string|null
     * }>
     */
    public function listEdges(int $tenantId, ?int $limit = null, int $offset = 0): array
    {
        $sql = '
            SELECT r.id,
                   r.from_person_id,
                   r.to_person_id,
                   rt.id AS type_id,
                   rt.name AS type_name,
                   inv.name AS inverse_name
            FROM relations r
            JOIN relationship_types rt ON rt.id = r.relationship_type_id
            LEFT JOIN relationship_types inv ON inv.id = rt.inverse_type_id';

        $params = [];
        if ($tenantId !== 0) {
            $sql .= ' WHERE r.tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }
        $sql .= ' ORDER BY r.id ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params[':limit']  = $limit;
            $params[':offset'] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'fromPersonId' => (int) $row['from_person_id'],
                'toPersonId' => (int) $row['to_person_id'],
                'typeId' => (int) $row['type_id'],
                'typeName' => (string) $row['type_name'],
                'inverseTypeName' => isset($row['inverse_name']) && $row['inverse_name'] !== null
                    ? (string) $row['inverse_name']
                    : null,
            ],
            $rows
        );
    }

    /**
     * Coerce a DB boolean (Postgres 't'/'f', SQLite 0/1, native bool) to bool.
     *
     * @param mixed $value The raw column value.
     * @return bool
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        $normalised = strtolower(trim((string) $value));

        return !in_array($normalised, ['', '0', 'f', 'false', 'no'], true);
    }
}
