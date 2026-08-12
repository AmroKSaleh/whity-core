<?php

declare(strict_types=1);

namespace Whity\Sdk\Sql;

use Whity\Sdk\Schema\SchemaInspector;

/**
 * "Insert, or update the row that is already there, and tell me which row that
 * was" — built once, with the tenant key impossible to leave out.
 *
 * The chore this deletes
 * ---------------------
 * `INSERT … ON CONFLICT … DO UPDATE SET … RETURNING …` is the single most
 * repeated statement shape in an adopting plugin: one codebase carries 58 of
 * them. Each one hand-builds a column list, a `VALUES` list of placeholders, a
 * conflict target, and a `SET a = EXCLUDED.a, b = EXCLUDED.b` list that must
 * stay in step with the first three. Four lists, written four times, kept
 * aligned by hand.
 *
 * BE HONEST ABOUT THE PORTABILITY PART
 * ------------------------------------
 * This is NOT the same kind of dialect gap {@see SchemaInspector} closes. The
 * column-list form of `ON CONFLICT`, the `excluded.` pseudo-table and
 * `RETURNING` all parse on PostgreSQL and on SQLite ≥ 3.35, so the statement
 * this class emits is one statement, not two branches. Two real differences
 * remain and are handled: PostgreSQL's `ON CONFLICT ON CONSTRAINT <name>` form
 * has no SQLite equivalent, so this class only ever names conflict COLUMNS; and
 * SQLite below 3.35 has no `RETURNING`, which is detected and refused with a
 * version message instead of surfacing as a syntax error at 3am.
 *
 * The value here is therefore mostly not portability. It is that the
 * construction can no longer be got wrong.
 *
 * The failure this makes unrepresentable
 * --------------------------------------
 * On a tenant-owned table the conflict target MUST include `tenant_id`. Leave
 * it out — `ON CONFLICT (client_uuid)` where the intent was
 * `ON CONFLICT (tenant_id, client_uuid)` — and the upsert stops being a
 * per-tenant operation: tenant B's insert finds tenant A's row, takes the
 * `DO UPDATE` branch, and OVERWRITES it. That is cross-tenant data loss written
 * as an ordinary create, and neither the unique index nor the tenant-predicate
 * scanner will complain, because the statement does mention `tenant_id` — in
 * the value list.
 *
 * So {@see tenantScoped()} takes the tenant id as a REQUIRED, separate
 * argument, writes it into the inserted columns, and prepends it to the
 * conflict target itself. There is no way to call it and end up with an
 * unscoped conflict target. A table that genuinely has no tenant column is
 * served by {@see unscoped()}, whose name is the declaration — and which
 * a reviewer or a grep can find, unlike an omission.
 *
 * What is NOT traded away
 * -----------------------
 * No query is hidden. {@see buildSql()} is public and returns the exact
 * statement that will be executed, so a caller can log it, assert on it in a
 * test, or paste it into psql. This class builds SQL; it does not become a
 * layer that owns SQL, and nothing about it invites a plugin to stop knowing
 * what its writes do.
 *
 * Identifiers are validated rather than escaped-and-hoped: table and column
 * names cannot be bound as parameters, so they are checked against
 * `[A-Za-z_][A-Za-z0-9_]*` and quoted. Every VALUE is bound.
 */
final class Upsert
{
    /**
     * Static-only.
     */
    private function __construct()
    {
    }

