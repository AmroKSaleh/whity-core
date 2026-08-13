<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Tenant\MigrationTenantColumnLinter;
use Whity\Sdk\Tenant\TenantPredicateScanner;
use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * Shared base test case for the plugin tenant-isolation conformance kit
 * (WC-194).
 *
 * Any plugin — including a distributable one that depends ONLY on
 * `whity/plugin-sdk` and never on the host framework — extends this case in its
 * own test suite to PROVE its tenant isolation holds. It wires the three
 * conformance checks:
 *
 *   1. {@see testMigrationsDeclareTenantColumnOnTenantTables()} — the migration
 *      linter: every `CREATE TABLE` the plugin ships either declares a
 *      `tenant_id` column or is declared global / transitively-scoped (with a
 *      reason) in the registry. An unscoped tenant table FAILS.
 *
 *   2. {@see testHandlersScopeEveryTenantQuery()} — the tenant-predicate scanner
 *      over the plugin's source: every SELECT/UPDATE/DELETE on a tenant-owned
 *      table (the plugin's OR the host's, via the merged registry) binds a
 *      `tenant_id` predicate, honours `@tenant-guard-ignore: <reason>`, and
 *      respects the sanctioned-global allowlist. An unscoped query FAILS.
 *
 *   3. {@see testDeclaredTenantTablesExistWithTenantIdOnARealEngine()} — the
 *      RealEngine check: the plugin's migrations are applied to a genuine SQL
 *      engine (in-memory SQLite locally, the host's Postgres in CI) and every
 *      table the plugin declares tenant-owned is asserted to physically carry a
 *      `tenant_id` column. This catches a registry that claims a column the
 *      schema does not actually create.
 *
 * A plugin supplies its specifics by implementing the three abstract hooks. The
 * base class is engine-agnostic: override {@see makePdo()} to point at Postgres
 * in CI; the default in-memory SQLite is used locally.
 */
abstract class TenantIsolationConformanceTestCase extends TestCase
{
    /**
     * The registry of tenant-owned + sanctioned-global tables the plugin is
     * judged against.
     *
     * A plugin returns ITS OWN tenant-owned tables (each with a reason) and,
     * crucially, {@see TenantTableRegistry::merge()}s in the HOST's registry so
     * an unscoped query against a CORE tenant table is flagged too. Tables the
     * plugin creates that are global / transitively-scoped are declared here so
     * the migration linter treats them as sanctioned exceptions.
     */
    abstract protected function tenantTableRegistry(): TenantTableRegistry;

    /**
     * Absolute path to the directory holding the plugin's migration classes /
     * SQL. The migration linter scans this tree.
     */
    abstract protected function migrationsDirectory(): string;

    /**
     * Absolute path(s) to the plugin's handler/source directory. The
     * tenant-predicate scanner scans these trees for unscoped queries.
     *
     * @return list<string>
     */
    abstract protected function handlerSourceDirectories(): array;

    /**
     * The migration instances to apply on the real engine, in run order.
     *
     * Most plugins return their schema migrations (`new CreateXTable()`); a
     * plugin whose migrations need no host services can apply them directly.
     * Return [] to skip the RealEngine check (e.g. a plugin with no own tables).
     *
     * @return list<MigrationInterface>
     */
    protected function schemaMigrations(): array
    {
        return [];
    }

    /**
     * The tables the plugin declares tenant-owned AND creates itself — asserted
     * to physically carry a `tenant_id` column after the migrations run. By
     * default this is every tenant-owned table in the plugin's registry that is
     * not also part of the host (a plugin should not re-create host tables).
     *
     * Override to narrow/override the set if the default heuristic does not fit.
     *
     * @return list<string>
     */
    protected function ownTenantTables(): array
    {
        return $this->tenantTableRegistry()->tenantOwnedTables();
    }

    // ==================== 1. migration linter ====================

    final public function testMigrationsDeclareTenantColumnOnTenantTables(): void
    {
        $dir = $this->migrationsDirectory();
        self::assertDirectoryExists($dir, "Migrations directory not found: {$dir}");

        $linter = new MigrationTenantColumnLinter($this->tenantTableRegistry());
        $violations = $linter->lintDirectory($dir);

        self::assertSame(
            [],
            $violations,
            "Migration linter found tenant table(s) without a `tenant_id` column:\n"
            . self::formatLintViolations($violations)
        );
    }

    // ==================== 2. handler-scoping scanner ====================

    final public function testHandlersScopeEveryTenantQuery(): void
    {
        $scanner = new TenantPredicateScanner($this->tenantTableRegistry());

        $violations = [];
        foreach ($this->handlerSourceDirectories() as $dir) {
            self::assertDirectoryExists($dir, "Handler source directory not found: {$dir}");
            foreach ($scanner->scanDirectory($dir) as $violation) {
                $violations[] = $violation;
            }
        }

        self::assertSame(
            [],
            $violations,
            "Tenant-predicate scanner found unscoped query(ies) on tenant-owned table(s).\n"
            . "Scope the query with a `tenant_id` predicate, or — if the access is a\n"
            . "sanctioned exception — annotate it `// " . TenantPredicateScanner::IGNORE_TAG . " <reason>`.\n\n"
            . self::formatScanViolations($violations)
        );
    }

    // ==================== 3. RealEngine ====================

    final public function testDeclaredTenantTablesExistWithTenantIdOnARealEngine(): void
    {
        $migrations = $this->schemaMigrations();
        $ownTables = $this->ownTenantTables();

        if ($migrations === [] || $ownTables === []) {
            self::markTestSkipped('No own migrations/tenant tables to validate on a real engine.');
        }

        $pdo = $this->makePdo();
        foreach ($migrations as $migration) {
            $migration->up($pdo);
        }

        foreach ($ownTables as $table) {
            self::assertTrue(
                $this->tableHasTenantIdColumn($pdo, $table),
                "Tenant-owned table `{$table}` must physically carry a `tenant_id` column "
                . 'after the plugin migrations run on a real engine.'
            );
        }
    }

    /**
     * The PDO the RealEngine check runs against. Defaults to in-memory SQLite
     * with PostgreSQL-flavoured affordances (NOW(), string fetches) so plugin
     * migrations written for Postgres apply unmodified locally. Override in CI
     * to return a real Postgres connection.
     */
    protected function makePdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, true);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        return $pdo;
    }

    /**
     * Whether the given table has a `tenant_id` column on the live connection.
     * Engine-aware: uses SQLite's PRAGMA locally, information_schema elsewhere.
     */
    private function tableHasTenantIdColumn(\PDO $pdo, string $table): bool
    {
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            // PRAGMA table_info has no parameter binding; the table name comes
            // from the plugin's own declared list, not from request input.
            $stmt = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
            if ($stmt === false) {
                return false;
            }
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $col) {
                if (strtolower((string) ($col['name'] ?? '')) === 'tenant_id') {
                    return true;
                }
            }

            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_name = :table AND column_name = :column
             LIMIT 1'
        );
        $stmt->execute([':table' => $table, ':column' => 'tenant_id']);

        return $stmt->fetchColumn() !== false;
    }

    // ==================== reporting ====================

    /**
     * @param list<array{file: string, table: string, reason: string}> $violations
     */
    private static function formatLintViolations(array $violations): string
    {
        return implode("\n", array_map(
            static fn (array $v): string => sprintf('  %s [%s]: %s', $v['file'], $v['table'], $v['reason']),
            $violations
        ));
    }

    /**
     * @param list<array{file: string, line: int, tables: list<string>, sql: string}> $violations
     */
    private static function formatScanViolations(array $violations): string
    {
        return implode("\n", array_map(
            static fn (array $v): string => sprintf(
                '  %s:%d [%s]  %s',
                $v['file'],
                $v['line'],
                implode(', ', $v['tables']),
                $v['sql']
            ),
            $violations
        ));
    }
}
