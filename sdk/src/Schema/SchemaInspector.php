<?php

declare(strict_types=1);

namespace Whity\Sdk\Schema;

/**
 * "Does this already exist?" — answered identically on PostgreSQL and SQLite.
 *
 * The chore this deletes
 * ----------------------
 * The SDK asks every migration to be idempotent (`IF NOT EXISTS` / `IF EXISTS`)
 * and the host runs plugin migrations on both engines. Most DDL cooperates:
 * `CREATE TABLE IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS` parse on both.
 * `ALTER TABLE … ADD COLUMN IF NOT EXISTS` does NOT — it is a PostgreSQL
 * extension SQLite rejects outright — so the moment a plugin needs to add a
 * column to a table it already shipped, the author has to write the existence
 * check by hand, and the check is dialect-specific.
 *
 * Every plugin author therefore writes the same private `tableExists()` /
 * `columnExists()` pair, branching on `PDO::ATTR_DRIVER_NAME` between
 * `information_schema` and `PRAGMA table_info` / `sqlite_master`. It has been
 * written four times in a single session in one adopter's codebase, identically
 * each time. This class is that pair, written once, on the SDK, so a migration
 * imports it instead of re-deriving it.
 *
 * Why a wrong answer here is the worst kind of bug
 * ------------------------------------------------
 * These predicates gate DDL. A false negative re-runs a `CREATE`/`ADD COLUMN`
 * that then fails on a live database; a false positive silently skips a column
 * the plugin's queries go on to reference. Either way the migration passes on
 * the engine the author develops against and fails on the other one — in
 * production, at enable time, on somebody else's deployment.
 *
 * So the two answers this class gives are pinned to be the SAME answer on both
 * engines, and the dual-engine test suite is the point of the class, not an
 * afterthought.
 *
 * Two things the hand-written copies get wrong
 * --------------------------------------------
 * 1. **Schema scoping.** The usual hand-written PostgreSQL query is
 *    `SELECT 1 FROM information_schema.columns WHERE table_name = ?` with no
 *    schema predicate at all. That matches a same-named table in ANY schema on
 *    the search path — or off it. Multi-schema deployments are ordinary, and
 *    the host's own test harness puts every run in a schema of its own. Every
 *    query here is constrained to `current_schemas(false)` — the caller's
 *    actual search path — so "exists" means "exists for the connection
 *    asking", which is the only reading under which the answer can gate DDL
 *    that the same connection is about to run.
 *
 * 2. **Privilege filtering.** `information_schema` shows only objects the
 *    current role has some privilege on. A table that exists but is not visible
 *    to the role reads as "does not exist", and the migration then tries to
 *    create it and fails. `pg_catalog` is not privilege-filtered, so it answers
 *    the question actually being asked: does the object exist.
 *
 * Case handling is deliberately uniform: PostgreSQL folds unquoted identifiers
 * to lowercase while SQLite compares identifiers case-insensitively, so both
 * paths here match case-insensitively and `columnExists($pdo, 'Items', 'NAME')`
 * gives one answer everywhere.
 *
 * Scope and non-scope
 * -------------------
 * These are MIGRATION-TIME predicates: schema introspection is not free, and a
 * request-path caller that needs to know whether a column exists has a design
 * problem this class should not make comfortable.
 *
 * Nothing here hides a query behind a builder. The platform's static guards —
 * {@see \Whity\Sdk\Tenant\TenantPredicateScanner},
 * {@see \Whity\Sdk\Tenant\MigrationTenantColumnLinter} — work precisely because
 * SQL stays visible in the source, and DDL a plugin writes stays DDL a plugin
 * writes. What is removed is the dialect branch around it, which the guards do
 * not read and no author benefits from writing.
 *
 * Depends on nothing but PDO, so an out-of-repo plugin gets it with the SDK.
 *
 * @see MigrationSchema The trait that puts these on `$this` inside a migration.
 */
final class SchemaInspector
{
    /** PostgreSQL driver name as PDO reports it. */
    public const DRIVER_PGSQL = 'pgsql';

    /** SQLite driver name as PDO reports it. */
    public const DRIVER_SQLITE = 'sqlite';

    /**
     * The PostgreSQL identifier length limit, which is also the longest name
     * either engine will ever hold, so it doubles as the validation bound.
     */
    private const MAX_IDENTIFIER_LENGTH = 63;

