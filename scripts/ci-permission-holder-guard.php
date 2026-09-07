<?php

declare(strict_types=1);

/**
 * CI permission-holder guard (#990).
 *
 * THE INVARIANT
 * -------------
 * Every permission slug that a ROUTE GATES ON must be held by at least one
 * role.
 *
 * A slug that fails that is not a permission check. It is a lockout: the gate
 * refuses every caller there is, including the administrator the gate was
 * written for, and it does so on the day the re-gate ships rather than on the
 * day somebody notices.
 *
 * WHY THIS IS NOT "NO ORPHAN SLUGS"
 * ---------------------------------
 * The narrowing is the whole design. A catalogue entry that nothing consults
 * yet is FINE. There was one in the tree on purpose while this guard was
 * written: `tenants:read` existed, was held by nobody, and was the slug
 * `GET /api/tenants` was to be re-gated onto — the docblock of
 * `115_remove_legacy_crud_permission_slugs.php` says so in as many words, and
 * explains why it was deliberately left in place while its eight neighbours were
 * deleted. #990 made that re-gate and shipped migration
 * 138 with it, so the slug is now gated AND held and this guard's proper
 * business. A guard that had fired on it beforehand would have been wrong, and
 * the only available fix would have been an allowlist entry saying "wrong on
 * purpose", which is how a guard stops being read.
 *
 * So the subject is CONSULTED, not EXISTING. A slug becomes this guard's
 * business the moment a route gates on it, and not before.
 *
 * WHAT IT ALREADY COST
 * --------------------
 * `roles:read` sat in the catalogue from migration 013 held by nobody for
 * ninety-eight migrations, and nothing reported it — because nothing consulted
 * it: every roles route was gated on the literal role name `admin`. #977 moved
 * those routes onto slugs, and `GET /api/roles` — the most-used of the nine —
 * would have gone dark for every administrator on upgrade. It did not, because
 * the author measured the catalogue by hand first and shipped migration 111 in
 * the same change. That measurement is the thing this file automates: it should
 * not depend on somebody remembering to run a query.
 *
 * AND WHAT IT IS QUEUED TO COST
 * -----------------------------
 * #990 is the same shape, fourteen times over: fourteen routes gated on the
 * `admin` ROLE NAME and to be re-gated onto slugs. This guard measured them
 * while it was being written, and `tenants:read` — the slug `GET /api/tenants`
 * was headed for — had ZERO holders. Landing that re-gate without a grant
 * migration beside it would have been a lockout, and one CI would otherwise have
 * called green: no test asks who holds a permission.
 *
 * Five of the fourteen have since landed (the four tenants routes and
 * `GET /api/permissions`), with migration 138 granting `tenants:read` to every
 * role that may already write or delete a tenant. NINE REMAIN — email-domains,
 * deployments, migrations and admin/stats — and those are the harder half: no
 * slug exists for any of them, so re-gating means inventing the vocabulary and
 * deciding its audience, one group at a time. Each such slug arrives held by
 * nobody by construction, which is exactly the case this guard is for.
 *
 * HOW IT LEARNS WHICH SLUGS ARE GATED
 * -----------------------------------
 * Two sources, because core and plugins declare routes in two different ways:
 *
 *   CORE — {@see whity_core_route_table()}, which reads public/index.php with
 *   PHP's tokenizer. That extractor was factored out of
 *   scripts/ci-plugin-frontend-features.php (#969/#980) rather than copied, and
 *   it carries the floor check that guard established: a core route table that
 *   comes back implausibly small is an error, because an empty one makes every
 *   caller pass while measuring nothing. It carries a SECOND floor for this
 *   guard's sake — on the number of distinct slugs found — because the route
 *   floor cannot see an argument reader that broke while the call matcher kept
 *   working.
 *
 *   PLUGINS — the real {@see PluginLoader}, loading the real plugins/ directory
 *   onto a router that already carries every core route, in production's order.
 *   Not a scrape: a plugin route that COLLIDES with a core route is refused and
 *   never registers, so it gates nothing on this host and must not be counted.
 *   Only the loader knows which ones those are, and this is the same host
 *   construction ci-plugin-frontend-features.php uses for the same reason.
 *
 * HOW IT LEARNS WHO HOLDS WHAT
 * ----------------------------
 * From a migrated PostgreSQL database, with the query the #990 survey used:
 *
 *     SELECT p.name, COUNT(rp.role_id) AS holders
 *       FROM permissions p
 *       LEFT JOIN role_permissions rp ON rp.permission_id = p.id
 *      GROUP BY p.id, p.name
 *
 * `role_permissions` is the only origin of a grant, which is what makes that one
 * query sufficient:
 *
 *   - ROLE HIERARCHY can only ADD holders. {@see RoleChecker} walks parent_id
 *     and unions the parents' direct grants, so a slug with no row at all is
 *     held by no role and by no descendant of one.
 *   - OU and RESOURCE assignments assign ROLES, not permissions.
 *   - A DELEGATION cannot originate a grant. {@see DelegationService} enforces a
 *     hard subset invariant against the grantor's own effective permissions, so
 *     a permission nobody holds is a permission nobody can delegate.
 *
 * WHY IT LIVES IN THE MIGRATIONS + SEED JOB
 * -----------------------------------------
 * Because it needs a real database, and that job is the one that has one. It
 * runs after `migrate run` and `seed`, which is the state a fresh install is in
 * — and a fresh install is the deployment that gets locked out, since it has
 * whatever the migrations granted and nothing an operator added by hand.
 *
 * A guard that reads a database has one failure mode worth more care than the
 * rest: answering from the WRONG database. Reading from one that has not been
 * migrated to this tree reports every slug as unheld; reading from one migrated
 * to an older point reports a slug as unheld whose grant is sitting in this
 * branch's own new migration. Both are decidable, so this guard checks before it
 * measures — every migration file in database/migrations must appear in
 * `core_schema_migrations` — and refuses rather than reporting. This is the
 * shape of #941, where a dead DSN fell back to SQLite and reported green.
 *
 * PLUGINS ARE IN SCOPE. IN-TREE ONES.
 * -----------------------------------
 * An unheld gate on a plugin route is the plugin's bug and its users' lockout,
 * and the plugins in this repository are the ones a plugin author copies — an
 * unheld gate in HelloWorld teaches the mistake rather than catching it. Their
 * migrations run in the same `migrate run` as core's, so their grants are
 * measurable in the same database, at no extra cost.
 *
 * Out-of-tree plugins are out of reach by construction: this guard runs in
 * core's CI over core's checkout. The conformance kit a plugin runs in its own
 * CI (scripts/ci-plugin-tenant-conformance.php) is where that check would go,
 * and it would need the plugin's own migrated database to be worth anything.
 *
 * ROLE OCCUPANCY IS NOT CHECKED HERE
 * ----------------------------------
 * "Granted to a role nobody occupies" is a lockout with extra steps, and it is
 * deliberately a different question:
 *
 *   - A GRANT is repository state. A migration in this tree creates it, a PR can
 *     break it, and CI is exactly where that belongs.
 *   - OCCUPANCY is deployment state. A role with no members is an ordinary,
 *     legitimate moment in a customer's install — a role created for a team not
 *     yet staffed — and nothing in a PR causes or fixes it. In CI it is
 *     manufactured: `seed` creates the memberships, so measuring occupancy here
 *     would only ever confirm that the seeder ran.
 *
 * It is also not one query. Roles nest through parent_id, and memberships,
 * ou_role_assignments and resource_role_assignments all place an occupant, so
 * "unoccupied" has several ways to be wrong — and a guard that cries wolf is a
 * guard that gets deleted. If occupancy is worth enforcing it belongs in an
 * operator-facing health check against a LIVE database, where the answer is
 * about that deployment and someone can act on it.
 *
 * NAV ITEMS ARE NOT CHECKED HERE either, for the narrower reason that they are
 * not the enforcement point. public/index.php gives several navigation entries a
 * `requiredPermission` so a permission-aware client can hide them; an unheld one
 * there hides a link, while the route behind it decides what actually happens.
 * Extending to nav is cheap if a case for it appears — every entry uses a slug
 * some route already gates on today, so it would find nothing now.
 *
 * Usage:  php scripts/ci-permission-holder-guard.php
 *         php scripts/ci-permission-holder-guard.php --dsn=pgsql:host=...;dbname=... \
 *                                                    --user=... --password=...
 *
 * With no options the app's own DB_* environment is used (a .env file is read
 * for anything not already set, as bin/whity-cli and bin/desktop-app-release
 * do). The explicit options are for pointing it at a disposable database
 * locally.
 */

