<?php

declare(strict_types=1);

namespace Whity\Core\Relations;

use PDO;

/**
 * Data-access layer for the `persons` graph-node table (WC-65).
 *
 * All SQL touching `persons` lives here so API handlers never issue raw queries
 * (project convention). Every method is tenant-scoped and fails closed: a person
 * written under one tenant can never be read or mutated under another. The
 * system tenant (id 0) may see/act across all tenants, consistent with the other
 * admin repositories.
 *
 * Type discipline (real-Postgres parity): PostgreSQL's PDO driver returns
 * integer/boolean columns as PHP STRINGS, so every id/flag read back is
 * normalised with an explicit cast in {@see self::normalizeRow()} — the
 * int-vs-string trap the project's real-engine tests exist to catch.
 */
class PersonRepository
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
     * Insert a person and return the new id.
     *
     * @param int         $tenantId    The owning tenant.
     * @param string      $displayName The human-readable label (required).
     * @param int|null    $profileId   Optional linked profile (the shadow link).
     * @param string|null $birthDate   Optional birth date (Y-m-d) or null.
     * @param bool        $deceased    Whether the person is deceased.
     * @param string|null $notes       Optional free-text notes.
     * @return int The new person id.
     */
    public function insert(
        int $tenantId,
        string $displayName,
        ?int $profileId = null,
        ?string $birthDate = null,
        bool $deceased = false,
        ?string $notes = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO persons (tenant_id, display_name, profile_id, birth_date, deceased, notes, created_at)
             VALUES (:tenant_id, :display_name, :profile_id, :birth_date, :deceased, :notes, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':display_name' => $displayName,
            ':profile_id' => $profileId,
            ':birth_date' => $birthDate,
            ':deceased' => $deceased ? 1 : 0,
            ':notes' => $notes,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Fetch a single person by id, scoped to the tenant.
     *
     * The system tenant (id 0) may read a person in any tenant; any other tenant
     * reads only its own. Returns null when not visible/absent so callers surface
     * a 404 without disclosing cross-tenant existence.
     *
     * @param int $id       The person id.
     * @param int $tenantId The acting tenant (0 = system).
     * @return array<string, mixed>|null The normalised row, or null.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('SELECT * FROM persons WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM persons WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    /**
     * Fetch the person row that shadows a given profile, scoped to the tenant.
     *
     * Used by {@see RelationResolver} to resolve a `{kind:'profile'}` reference to a
     * person (auto-provisioning one when absent).
     *
     * @param int $profileId The profile id.
     * @param int $tenantId  The acting tenant (0 = system).
     * @return array<string, mixed>|null The normalised row, or null when the profile has no person yet.
     */
    public function findByProfileId(int $profileId, int $tenantId): ?array
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('SELECT * FROM persons WHERE profile_id = :profile_id');
            $stmt->execute([':profile_id' => $profileId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM persons WHERE profile_id = :profile_id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':profile_id' => $profileId, ':tenant_id' => $tenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalizeRow($row);
    }

    /**
     * Count persons visible to the tenant, with an optional name filter.
     *
     * @param int         $tenantId The acting tenant (0 = system).
     * @param string|null $search   Optional display-name substring filter.
     * @return int Total matching rows.
     */
    public function count(int $tenantId, ?string $search = null): int
    {
        [$where, $params] = $this->buildWhereClause($tenantId, $search);

        // @tenant-guard-ignore: tenant_id predicate added to $where only for non-system tenants
        $sql = 'SELECT COUNT(*) AS cnt FROM persons';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int)($row['cnt'] ?? 0) : 0;
    }

    /**
     * List persons visible to the tenant, with an optional name search.
     *
     * The system tenant (id 0) sees all tenants' persons; any other tenant sees
     * only its own. The optional `$search` does a case-insensitive substring
     * match on `display_name`.
     *
     * @param int         $tenantId The acting tenant (0 = system).
     * @param string|null $search   Optional display-name substring filter.
     * @param int|null    $limit    Max rows to return, or null for all.
     * @param int         $offset   Zero-based row offset (default 0).
     * @return array<int, array<string, mixed>> Normalised rows, ordered by display name.
     */
    public function list(int $tenantId, ?string $search = null, ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhereClause($tenantId, $search);

        // @tenant-guard-ignore: tenant_id predicate added to $where only for non-system tenants; system tenant (id 0) lists all persons by design
        $sql = 'SELECT * FROM persons';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY display_name ASC, id ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params[':limit']  = $limit;
            $params[':offset'] = $offset;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
    }

    /**
     * Build the shared WHERE clause and params array for count() and list().
     *
     * @param int         $tenantId
     * @param string|null $search
     * @return array{array<int, string>, array<string, mixed>}
     */
    private function buildWhereClause(int $tenantId, ?string $search): array
    {
        $where = [];
        $params = [];

        if ($tenantId !== 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        if ($search !== null && trim($search) !== '') {
            $where[] = 'LOWER(display_name) LIKE :search';
            $params[':search'] = '%' . strtolower(trim($search)) . '%';
        }

        return [$where, $params];
    }

    /**
     * Update the editable fields of a (non-user) person, tenant-scoped.
     *
     * Only the provided fields are changed; pass null for a field to leave it
     * untouched, except `notes`/`birth_date` which are set verbatim (use the
     * dedicated flags to clear them via the handler). Returns the rows affected.
     *
     * @param int                  $id       The person id.
     * @param int                  $tenantId The acting tenant (0 = system).
     * @param array<string, mixed> $fields   Map of column => value to set.
     * @return int Rows affected (0 when not found / not visible / nothing to update).
     */
    public function update(int $id, int $tenantId, array $fields): int
    {
        $allowed = ['display_name', 'birth_date', 'deceased', 'notes'];
        $sets = [];
        $params = [':id' => $id];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $fields)) {
                continue;
            }
            $value = $fields[$column];
            if ($column === 'deceased') {
                $value = $value ? 1 : 0;
            }
            $sets[] = "{$column} = :{$column}";
            $params[":{$column}"] = $value;
        }

        if ($sets === []) {
            return 0;
        }

        $sql = 'UPDATE persons SET ' . implode(', ', $sets) . ' WHERE id = :id';
        if ($tenantId !== 0) {
            $sql .= ' AND tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Delete a person by id, tenant-scoped. The `relations` FK cascade removes
     * the person's edges automatically.
     *
     * @param int $id       The person id.
     * @param int $tenantId The acting tenant (0 = system).
     * @return int Rows affected (0 when not found / not visible).
     */
    public function delete(int $id, int $tenantId): int
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('DELETE FROM persons WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM persons WHERE id = :id AND tenant_id = :tenant_id');
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        return $stmt->rowCount();
    }

    /**
     * Count the relation edges in which a person is either endpoint, tenant-scoped.
     *
     * Drives the "# relations" column in the list view and the delete-cascade
     * messaging.
     *
     * @param int $personId The person id.
     * @param int $tenantId The acting tenant (0 = system).
     * @return int The number of edges touching this person.
     */
    public function relationCount(int $personId, int $tenantId): int
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM relations WHERE from_person_id = :p OR to_person_id = :p2'
            );
            $stmt->execute([':p' => $personId, ':p2' => $personId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM relations
                 WHERE tenant_id = :tenant_id AND (from_person_id = :p OR to_person_id = :p2)'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':p' => $personId, ':p2' => $personId]);
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Normalise a raw person row so callers and JSON output never depend on the
     * PDO driver's int/bool-as-string behaviour.
     *
     * @param array<string, mixed> $row Raw row from a SELECT *.
     * @return array<string, mixed> Normalised row.
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'display_name' => (string) $row['display_name'],
            'profile_id' => isset($row['profile_id']) && $row['profile_id'] !== null ? (int) $row['profile_id'] : null,
            'birth_date' => isset($row['birth_date']) && $row['birth_date'] !== null
                ? (string) $row['birth_date']
                : null,
            // Postgres returns 't'/'f', SQLite returns 0/1 — both normalise via a
            // truthiness map that treats the canonical false markers as false.
            'deceased' => self::toBool($row['deceased'] ?? false),
            'notes' => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
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
