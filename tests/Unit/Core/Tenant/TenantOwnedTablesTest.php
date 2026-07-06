<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\SanctionedGlobalTables;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * Pins the tenant-owned table set (WC-192) against the migrations so the guard
 * can never silently drift from the schema.
 *
 * The test re-derives the set of tables that declare a `tenant_id` column
 * straight from database/migrations/ and asserts it equals
 * {@see TenantOwnedTables::all()}. Add a tenant-owned table in a migration and
 * this fails until the canonical list is updated — exactly the drift alarm the
 * static guard needs to stay trustworthy.
 */
final class TenantOwnedTablesTest extends TestCase
{
    public function testCanonicalSetMatchesTablesWithTenantIdColumnInMigrations(): void
    {
        $derived = $this->tablesWithTenantIdColumnFromMigrations();

        $expected = $derived;
        sort($expected);
        $actual = TenantOwnedTables::all();
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            "TenantOwnedTables::all() drifted from the migrations. Tables declaring a "
            . "tenant_id column: " . implode(', ', $expected)
        );
    }

    public function testKnownTenantTablesAreOwned(): void
    {
        foreach (['roles', 'persons', 'relations', 'audit_log', 'permission_delegations', 'memberships'] as $table) {
            self::assertTrue(TenantOwnedTables::isTenantOwned($table), "{$table} must be tenant-owned.");
        }
    }

    public function testMembershipIsCaseInsensitive(): void
    {
        self::assertTrue(TenantOwnedTables::isTenantOwned('MEMBERSHIPS'));
        self::assertTrue(TenantOwnedTables::isTenantOwned('Audit_Log'));
    }

    public function testTransitivelyScopedAndGlobalTablesAreNotOwned(): void
    {
        // No tenant_id column: scoped via a parent (role_permissions->roles,
        // backup_codes->profiles) or genuinely global.
        foreach (['role_permissions', 'backup_codes', 'permissions', 'relationship_types', 'tenants'] as $table) {
            self::assertFalse(
                TenantOwnedTables::isTenantOwned($table),
                "{$table} has no tenant_id column and must NOT be treated as tenant-owned."
            );
        }
    }

    public function testOwnedSetAndSanctionedGlobalSetAreDisjoint(): void
    {
        $overlap = array_intersect(TenantOwnedTables::all(), SanctionedGlobalTables::all());
        self::assertSame([], $overlap, 'A table cannot be both tenant-owned and a sanctioned global table.');
    }

    /**
     * Re-derive, from the migration SQL, every table whose CREATE TABLE body
     * declares a `tenant_id` column — minus any table that is later DROPped by
     * a forward migration `up()` method (e.g. migration 039 drops user_roles).
     *
     * Migrations are processed in filename order (the same order the runner uses)
     * so that a later DROP correctly removes a table a earlier CREATE added.
     *
     * Only the `up()` method body is considered — the `down()` method may recreate
     * a dropped table for rollback purposes, but the live forward schema reflects
     * `up()` only.
     *
     * @return list<string>
     */
    private function tablesWithTenantIdColumnFromMigrations(): array
    {
        $dir = dirname(__DIR__, 4) . '/database/migrations';
        self::assertDirectoryExists($dir);

        $files = glob($dir . '/*.php') ?: [];
        sort($files); // process in migration order

        $tables  = [];
        $dropped = [];

        foreach ($files as $file) {
            $fullSql = file_get_contents($file);
            if ($fullSql === false) {
                continue;
            }

            // Extract only the up() method body so that down() recreations of
            // dropped tables do not re-add entries to the derived set.
            $upSql = $this->extractUpMethodBody($fullSql);

            // 1. Accumulate tables with a tenant_id column from CREATE TABLE.
            if (preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*\(/i',
                $upSql,
                $matches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER
            )) {
                foreach ($matches as $m) {
                    $table    = strtolower($m[1][0]);
                    $openParen = $m[0][1] + strlen($m[0][0]) - 1;
                    $body     = $this->balancedParenBody($upSql, $openParen);
                    if (preg_match('/\btenant_id\b\s+(?:INT|INTEGER|BIGINT|SERIAL)/i', $body) === 1) {
                        $tables[$table] = true;
                    }
                }
            }

            // 2. Track tables dropped by a forward migration.
            if (preg_match_all(
                '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i',
                $upSql,
                $dropMatches
            )) {
                foreach ($dropMatches[1] as $droppedTable) {
                    $dropped[strtolower($droppedTable)] = true;
                }
            }
        }

        // Remove tables that are dropped in a later up() (they are not in the
        // live forward schema).
        foreach ($dropped as $droppedTable => $_) {
            unset($tables[$droppedTable]);
        }

        return array_keys($tables);
    }

    /**
     * Extract the body of the static up() method from the migration PHP source.
     * Returns the full file content when extraction is not possible (safe fallback).
     */
    private function extractUpMethodBody(string $phpSource): string
    {
        // Find `public static function up(...)` and capture its brace-delimited body.
        if (!preg_match('/public\s+static\s+function\s+up\s*\([^)]*\)\s*:\s*\w+\s*\{/i', $phpSource, $m, PREG_OFFSET_CAPTURE)) {
            return $phpSource; // fallback: scan the whole file
        }

        $openBrace = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $depth     = 0;
        $len       = strlen($phpSource);
        for ($i = $openBrace; $i < $len; $i++) {
            if ($phpSource[$i] === '{') {
                $depth++;
            } elseif ($phpSource[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($phpSource, $openBrace + 1, $i - $openBrace - 1);
                }
            }
        }

        return $phpSource; // fallback
    }

    /**
     * Given the position of an opening `(`, return the substring up to its
     * matching close paren (handling nested parens such as `REFERENCES t(id)`).
     */
    private function balancedParenBody(string $sql, int $openParen): string
    {
        $depth = 0;
        $len = strlen($sql);
        for ($i = $openParen; $i < $len; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($sql, $openParen + 1, $i - $openParen - 1);
                }
            }
        }

        return substr($sql, $openParen + 1);
    }
}