$projectRoot = dirname(__DIR__);

require $projectRoot . '/vendor/autoload.php';
require $projectRoot . '/src/helpers.php';
require $projectRoot . '/scripts/lib/core-route-table.php';

use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Router;
use Whity\Database\Database;

/**
 * Slugs a route gates on that NOTHING is expected to hold.
 *
 * An entry is a reviewed decision with a reason, in the shape
 * ci-plugin-frontend-features.php's SERVER_DORMANT_FEATURES established: it says
 * "this gate refusing everybody IS the intended behaviour", and it names the
 * plugin entitled to say so. It is checked in both directions below, so it can
 * neither outlive its reason nor quietly widen.
 *
 * There is exactly one, and it is not an exemption from the invariant so much as
 * a demonstration of it.
 *
 * `owner` is the plugin whose routes may gate on the slug. A CORE route gating
 * on a listed slug is a hard failure regardless of the entry: core's gates are
 * the ones that lock administrators out of the platform, and no plugin's demo
 * may cover for one.
 *
 * @var array<string, array{owner: string, why: string}>
 */
const UNHELD_BY_DESIGN = [
    'uikit:manage' => [
        'owner' => 'UiKitShowcase',
        'why' => 'The UI-kit showcase declares this slug and deliberately never grants it (#909), '
            . 'so PUT /api/uikit/demo/rows/{name} refuses for everybody on a stock install — '
            . 'including the platform admin. That refusal IS the exhibit: the Record tab\'s '
            . 'accessGate asks the host whether the PUT would be admitted, the host answers no '
            . 'from this very route table, and the page renders a REAL read-only branch instead '
            . 'of a screenshot of one. Grant it to a role and the same block tree renders the '
            . 'editor, with nothing in the declaration changing. Granting it to make this guard '
            . 'pass would delete the only live demonstration of a refused write in the showcase.',
    ],
];

