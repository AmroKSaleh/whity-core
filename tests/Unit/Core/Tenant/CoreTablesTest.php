<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\CoreTables;
use Whity\Core\Tenant\SanctionedGlobalTables;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * Pins {@see CoreTables} against the migrations (WC-723).
 *
 * Why the drift alarm matters here specifically: table OWNERSHIP is positive —
 * a table nobody has claimed is claimable by the next plugin that asks for it,
 * and a plugin that owns a table may declare a referential guard over it. So a
 * core table missing from this list is not a cosmetic omission; it is a table a
 * plugin can claim and then count rows in.
 *
 * The set is re-derived here from every `CREATE TABLE` in a forward `up()`,
 * minus every table a later `up()` drops — the same technique
 * TenantOwnedTablesTest uses, and processed in migration order so a later DROP
 * removes an earlier CREATE.
 */
final class CoreTablesTest extends TestCase
{
    public function testCanonicalSetMatchesTheTablesCreatedByTheMigrations(): void
    {
        $expected = $this->tablesCreatedByMigrations();
        sort($expected);
        $actual = CoreTables::all();
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            "CoreTables::all() drifted from the migrations. A core table missing here is a table "
            . "a plugin can CLAIM, and therefore declare a referential guard over. Tables created "
            . 'by the migrations: ' . implode(', ', $expected)
        );
    }

    public function testEveryTenantOwnedTableIsACoreTable(): void
    {
        foreach (TenantOwnedTables::all() as $table) {
            self::assertTrue(
                CoreTables::isCoreTable($table),
                "{$table} is tenant-owned but absent from CoreTables — ownership would be unclaimed."
            );
        }
    }

    public function testEverySanctionedGlobalTableIsACoreTable(): void
    {
        foreach (SanctionedGlobalTables::all() as $table) {
            self::assertTrue(
                CoreTables::isCoreTable($table),
                "{$table} is a sanctioned global but absent from CoreTables."
            );
        }
    }

    public function testTablesInNeitherOtherListAreStillCoreOwned(): void
    {
        // The exact gap CoreTables exists to close: catalogues and
        // transitively-scoped children appear in neither of the two older lists.
        foreach (['permissions', 'role_permissions', 'backup_codes', 'tenants', 'relationship_types'] as $table) {
            self::assertTrue(CoreTables::isCoreTable($table), "{$table} must be recognised as core's.");
            self::assertFalse(TenantOwnedTables::isTenantOwned($table));
            self::assertFalse(SanctionedGlobalTables::isGlobal($table));
        }
    }

    public function testMembershipIsCaseInsensitive(): void
    {
        self::assertTrue(CoreTables::isCoreTable('MEMBERSHIPS'));
        self::assertTrue(CoreTables::isCoreTable('Audit_Log'));
        self::assertFalse(CoreTables::isCoreTable('acme_records'));
    }

    public function testProvenanceIsRecordedForEveryEntry(): void
    {
        foreach (CoreTables::all() as $table) {
            $migration = CoreTables::migrationFor($table);
            self::assertIsString($migration);
            self::assertMatchesRegularExpression(
                '/^\d{3}_[a-z0-9_]+\.php$/',
                $migration,
                "{$table} must record the migration that creates it."
            );
            self::assertFileExists(
                dirname(__DIR__, 4) . '/database/migrations/' . $migration,
                "{$table} names a migration file that does not exist."
            );
        }
    }

    /**
     * Re-derive every table created by a forward migration, minus every table a
     * later forward migration drops.
     *
     * @return list<string>
     */
    private function tablesCreatedByMigrations(): array
    {
        $dir = dirname(__DIR__, 4) . '/database/migrations';
        self::assertDirectoryExists($dir);

        $files = glob($dir . '/*.php') ?: [];
        sort($files); // migration order

        $tables = [];
        $dropped = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }
            $up = $this->extractUpMethodBody($source);

            if (preg_match_all(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*\(/i',
                $up,
                $matches
            )) {
                foreach ($matches[1] as $table) {
                    $tables[strtolower($table)] = true;
                }
            }

            if (preg_match_all(
                '/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?/i',
                $up,
                $dropMatches
            )) {
                foreach ($dropMatches[1] as $table) {
                    $dropped[strtolower($table)] = true;
                }
            }
        }

        foreach (array_keys($dropped) as $table) {
            unset($tables[$table]);
        }

        return array_keys($tables);
    }

    /**
     * Extract the body of the static up() method, so a down() that recreates a
     * dropped table does not re-add it to the derived set.
     */
    private function extractUpMethodBody(string $phpSource): string
    {
        if (
            !preg_match(
                '/public\s+static\s+function\s+up\s*\([^)]*\)\s*:\s*\w+\s*\{/i',
                $phpSource,
                $m,
                PREG_OFFSET_CAPTURE
            )
        ) {
            return $phpSource;
        }

        $openBrace = (int) $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $length = strlen($phpSource);
        for ($i = $openBrace; $i < $length; $i++) {
            if ($phpSource[$i] === '{') {
                $depth++;
            } elseif ($phpSource[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($phpSource, $openBrace + 1, $i - $openBrace - 1);
                }
            }
        }

        return $phpSource;
    }
}
