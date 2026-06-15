<?php

declare(strict_types=1);

/**
 * CI plugin tenant-isolation conformance (WC-194): run the SDK conformance kit
 * — the migration linter + the tenant-predicate scanner — over the in-tree
 * HelloWorld reference plugin, exactly the way an OUT-OF-REPO plugin (which
 * depends only on whity/plugin-sdk) would run it in its own CI.
 *
 * This is the standalone counterpart to the PHPUnit fixture
 * (Tests\Plugins\HelloWorldTenantConformanceTest): it proves the kit is usable
 * with nothing but the SDK + a TenantTableRegistry, no PHPUnit required, and
 * fails the build if a plugin tenant table lacks a `tenant_id` column or a
 * handler runs an unscoped SELECT/UPDATE/DELETE on a tenant-owned table.
 *
 * Mirrors scripts/ci-tenant-predicate-guard.php / ci-plugin-smoke.php:
 * standalone, no HTTP/DB, exits non-zero on any violation.
 *
 * Usage:  php scripts/ci-plugin-tenant-conformance.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\Tenant\MigrationTenantColumnLinter;
use Whity\Sdk\Tenant\TenantPredicateScanner;
use Whity\Sdk\Tenant\TenantTableRegistry;

$pluginDir = dirname(__DIR__) . '/plugins/HelloWorld';

// A plugin declares ITS OWN tenant tables and merges in the host registry so an
// unscoped query against a CORE tenant table is flagged too. An out-of-repo
// plugin that does not ship with core would build the host portion from a small
// published table list instead of CoreTenantTableRegistry.
$registry = TenantTableRegistry::for([
    'hello_greetings' => 'HelloWorld greetings; carries tenant_id (CreateHelloGreetingsTable).',
])->merge(CoreTenantTableRegistry::build());

$failures = [];

// 1. Migration linter: every CREATE TABLE declares tenant_id (or is declared exempt).
$lintViolations = (new MigrationTenantColumnLinter($registry))->lintDirectory($pluginDir . '/Migrations');
foreach ($lintViolations as $v) {
    $failures[] = sprintf('  [migration] %s: %s', $v['table'], $v['reason']);
}

// 2. Handler-scoping scanner: every tenant-table query binds a tenant_id predicate.
$scanViolations = (new TenantPredicateScanner($registry))->scanDirectory($pluginDir . '/Api');
foreach ($scanViolations as $v) {
    $relative = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $v['file']);
    $relative = str_replace('\\', '/', $relative);
    $failures[] = sprintf('  [handler] %s:%d [%s]  %s', $relative, $v['line'], implode(', ', $v['tables']), $v['sql']);
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: HelloWorld did not pass the tenant-isolation conformance kit.' . "\n\n");
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "OK: HelloWorld passes the SDK tenant-isolation conformance kit (migration linter + handler scanner).\n";
exit(0);
