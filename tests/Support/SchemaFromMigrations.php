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
 * SQLite limitations handled automatically:
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
     * Build a fresh in-memory SQLite PDO with the full production schema applied.
     *
     * @param bool $stringifyFetches When true, integers come back as strings,
     *                               mirroring PostgreSQL's PDO driver behaviour.
     */
    public static function make(bool $stringifyFetches = false): PDO
    {
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

    // ─── internals ────────────────────────────────────────────────────────────

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