/**
 * Gates that refuse everybody — the invariant itself.
 *
 * @var list<string> $lockouts
 */
$lockouts = [];

/**
 * Entries in UNHELD_BY_DESIGN that no longer describe the tree.
 *
 * Kept apart from the lockouts because they are a different failure with a
 * different fix — one is "grant it or stop gating on it", the other is "delete a
 * line from this file" — and a message that ran the two together would offer the
 * wrong remediation for whichever one fired.
 *
 * @var list<string> $staleEntries
 */
$staleEntries = [];

function fail(string $summary, string $detail, string $remediation): never
{
    fwrite(STDERR, 'FAIL: ' . $summary . "\n\n");
    if ($detail !== '') {
        fwrite(STDERR, $detail . "\n\n");
    }
    fwrite(STDERR, $remediation . "\n");
    exit(1);
}

/**
 * Minimal `.env` loader — the same one bin/whity-cli, bin/desktop-plugin-release
 * and bin/desktop-app-release each carry, and the same acknowledged duplication:
 * there is still no shared helper to extract into, and this script needs it for
 * the same reason they do. CI writes a .env for the CLI bootstrap because the
 * runner's php.ini does not put job environment variables into $_ENV, so reading
 * that file is how this guard sees the database the migrations just ran against.
 * An already-set variable always wins.
 */
