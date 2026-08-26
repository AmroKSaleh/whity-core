<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

use PDO;
use PDOException;

/**
 * Data-access layer for `time_window_types` — a tenant's own vocabulary of
 * period KINDS, and the nesting between them (#1070, migration 126). All SQL
 * touching the table lives here so API handlers never issue raw queries
 * (project convention).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every row
 * belongs to one tenant and every SELECT/UPDATE/DELETE binds an explicit
 * `tenant_id` predicate, so a type written under one tenant can never be read or
 * mutated under another. That is not decoration here — the whole point of the
 * table is that one tenant's `crop_year` and another's `kiln_campaign` coexist
 * in one install without either seeing the other's vocabulary.
 *
 * NESTING, NOT RANK. Unlike {@see \Whity\Core\Ou\OuTypeRepository}, ordering is
 * not a property this table carries: a period kind's place is expressed by WHAT
 * IT NESTS INSIDE, which is a real structural fact, rather than by a sort order,
 * which would be an opinion about how to draw a picker. Depth is derivable from
 * the nesting; the reverse is not true.
 */
final class WindowTypeRepository
{
    /**
     * Hard ceiling on how deep a tenant may nest its period vocabulary.
     *
     * Not a modelling opinion — a cycle guard's stopping condition. The ancestor
     * walks below follow `parent_type_id` row by row, and a chain longer than
     * this is either a loop the row-level checks somehow admitted or a
     * vocabulary nobody can read; either way, continuing to walk it is worse
     * than refusing. Deliberately far above any hierarchy anyone has described.
     */
    public const MAX_NESTING_DEPTH = 32;

    private const COLUMNS = 'id, tenant_id, type_key, label, parent_type_id, source, created_at, updated_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The tenant's whole vocabulary.
     *
     * `type_key` orders it so a client rendering a picker gets the same sequence
     * on every request — a hierarchy is drawn from `parent_type_id`, and the key
     * is what makes siblings' order total rather than arbitrary.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM time_window_types
             WHERE tenant_id = :tenant_id
             ORDER BY type_key ASC'
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
            'SELECT ' . self::COLUMNS . '
             FROM time_window_types
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * A type by its canonical key, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByKey(int $tenantId, string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM time_window_types
             WHERE tenant_id = :tenant_id AND type_key = :type_key
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':type_key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Adopt a type into the tenant's vocabulary.
     *
     * @return int|null The new id, or null when the tenant already holds the key.
     */
    public function create(
        int $tenantId,
        string $key,
        string $label,
        ?int $parentTypeId,
        string $source
    ): ?int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO time_window_types
                    (tenant_id, type_key, label, parent_type_id, source, created_at, updated_at)
                 VALUES (:tenant_id, :type_key, :label, :parent_type_id, :source, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id'      => $tenantId,
                ':type_key'       => $key,
                ':label'          => $label,
                ':parent_type_id' => $parentTypeId,
                ':source'         => $source,
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
     * Update a type's label and/or its parent. Only the supplied fields change.
     *
     * The KEY is deliberately NOT updatable, for the reason
     * {@see \Whity\Core\Ou\OuTypeRepository::update()} gives: it is the stable
     * identifier code binds to, and renaming it in place would silently repoint
     * every reference at a type that no longer exists. Relabelling is free;
     * re-keying is a create plus a move.
     *
     * `parent_type_id` IS updatable, and a null in `$fields` genuinely means
     * "detach from its parent" rather than "leave alone" — which is why the
     * presence of the key, not the value, decides whether it is written.
     *
     * @param array{label?: string, parent_type_id?: int|null} $fields
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
        if (array_key_exists('parent_type_id', $fields)) {
            $sets[] = 'parent_type_id = :parent_type_id';
            $params[':parent_type_id'] = $fields['parent_type_id'];
        }
        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';

        $stmt = $this->db->prepare(
            'UPDATE time_window_types SET ' . implode(', ', $sets)
            . ' WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * How many periods of this kind the tenant holds.
     *
     * Consulted by the delete guard so the blast radius is reported BEFORE
     * anything changes. A type with periods is never deleted: the migration's FK
     * would cascade the periods away, and a period is not something a
     * vocabulary edit should be able to destroy.
     */
    public function countWindows(int $tenantId, int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM time_windows
             WHERE tenant_id = :tenant_id AND window_type_id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * How many of the tenant's types nest inside this one.
     */
    public function countChildTypes(int $tenantId, int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM time_window_types
             WHERE tenant_id = :tenant_id AND parent_type_id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Remove a type. The caller is expected to have consulted the two counts
     * above; this refuses on its own account anyway rather than relying on it.
     *
     * @return bool True when a row was removed.
     */
    public function delete(int $tenantId, int $id): bool
    {
        if ($this->countWindows($tenantId, $id) > 0 || $this->countChildTypes($tenantId, $id) > 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM time_window_types WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether making `$parentId` the parent of `$id` would close a loop.
     *
     * Walks up from the proposed parent looking for `$id`. A loop makes the
     * hierarchy unreadable and makes every ancestor walk in the subsystem
     * non-terminating, so it is refused at the boundary rather than survived at
     * every reader.
     *
     * A parent id that does not belong to the tenant answers TRUE here — not
     * because it forms a cycle, but because the walk cannot see it and the safe
     * answer to "may I attach to a row I cannot read" is no. The caller
     * validates existence separately and reports the better message.
     */
    public function wouldCycle(int $tenantId, int $id, int $parentId): bool
    {
        if ($id === $parentId) {
            return true;
        }

        $cursor = $parentId;
        for ($depth = 0; $depth < self::MAX_NESTING_DEPTH; $depth++) {
            $row = $this->find($tenantId, $cursor);
            if ($row === null) {
                return true;
            }
            $next = $row['parent_type_id'];
            if ($next === null) {
                return false;
            }
            if ((int) $next === $id) {
                return true;
            }
            $cursor = (int) $next;
        }

        return true;
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
     * across the PostgreSQL and SQLite engines, but the wire contract has no
     * such constraint and `key` is what a consumer binds to.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'tenant_id'      => (int) $row['tenant_id'],
            'key'            => (string) $row['type_key'],
            'label'          => (string) $row['label'],
            'parent_type_id' => $row['parent_type_id'] === null ? null : (int) $row['parent_type_id'],
            'source'         => (string) $row['source'],
            'created_at'     => (string) $row['created_at'],
            'updated_at'     => (string) $row['updated_at'],
        ];
    }
}