    /**
     * Upsert a row on a TENANT-OWNED table.
     *
     * The tenant id is a separate, required argument rather than one more entry
     * in `$values` because it is not one more value: it is half of the identity
     * of the row, and the half that is catastrophic to omit from the conflict
     * target. This method writes it into both places, so the caller cannot.
     *
     *     $row = Upsert::tenantScoped(
     *         $pdo,
     *         'acme_items',
     *         $tenantId,
     *         ['client_uuid' => $uuid, 'name' => $name, 'status' => 'active'],
     *         ['client_uuid'],                    // tenant_id is prepended for you
     *         ['name', 'status'],                 // what a conflict overwrites
     *         ['id', 'version']
     *     );
     *
     * @param \PDO                 $pdo             Live connection (PostgreSQL or SQLite).
     * @param string               $table           Target table.
     * @param int                  $tenantId        The tenant owning the row.
     * @param array<string, mixed> $values          Column => value, EXCLUDING the tenant column.
     * @param list<string>         $conflictColumns The unique key, EXCLUDING the tenant
     *        column (prepended here). Must correspond to a real unique index that
     *        also leads with the tenant column, or the engine refuses the statement.
     * @param list<string>|null    $updateColumns   Columns a conflict overwrites from the
     *        proposed row. `null` means every column in `$values`. An EMPTY list means
     *        `DO NOTHING` — see the return value.
     * @param list<string>         $returning       Columns to return; `['*']` for the whole
     *        row, `[]` to omit RETURNING entirely (then the return is always null).
     * @param string               $tenantColumn    The tenant column's name.
     * @return array<string, mixed>|null The affected row, or null when nothing was
     *         returned — which happens when `$returning` is empty, AND when
     *         `DO NOTHING` was chosen and the conflict actually fired. That second
     *         case is the trap: on BOTH engines a `DO NOTHING` that skips the row
     *         returns NO row, so null means "already there", not "failed".
     * @throws \InvalidArgumentException On a malformed identifier or an empty value set.
     * @throws \RuntimeException On an unsupported driver, or SQLite below 3.35 with RETURNING.
     */
    public static function tenantScoped(
        \PDO $pdo,
        string $table,
        int $tenantId,
        array $values,
        array $conflictColumns,
        ?array $updateColumns = null,
        array $returning = ['*'],
        string $tenantColumn = 'tenant_id'
    ): ?array {
        self::assertIdentifier($tenantColumn, 'column');

        if (array_key_exists($tenantColumn, $values)) {
            throw new \InvalidArgumentException(sprintf(
                'Do not pass %s in $values: tenantScoped() writes it from $tenantId, '
                . 'so the value and the conflict target cannot disagree.',
                $tenantColumn
            ));
        }
        if (in_array($tenantColumn, $conflictColumns, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Do not pass %s in $conflictColumns: it is prepended for you.',
                $tenantColumn
            ));
        }