function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key === '' || isset($_ENV[$key])) {
            continue;
        }

        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

/**
 * @param  list<string> $argv
 * @return array<string, string>
 */
function parse_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match) === 1) {
            $options[$match[1]] = $match[2];
        }
    }

    return $options;
}

$options = parse_options($argv);
load_env_file($projectRoot . '/.env');

// ---------------------------------------------------------------------------
// 1. Which slugs are GATED — core's own route table.
// ---------------------------------------------------------------------------

try {
    $coreRoutes = whity_core_route_table($projectRoot . '/public/index.php');
} catch (RuntimeException $e) {
    fail(
        "core's route table could not be read, so there is nothing to check.",
        $e->getMessage(),
        "This guard's whole subject is which permissions core's routes gate on. It refuses to\n"
        . "report on a route table it cannot see, because a guard that measures an empty table\n"
        . 'passes every time.'
    );
}

/**
 * slug => list of "METHOD /path (source)" that gate on it.
 *
 * @var array<string, list<string>> $gatedBy
 */
$gatedBy = [];
/** @var array<string, array<string, true>> slug => set of owners ('core', or a plugin's namespace) */
$gateOwners = [];

$roleGatedRoutes = [];

foreach ($coreRoutes as $route) {
    if ($route['requiredRole'] !== null && $route['requiredPermission'] === null) {
        // Not this guard's business, and the reason it exists: these are #990's
        // fourteen. Counted so the OK line can say how many re-gates are still
        // to come.
        $roleGatedRoutes[] = sprintf(
            '%s %s (role %s, public/index.php:%d)',
            $route['method'],
            $route['path'],
            $route['requiredRole'],
            $route['line']
        );
    }

    if ($route['requiredPermission'] === null) {
        continue;
    }

    $slug = $route['requiredPermission'];
    $gatedBy[$slug][] = sprintf(
        '%s %s  (core, public/index.php:%d)',
        $route['method'],
        $route['path'],
        $route['line']
    );
    $gateOwners[$slug]['core'] = true;
}

if (count($gatedBy) < CORE_GATED_PERMISSION_FLOOR) {
    fail(
        sprintf(
            'only %d distinct permission slug(s) were found gating core routes, which is below the '
            . 'floor of %d.',
            count($gatedBy),
            CORE_GATED_PERMISSION_FLOOR
        ),
        sprintf('%d core route(s) were read, so the calls were found and the ARGUMENTS were not.', count($coreRoutes)),
        "The route-count floor cannot catch this: an argument reader that returns null for every\n"
        . "requiredPermission clears it comfortably and leaves this guard checking an empty set of\n"
        . "gates. Fix whity_route_table_scalar()/whity_route_table_arguments() in\n"
        . 'scripts/lib/core-route-table.php rather than lowering the floor.'
    );
}

// ---------------------------------------------------------------------------
// 2. Which slugs are GATED — the plugin routes that actually register.
// ---------------------------------------------------------------------------
// Production's construction: core's routes first, in index.php's order, on the
// same '/v1' prefix, then the real loader over the real plugins/ directory. A
// plugin route that collides with a core route is refused by Router::doRegister
// and never registers, so it gates nothing here — which is correct, and is why
// this half reads the LOADER rather than the plugins' sources.

\Whity\register_service(HookManager::class, new HookManager());

$router = new Router('/v1');
$noop = static fn (): null => null;

foreach ($coreRoutes as $route) {
    if ($route['call'] === 'registerUnversioned') {
        $router->registerUnversioned($route['method'], $route['path'], $noop);
        continue;
    }
    $router->register($route['method'], $route['path'], $noop);
}

$coreRouteCount = count($router->getRoutes());

