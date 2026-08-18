<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing\Support;

/**
 * A minimal, SDK-self-contained stand-in for the offline desktop host's own
 * `SqliteCompatPdo` (templates/tauri-desktop/php-host/src/Database/) — the
 * SDK cannot reference that class directly (it lives in a downstream
 * template, not in whity/plugin-sdk), so this reproduces the one dialect
 * rewrite that matters for a conformance test: `SERIAL PRIMARY KEY` (a
 * Postgres-ism many plugin migrations use) rewritten to SQLite's real
 * autoincrement alias, `INTEGER PRIMARY KEY`. Without this, any plugin using
 * `SERIAL` would fail {@see \Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase}'s
 * migration check even though the real offline host handles it fine.
 *
 * Deliberately narrow, matching the production shim's own documented scope —
 * a future plugin using `JSONB`/`gen_random_uuid()`/`RETURNING` in DDL is
 * still unhandled here, same as in the real host.
 */
final class OfflineHostReferencePdo extends \PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        // The same technique TenantIsolationConformanceTestCase::makePdo()
        // already uses in this SDK: PDO::sqliteCreateFunction() is deprecated
        // since PHP 8.5, but that only matters when a deprecation notice can
        // corrupt an HTTP response body (the real offline host's production
        // SqliteCompatPdo extends \Pdo\Sqlite for exactly that reason). This
        // is test-only code run under PHPUnit CLI — no response body to
        // corrupt — so it matches the SDK's own established, PHPStan-clean
        // convention instead of introducing a second pattern.
        $this->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);
    }

    #[\Override]
    public function exec(string $statement): int|false
    {
        return parent::exec($this->rewrite($statement));
    }

    /**
     * @param array<int, mixed> $options
     */
    #[\Override]
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    private function rewrite(string $sql): string
    {
        return preg_replace('/\bSERIAL\s+PRIMARY\s+KEY\b/i', 'INTEGER PRIMARY KEY', $sql) ?? $sql;
    }
}
