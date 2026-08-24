<?php

declare(strict_types=1);

/**
 * CI gate (#969): every frontend feature an in-tree plugin DECLARES must
 * actually REGISTER with the host — or be listed below as deliberately dormant,
 * together with the verbatim refusal reason it is expected to produce.
 *
 * Why this exists when scripts/ci-plugin-smoke.php already runs: the smoke test
 * proves a plugin LOADS. Loading is not the same as your screens surviving
 * validation. PluginLoader::collectFrontendFeatures() validates each descriptor
 * separately and fail-closed — a refused descriptor is dropped, the plugin still
 * reaches the ACTIVE lifecycle state, and the smoke test still prints OK. That
 * is exactly how three in-tree plugins came to contribute no screens at all with
 * CI green (#969), and it stayed invisible until #968 made refusals reportable
 * in the plugin console.
 *
 * The per-plugin unit tests did not catch it either, and could not have: they
 * assert the DECLARATION (BlockValidator over the tree, data sources
 * cross-checked against the plugin's own route list). Nothing ran the host's own
 * verdict. This guard runs the real PluginLoader against the real plugins/
 * directory, on a router that already carries every core route public/index.php
 * registers — the production ordering, where core registers first and a
 * colliding plugin route is refused.
 *
 * It fails in FOUR directions, so neither the code nor the list can rot:
 *
 *   1. a declared feature that neither registered nor is listed as dormant —
 *      the regression this guard exists to catch;
 *   2. a LISTED feature that registered after all — the dormancy ended (e.g. a
 *      cutover removed core's routes) and the entry must be deleted;
 *   3. a listed feature whose refusal reason no longer matches what the entry
 *      says to expect — the mechanism changed under us, which is precisely how
 *      #969 came to be misdiagnosed (the reported cause was a route collision;
 *      the measured cause was the core-permission ownership rule, which fires
 *      first and fires whether or not any route collides);
 *   4. a declared feature that is neither registered NOR reported as dropped —
 *      a refusal that reaches nobody, the #951/#953 family this line of work
 *      exists to close.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/scripts/lib/core-route-table.php';

use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Sdk\PluginFrontendInterface;

/**
 * Frontend features EXPECTED not to register on this host, keyed
 * `PluginName/feature-id`.
 *
 * An entry is a reviewed decision, not a mute button: it says "this screen
 * cannot work on a server where core owns the surface, and that is the intended
 * design", and it must name the verbatim reason the host gives — so if the host
 * ever refuses it for a DIFFERENT reason, CI reports the new one instead of
 * silently accepting it.
 *
 * All four current entries are the same case (#969). Documents, Relations and
 * Taxonomy are the offline halves of an in-flight strangler-fig extraction
 * (ADR 0003 / the desktop feature-parity effort): each is the SOLE provider of
 * its resource on the Tauri desktop's offline PHP host, where no core equivalent
 * exists, and each is deliberately inert on the server until the cutover removes
 * core's version. All three say so in their own class docblock.
 *
 * Their screens are NOT left unverified by being listed here. The device host
 * validates a screen:'blocks' feature with BlockValidator plus a permission GATE
 * only — deliberately WITHOUT the cross-plugin ownership policing production
 * applies, because every plugin on a device was already vetted by the release
 * pipeline (see templates/tauri-desktop/php-host/src/Api/FrontendFeaturesHandler.php).
 * That narrower contract is what tests/Plugins/DocumentsPluginFrontendTest.php,
 * RelationsPluginFrontendTest.php and TaxonomyPluginFrontendTest.php assert.
 *
 * @var array<string, array{expect: string, why: string}>
 */
