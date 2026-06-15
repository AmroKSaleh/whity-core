<?php

declare(strict_types=1);

/**
 * CI smoke: prove the plugin system discovers and loads its drop-in plugins
 * through the SDK contract (WC-162/WC-163). Runs the real PluginLoader against
 * the /plugins directory — no HTTP/RBAC, no database — and asserts that at
 * least one plugin implements Whity\Sdk\PluginInterface and reaches the ACTIVE
 * lifecycle state, so a regression in SDK-path discovery/loading fails CI
 * directly. The version gate (WC-165) will extend this further.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

// Plugins may subscribe hooks during load; register the manager the host provides.
\Whity\register_service(HookManager::class, new HookManager());

$loader = new PluginLoader(dirname(__DIR__) . '/plugins', new Router(''));
$loader->load();

$plugins = $loader->getPlugins();
if (count($plugins) < 1) {
    fwrite(STDERR, "FAIL: PluginLoader discovered/loaded no plugins.\n");
    exit(1);
}

// SDK path: every loaded plugin satisfies the standalone SDK contract.
foreach ($plugins as $plugin) {
    if (!$plugin instanceof \Whity\Sdk\PluginInterface) {
        fwrite(STDERR, 'FAIL: ' . get_class($plugin) . " does not implement Whity\\Sdk\\PluginInterface.\n");
        exit(1);
    }
}

// Lifecycle: at least one plugin must have reached the ACTIVE state.
$states = array_column($loader->getPluginStatuses(), 'state', 'name');
$active = array_keys(array_filter($states, static fn (string $state): bool => $state === 'active'));
if ($active === []) {
    fwrite(STDERR, "FAIL: no plugin reached the active lifecycle state.\n");
    exit(1);
}

echo 'OK: ' . count($plugins) . ' SDK plugin(s) loaded; active: ' . implode(', ', $active) . ".\n";
exit(0);
