<?php

declare(strict_types=1);

namespace Whity\Sdk\Tenant;

/**
 * Migration linter for the tenant-isolation conformance kit (WC-194).
 *
 * Scans a plugin's migration source for `CREATE TABLE` statements and proves
 * every table that stores tenant-owned data declares a `tenant_id` column. A
 * table that is, by design, GLOBAL (platform-unique rows) or TRANSITIVELY
 * SCOPED (no `tenant_id` column of its own; isolated through a parent table)
 * must be explicitly declared so via a {@see TenantTableRegistry} — the
 * exception is then recorded and reviewable rather than a silent omission.
 *
 * Verdict per `CREATE TABLE`:
 *   PASS  — the table body declares a `tenant_id <int-type>` column; OR the
 *           table is declared global / transitively-scoped in the registry.
 *   FAIL  — the table declares NO `tenant_id` column AND is not declared as a
 *           sanctioned exception. This is the teeth: a plugin tenant table
 *           missing `tenant_id` cannot pass the kit.
 *
 * The parser walks each `CREATE TABLE … (` opener to its matching close paren
 * (so nested `REFERENCES parent(id)` parens never truncate the body), mirroring
 * the host's TenantOwnedTablesTest so the linter and the host's own drift alarm
 * read the schema identically. It is pure PHP and depends on nothing but the
 * SDK, so an out-of-repo plugin can run it in its own CI.
 *
 * @phpstan-type LintViolation array{file: string, table: string, reason: string}
 */
final class MigrationTenantColumnLinter
{
    /**
     * Columns whose presence (with an integer type) marks a table tenant-owned.
     */
    private const TENANT_COLUMN = 'tenant_id';

    private TenantTableRegistry $registry;

    /**
     * @param TenantTableRegistry $registry Declares the tables that are, by
     *        design, global or transitively-scoped and therefore exempt from
     *        carrying a `tenant_id` column (its tenant-owned set is advisory
     *        here — membership is determined from the migration body).
     */
    public function __construct(TenantTableRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Lint a directory tree of migration PHP files.
     *
     * @return list<LintViolation>
     */
    public function lintDirectory(string $dir): array
    {
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var array<int, string> $paths */
        $paths = [];
        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                $paths[] = $fileInfo->getPathname();
            }
        }
        sort($paths);

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }
            foreach ($this->lintSource($source, $path) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * Lint a single source string (the SQL text of one or more migrations).
     * Exposed for unit testing.
     *
     * @return list<LintViolation>
     */
    public function lintSource(string $source, string $file = '<source>'): array
    {
        $violations = [];

        foreach ($this->createTableBodies($source) as [$table, $body]) {
            if ($this->declaresTenantColumn($body)) {
                continue; // Tenant-owned and correctly columned — PASS.
            }
            if ($this->registry->isGlobal($table) || $this->registry->isTenantOwned($table)) {
                // Declared exception: global, or a registry entry that records a
                // transitively-scoped table whose isolation is enforced elsewhere.
                continue;
            }

            $violations[] = [
                'file' => $file,
                'table' => $table,
                'reason' => sprintf(
                    'CREATE TABLE %s declares no `%s` column and is not declared global '
                    . 'or transitively-scoped. Add a `%s` column, or declare it in the '
                    . 'TenantTableRegistry with a documented reason.',
                    $table,
                    self::TENANT_COLUMN,
                    self::TENANT_COLUMN
                ),
            ];
        }

        return $violations;
    }

    /**
     * Every `CREATE TABLE <name> (...)` in the source, as [table, columnBody].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function createTableBodies(string $source): array
    {
        $bodies = [];

        if (preg_match_all(
            '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?([A-Za-z_][A-Za-z0-9_]*)["`]?\s*\(/i',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $table = strtolower($m[1][0]);
                $openParen = $m[0][1] + strlen($m[0][0]) - 1;
                $bodies[] = [$table, $this->balancedParenBody($source, $openParen)];
            }
        }

        return $bodies;
    }

    /**
     * Whether the CREATE TABLE body declares a `tenant_id` integer column.
     *
     * Requires an integer-flavoured type so a coincidental mention of the
     * identifier (e.g. in a comment) does not count as a column declaration.
     */
    private function declaresTenantColumn(string $body): bool
    {
        return preg_match(
            '/\b' . self::TENANT_COLUMN . '\b\s+(?:INT|INTEGER|BIGINT|SMALLINT|SERIAL|BIGSERIAL)/i',
            $body
        ) === 1;
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
