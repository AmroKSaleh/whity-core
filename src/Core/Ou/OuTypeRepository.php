<?php

declare(strict_types=1);

namespace Whity\Core\Ou;

use PDO;
use PDOException;
use Throwable;

/**
 * Data-access layer for `ou_types` — a tenant's own organizational-unit type
 * vocabulary (#822, migration 102). All SQL touching `ou_types` lives here so
 * API handlers never issue raw queries (project convention).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every row
 * belongs to one tenant and every SELECT/UPDATE/DELETE binds an explicit
 * `tenant_id` predicate, so a type written under one tenant can never be read or
 * mutated under another. That is not decoration here — the whole point of the
 * table is that a university tenant's `faculty` and a hospital tenant's `ward`
 * coexist in one install without either seeing the other's vocabulary.
 *
 * Ordering is data, not presentation: a campus outranks a faculty outranks a
 * department, and `sort_order` is what expresses it. It is the single reason
 * this cannot be modelled on the taxonomy subsystem — a tag group has no rank,
 * and a type is single-valued and structural where a tag is multi-valued and
 * descriptive.
 */
final class OuTypeRepository
{
    /**
     * Gap left between appended types.
     *
     * Ranks are spaced rather than consecutive so an operator can insert a level
     * BETWEEN two existing ones (a campus above a faculty) by editing one row,
     * instead of renumbering the whole vocabulary. A spacing convention, not a
     * behavioural tunable — nothing reads it but {@see nextSortOrder()}.
     */
    private const SORT_ORDER_STEP = 10;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The tenant's whole vocabulary, in rank order.
     *
     * `type_key` breaks ties so the order is total and a client rendering a
     * picker gets the same sequence on every request — two types sharing a rank
     * would otherwise swap places between calls with no ORDER BY tiebreaker.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, type_key, label, sort_order, source, created_at, updated_at
             FROM ou_types
             WHERE tenant_id = :tenant_id
             ORDER BY sort_order ASC, type_key ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * A single type scoped to the tenant, or null when absent — including when
     * the id belongs to a DIFFERENT tenant, which the tenant predicate makes
     * indistinguishable from "does not exist" rather than a cross-tenant leak.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, type_key, label, sort_order, source, created_at, updated_at
             FROM ou_types
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * A single type by its KEY, which is what code binds to.
     *
     * @return array<string, mixed>|null
     */
    public function findByKey(int $tenantId, string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, type_key, label, sort_order, source, created_at, updated_at
             FROM ou_types
             WHERE tenant_id = :tenant_id AND type_key = :type_key
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':type_key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Create a type. Returns the new id, or null when the tenant already holds
     * that key (UNIQUE(tenant_id, type_key) rejects the insert; the caller
     * translates that to a 409).
     *
     * @param int|null $sortOrder Explicit rank, or null to append to the end.
     */
    public function create(
        int $tenantId,
        string $key,
        string $label,
        ?int $sortOrder,
        string $source
    ): ?int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ou_types (tenant_id, type_key, label, sort_order, source, created_at, updated_at)
                 VALUES (:tenant_id, :type_key, :label, :sort_order, :source, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id'  => $tenantId,
                ':type_key'   => $key,
                ':label'      => $label,
                ':sort_order' => $sortOrder ?? $this->nextSortOrder($tenantId),
                ':source'     => $source,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            if (self::isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Update a type's label and/or rank. Only the supplied fields change.
     *
     * The KEY is deliberately NOT updatable. It is the stable identifier a
     * routing rule binds to, and renaming it in place would silently repoint
     * every such rule at a type that no longer exists — the exact drift this
     * feature was reported to eliminate. Relabelling is free; re-keying is a
     * create plus a retype.
     *
     * @param array{label?: string, sort_order?: int} $fields
     * @return bool True when a row matched, false when none did.
     */
    public function update(int $tenantId, int $id, array $fields): bool
    {
        $sets = [];
        $params = [':tenant_id' => $tenantId, ':id' => $id];

        if (array_key_exists('label', $fields)) {
            $sets[] = 'label = :label';
            $params[':label'] = $fields['label'];
        }
        if (array_key_exists('sort_order', $fields)) {
            $sets[] = 'sort_order = :sort_order';
            $params[':sort_order'] = $fields['sort_order'];
        }
        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';

        $stmt = $this->db->prepare(
            'UPDATE ou_types SET ' . implode(', ', $sets)
            . ' WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * How many of the tenant's units currently carry this type.
     *
     * Consulted by the delete guard so the blast radius is reported BEFORE
     * anything changes, mirroring {@see \Whity\Api\TagGroupsApiHandler::delete()}
     * and the child/member guards on {@see \Whity\Api\OusApiHandler::delete()}.
     */
    public function countUnits(int $tenantId, int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM organizational_units
             WHERE tenant_id = :tenant_id AND ou_type_id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete a type, first untyping any units that still carry it.
     *
     * Returns false when no row matched. DESTRUCTIVE in the narrow sense that a
     * unit loses its type — no unit is ever removed. Callers MUST consult
     * {@see countUnits()} first and refuse unless the operator explicitly forced
     * it, so "delete this vocabulary entry" can never quietly untype forty
     * departments.
     *
     * The untyping is EXPLICIT rather than left to the `ON DELETE SET NULL` FK,
     * for the same reason the taxonomy repository deletes its levels by hand:
     * SQLite honours foreign keys only under `PRAGMA foreign_keys = ON`, so on
     * the test engine the FK action silently does nothing and leaves
     * `ou_type_id` pointing at a row that no longer exists. An explicit UPDATE
     * behaves identically on every engine; the FK action remains as a backstop.
     */
    public function delete(int $tenantId, int $id): bool
    {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $untype = $this->db->prepare(
                'UPDATE organizational_units SET ou_type_id = NULL
                 WHERE tenant_id = :tenant_id AND ou_type_id = :id'
            );
            $untype->execute([':tenant_id' => $tenantId, ':id' => $id]);

            $stmt = $this->db->prepare(
                'DELETE FROM ou_types WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
            $deleted = $stmt->rowCount() > 0;

            if ($ownTransaction) {
                $this->db->commit();
            }

            return $deleted;
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The rank an appended type takes: one step past the tenant's current last.
     *
     * A tenant with no types yet starts at the step rather than 0, leaving room
     * for a level to be inserted ABOVE the first one without renumbering.
     */
    public function nextSortOrder(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), 0) FROM ou_types WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return ((int) $stmt->fetchColumn()) + self::SORT_ORDER_STEP;
    }

    private static function isUniqueViolation(PDOException $e): bool
    {
        // Postgres 23505 / SQLite "UNIQUE constraint failed".
        return $e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Shape a row for the API.
     *
     * `key` rather than `type_key`: the column dodges `key`, a reserved word
     * across the PostgreSQL and SQLite engines (the same dodge `tag_groups`
     * makes with `group_key`), but the wire contract has no such constraint and
     * `key` is what a consumer binds to.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'tenant_id'  => (int) $row['tenant_id'],
            'key'        => (string) $row['type_key'],
            'label'      => (string) $row['label'],
            'sort_order' => (int) $row['sort_order'],
            'source'     => (string) $row['source'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
