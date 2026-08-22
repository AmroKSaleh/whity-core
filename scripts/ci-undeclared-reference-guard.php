<?php

declare(strict_types=1);

/**
 * CI undeclared-reference guard: fail the build on a relationship CORE CANNOT
 * SEE.
 *
 * With no foreign keys between plugin tables — the convention here, and the
 * reason `blocks_delete` / `cascade_delete` are declared as data at all —
 * nothing at the database level stops a delete from orphaning a record's
 * children. An adopter's schema had zero foreign keys and deleting a parent
 * silently left its children pointing at an id that no longer resolved. The
 * delete answered 200.
 *
 * This guard flags a `*_id` column that is NEITHER enforced by a FOREIGN KEY
 * NOR declared in the owning data type's reference graph. It deliberately does
 * NOT flag "an `*_id` column with no foreign key" — that would fire on the
 * intended design of nearly every plugin table and be muted within a day, which
 * is how a linter stops being read. See
 * {@see Whity\Sdk\Schema\UndeclaredReferenceLinter} for the full rule.
 *
 * CORE IS IN SCOPE TOO (#751)
 * ---------------------------
 * This guard used to lint plugins ONLY, treating core tables purely as
 * resolvable TARGETS — so core's own migrations were never the subject of the
 * scan. #751 found two core columns naming a profile with no foreign key and no
 * later migration adding one (`notifications.recipient_profile_id`,
 * `user_notification_preferences.profile_id`), which is exactly the orphaning
 * bug described above, in the codebase that ships the linter written to catch
 * it. They were found by grep. Core's migration directory is therefore linted
 * alongside every plugin's, so the next one fails a build instead of waiting to
 * be noticed.
 *
 * Mirrors scripts/ci-tenant-predicate-guard.php and
 * scripts/ci-plugin-tenant-conformance.php: standalone, no HTTP, no DB, exits
 * non-zero on any violation.
 *
 * Usage:  php scripts/ci-undeclared-reference-guard.php [migrationDir ...]
 *         (defaults to database/migrations plus every in-tree plugin under
 *          plugins/)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Whity\Core\Tenant\CoreTables;
use Whity\Sdk\DataType\PluginDataTypesInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Schema\ReferenceDeclarations;
use Whity\Sdk\Schema\UndeclaredReferenceLinter;
use Whity\Sdk\Tenant\PluginTablesInterface;

$root = dirname(__DIR__);
$pluginRoot = $root . '/plugins';
$coreMigrations = $root . '/database/migrations';

$targets = array_slice($argv, 1);
if ($targets === []) {
    $plugins = array_filter(
        glob($pluginRoot . '/*') ?: [],
        static fn (string $path): bool => is_dir($path) && is_dir($path . '/Migrations')
    );
    sort($plugins);

    // Core first: it owns the tables everything else points at, so its own
    // violations are the ones worth reading before a plugin's.
    $targets = is_dir($coreMigrations) ? array_merge([$coreMigrations], $plugins) : $plugins;
}

if ($targets === []) {
    fwrite(STDERR, "FAIL: no migration directories were found.\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// What the platform can resolve, and what it has been told.
//
// Both are gathered across EVERY loaded plugin before any one of them is
// linted: one plugin's guard can legitimately cover another's column, and a
// column pointing at another plugin's table is still a real reference.
// ---------------------------------------------------------------------------

$plugins = discoverPlugins($pluginRoot);

$knownTables = CoreTables::all();
$declarations = new ReferenceDeclarations();

foreach ($plugins as $name => $plugin) {
    if ($plugin instanceof PluginTablesInterface) {
        foreach (array_keys($plugin->getOwnedTables()) as $table) {
            $knownTables[] = (string) $table;
        }
    }
    if ($plugin instanceof PluginDataTypesInterface) {
        $declarations = $declarations->merge(
            ReferenceDeclarations::fromDataTypes($plugin->getDataTypes(), $name)
        );
    }
}

$linter = new UndeclaredReferenceLinter($knownTables, $declarations);

$violations = [];
foreach ($targets as $dir) {
    $migrations = is_dir($dir . '/Migrations') ? $dir . '/Migrations' : $dir;
    if (!is_dir($migrations)) {
        fwrite(STDERR, "FAIL: not a directory: {$migrations}\n");
        exit(2);
    }
    foreach ($linter->lintDirectory($migrations) as $violation) {
        $violations[] = $violation;
    }
}

if ($violations !== []) {
    fwrite(STDERR, 'FAIL: ' . count($violations) . " undeclared reference(s) found.\n\n");
    fwrite(STDERR, "A column that points at a real table but carries NEITHER a foreign key NOR a\n");
    fwrite(STDERR, "declaration is a relationship core cannot see: deleting the parent will not be\n");
    fwrite(STDERR, "refused and the children will not be cleaned up, so they are left pointing at an\n");
    fwrite(STDERR, "id that no longer resolves.\n\n");
    fwrite(STDERR, "Fix it in whichever way is TRUE:\n");
    fwrite(STDERR, "  - `blocks_delete` on the owning data type, if these rows must OUTLIVE the parent;\n");
    fwrite(STDERR, "  - `cascade_delete`, if they are PART OF it and should go with it;\n");
    fwrite(STDERR, "  - a FOREIGN KEY, if the engine should enforce it;\n");
    fwrite(STDERR, '  - `-- ' . UndeclaredReferenceLinter::IGNORE_TAG . ": <reason>` on the column, if it\n");
    fwrite(STDERR, "    genuinely is not a reference. The reason is required.\n\n");
    fwrite(STDERR, "This guard does NOT ask you to add foreign keys between plugin tables. A schema\n");
    fwrite(STDERR, "with none at all passes, provided its relationships are declared.\n\n");

    foreach ($violations as $v) {
        $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $v['file']);
        $relative = str_replace('\\', '/', $relative);
        fwrite(STDERR, sprintf(
            "  %s\n    %s.%s -> %s\n    %s\n\n",
            $relative,
            $v['table'],
            $v['column'],
            $v['target'],
            $v['reason']
        ));
    }

    exit(1);
}

printf(
    "OK: every reference in %d migration set(s) (core + in-tree plugins) is either enforced by a "
    . "foreign key or declared to core.\n",
    count($targets)
);
exit(0);

/**
 * Instantiate every in-tree plugin, by name.
 *
 * Uses the host's own loader conventions (directory name => namespace prefix)
 * rather than Composer, because plugin classes are not in the PSR-4 map.
 *
 * @return array<string, PluginInterface>
 */
function discoverPlugins(string $pluginRoot): array
{
    $plugins = [];

    foreach (glob($pluginRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $name = basename($dir);
        $file = $dir . '/' . $name . 'Plugin.php';
        if (!is_file($file)) {
            continue;
        }

        require_once $file;

        $fqcn = $name . '\\' . $name . 'Plugin';
        if (!class_exists($fqcn) || !is_subclass_of($fqcn, PluginInterface::class)) {
            continue;
        }

        /** @var PluginInterface $instance */
        $instance = new $fqcn();
        $plugins[$name] = $instance;
    }

    return $plugins;
}