const SERVER_DORMANT_FEATURES = [
    'Documents/document-templates' => [
        'expect' => "requiredPermission 'documents:read' collides with a core permission",
        'why' => 'Offline twin of the core Document Designer (migration 059; epic #947 extends '
            . 'the core side further). Core owns documents:* and /api/document-templates on the '
            . 'server; the plugin is the sole provider on the offline desktop host.',
    ],
    'Documents/document-blocks' => [
        'expect' => "requiredPermission 'documents:read' collides with a core permission",
        'why' => 'The reusable-fragment half of the same port as Documents/document-templates.',
    ],
    'Relations/relations' => [
        'expect' => "requiredPermission 'relations:read' collides with a core permission",
        'why' => 'Offline twin of core Family Relations (#774/#775 generalise the core side). '
            . 'Core owns relations:* and /api/persons on the server; the plugin is the sole '
            . 'provider on the offline desktop host.',
    ],
    'Taxonomy/taxonomy' => [
        'expect' => "requiredPermission 'tags:read' collides with a core permission",
        'why' => 'Offline twin of core Taxonomy (#714 develops the core side). Core owns tags:* '
            . 'and /api/tags + /api/tag-groups on the server; the plugin is the sole provider on '
            . 'the offline desktop host.',
    ],
];

$projectRoot = dirname(__DIR__);

// Plugins may subscribe hooks during load; register the manager the host provides.
\Whity\register_service(HookManager::class, new HookManager());

// ---------------------------------------------------------------------------
// 1. A router carrying core's live route table, in production's order.
// ---------------------------------------------------------------------------
// Same '/v1' prefix public/index.php uses, so a plugin path collides here
// exactly as it does in production (Router::doRegister compares the PREFIXED
// method+path). The routes are scraped from index.php rather than hand-listed —
// a curated copy would only encode which core routes somebody remembered, which
// is the failure mode this guard exists to remove.
//
// The extraction itself now lives in scripts/lib/core-route-table.php, together
// with the floor check that used to be here: #990's permission-holder guard
// needs the same table plus each route's requiredPermission, and two scrapers of
// one file are two things to keep in step — with "silently stopped matching" as
// the shared failure mode. The floor moved WITH it and is enforced there, so a
// core route table that comes back implausibly small still fails loudly rather
// than letting this guard pass vacuously against an empty one.
//
// requiredPermission is deliberately NOT passed on these: a collision is decided
// by method+path alone, and a plugin route that IS accepted keeps its own
// declared permission — so core's permission values cannot change any verdict
// below.
$router = new Router('/v1');

try {
    $coreRoutes = whity_core_route_table($projectRoot . '/public/index.php');
} catch (RuntimeException $e) {
    fwrite(
        STDERR,
        "FAIL: core's route table could not be read, so plugin routes cannot be checked against it.\n\n"
        . $e->getMessage() . "\n\n"
        . "This guard needs core's real route table to reproduce production's collision ordering;\n"
        . "if core registration moved, point the extractor in scripts/lib/core-route-table.php at\n"
        . "its new home.\n"
    );
    exit(1);
}

$noop = static fn (): null => null;
foreach ($coreRoutes as $coreRoute) {
    if ($coreRoute['call'] === 'registerUnversioned') {
        $router->registerUnversioned($coreRoute['method'], $coreRoute['path'], $noop);
        continue;
    }
    $router->register($coreRoute['method'], $coreRoute['path'], $noop);
}

// ---------------------------------------------------------------------------
// 2. Load every in-tree plugin through the real loader.
// ---------------------------------------------------------------------------
$loader = new PluginLoader($projectRoot . '/plugins', $router);
$loader->load();

/** @var array<string, true> $registeredIds */
$registeredIds = [];
foreach ($loader->getFrontendFeatures() as $feature) {
    $pluginName = is_string($feature['plugin'] ?? null) ? $feature['plugin'] : '?';
    $featureId = is_string($feature['id'] ?? null) ? $feature['id'] : '?';
    $registeredIds[$pluginName . '/' . $featureId] = true;
}

/** @var array<string, string> $droppedReasons */
$droppedReasons = [];
foreach ($loader->getDroppedFrontendFeatures() as $entry) {
    $droppedReasons[$entry['plugin'] . '/' . ($entry['featureId'] ?? '(no id)')] = $entry['reason'];
}

// ---------------------------------------------------------------------------
// 3. Every DECLARED feature must register, or be a listed dormancy.
// ---------------------------------------------------------------------------
/** @var list<string> $failures */
$failures = [];
/** @var array<string, true> $declaredKeys */
$declaredKeys = [];
$declaredCount = 0;

