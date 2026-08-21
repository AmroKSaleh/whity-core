<?php

declare(strict_types=1);

/**
 * CI database-boolean guard (#891): fail the build on a boolean read whose
 * answer depends on which spelling the driver happened to return.
 *
 * The same logical value comes back from a `BOOLEAN` column as a native
 * `bool`, as `'1'`/`'0'`, as `'t'`/`'f'`, or — from a `::text` projection — as
 * `'true'`/`'false'`. A bare `(bool)` cast reads that last pair as TRUE in both
 * directions, and `#891` found five such casts on `memberships.is_primary`
 * alone, one of them gating a 409 on "cannot remove the primary membership".
 *
 * Measured on the PHP this platform ships (8.4), pdo_pgsql returns only the
 * first two of those four spellings, so those casts are correct TODAY. The
 * guard exists because "correct by a driver default nobody chose" is a
 * property of the environment rather than of the code, and because the twelve
 * hand-rolled coercion helpers that had accumulated in `src/` disagreed with
 * each other on inputs that do reach them.
 *
 * Mirrors scripts/ci-tenant-predicate-guard.php and
 * scripts/ci-undeclared-reference-guard.php: standalone, no HTTP, no DB, exits
 * non-zero on any violation. See {@see Whity\Core\Db\DbBoolScanner} for the
 * full rule, including the two exemptions it derives rather than demands.
 *
 * Usage:  php scripts/ci-db-bool-guard.php [path ...]
 *         (defaults to scanning src/)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Db\DbBoolScanner;

$roots = array_slice($argv, 1);
if ($roots === []) {
    $roots = [dirname(__DIR__) . '/src'];
}

$scanner = DbBoolScanner::fromMigrations();

$violations = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        fwrite(STDERR, "FAIL: not a directory: {$root}\n");
        exit(2);
    }
    foreach ($scanner->scanDirectory($root) as $violation) {
        $violations[] = $violation;
    }
}

if ($violations !== []) {
    fwrite(STDERR, 'FAIL: ' . count($violations) . " driver-dependent boolean read(s) found.\n\n");
    fwrite(STDERR, "A `BOOLEAN` column does not have one spelling. Depending on the driver, the\n");
    fwrite(STDERR, "PHP version, and PDO::ATTR_STRINGIFY_FETCHES, the same row hands you bool(false),\n");
    fwrite(STDERR, "'0', 'f', or 'false' — and `(bool) 'false'` is TRUE, so the cast silently\n");
    fwrite(STDERR, "reports every row as true rather than failing.\n\n");
    fwrite(STDERR, "Fix it by reading the value through the one coercion that accepts all of them:\n\n");
    fwrite(STDERR, "    use Whity\\Core\\Db\\DbBool;\n\n");
    fwrite(STDERR, "    'isPrimary' => DbBool::of(\$row['is_primary']),\n\n");
    fwrite(STDERR, "If the value genuinely is not a database read — a request body, a plugin\n");
    fwrite(STDERR, "manifest, a value already normalised by its repository — annotate the line with\n");
    fwrite(STDERR, '  // ' . DbBoolScanner::IGNORE_TAG . " <reason>\n");
    fwrite(STDERR, "and say which. The reason is required; a bare tag is itself reported.\n\n");

    foreach ($violations as $v) {
        $relative = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $v['file']);
        $relative = str_replace('\\', '/', $relative);
        fwrite(STDERR, sprintf(
            "  %s:%d\n    %s\n    %s\n\n",
            $relative,
            $v['line'],
            $v['snippet'],
            $v['reason']
        ));
    }

    exit(1);
}

echo 'OK: no driver-dependent boolean reads found in: ' . implode(', ', $roots) . ".\n";
exit(0);