$loader = new PluginLoader($projectRoot . '/plugins', $router);
$loader->load();

$pluginRoutes = array_slice($router->getRoutes(), $coreRouteCount);

foreach ($pluginRoutes as $route) {
    $slug = $route['requiredPermission'] ?? null;
    if (!is_string($slug) || $slug === '') {
        continue;
    }

    $owner = is_string($route['namespacePrefix'] ?? null) && $route['namespacePrefix'] !== ''
        ? $route['namespacePrefix']
        : '(unnamed plugin)';

    $gatedBy[$slug][] = sprintf('%s %s  (plugin %s)', $route['method'], $route['path'], $owner);
    $gateOwners[$slug][$owner] = true;
}

ksort($gatedBy);

// ---------------------------------------------------------------------------
// 3. Who holds what — from a database migrated to THIS tree.
// ---------------------------------------------------------------------------

try {
    $db = isset($options['dsn'])
        ? Database::fromDsn($options['dsn'], $options['user'] ?? '', $options['password'] ?? '')
        : Database::connect();
    $db->getPdo();
} catch (Throwable $e) {
    fail(
        'no database could be reached, so who holds a permission is unknown.',
        get_class($e) . ': ' . $e->getMessage(),
        "This guard answers a question only a migrated database can answer, and it fails rather\n"
        . "than skipping: a permission-holder check that quietly does not run is indistinguishable\n"
        . "from one that passed, which is the whole failure this file exists to remove.\n\n"
        . "In CI it belongs in the migrations + seed job, after `migrate run` and `seed`, where the\n"
        . "PostgreSQL service and the .env are already in place.\n\n"
        . "Locally, point it at a disposable database:\n\n"
        . '    php scripts/ci-permission-holder-guard.php --dsn=pgsql:host=127.0.0.1;port=5432;'
        . "dbname=whity_core --user=... --password=...\n"
    );
}

// The database must be migrated to this tree, or the measurement describes a
// different tree. A branch that ADDS the grant migration for a slug it also
// starts gating on is the case that matters: run against a database migrated to
// develop, that grant has not happened, and this guard would report a lockout
// the branch already fixed.
$migrationFiles = glob($projectRoot . '/database/migrations/*.php') ?: [];
$expected = [];
foreach ($migrationFiles as $file) {
    $expected[basename($file, '.php')] = true;
}

if ($expected === []) {
    fail(
        'no core migration files were found in database/migrations.',
        '',
        "The applied-migrations check below compares the database against this directory. An empty\n"
        . 'directory would make that check vacuous, so it is an error here.'
    );
}

try {
    $appliedRows = $db->query('SELECT migration_name FROM core_schema_migrations')->fetchAll();
} catch (Throwable $e) {
    fail(
        'the database has no core_schema_migrations table, so it has never been migrated.',
        get_class($e) . ': ' . $e->getMessage(),
        "Run the migrations first. In CI this guard goes after the `migrate run` and `seed` steps;\n"
        . 'an unmigrated database reports every slug as unheld, which is a page of false alarms.'
    );
}

$applied = [];
foreach (is_array($appliedRows) ? $appliedRows : [] as $row) {
    if (isset($row['migration_name']) && is_string($row['migration_name'])) {
        $applied[$row['migration_name']] = true;
    }
}

$missing = array_keys(array_diff_key($expected, $applied));
sort($missing);

if ($missing !== []) {
    fail(
        sprintf(
            '%d migration(s) in this tree have not been applied to the database being measured.',
            count($missing)
        ),
        '  ' . implode("\n  ", $missing),
        "Who holds a permission is a fact about a database at a point in its migration history, so\n"
        . "measuring one that is behind this tree answers a question nobody asked. It matters most\n"
        . "for the exact change this guard is meant to make safe: a branch that starts gating a\n"
        . "route on a slug AND adds the migration granting it is correct, and would be reported as\n"
        . "a lockout if its own migration had not run.\n\n"
        . 'Run `php public/index.php migrate run` (and `seed`) before this guard.'
    );
}

