<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

use PDO;
use RuntimeException;

/**
 * The supported way for a plugin's own test suite to get a REAL SQL engine —
 * in-memory SQLite by default, genuine PostgreSQL when `PHPUNIT_PG_DSN` is set.
 *
 * Why this exists
 * ---------------
 * A plugin's tests run on SQLite, its users run PostgreSQL, and a whole class
 * of defect lives in that gap and cannot be found by any amount of extra
 * assertions:
 *
 *   - `GROUP_CONCAT(x SEPARATOR ',')` parses on SQLite and is a syntax error on
 *     PostgreSQL (which spells it `string_agg(x, ',')`);
 *   - `WHERE varchar_col = 42` silently coerces on SQLite and is a hard
 *     `operator does not exist: character varying = integer` on PostgreSQL.
 *
 * The second one is the important one: no wrapper library or SQL-builder helper
 * can catch it, because nothing about the statement is *wrong* — it is the
 * engine's type-comparison semantics that differ. The only thing that finds it
 * is executing the statement on the engine you actually ship against. So make
 * that easy instead of clever.
 *
 * Usage in a plugin test
 * ----------------------
 *     $pdo = RealEnginePdo::make();
 *     foreach ($this->migrations() as $migration) {
 *         $migration->up($pdo);
 *     }
 *
 * Run it the normal way for the fast local loop, and again with a DSN for the
 * engine that matters:
 *
 *     vendor/bin/phpunit                                    # SQLite
 *     PHPUNIT_PG_DSN="pgsql:host=127.0.0.1;port=5432;dbname=plugin_test" \
 *     PHPUNIT_PG_USER=postgres PHPUNIT_PG_PASSWORD=postgres \
 *       vendor/bin/phpunit                                  # real PostgreSQL
 *
 * A throwaway server is one command:
 *
 *     docker run -d --name plugin_pg -p 5432:5432 \
 *       -e POSTGRES_PASSWORD=postgres -e POSTGRES_DB=plugin_test \
 *       postgres:15-alpine
 *
 * Recognised environment variables:
 *
 *   PHPUNIT_PG_DSN       PDO pgsql DSN. Absent/empty ⇒ SQLite. Presence is the
 *                        entire switch; nothing else changes in the test.
 *   PHPUNIT_PG_USER      Connecting role. Absent ⇒ libpq's own defaults (PGUSER,
 *                        else the OS user).
 *   PHPUNIT_PG_PASSWORD  Password. Absent ⇒ libpq's defaults (PGPASSWORD,
 *                        ~/.pgpass).
 *
 * Isolation: every {@see make()} call on the PostgreSQL path creates its own
 * schema (namespace) in the target database, locks the connection's
 * `search_path` to it, and drops it at process exit. Concurrent test processes,
 * and tests within one process, therefore never see each other's tables — and
 * the target database is left exactly as it was found. That means it is safe to
 * point `PHPUNIT_PG_DSN` at a scratch database without a per-test reset step;
 * it is NOT a licence to point it at anything with real data in it.
 */
final class RealEnginePdo
{
    /** Prefix for the per-call isolation schemas, so leftovers are identifiable. */
    private const SCHEMA_PREFIX = 'whity_plugin_test_';

    /**
     * A real SQL engine for a test: PostgreSQL when `PHPUNIT_PG_DSN` is set,
     * otherwise in-memory SQLite.
     */
    public static function make(): PDO
    {
        $dsn = self::env('PHPUNIT_PG_DSN');

        return $dsn === null ? self::sqlite() : self::postgres($dsn);
    }

    /** Whether {@see make()} will hand back PostgreSQL rather than SQLite. */
    public static function isPostgres(): bool
    {
        return self::env('PHPUNIT_PG_DSN') !== null;
    }

    /**
     * In-memory SQLite with the PostgreSQL-flavoured affordances plugin
     * migrations tend to assume.
     *
     * `NOW()` is registered as a UDF (SQLite spells it `datetime('now')`), and
     * ATTR_STRINGIFY_FETCHES is on so every column comes back as a PHP string —
     * which is what the PostgreSQL driver does, and therefore the only way an
     * `assertSame(1, $row['id'])` that would break in production breaks here.
     */
    public static function sqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        return $pdo;
    }

    /**
     * A real PostgreSQL connection scoped to a private, auto-dropped schema.
     *
     * @param string|null $dsn Defaults to `PHPUNIT_PG_DSN`.
     */
    public static function postgres(?string $dsn = null): PDO
    {
        $dsn ??= self::env('PHPUNIT_PG_DSN');
        if ($dsn === null) {
            throw new RuntimeException(
                'No PostgreSQL DSN: pass one, or set PHPUNIT_PG_DSN '
                . '(e.g. "pgsql:host=127.0.0.1;port=5432;dbname=plugin_test").'
            );
        }

        $user     = self::env('PHPUNIT_PG_USER');
        $password = self::env('PHPUNIT_PG_PASSWORD');

        $schema = self::SCHEMA_PREFIX . bin2hex(random_bytes(8));

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE SCHEMA ' . self::quoteIdentifier($schema));
        // `public` stays on the path so extensions installed there (pgcrypto,
        // uuid-ossp, …) still resolve; the private schema shadows it for tables.
        $pdo->exec('SET search_path TO ' . self::quoteIdentifier($schema) . ', public');

        // Drop on a SEPARATE connection at exit: this one may be mid-transaction
        // or already collected, and a schema left behind would accumulate in a
        // long-lived scratch database.
        register_shutdown_function(static function () use ($dsn, $user, $password, $schema): void {
            try {
                $cleanup = new PDO((string) $dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $cleanup->exec('DROP SCHEMA IF EXISTS ' . self::quoteIdentifier($schema) . ' CASCADE');
            } catch (\Throwable) {
                // Best-effort: never turn cleanup trouble into a failed test run.
            }
        });

        return $pdo;
    }

    /** A non-empty environment variable, from `$_ENV` or the process env; else null. */
    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Quote an identifier we generated ourselves. Not user input — but a
     * generated name still goes through quoting so the pattern in this file is
     * the one a reader copies.
     */
    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