foreach ($loader->getPlugins() as $plugin) {
    if (!$plugin instanceof PluginFrontendInterface) {
        continue;
    }

    $name = $plugin->getName();

    try {
        $declared = $plugin->getFrontendFeatures();
    } catch (Throwable $e) {
        $failures[] = sprintf(
            '  [%s] getFrontendFeatures() threw %s: %s — the plugin contributes no screens at all.',
            $name,
            get_class($e),
            $e->getMessage()
        );
        continue;
    }

    foreach ($declared as $index => $descriptor) {
        $declaredCount++;
        $id = is_array($descriptor) && is_string($descriptor['id'] ?? null) ? $descriptor['id'] : null;

        if ($id === null) {
            $failures[] = sprintf(
                '  [%s] declared feature #%s has no string id, so it can never register. '
                . 'Give it a kebab-case id.',
                $name,
                (string) $index
            );
            continue;
        }

        $key = $name . '/' . $id;
        $declaredKeys[$key] = true;

        if (isset($registeredIds[$key])) {
            continue;
        }

        $reason = $droppedReasons[$key] ?? null;
        $expectation = SERVER_DORMANT_FEATURES[$key] ?? null;

        if ($expectation === null) {
            $failures[] = sprintf(
                "  [%s] declared frontend feature '%s' did NOT register.\n"
                . "        host reason: %s\n"
                . '        Fix the declaration, or — if this screen genuinely cannot work on a '
                . 'server where core owns the surface — add it to SERVER_DORMANT_FEATURES with '
                . 'the reason why.',
                $name,
                $id,
                $reason ?? '(none reported)'
            );
            continue;
        }

        // (4) A refusal that reaches nobody is the bug one layer up (#951/#953).
        if ($reason === null) {
            $failures[] = sprintf(
                "  [%s] declared frontend feature '%s' neither registered NOR was reported as "
                . 'dropped. The host computed a refusal that reaches no operator — report it '
                . 'through the same drop path every other rule uses.',
                $name,
                $id
            );
            continue;
        }

        // (3) The documented mechanism must still be the actual mechanism.
        if (!str_contains($reason, $expectation['expect'])) {
            $failures[] = sprintf(
                "  [%s] frontend feature '%s' is listed as server-dormant, but the host now "
                . "refuses it for a DIFFERENT reason.\n"
                . "        expected to contain: %s\n"
                . "        actual reason:       %s\n"
                . '        Re-establish WHY it is refused before updating the entry: the entry '
                . 'records a measured chain, not an assumption.',
                $name,
                $id,
                $expectation['expect'],
                $reason
            );
        }
    }
}

// ---------------------------------------------------------------------------
// 4. No stale or phantom dormancy entries — the list cannot outlive its reason.
// ---------------------------------------------------------------------------
foreach (array_keys(SERVER_DORMANT_FEATURES) as $key) {
    if (isset($registeredIds[$key])) {
        $failures[] = sprintf(
            "  [%s] is listed in SERVER_DORMANT_FEATURES but now REGISTERS successfully.\n"
            . '        The dormancy ended — delete the entry so the feature is guarded like any other.',
            $key
        );
        continue;
    }

    if (!isset($declaredKeys[$key])) {
        $failures[] = sprintf(
            "  [%s] is listed in SERVER_DORMANT_FEATURES but no in-tree plugin declares it.\n"
            . '        Remove the stale entry.',
            $key
        );
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: an in-tree plugin's declared frontend features do not match what registered.\n\n");
    fwrite(STDERR, implode("\n", $failures) . "\n\n");
    fwrite(
        STDERR,
        "A plugin can load successfully and still contribute no screens: descriptor validation is\n"
        . "per-descriptor and fail-closed, so ci-plugin-smoke.php stays green either way (#969).\n"
    );
    exit(1);
}

printf(
    "OK: %d declared frontend feature(s) across in-tree plugins; %d registered, %d dormant by design.\n",
    $declaredCount,
    count($registeredIds),
    count(SERVER_DORMANT_FEATURES)
);
exit(0);
