<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Database\Database;

/**
 * Loads and runs every production migration file against an in-memory SQLite
 * database, translating PostgreSQL-specific DDL to SQLite-compatible equivalents.
 *
 * Replaces the hand-copied CREATE TABLE blocks that used to live inside each
 * RealEngine test's private makeSchema() / makeSqliteSchema() helper: those copies
 * drifted from the migration files over time and masked schema bugs. With this
 * loader the test schema IS the migration schema.
 *
 * Usage — fresh PDO with full schema:
 *
 *     $pdo = SchemaFromMigrations::make();
 *     // seed data, run assertions …
 *
 * Usage — apply to an existing PDO (e.g. one with ATTR_STRINGIFY_FETCHES already set):
 *
 *     $pdo = new PDO('sqlite::memory:');
 *     $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
 *     SchemaFromMigrations::apply($pdo);
 *
 * PostgreSQL real-engine mode (CI: postgres-integration job):
 *
 *   When PHPUNIT_PG_DSN is set (e.g. "pgsql:host=localhost;port=5432;dbname=whity_core")
 *   together with PHPUNIT_PG_USER / PHPUNIT_PG_PASSWORD, make() returns a real
 *   PostgreSQL PDO instead of SQLite.  A fresh per-call schema is created inside
 *   the target database (one Postgres schema per make() invocation, dropped at
 *   process exit via a shutdown function), so parallel test processes are isolated
 *   and the main whity_core database left intact after the suite completes.
 *   The returned PDO wrapper translates SQLite-only DML idioms that appear in test
 *   seed helpers (INSERT OR IGNORE, datetime('now'), etc.) so individual test
 *   files need no modification.
 *
 * SQLite limitations handled automatically (SQLite path only):
 *   - SERIAL / BIGSERIAL → INTEGER AUTOINCREMENT
 *   - VARCHAR(n) / TIMESTAMP / BOOLEAN / JSONB / JSON → TEXT / INTEGER
 *   - DEFAULT NOW() → DEFAULT (datetime('now'))
 *   - DEFAULT false/true → DEFAULT 0/1
 *   - PostgreSQL cast operator (::typename) → stripped
 *   - ALTER TABLE … ADD COLUMN IF NOT EXISTS → IF NOT EXISTS stripped (SQLite ≥ 3.37)
 *   - DROP TABLE … CASCADE → CASCADE stripped
 *   - ALTER SEQUENCE … / ALTER TABLE … ALTER COLUMN … TYPE → silently skipped
 *   - Multi-column ADD COLUMN (PG extension) → split into separate statements
 */
final class SchemaFromMigrations
{
    /**
     * Build a fresh PDO with the full production schema applied.
     *
     * When the PHPUNIT_PG_DSN environment variable is set the returned PDO
     * connects to a real PostgreSQL server (migrations run natively); otherwise
     * an in-memory SQLite PDO is returned with the PG→SQLite translation layer.
     *
     * @param bool $stringifyFetches When true on the SQLite path, integers come
     *                               back as strings, mirroring PostgreSQL's PDO
     *                               driver behaviour (ignored on the PG path,
     *                               where the native driver always stringifies).
     */
    public static function make(bool $stringifyFetches = false): PDO
    {
        $pgDsn = $_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN') ?: null;

        if ($pgDsn !== null) {
            return self::buildPostgresPdo((string) $pgDsn);
        }

        $pdo = self::buildTranslatingPdo($stringifyFetches);
        self::runMigrations($pdo);
        return $pdo;
    }

    /**
     * Apply all core migrations to an already-constructed SQLite PDO.
     *
     * The caller is responsible for having set ATTR_ERRMODE_EXCEPTION on the
     * PDO before calling this method.  The NOW() UDF is registered here.
     */
    public static function apply(PDO $pdo): void
    {
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
        $db = self::databaseWrapper($pdo);
        self::runMigrations($pdo, $db);
    }

    // ─── PostgreSQL real-engine path ─────────────────────────────────────────