    /**
     * Static-only.
     */
    private function __construct()
    {
    }

    /**
     * Whether a BASE TABLE of this name is visible to the connection.
     *
     * Views, sequences and indexes are deliberately NOT tables: a migration
     * guarded by this is about to run `CREATE TABLE` or `ALTER TABLE`, and
     * neither succeeds against a view. Partitioned tables DO count — they are
     * tables, and `ALTER TABLE` applies to them.
     *
     * @param \PDO   $pdo   Live connection (PostgreSQL or SQLite).
     * @param string $table Bare table name, unquoted, case-insensitive.
     * @throws \InvalidArgumentException On a malformed identifier.
     * @throws \RuntimeException On an unsupported driver.
     */
    public static function tableExists(\PDO $pdo, string $table): bool
    {
        self::assertIdentifier($table, 'table');

        return match (self::driver($pdo)) {
            self::DRIVER_PGSQL => self::exists(
                $pdo,
                "SELECT 1
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                  WHERE c.relkind IN ('r', 'p')
                    AND lower(c.relname) = lower(:table)
                    AND n.nspname = ANY (current_schemas(false))
                  LIMIT 1",
                [':table' => $table]
            ),
            // sqlite_master lists every object in the main database; `type`
            // separates tables from indexes and views the same way relkind does.
            self::DRIVER_SQLITE => self::exists(
                $pdo,
                "SELECT 1 FROM sqlite_master
                  WHERE type = 'table' AND lower(name) = lower(:table)
                  LIMIT 1",
                [':table' => $table]
            ),
        };
    }

    /**
     * Whether a column of this name exists on the table.
     *
     * Answers `false` for a table that does not exist, rather than throwing:
     * the caller is asking whether it must add the column, and "there is no
     * table" is answered by {@see tableExists()}, which the caller can ask
     * separately when it matters.
     *
     * @param \PDO   $pdo    Live connection (PostgreSQL or SQLite).
     * @param string $table  Bare table name, unquoted, case-insensitive.
     * @param string $column Bare column name, unquoted, case-insensitive.
     * @throws \InvalidArgumentException On a malformed identifier.
     * @throws \RuntimeException On an unsupported driver.
     */
    public static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        self::assertIdentifier($table, 'table');
        self::assertIdentifier($column, 'column');

