<?php

declare(strict_types=1);

/**
 * CI tenant-predicate guard (WC-192): enforce the platform's #1 isolation
 * invariant in CI. Scans the given roots (CI passes src, database and plugins —
 * #1262) for SELECT/UPDATE/DELETE statements that touch a
 * TENANT-OWNED table ({@see Whity\Core\Tenant\TenantOwnedTables}) without binding
 * a `tenant_id` predicate, and FAILS the build on any such statement that is not
 * a sanctioned global table ({@see Whity\Core\Tenant\SanctionedGlobalTables}) and
 * not carrying an explicit, reasoned `@tenant-guard-ignore:` annotation.
 *
 * Mirrors scripts/ci-plugin-smoke.php: standalone, no HTTP/DB, exits non-zero on
 * any violation so a missing tenant scope fails CI directly rather than leaking
 * cross-tenant data at runtime.
 *
 * Usage:  php scripts/ci-tenant-predicate-guard.php [path ...]
 *         (defaults to scanning src/; CI passes `src database plugins`)
 *
 * Migration directories are excluded by policy wherever they appear under a
 * scanned root — see the note beside $isMigrationPath below. The number
 * excluded is printed on every run, pass or fail, so the exclusion stays
 * visible instead of becoming the next unnoticed coverage gap.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Tenant\TenantPredicateGuard;

$roots = array_slice($argv, 1);
if ($roots === []) {
    $roots = [dirname(__DIR__) . '/src'];
}

/**
 * Migration directories are OUT OF SCOPE, and this is a policy, not an oversight.
 *
 * The invariant this guard enforces is a REQUEST-scoped one: a handler serving
 * tenant A must not read tenant B's rows. A migration has no request and no
 * current tenant behind it — it runs once, from the CLI, deliberately across
 * every tenant at once. "Bind a tenant_id predicate" is therefore not merely
 * unnecessary there, it is usually WRONG: a backfill that scoped itself would
 * silently skip every tenant but one.
 *
 * So these paths are excluded here rather than annotated 40 times over. The
 * count is reported on every run: an exclusion nobody can see is how this guard
 * came to scan `src/` alone for so long while being trusted to cover more.
 *
 * NOT excluded, and deliberately so: a plugin's Api/ and Jobs/ code, which is
 * request-scoped exactly like core's.
 */
$isMigrationPath = static function (string $file): bool {
    $normalized = str_replace('\\', '/', $file);

    return (bool) preg_match('#(^|/)([Mm]igrations)/#', $normalized);
};

$guard = new TenantPredicateGuard();
$violations = [];
$excludedMigrationHits = 0;
foreach ($roots as $root) {
    if (!is_dir($root)) {
        fwrite(STDERR, "FAIL: not a directory: {$root}\n");
        exit(2);
    }
    foreach ($guard->scanDirectory($root) as $violation) {
        if ($isMigrationPath((string) $violation['file'])) {
            $excludedMigrationHits++;
            continue;
        }
        $violations[] = $violation;
    }
}

if ($violations !== []) {
    fwrite(STDERR, 'FAIL: ' . count($violations) . " unscoped tenant-table query(ies) found.\n\n");
    fwrite(STDERR, "The tenant-isolation invariant requires every SELECT/UPDATE/DELETE on a\n");
    fwrite(STDERR, "tenant-owned table to bind a `tenant_id` predicate. Scope the query, or — if\n");
    fwrite(STDERR, "the access is a sanctioned exception (e.g. a system-tenant branch) — annotate\n");
    fwrite(STDERR, "it with `// " . TenantPredicateGuard::IGNORE_TAG . " <reason>` directly above it.\n\n");

    foreach ($violations as $v) {
        $relative = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $v['file']);
        $relative = str_replace('\\', '/', $relative);
        fwrite(STDERR, sprintf(
            "  %s:%d  [%s]\n    %s\n",
            $relative,
            $v['line'],
            implode(', ', $v['tables']),
            $v['sql']
        ));

        // The author already decided this access was legitimate and wrote the
        // reason down — the tag is just too far up to apply. Saying "unscoped
        // query" and stopping sends them to re-argue a settled question; the
        // fix is to move two lines, and it should say so.
        if (isset($v['strandedAnnotation'])) {
            fwrite(STDERR, sprintf(
                "    NOTE: there IS an ignore annotation at line %d, but only the line carrying\n"
                . "    the tag counts and the scanner looks at most %d lines above the statement,\n"
                . "    so it does not apply here. Move the `%s` line to the END of the\n"
                . "    comment block, directly above the statement, keeping the reason.\n",
                $v['strandedAnnotation'],
                TenantPredicateGuard::ANNOTATION_LOOKBACK,
                TenantPredicateGuard::IGNORE_TAG
            ));
        }

        fwrite(STDERR, "\n");
    }

    if ($excludedMigrationHits > 0) {
        fwrite(STDERR, sprintf(
            "(%d further unscoped quer%s inside migration directories were excluded by\n"
            . "policy and are NOT counted above — see the note in %s.)\n",
            $excludedMigrationHits,
            $excludedMigrationHits === 1 ? 'y' : 'ies',
            basename(__FILE__)
        ));
    }

    exit(1);
}

echo "OK: no unscoped tenant-table queries found in: " . implode(', ', $roots) . ".\n";
if ($excludedMigrationHits > 0) {
    echo sprintf(
        "     (%d unscoped quer%s inside migration directories were excluded by policy —\n"
        . "      migrations run once, CLI-side, across all tenants at once; see the note in\n"
        . "      %s.)\n",
        $excludedMigrationHits,
        $excludedMigrationHits === 1 ? 'y' : 'ies',
        basename(__FILE__)
    );
}
exit(0);
