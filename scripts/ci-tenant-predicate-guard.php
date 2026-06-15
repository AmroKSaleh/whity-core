<?php

declare(strict_types=1);

/**
 * CI tenant-predicate guard (WC-192): enforce the platform's #1 isolation
 * invariant in CI. Scans src/ for SELECT/UPDATE/DELETE statements that touch a
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
 *         (defaults to scanning src/)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Tenant\TenantPredicateGuard;

$roots = array_slice($argv, 1);
if ($roots === []) {
    $roots = [dirname(__DIR__) . '/src'];
}

$guard = new TenantPredicateGuard();
$violations = [];
foreach ($roots as $root) {
    if (!is_dir($root)) {
        fwrite(STDERR, "FAIL: not a directory: {$root}\n");
        exit(2);
    }
    foreach ($guard->scanDirectory($root) as $violation) {
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
            "  %s:%d  [%s]\n    %s\n\n",
            $relative,
            $v['line'],
            implode(', ', $v['tables']),
            $v['sql']
        ));
    }

    exit(1);
}

echo "OK: no unscoped tenant-table queries found in: " . implode(', ', $roots) . ".\n";
exit(0);