        return match (self::driver($pdo)) {
            // `attnum > 0` excludes the system columns (ctid, xmin, …) and
            // `NOT attisdropped` excludes the tombstone row PostgreSQL leaves
            // behind after DROP COLUMN — which still carries the old attnum and
            // would otherwise read as a live column under a mangled name.
            self::DRIVER_PGSQL => self::exists(
                $pdo,
                "SELECT 1
                   FROM pg_catalog.pg_attribute a
                   JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                   JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                  WHERE c.relkind IN ('r', 'p')
                    AND lower(c.relname) = lower(:table)
                    AND lower(a.attname) = lower(:column)
                    AND a.attnum > 0
                    AND NOT a.attisdropped
                    AND n.nspname = ANY (current_schemas(false))
                  LIMIT 1",
                [':table' => $table, ':column' => $column]
            ),
            // The table-valued form of PRAGMA table_info (SQLite >= 3.16) takes
            // the table name as a BOUND parameter. The bare `PRAGMA table_info(x)`
            // statement cannot bind, which is why the hand-written copies
            // interpolate the table name into SQL — a habit worth not teaching.
            // An unknown table yields zero rows rather than an error.
            self::DRIVER_SQLITE => self::exists(
                $pdo,
                'SELECT 1 FROM pragma_table_info(:table)
                  WHERE lower(name) = lower(:column)
                  LIMIT 1',
                [':table' => $table, ':column' => $column]
            ),
        };
    }

    /**
     * Whether an index of this name exists.
     *
     * Index names are database-wide identifiers in SQLite and schema-wide in
     * PostgreSQL, so the owning table is not part of the key and is not asked
     * for. Both engines already accept `CREATE INDEX IF NOT EXISTS`, so this is
     * for the cases that predicate cannot express — deciding whether a backfill
     * or a rename is still outstanding, or asserting the shape in a test.
     *
     * @param \PDO   $pdo   Live connection (PostgreSQL or SQLite).
     * @param string $index Bare index name, unquoted, case-insensitive.
     * @throws \InvalidArgumentException On a malformed identifier.
     * @throws \RuntimeException On an unsupported driver.
     */
    public static function indexExists(\PDO $pdo, string $index): bool
    {
        self::assertIdentifier($index, 'index');

        return match (self::driver($pdo)) {
            // 'i' ordinary index, 'I' partitioned index.
            self::DRIVER_PGSQL => self::exists(
                $pdo,
                "SELECT 1
                   FROM pg_catalog.pg_class c
                   JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                  WHERE c.relkind IN ('i', 'I')
                    AND lower(c.relname) = lower(:index)
                    AND n.nspname = ANY (current_schemas(false))
                  LIMIT 1",
                [':index' => $index]
            ),
            self::DRIVER_SQLITE => self::exists(
                $pdo,
                "SELECT 1 FROM sqlite_master
                  WHERE type = 'index' AND lower(name) = lower(:index)
                  LIMIT 1",
                [':index' => $index]
            ),
        };
    }

    /**
     * Declare that a column SHOULD exist, and make it so.
     *
     * This is the reason the predicates exist, expressed directly. Instead of
     *
     *     if (!$this->columnExists($pdo, 'acme_items', 'archived_at')) {
     *         $pdo->exec('ALTER TABLE acme_items ADD COLUMN archived_at TIMESTAMP NULL');
     *     }
     *
     * the migration states the intended shape and the branch disappears:
     *
     *     SchemaInspector::addColumnIfMissing($pdo, 'acme_items', 'archived_at', 'TIMESTAMP NULL');
     *
     * PostgreSQL spells this `ADD COLUMN IF NOT EXISTS`; SQLite has no such
     * form. That gap is the whole reason the branch had to be written, and it
     * is closed here rather than in every migration.
     *
     * A missing TABLE is a hard error, not a silent skip: adding a column to a
     * table that is not there is a mistake in the migration, and swallowing it
     * would leave a plugin's later queries referencing a column nothing
     * created.
     *
     * @param \PDO   $pdo        Live connection (PostgreSQL or SQLite).
     * @param string $table      Bare table name.
     * @param string $column     Bare column name.
     * @param string $definition The column's SQL type and constraints, e.g.
     *        `'INTEGER NOT NULL DEFAULT 0'`. This is raw DDL authored by the
     *        migration and is interpolated as written — it is never a place to
     *        put a runtime value. Both engines require a CONSTANT default when
     *        adding a column to a populated table, so keep defaults constant.
     * @return bool True when the column was added, false when it already existed.
     * @throws \InvalidArgumentException On a malformed identifier or an empty definition.
     * @throws \RuntimeException On an unsupported driver or a missing table.
     */
    public static function addColumnIfMissing(
        \PDO $pdo,
        string $table,
        string $column,
        string $definition
    ): bool {
        self::assertIdentifier($table, 'table');
        self::assertIdentifier($column, 'column');

        if (trim($definition) === '') {
            throw new \InvalidArgumentException(
                "Column definition for {$table}.{$column} is empty; a type is required."
            );
        }

        if (!self::tableExists($pdo, $table)) {
            throw new \RuntimeException(
                "Cannot add column {$column}: table {$table} does not exist. "
                . 'Create it in an earlier migration, or reorder this one.'
            );
        }

        if (self::columnExists($pdo, $table, $column)) {
            return false;
        }

        $pdo->exec(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            self::quote($pdo, $table),
            self::quote($pdo, $column),
            $definition
        ));

        return true;
    }

    /**
     * Declare that a column should NOT exist — the `down()` half.
     *
     * PostgreSQL has `DROP COLUMN IF EXISTS`; SQLite gained `DROP COLUMN` in
     * 3.35 but never an `IF EXISTS` for it, so the same branch reappears in
     * every reversal. A missing table is not an error here: `down()` runs in
     * reverse order and a table dropped by a later-reverted migration being
     * already gone is the expected state, not a failure.
     *
     * @return bool True when the column was dropped, false when there was
     *              nothing to drop.
     * @throws \InvalidArgumentException On a malformed identifier.
     * @throws \RuntimeException On an unsupported driver.
     */
    public static function dropColumnIfExists(\PDO $pdo, string $table, string $column): bool
    {
        self::assertIdentifier($table, 'table');
        self::assertIdentifier($column, 'column');

        if (!self::columnExists($pdo, $table, $column)) {
            return false;
        }

        $pdo->exec(sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            self::quote($pdo, $table),
            self::quote($pdo, $column)
        ));

        return true;
    }

    /**
     * The columns of a table, lowercased, in declaration order.
     *
     * Returns an empty list for a table that does not exist. Useful when a
     * migration must decide between several shapes it might be adopting (a
     * hand-created table, an older release's table) rather than asking about
     * one column at a time.
     *
     * @return list<string>
     * @throws \InvalidArgumentException On a malformed identifier.
     * @throws \RuntimeException On an unsupported driver.
     */
    public static function columns(\PDO $pdo, string $table): array
    {
        self::assertIdentifier($table, 'table');

        $sql = match (self::driver($pdo)) {
            self::DRIVER_PGSQL =>
                "SELECT lower(a.attname) AS name
                   FROM pg_catalog.pg_attribute a
                   JOIN pg_catalog.pg_class c ON c.oid = a.attrelid
                   JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                  WHERE c.relkind IN ('r', 'p')
                    AND lower(c.relname) = lower(:table)
                    AND a.attnum > 0
                    AND NOT a.attisdropped
                    AND n.nspname = ANY (current_schemas(false))
                  ORDER BY a.attnum",
            self::DRIVER_SQLITE =>
                'SELECT lower(name) AS name FROM pragma_table_info(:table) ORDER BY cid',
        };

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':table' => $table]);

        /** @var list<string> $names */
        $names = array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        ));

        return $names;
    }

    /**
     * The PDO driver name, refusing anything this class has not been proven on.
     *
     * Guessing at a third engine's catalogue would produce a confident wrong
     * answer, and a confident wrong answer here corrupts a schema. A loud
     * refusal is the only safe default.
     *
     * @return self::DRIVER_PGSQL|self::DRIVER_SQLITE
     * @throws \RuntimeException On any other driver.
     */
    public static function driver(\PDO $pdo): string
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === self::DRIVER_PGSQL || $driver === self::DRIVER_SQLITE) {
            return $driver;
        }

        throw new \RuntimeException(sprintf(
            'SchemaInspector supports the %s and %s drivers; got "%s". '
            . 'Its catalogue queries are engine-specific and there is no safe fallback.',
            self::DRIVER_PGSQL,
            self::DRIVER_SQLITE,
            $driver
        ));
    }

    /**
     * Quote an identifier for the engine in hand.
     *
     * Both engines accept double quotes, but quoting is only reached after
     * {@see assertIdentifier()} has already restricted the name to
     * `[A-Za-z_][A-Za-z0-9_]*`, so this can never be a smuggling route — it
     * exists so a name that happens to be a reserved word (`order`, `check`)
     * still works.
     */
    private static function quote(\PDO $pdo, string $identifier): string
    {
        unset($pdo); // Both supported engines use the SQL-standard double quote.

        return '"' . $identifier . '"';
    }

    /**
     * Whether the query returns at least one row.
     *
     * @param array<string, string> $params
     */
    private static function exists(\PDO $pdo, string $sql, array $params): bool
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Reject anything that is not a plain, unquoted SQL identifier.
     *
     * Identifiers cannot be bound as parameters, so every one of them reaches
     * SQL by interpolation. Constraining the shape up front is what makes that
     * interpolation safe, and it catches the ordinary mistakes too — a name
     * with a schema prefix, a stray backtick, an empty string from a
     * misconfigured constant.
     *
     * @throws \InvalidArgumentException When the identifier is malformed.
     */
    private static function assertIdentifier(string $identifier, string $kind): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid %s identifier "%s": expected an unquoted name matching '
                . '[A-Za-z_][A-Za-z0-9_]* (no schema prefix, no quoting).',
                $kind,
                $identifier
            ));
        }

        if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid %s identifier "%s": %d characters exceeds the %d-character limit.',
                $kind,
                $identifier,
                strlen($identifier),
                self::MAX_IDENTIFIER_LENGTH
            ));
        }
    }
}
