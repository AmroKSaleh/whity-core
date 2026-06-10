<?php

declare(strict_types=1);

/**
 * CI smoke: prove the plugin system discovers and loads its drop-in plugins
 * without a fatal error (e.g. the bundled HelloWorld example). Runs the real
 * PluginLoader against the /plugins directory — no HTTP/RBAC, no database — so a
 * regression in plugin discovery/loading fails CI directly.
 *
 * This is the pre-SDK plugin-load gate (WC-163). Once the versioned SDK lands
 * (WC-162) it should additionally assert load through the SDK contract + version
 * gate (WC-165).
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/helpers.php';

use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

// Plugins may subscribe hooks during load; register the manager the host provides.
\Whity\register_service(HookManager::class, new HookManager());

$loader = new PluginLoader(dirname(__DIR__) . '/plugins', new Router());
$loader->load();

$count = count($loader->getPlugins());
if ($count < 1) {
    fwrite(STDERR, "FAIL: PluginLoader discovered/loaded no plugins.\n");
    exit(1);
}

echo "OK: PluginLoader loaded {$count} plugin(s).\n";
exit(0);