        return self::run(
            $pdo,
            $table,
            [$tenantColumn => $tenantId] + $values,
            [$tenantColumn, ...$conflictColumns],
            $updateColumns ?? array_keys($values),
            $returning
        );
    }

    /**
     * Upsert a row on a table that has NO tenant column.
     *
     * The explicit name is the point. A platform-unique catalogue or counter is
     * a legitimate thing to upsert into, and the alternative — letting
     * {@see tenantScoped()} accept a null tenant — would make the unscoped case
     * reachable by omission, which is exactly how the cross-tenant overwrite
     * happens. Here it is reachable only by saying so, in a form that greps.
     *
     * The table should be one declared global (see the SDK's tenant-table
     * registry); this method does not verify that, because the SDK cannot see
     * the host's registry, but a table with a `tenant_id` column upserted
     * through here is a bug the reviewer can now find by searching for the
     * method name.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $conflictColumns
     * @param list<string>|null    $updateColumns
     * @param list<string>         $returning
     * @return array<string, mixed>|null
     * @throws \InvalidArgumentException On a malformed identifier or an empty value set.
     * @throws \RuntimeException On an unsupported driver, or SQLite below 3.35 with RETURNING.
     */
    public static function unscoped(
        \PDO $pdo,
        string $table,
        array $values,
        array $conflictColumns,
        ?array $updateColumns = null,
        array $returning = ['*']
    ): ?array {
        return self::run(
            $pdo,
            $table,
            $values,
            $conflictColumns,
            $updateColumns ?? array_keys($values),
            $returning
        );
    }

    /**
     * The exact statement {@see tenantScoped()} / {@see unscoped()} will run.
     *
     * Public so nothing about this class has to be taken on trust: a test can
     * assert the tenant column is in the conflict target, an operator can log
     * the statement, and a reviewer can read the SQL rather than infer it.
     *
     * Placeholders are named `:v_<column>` in the order the columns are given.
     *
     * @param list<string>         $insertColumns
     * @param list<string>         $conflictColumns
     * @param list<string>         $updateColumns
     * @param list<string>         $returning
     * @throws \InvalidArgumentException On a malformed identifier or an empty column set.
     */
    public static function buildSql(
        string $table,
        array $insertColumns,
        array $conflictColumns,
        array $updateColumns,
        array $returning = ['*']
    ): string {
        self::assertIdentifier($table, 'table');

        if ($insertColumns === []) {
            throw new \InvalidArgumentException("Upsert into {$table} has no columns to insert.");
        }
        if ($conflictColumns === []) {
            throw new \InvalidArgumentException(
                "Upsert into {$table} names no conflict columns. Without a conflict target "
                . 'the statement is a plain INSERT that fails on the second call, which is '
                . 'not what an upsert is for.'
            );
        }

        foreach ([...$insertColumns, ...$conflictColumns, ...$updateColumns] as $column) {
            self::assertIdentifier($column, 'column');
        }

        $unknownConflict = array_diff($conflictColumns, $insertColumns);
        if ($unknownConflict !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Conflict column(s) %s are not among the inserted columns of %s. '
                . 'A conflict target the INSERT does not supply can never match.',
                implode(', ', $unknownConflict),
                $table
            ));
        }

        $unknownUpdate = array_diff($updateColumns, $insertColumns);
        if ($unknownUpdate !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Update column(s) %s are not among the inserted columns of %s; '
                . 'there is no proposed value for them to take.',
                implode(', ', $unknownUpdate),
                $table
            ));
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) ',
            self::quote($table),
            implode(', ', array_map(self::quote(...), $insertColumns)),
            implode(', ', array_map(static fn (string $c): string => ':v_' . $c, $insertColumns)),
            implode(', ', array_map(self::quote(...), $conflictColumns))
        );

        // An empty update list is DO NOTHING, and it is a real choice — "insert
        // if absent, leave alone if present" is the right semantic for a
        // revoked-token or an idempotency record. It changes what RETURNING
        // yields, which is documented on the callers.
        if ($updateColumns === []) {
            $sql .= 'DO NOTHING';
        } else {
            $sql .= 'DO UPDATE SET ' . implode(', ', array_map(
                static fn (string $c): string => self::quote($c) . ' = excluded.' . self::quote($c),
                $updateColumns
            ));
        }

        if ($returning !== []) {
            $sql .= ' RETURNING ' . implode(', ', array_map(
                static fn (string $c): string => $c === '*' ? '*' : self::quote($c),
                $returning
            ));
        }

        return $sql;
    }

    /**
     * Build, bind and execute.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $conflictColumns
     * @param list<string>         $updateColumns
     * @param list<string>         $returning
     * @return array<string, mixed>|null
     */
    private static function run(
        \PDO $pdo,
        string $table,
        array $values,
        array $conflictColumns,
        array $updateColumns,
        array $returning
    ): ?array {
        if ($values === []) {
            throw new \InvalidArgumentException("Upsert into {$table} has no values.");
        }

        /** @var list<string> $insertColumns */
        $insertColumns = array_keys($values);

        if ($returning !== []) {
            self::assertReturningIsSupported($pdo);
        }

        $sql = self::buildSql($table, $insertColumns, $conflictColumns, $updateColumns, $returning);

        $statement = $pdo->prepare($sql);
        foreach ($values as $column => $value) {
            $statement->bindValue(':v_' . $column, $value, self::pdoTypeOf($value));
        }
        $statement->execute();

        if ($returning === []) {
            return null;
        }

        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        /** @var array<string, mixed>|null */
        return is_array($row) ? $row : null;
    }

    /**
     * Refuse an engine whose RETURNING would be a syntax error.
     *
     * SQLite gained RETURNING in 3.35.0 (2021). Below that the statement fails
     * with an unhelpful parse error pointing at the wrong token; a named,
     * versioned refusal is diagnosable. PostgreSQL has had RETURNING since 8.2,
     * which predates every supported version, so nothing is checked there.
     *
     * @throws \RuntimeException When the engine cannot run RETURNING.
     */
    private static function assertReturningIsSupported(\PDO $pdo): void
    {
        if (SchemaInspector::driver($pdo) !== SchemaInspector::DRIVER_SQLITE) {
            return;
        }

        $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        if (version_compare($version, '3.35.0', '>=')) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'SQLite %s cannot run RETURNING (added in 3.35.0). Pass $returning = [] '
            . 'to omit the clause, or run on a newer SQLite.',
            $version
        ));
    }

    /**
     * The PDO parameter type for a bound value.
     *
     * Booleans are bound as integers rather than PDO::PARAM_BOOL: SQLite has no
     * boolean type and PDO's pgsql driver renders a bound bool as `''`/`'1'`,
     * which a PostgreSQL BOOLEAN column rejects. An integer literal is accepted
     * by both.
     */
    private static function pdoTypeOf(mixed $value): int
    {
        return match (true) {
            $value === null => \PDO::PARAM_NULL,
            is_bool($value), is_int($value) => \PDO::PARAM_INT,
            default => \PDO::PARAM_STR,
        };
    }

    /**
     * Quote an identifier. Both supported engines use the SQL-standard double
     * quote, and the name has already been shape-checked, so this exists to let
     * a reserved word (`order`, `check`, `value`) be used as a column name.
     */
    private static function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    /**
     * Identifiers cannot be bound, so every one of them reaches SQL by
     * interpolation. Constraining the shape is what makes that safe.
     *
     * @throws \InvalidArgumentException When the identifier is malformed.
     */
    private static function assertIdentifier(string $identifier, string $kind): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1 || strlen($identifier) > 63) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid %s identifier "%s": expected an unquoted name matching '
                . '[A-Za-z_][A-Za-z0-9_]* of at most 63 characters.',
                $kind,
                $identifier
            ));
        }
    }
}
