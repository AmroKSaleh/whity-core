<?php

declare(strict_types=1);

namespace Whity\Database;

/**
 * PDO subclass making an unmodified plugin's Postgres-flavored migrations and
 * queries run correctly against SQLite.
 *
 * Handles exactly the two divergences DemoCatalog's actual migrations use —
 * confirmed by reading the vendored source, not assumed:
 *
 *   1. `id SERIAL PRIMARY KEY` — SQLite parses SERIAL as an unrecognized type
 *      (NUMERIC affinity), NOT as its rowid/autoincrement alias, so every
 *      insert would leave `id` NULL and lastInsertId() would never match a
 *      later lookup. Rewritten to `INTEGER PRIMARY KEY`, SQLite's real alias.
 *   2. `DEFAULT (NOW())` — SQLite has no NOW() builtin; registered as a UDF
 *      instead of rewriting the SQL (same technique the SDK's own
 *      TenantIsolationConformanceTestCase::makePdo() test helper uses).
 *
 * `ON CONFLICT (...) DO NOTHING` and `CURRENT_TIMESTAMP` need no handling —
 * SQLite supports both natively. `nextChangeSeq()`'s pgsql-vs-sqlite RETURNING
 * branch is already handled inside DemoCatalogApiHandler itself.
 *
 * A future plugin using a Postgres construct not covered here (JSONB,
 * gen_random_uuid(), RETURNING in DDL, ...) needs a new rule added
 * explicitly — this is deliberately narrow, not a general SQL translator.
 */
final class SqliteCompatPdo extends \Pdo\Sqlite
{
    public function __construct(string $sqlitePath)
    {
        parent::__construct("sqlite:{$sqlitePath}", null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);

        // Postgres returns typed columns; SQLite's driver returns everything
        // as strings by default only with this attribute set explicitly.
        // Plugin code (toPublicItem() casts) tolerates either, but this keeps
        // behavior closer to production.
        $this->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);

        // Pdo\Sqlite::createFunction() (PHP 8.4+), not the deprecated
        // PDO::sqliteCreateFunction() — a deprecation notice here would print
        // straight into the HTTP response body under FrankenPHP and corrupt
        // every JSON response, so this isn't just style.
        $this->createFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
        $this->exec('PRAGMA foreign_keys = ON');
        // Matches the Rust side's rusqlite connections (src-tauri/src/db/,
        // busy_timeout=5000) — without this, a second writer hitting a lock
        // held by another gets an immediate "database is locked" error
        // instead of waiting. Confirmed live: FrankenPHP running with its
        // default one-worker-per-CPU-core pool (no explicit worker count set)
        // hit exactly this running concurrent migrations. The real fix for
        // that specific case is pinning FrankenPHP to one worker (done on the
        // Rust side, see sidecar.rs) — this pragma is a general-resilience
        // match to the Rust connections' own convention, not a substitute.
        $this->exec('PRAGMA busy_timeout = 5000');
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        return parent::exec($this->rewrite($statement));
    }

    #[\Override]
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    #[\Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $rewritten = $this->rewrite($query);

        return $fetchMode === null
            ? parent::query($rewritten)
            : parent::query($rewritten, $fetchMode, ...$fetchModeArgs);
    }

    private function rewrite(string $sql): string
    {
        return preg_replace('/\bSERIAL\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY', $sql) ?? $sql;
    }
}