/** @var array<string, int> slug => number of roles holding it */
$holders = [];
$rows = $db->query(
    'SELECT p.name, COUNT(rp.role_id) AS holders
       FROM permissions p
       LEFT JOIN role_permissions rp ON rp.permission_id = p.id
      GROUP BY p.id, p.name
      ORDER BY p.name'
)->fetchAll();

foreach (is_array($rows) ? $rows : [] as $row) {
    if (isset($row['name']) && is_string($row['name'])) {
        $holders[$row['name']] = (int) ($row['holders'] ?? 0);
    }
}

// ---------------------------------------------------------------------------
// 4. The invariant.
// ---------------------------------------------------------------------------

foreach ($gatedBy as $slug => $routes) {
    $count = $holders[$slug] ?? null;
    if ($count !== null && $count > 0) {
        continue;
    }

    $exception = UNHELD_BY_DESIGN[$slug] ?? null;
    $owners = array_keys($gateOwners[$slug] ?? []);

    // The entry covers the slug only while its owner is the ONLY thing gating on
    // it. Any other gate — a core route above all — is outside what the entry
    // reasoned about, so it is reported.
    if ($exception !== null && $owners === [$exception['owner']]) {
        continue;
    }

    $why = $count === null
        ? 'the slug is not in the permissions catalogue at all — nothing inserted it, so nothing '
            . 'can hold it'
        : 'the slug is in the catalogue and NO role holds it';

    $detail = sprintf("  %s — %s.\n    gated by:\n      %s", $slug, $why, implode("\n      ", $routes));

    if ($exception !== null) {
        // A listed slug that a route OTHER than its owner's now gates on. The
        // entry does not cover that, and if the widening is core's it is the
        // most serious case this guard has.
        $detail .= sprintf(
            "\n    NOTE: '%s' is listed in UNHELD_BY_DESIGN as %s's, but the gates above are not "
            . "only %s's.\n          A demo that refuses on purpose cannot cover a gate somewhere "
            . 'else that refuses by accident.',
            $slug,
            $exception['owner'],
            $exception['owner']
        );
    }

    $lockouts[] = $detail;
}

// ---------------------------------------------------------------------------
// 5. The exception list cannot outlive its reason.
// ---------------------------------------------------------------------------

foreach (UNHELD_BY_DESIGN as $slug => $entry) {
    if (!isset($gatedBy[$slug])) {
        $staleEntries[] = sprintf(
            "  %s is listed in UNHELD_BY_DESIGN but NO route gates on it any more.\n"
            . "    Delete the entry. An unconsulted slug is not this guard's business at all, and a\n"
            . "    stale entry is a standing permission to leave a REAL gate unheld under that name\n"
            . '    the next time something starts consulting it.',
            $slug
        );
        continue;
    }

    if (($holders[$slug] ?? 0) > 0) {
        $staleEntries[] = sprintf(
            "  %s is listed in UNHELD_BY_DESIGN but %d role(s) now hold it.\n"
            . "    Delete the entry so the slug is guarded like any other — and check what the grant\n"
            . "    did to whatever the entry says depends on it being refused:\n\n    %s",
            $slug,
            $holders[$slug],
            wordwrap($entry['why'], 84, "\n    ")
        );
    }
}

