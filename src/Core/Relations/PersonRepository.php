<?php

declare(strict_types=1);

namespace Whity\Core\Relations;

use PDO;
use PDOStatement;
use Whity\Core\Db\DbBool;
use Whity\Http\ListQuery;
use Whity\Http\ListSpec;

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
     * What `GET /api/persons` lets a caller sort and search by.
     *
     * DECLARED HERE, NOT IN THE HANDLER, because the values are SQL column
     * expressions and this class is where the `persons` SQL lives (the same
     * convention that keeps raw queries out of handlers). Only the KEYS are
     * caller-facing; {@see ListQuery} never lets a request value reach the SQL.
     *
     * WHAT THE SCREEN SHOWS is the sort menu: `web/app/(protected)/admin/
     * relations` lists Name, Has account and Relations, and its search box says
     * "Search by name…". `account` sorts on `profile_id`, which is exactly what
     * the "Has account" column renders (a person with a linked profile vs one
     * without) rather than a second, derived notion of the same thing.
     *
     * NO `relations` SORT KEY, deliberately. That column is a COUNT of
     * reciprocal-derived edges assembled in PHP by the handler, not a column of
     * this table; ordering by it in SQL would mean a correlated subquery over
     * the relations table that has to reproduce the resolver's reciprocal rules,
     * and a sort that disagreed with the number on screen would be worse than no
     * sort at all.
     *
     * `defaultSort` is `name`, ascending — the `ORDER BY display_name ASC, id
     * ASC` this listing has always used, now expressed as the contract's default
     * plus its tiebreaker, so an unsorted caller sees no change.
     */
    public static function listSpec(): ListSpec
    {
        return new ListSpec(
            sortable: [
                'name' => 'display_name',
                'account' => 'profile_id',
                'created' => 'created_at',
            ],
            tiebreaker: 'id',
            searchable: ['display_name'],
            defaultSort: 'name',
            defaultDirection: 'asc',
        );
    }

    /**
     * Count persons visible to the tenant, matching the same {@see list()} call.
     *
     * CARRIES THE SEARCH PREDICATE. A count that ignored it would report the
     * tenant's whole total while the page showed the filtered rows, and the
     * client would render page controls for pages that come back empty.
     *
     * @param int            $tenantId The acting tenant (0 = system).
     * @param ListQuery|null $query    The caller's page/sort/search, or null for all rows.
     * @return int Total matching rows.
     */
    public function count(int $tenantId, ?ListQuery $query = null): int
    {
        [$where, $params] = $this->buildWhereClause($tenantId, $query);

        // @tenant-guard-ignore: tenant_id predicate added to $where only for non-system tenants
        $sql = 'SELECT COUNT(*) AS cnt FROM persons';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->db->prepare($sql);
        $this->bindWhere($stmt, $params);
        $query?->bindSearch($stmt);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (int)($row['cnt'] ?? 0) : 0;
    }

    /**
     * List persons visible to the tenant, ordered, searched and paged by the
     * caller's {@see ListQuery}.
     *
     * The system tenant (id 0) sees all tenants' persons; any other tenant sees
     * only its own — sort and search operate strictly within that set, never
     * across it. Passing null returns every visible person in the listing's
     * historical order (`display_name ASC, id ASC`), which is what the internal
     * callers and the data-layer tests want.
     *
     * @param int            $tenantId The acting tenant (0 = system).
     * @param ListQuery|null $query    The caller's page/sort/search, or null for all rows.
     * @return array<int, array<string, mixed>> Normalised rows.
     */
    public function list(int $tenantId, ?ListQuery $query = null): array
    {
        [$where, $params] = $this->buildWhereClause($tenantId, $query);

        // @tenant-guard-ignore: tenant_id predicate added to $where only for non-system tenants; system tenant (id 0) lists all persons by design
        $sql = 'SELECT * FROM persons';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= $query === null ? ' ORDER BY display_name ASC, id ASC' : ' ' . $query->orderBy();

        $paginated = $query !== null && $query->paginated;
        if ($paginated) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = $this->db->prepare($sql);
        $this->bindWhere($stmt, $params);
        // $paginated is only ever true when $query is non-null, so this branch
        // needs no nullsafe call; the else branch is the one $query may reach as
        // null, and that call stays nullsafe.
        if ($paginated) {
            $query->bindAll($stmt);
        } else {
            $query?->bindSearch($stmt);
        }
        $stmt->execute();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
    }

    /**
     * Build the shared WHERE clause and params array for count() and list().
     *
     * The search half is the ListQuery's own predicate, so both statements
     * carry the SAME filter — a count built without it would report an
     * unfiltered total next to a filtered page.
     *
     * @param int            $tenantId
     * @param ListQuery|null $query
     * @return array{array<int, string>, array<string, mixed>}
     */
    private function buildWhereClause(int $tenantId, ?ListQuery $query): array
    {
        $where = [];
        $params = [];

        if ($tenantId !== 0) {
            $where[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = $tenantId;
        }

        $search = $query?->searchPredicate($this->db) ?? '';
        if ($search !== '') {
            $where[] = $search;
        }

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindWhere(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
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
            // A BOOLEAN arrives in several spellings across the two engines;
            // {@see DbBool} normalises all of them.
            'deceased' => self::toBool($row['deceased'] ?? false),
            'notes' => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
            'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }

        /**
     * Coerce a DB boolean column to a real bool.
     *
     * Delegates to the canonical coercion (#891). {@see DbBool} records which
     * spellings each driver actually returns — measured on the PHP this
     * platform ships, not assumed — and why a bare `(bool)` cast is not an
     * equivalent substitute for it.
     */
    private static function toBool(mixed $value): bool
    {
        return DbBool::of($value);
    }
}