    /**
     * Build a PostgreSQL PDO that wraps each make() call in its own dedicated
     * Postgres schema (namespace).  The schema is:
     *  - created fresh, with search_path locked to it
     *  - all production migrations applied inside it
     *  - registered for DROP on process exit so the host database is left clean
     *
     * The returned PDO subclass also translates the SQLite-only DML idioms that
     * appear in test seed helpers (INSERT OR IGNORE → INSERT … ON CONFLICT DO
     * NOTHING; datetime('now') → NOW(); etc.) so test files need no changes.
     */
    private static function buildPostgresPdo(string $dsn): PDO
    {
        $user     = (string) ($_ENV['PHPUNIT_PG_USER']     ?? getenv('PHPUNIT_PG_USER')     ?: 'whity');
        $password = (string) ($_ENV['PHPUNIT_PG_PASSWORD'] ?? getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev');

        // Each make() call gets its own Postgres schema so tests are fully
        // isolated from the main whity_core schema AND from each other.
        $schemaName = 'phpunit_' . bin2hex(random_bytes(8));

        // Bootstrap connection: create the isolated schema and run migrations.
        $bootstrap = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $bootstrap->exec("CREATE SCHEMA {$schemaName}");
        $bootstrap->exec("SET search_path TO {$schemaName}, public");

        // Run the production migrations inside the fresh schema.
        //
        // NOTE: nothing beyond the migrations is seeded here — the Postgres path
        // must expose exactly the same starting state as the SQLite path (only
        // migration-seeded rows, e.g. the system tenant id=0 from migration 010).
        // Tests that need tenants 1/2 must seed them themselves, exactly as they
        // would (or already do) for SQLite.  Pre-seeding here collides with tests
        // that plain-INSERT tenant id=1 (duplicate key on tenants_pkey).
        self::runMigrationsOnPg($bootstrap);

        // Close the bootstrap connection; the wrapper opens its own.
        unset($bootstrap);

        // Register a shutdown function to clean up after the test process exits.
        register_shutdown_function(static function () use ($dsn, $user, $password, $schemaName): void {
            try {
                $cleanup = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $cleanup->exec("DROP SCHEMA IF EXISTS {$schemaName} CASCADE");
            } catch (\Throwable) {
                // best-effort; do not fail the process on cleanup errors
            }
        });

        // Return a DML-translating wrapper so SQLite seed idioms work on PG.
        return self::buildPgTranslatingWrapper($dsn, $user, $password, $schemaName);
    }

    /**
     * Run production migrations against a real Postgres connection that already
     * has search_path set to the target schema.
     *
     * Migrations use native PG DDL, so no translation is needed.  Output is
     * silenced the same way as the SQLite path (some migrations echo passwords).
     */
    private static function runMigrationsOnPg(PDO $pdo): void
    {
        $db    = self::databaseWrapper($pdo);
        $dir   = dirname(__DIR__, 2) . '/database/migrations';
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        ob_start();
        try {
            foreach ($files as $file) {
                $class = self::resolveMigrationClass($file);
                $class::up($db);
            }
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Build a PDO subclass connected to PostgreSQL that translates SQLite-only
     * DML idioms so test seed helpers run unchanged on Postgres.
     *
     * Translations applied to exec() / prepare() / query():
     *   INSERT OR IGNORE INTO t  →  INSERT INTO t … ON CONFLICT DO NOTHING
     *   datetime('now')          →  NOW()
     *   INTEGER PRIMARY KEY AUTOINCREMENT  →  SERIAL PRIMARY KEY  (test DDL)
     */
    private static function buildPgTranslatingWrapper(string $dsn, string $user, string $password, string $schemaName): PDO
    {
        // Build a fresh connection for the wrapper so the anonymous class can
        // call parent::__construct() with the real DSN.
        return new class ($dsn, $user, $password, $schemaName) extends PDO {
            public function __construct(
                string $dsn,
                string $user,
                string $password,
                string $schemaName,
            ) {
                parent::__construct($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                // Lock this connection to the isolated test schema.
                parent::exec("SET search_path TO {$schemaName}, public");
            }

            /** Translate SQLite-only SQL to PostgreSQL equivalents. */
            public static function translate(string $sql): string
            {
                // ── SQLite introspection → Postgres information_schema ──────────────

                // PRAGMA table_info(table_name) → query information_schema.columns
                // The PRAGMA returns rows with {cid, name, type, notnull, dflt_value, pk};
                // we return {name} only since that is all callers use from the result.
                if (preg_match("/^\s*PRAGMA\s+table_info\s*\(\s*['\"]?(\w+)['\"]?\s*\)\s*$/i", $sql, $m)) {
                    $table = $m[1];
                    return "SELECT column_name AS name FROM information_schema.columns "
                         . "WHERE table_schema = current_schema() AND table_name = " . "'{$table}'";
                }

                // Any other PRAGMA (e.g. PRAGMA foreign_keys = ON) is a SQLite-only
                // knob with no Postgres equivalent needed: Postgres always enforces
                // FKs, so translate to a harmless no-op statement.
                if (preg_match('/^\s*PRAGMA\b/i', $sql)) {
                    return 'SELECT 1';
                }

                // SELECT ... FROM sqlite_master WHERE ... name = 'table'
                // → query information_schema.tables
                if (preg_match('/sqlite_master/i', $sql)) {
                    // Extract the table name from  AND name = 'foo'  or  AND name = $1
                    if (preg_match("/AND\s+name\s*=\s*'([^']+)'/i", $sql, $m)) {
                        $table = $m[1];
                        return "SELECT table_name AS name FROM information_schema.tables "
                             . "WHERE table_schema = current_schema() AND table_name = '{$table}'";
                    }
                    // Fallback: list all tables in the current schema.
                    return "SELECT table_name AS name FROM information_schema.tables "
                         . "WHERE table_schema = current_schema()";
                }

                // ── INSERT OR IGNORE → ON CONFLICT DO NOTHING ──────────────────────

                $hadIgnore = (bool) preg_match('/\bINSERT\s+OR\s+IGNORE\s+INTO\b/i', $sql);
                if ($hadIgnore) {
                    $sql = preg_replace(
                        '/\bINSERT\s+OR\s+IGNORE\s+INTO\b/i',
                        'INSERT INTO',
                        $sql
                    ) ?? $sql;

                    if (!preg_match('/\bON\s+CONFLICT\b/i', $sql)) {
                        // Strip trailing semicolon before appending.
                        $sql = rtrim(rtrim($sql), ';');
                        $sql .= ' ON CONFLICT DO NOTHING';
                    }
                }

                // ── Boolean column value normalisation ─────────────────────────────
                // PostgreSQL enforces strict boolean typing; SQLite accepts 0/1.
                // Translate integer literals (0/1) to true/false in INSERT/UPDATE
                // statements that reference known boolean columns.
                // Pattern: after the column name appears in the column list, the
                // corresponding VALUE position has 0 or 1 that needs normalising.
                // Simpler heuristic: replace bare ", 0," → ", false," and ", 1," →
                // ", true," ONLY when the SQL contains a known boolean column name,
                // and ONLY in VALUES / SET contexts (not in WHERE predicates where
                // 1 / 0 are valid integer comparisons).
                static $boolColumns = ['two_factor_enabled', 'auto_provision'];
                foreach ($boolColumns as $col) {
                    if (stripos($sql, $col) !== false) {
                        // In INSERT INTO ... VALUES (..., 1, ...) and UPDATE SET col = 0
                        // the 0/1 are boolean values that Postgres needs as true/false.
                        // Replace only isolated 0 or 1 (word-boundary) preceded by comma
                        // or the column = assignment.
                        $sql = preg_replace('/\b' . $col . '\s*=\s*0\b/', $col . ' = false', $sql) ?? $sql;
                        $sql = preg_replace('/\b' . $col . '\s*=\s*1\b/', $col . ' = true', $sql) ?? $sql;
                    }
                }

                // ── Other DML / DDL shims ───────────────────────────────────────────

                // datetime('now') → NOW()
                $sql = str_replace("datetime('now')", 'NOW()', $sql);

                // SQLite test-only DDL: INTEGER PRIMARY KEY AUTOINCREMENT → SERIAL PRIMARY KEY
                $sql = preg_replace(
                    '/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/i',
                    'SERIAL PRIMARY KEY',
                    $sql
                ) ?? $sql;

                return $sql;
            }

            public function exec(string $statement): int|false
            {
                return parent::exec(self::translate($statement));
            }

            /** @param array<int, mixed> $options */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(self::translate($query), $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $query = self::translate($query);
                return $fetchMode === null
                    ? parent::query($query)
                    : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }
        };
    }

    // ─── SQLite path (unchanged) ──────────────────────────────────────────────

    /**
     * Build an anonymous PDO subclass that opens sqlite::memory: and applies
     * PG->SQLite SQL translation in exec(), prepare(), and query() so that the
     * production migration SQL runs without modification.
     */
    private static function buildTranslatingPdo(bool $stringifyFetches): PDO
    {
        $pdo = new class('sqlite::memory:') extends PDO
        {
            /** Apply PG-specific DDL -> SQLite translation. */
            public static function translate(string $sql): string
            {
                // Skip PostgreSQL-only DDL that has no SQLite equivalent.
                if (
                    preg_match('/^\s*ALTER\s+SEQUENCE\b/i', $sql) ||
                    preg_match('/^\s*ALTER\s+TABLE\s+\w+\s+ALTER\s+COLUMN\b/i', $sql)
                ) {
                    return 'SELECT 1'; // harmless no-op
                }

                // SERIAL / BIGSERIAL -> INTEGER PRIMARY KEY AUTOINCREMENT
                $sql = preg_replace('/\bBIGSERIAL\b/i', 'INTEGER', $sql) ?? $sql;
                $sql = preg_replace('/\bSERIAL\b/i', 'INTEGER', $sql) ?? $sql;

                // VARCHAR(n) -> TEXT
                $sql = preg_replace('/\bVARCHAR\s*\(\d+\)/i', 'TEXT', $sql) ?? $sql;

                // TIMESTAMP ... DEFAULT NOW() -> TEXT ... DEFAULT (datetime('now'))
                $sql = preg_replace(
                    "/\bTIMESTAMP\s+(NOT\s+NULL\s+)?DEFAULT\s+NOW\s*\(\)/i",
                    "TEXT $1DEFAULT (datetime('now'))",
                    $sql
                ) ?? $sql;
                // Remaining bare TIMESTAMP columns -> TEXT
                $sql = preg_replace('/\bTIMESTAMP\b/i', 'TEXT', $sql) ?? $sql;

                // BOOLEAN DEFAULT false/true -> INTEGER DEFAULT 0/1
                $sql = preg_replace('/\bBOOLEAN(\s+NOT\s+NULL)?\s+DEFAULT\s+false\b/i', 'INTEGER$1 DEFAULT 0', $sql) ?? $sql;
                $sql = preg_replace('/\bBOOLEAN(\s+NOT\s+NULL)?\s+DEFAULT\s+true\b/i',  'INTEGER$1 DEFAULT 1', $sql) ?? $sql;
                $sql = preg_replace('/\bBOOLEAN\b/i', 'INTEGER', $sql) ?? $sql;

                // JSONB / JSON -> TEXT
                $sql = preg_replace('/\bJSONB?\b/i', 'TEXT', $sql) ?? $sql;

                // PostgreSQL type-cast operator (e.g. '{}'::jsonb) -> strip ::typename
                $sql = preg_replace('/::\w+/i', '', $sql) ?? $sql;

                // DROP TABLE ... CASCADE -> DROP TABLE ... (SQLite has no CASCADE on DROP)
                $sql = preg_replace('/\bDROP\s+TABLE\s+(IF\s+EXISTS\s+)?(\w+)\s+CASCADE\b/i', 'DROP TABLE IF EXISTS $2', $sql) ?? $sql;
                $sql = preg_replace('/\bDROP\s+INDEX\s+(IF\s+EXISTS\s+)?(\w+)\s+CASCADE\b/i', 'DROP INDEX IF EXISTS $2', $sql) ?? $sql;

                // ADD COLUMN IF NOT EXISTS — strip "IF NOT EXISTS"
                $sql = preg_replace('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+/i', 'ADD COLUMN ', $sql) ?? $sql;

                // bare NOW() in DEFAULT clauses (not already translated)
                $sql = preg_replace('/\bDEFAULT\s+NOW\s*\(\)/i', "DEFAULT (datetime('now'))", $sql) ?? $sql;

                return $sql;
            }

            /**
             * Execute a (possibly multi-column-ADD-COLUMN) statement.
             *
             * @return int|false
             */
            public function exec(string $statement): int|false
            {
                $result = 0;
                foreach (self::splitIfMultiAddColumn($statement) as $stmt) {
                    $r = parent::exec(self::translate($stmt));
                    if ($r === false) {
                        return false;
                    }
                    $result = $r;
                }
                return $result;
            }

            /** @param array<int, mixed> $options */
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(self::translate($query), $options);
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $query = self::translate($query);
                return $fetchMode === null
                    ? parent::query($query)
                    : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            /**
             * Split "ALTER TABLE t ADD COLUMN a INT, ADD COLUMN b TEXT" into
             * separate ALTER TABLE statements so SQLite can handle them.
             *
             * @return list<string>
             */
            private static function splitIfMultiAddColumn(string $sql): array
            {
                // Only ALTER TABLE ... ADD COLUMN statements can be multi-column in PG.
                if (!preg_match('/^\s*ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN/i', $sql, $m)) {
                    return [$sql];
                }
                $table = $m[1];

                // Find all "ADD COLUMN ..." clauses.
                if (!preg_match_all('/ADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?(.+?)(?=,\s*ADD\s+COLUMN|$)/is', $sql, $cols)) {
                    return [$sql];
                }

                if (count($cols[0]) <= 1) {
                    return [$sql];
                }

                $stmts = [];
                foreach ($cols[1] as $colDef) {
                    $stmts[] = "ALTER TABLE {$table} ADD COLUMN " . trim($colDef);
                }
                return $stmts;
            }
        };

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($stringifyFetches) {
            $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        }
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

        return $pdo;
    }

    private static function databaseWrapper(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo, 86400, 86400);
        $db->forceConnect();
        return $db;
    }

    /**
     * @param PDO $pdo The translating PDO
     * @param Database|null $db Pre-built wrapper; null means build one from $pdo
     */
    private static function runMigrations(PDO $pdo, ?Database $db = null): void
    {
        if ($db === null) {
            $db = self::databaseWrapper($pdo);
        }

        $dir   = dirname(__DIR__, 2) . '/database/migrations';
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        // Some migrations (e.g. 010) print to stdout (generated password notices).
        // PHPUnit treats any test-time stdout output as a failure, so silence it.
        ob_start();
        try {
            foreach ($files as $file) {
                $class = self::resolveMigrationClass($file);
                try {
                    $class::up($db);
                } catch (\Throwable $e) {
                    $msg = strtolower($e->getMessage());
                    if (
                        str_contains($msg, 'alter sequence') ||
                        str_contains($msg, 'near "type": syntax error')
                    ) {
                        continue; // gracefully skip PG-only DDL
                    }
                    throw $e;
                }
            }
        } finally {
            ob_end_clean();
        }
    }

    private static function resolveMigrationClass(string $file): string
    {
        require_once $file;

        $name  = pathinfo($file, PATHINFO_FILENAME); // e.g. 001_create_users_roles
        $parts = explode('_', $name);
        array_shift($parts);                          // strip the numeric prefix
        return 'Database\\Migrations\\' . implode('', array_map('ucfirst', $parts));
    }
}
