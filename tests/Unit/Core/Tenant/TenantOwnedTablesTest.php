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
        foreach (['users', 'roles', 'persons', 'relations', 'audit_log', 'permission_delegations'] as $table) {
            self::assertTrue(TenantOwnedTables::isTenantOwned($table), "{$table} must be tenant-owned.");
        }
    }

    public function testMembershipIsCaseInsensitive(): void
    {
        self::assertTrue(TenantOwnedTables::isTenantOwned('USERS'));
        self::assertTrue(TenantOwnedTables::isTenantOwned('Audit_Log'));
    }

    public function testTransitivelyScopedAndGlobalTablesAreNotOwned(): void
    {
        // No tenant_id column: scoped via a parent (role_permissions->roles,
        // backup_codes->users) or genuinely global.
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
     * declares a `tenant_id` column. Mirrors how the canonical list was built.
     *
     * @return list<string>
     */
    private function tablesWithTenantIdColumnFromMigrations(): array
    {
        $dir = dirname(__DIR__, 4) . '/database/migrations';
        self::assertDirectoryExists($dir);

        $tables = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                continue;
            }

            // Locate each `CREATE TABLE [IF NOT EXISTS] <name> (` opener, then
            // walk its column list to the matching close paren so nested
            // `REFERENCES tenants(id)` parens don't truncate the body. A table is
            // tenant-owned when that body declares a `tenant_id <int-type>` column.
            if (preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*\(/i',
                $sql,
                $matches,
                PREG_OFFSET_CAPTURE | PREG_SET_ORDER
            )) {
                foreach ($matches as $m) {
                    $table = strtolower($m[1][0]);
                    $openParen = $m[0][1] + strlen($m[0][0]) - 1;
                    $body = $this->balancedParenBody($sql, $openParen);
                    if (preg_match('/\btenant_id\b\s+(?:INT|INTEGER|BIGINT|SERIAL)/i', $body) === 1) {
                        $tables[$table] = true;
                    }
                }
            }
        }

        return array_keys($tables);
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