if ($lockouts !== [] || $staleEntries !== []) {
    $summary = match (true) {
        $lockouts !== [] && $staleEntries !== [] => sprintf(
            '%d route-gating permission slug(s) are held by no role, and %d UNHELD_BY_DESIGN '
            . 'entr(ies) no longer describe this tree.',
            count($lockouts),
            count($staleEntries)
        ),
        $lockouts !== [] => sprintf(
            '%d route-gating permission slug(s) are held by no role. Each one is a lockout, not a '
            . 'permission check.',
            count($lockouts)
        ),
        default => sprintf(
            '%d UNHELD_BY_DESIGN entr(ies) no longer describe this tree. Every gated slug IS held.',
            count($staleEntries)
        ),
    };

    // The grant-or-do-not-gate advice belongs only to an actual lockout. A stale
    // list entry is fixed by deleting a line from this file, and printing four
    // paragraphs about migrations beside it would send the reader to the wrong
    // place — which is how remediation text stops being read.
    $remediation = $lockouts !== []
        ? "A gate on a slug nobody holds refuses EVERY caller, including the administrator it was\n"
            . "written for, and it does so the moment it ships. That is what happened to `roles:read`\n"
            . "(#977): ninety-eight migrations in the catalogue held by nobody, invisible the whole\n"
            . "time because nothing consulted it, and a lockout of every roles screen the day\n"
            . "something did.\n\n"
            . "Two ways out, and only one of them is usually right:\n\n"
            . "  1. GRANT IT, in a migration that ships WITH the gate rather than after it.\n"
            . "     database/migrations/111_grant_roles_read_to_role_writers.php is the worked\n"
            . "     example, including the part worth copying: it grants to every role that already\n"
            . "     holds a related capability rather than to a role NAMED `admin`, so a deployment\n"
            . "     running a custom administrative role does not silently lose access on upgrade.\n\n"
            . "     If the slug is a plugin's, its grant belongs in the plugin's own migration — see\n"
            . "     plugins/HelloWorld/Migrations/GrantGreetingsPermissionsToAdmin.php. A plugin\n"
            . "     migration that has not been applied to the database being measured looks exactly\n"
            . "     like a missing grant from here.\n\n"
            . "  2. DO NOT GATE ON IT YET. A slug can sit in the catalogue unheld indefinitely and\n"
            . "     harm nothing — `tenants:read` did exactly that until #990 gated it and shipped\n"
            . "     the grant in the same change. The harm starts when a route consults it.\n\n"
            . "Adding it to UNHELD_BY_DESIGN is a third way and almost never the answer: it says the\n"
            . 'gate refusing everybody is the intended product behaviour.'
        : "UNHELD_BY_DESIGN is a list of reviewed decisions, not a mute button, so it is checked in\n"
            . "both directions: an entry whose slug became held, or whose slug nothing gates on any\n"
            . 'more, is an entry that has outlived its reason and must go.';

    fail($summary, implode("\n\n", array_merge($lockouts, $staleEntries)), $remediation);
}

$totalGates = array_sum(array_map('count', $gatedBy));

printf(
    "OK: %d distinct permission slug(s) gate %d route(s) (%d core route(s) read from "
    . "public/index.php, %d plugin route(s) registered by the real loader); every one of them is "
    . "held by at least one role.\n",
    count($gatedBy),
    $totalGates,
    count($coreRoutes),
    count($pluginRoutes)
);
printf(
    "    %d slug(s) unheld by design and listed: %s\n",
    count(UNHELD_BY_DESIGN),
    implode(', ', array_keys(UNHELD_BY_DESIGN))
);
printf(
    "    %d catalogue slug(s) held by nobody and gated by nothing, which is allowed: %s\n",
    count(array_diff(array_keys(array_filter($holders, static fn (int $n): bool => $n === 0)), array_keys($gatedBy))),
    implode(
        ', ',
        array_diff(array_keys(array_filter($holders, static fn (int $n): bool => $n === 0)), array_keys($gatedBy))
    ) ?: '(none)'
);
printf(
    "    %d route(s) still gate on a ROLE NAME rather than a slug (#990). Each re-gate needs the "
    . "slug it moves to held before it lands; this guard is what makes that check automatic:\n      %s\n",
    count($roleGatedRoutes),
    implode("\n      ", $roleGatedRoutes)
);
printf("    %d core permission(s) declared in CorePermissions.\n", count(CorePermissions::all()));

exit(0);
