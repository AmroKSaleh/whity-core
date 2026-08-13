<?php

declare(strict_types=1);

/**
 * Entrypoint for the offline PHP plugin host, run under FrankenPHP worker
 * mode. A miniature of production's public/index.php boot sequence: open the
 * local SQLite database, wire the host-service shims plugin code resolves
 * via \Whity\app(), load the fixed plugin set, run pending migrations once,
 * register routes, then loop on frankenphp_handle_request() — mirroring
 * TenantContext::reset() in a finally exactly like production does.
 */

// ---- Manual PSR-4 autoloading (no Composer needed for this proof of
// concept: whity/plugin-sdk has zero runtime dependencies, so a hand-rolled
// autoloader for `Whity\Sdk\` + this app's own `Whity\` namespace achieves
// exactly what vendor/autoload.php would, without requiring `composer
// install` to have been run). Production's own app uses Composer; this
// mirrors the same technique the plugin loader already needs anyway.
spl_autoload_register(static function (string $class): void {
    $map = [
        'Whity\\Sdk\\' => __DIR__ . '/../sdk/src/',
        'Whity\\' => __DIR__ . '/../src/',
        'Composer\\Semver\\' => __DIR__ . '/../vendor/composer/semver/src/',
    ];
    foreach ($map as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) === 0) {
            $relative = substr($class, $len);
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;

                return;
            }
        }
    }
});

require_once __DIR__ . '/../src/helpers.php';

use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\DeviceRoleChecker;
use Whity\Core\RBAC\RoleSeeder;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Database\SqliteCompatPdo;
use Whity\Http\RbacGate;
use Whity\Native\NativeBridgeClient;
use Whity\PluginHost\MigrationRunner;
use Whity\PluginHost\OfflineIdentity;
use Whity\PluginHost\PluginRuntimeLoader;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Rbac\PermissionResolver;

// ---- Boot (runs once per worker process start) ----------------------------

$sqlitePath = $_ENV['WHITY_SQLITE_PATH'] ?? (__DIR__ . '/../var/whity-offline.sqlite');
$pdo = new SqliteCompatPdo($sqlitePath);
\Whity\register_service(Database::class, new Database($pdo));
\Whity\register_service(NativeBridgeClient::class, NativeBridgeClient::fromEnv());

$offlineTenantId = (int) ($_ENV['WHITY_OFFLINE_TENANT_ID'] ?? 1);
$offlineProfileId = (int) ($_ENV['WHITY_OFFLINE_PROFILE_ID'] ?? 1);
// Default device role is 'admin', unconditionally granted every declared
// permission (see RoleSeeder::grantAllToAdminRole()) — a single desktop user
// has full authority over their own machine by default. Point this at a
// narrower seeded role (e.g. a plugin's own PluginRolesInterface role) to
// deliberately exercise the 403 path offline.
$deviceRole = (string) ($_ENV['WHITY_DEVICE_ROLE'] ?? 'admin');

$pluginsRoot = $_ENV['PLUGINS_ROOT'] ?? (__DIR__ . '/../plugins');
$loader = new PluginRuntimeLoader($pluginsRoot);

// WHITY_PLUGINS set (non-empty) pins an explicit FQCN list; unset means
// "discover whatever plugin directories are actually there" — the
// "arbitrary plugin" default (see PluginDiscovery).
$explicitPlugins = isset($_ENV['WHITY_PLUGINS']) ? trim((string) $_ENV['WHITY_PLUGINS']) : '';
if ($explicitPlugins !== '') {
    $pluginFqcns = array_values(array_filter(array_map('trim', explode(',', $explicitPlugins))));
    $loader->load($pluginFqcns);
} else {
    $loader->loadDiscovered();
}

$migrationRunner = new MigrationRunner();
$migrationRunner->bootstrapHostSkeleton($pdo);

// Quarantines any plugin whose getPermissions() throws or is malformed
// BEFORE its migrations/roles/hooks/routes are registered.
$permissionRegistry = $loader->buildPermissionRegistry();
$migrationRunner->persistPermissionCatalog($pdo, $permissionRegistry);
$migrationRunner->run($loader, $pdo);

RoleSeeder::seedPluginRoles($pdo, $permissionRegistry, $loader->getLoadedPlugins());
RoleSeeder::grantAllToAdminRole($pdo, $permissionRegistry);

// Empty version prefix: this host has no versioning story yet, so plugin
// routes stay at /api/demo-catalog/... rather than /api/v1/demo-catalog/...
$router = new Router('');
$loader->registerRoutes($router);

$hookManager = new HookManager();
\Whity\register_service(HookManager::class, $hookManager);
$loader->registerHooks($hookManager);

$deviceRoleChecker = new DeviceRoleChecker($pdo, $permissionRegistry, $deviceRole);
if (!$deviceRoleChecker->hasRole($offlineProfileId, $offlineTenantId, $deviceRole)) {
    error_log("[php-host] configured device role '{$deviceRole}' not found among seeded roles — every protected route will 403");
}
\Whity\register_service(PermissionResolver::class, $deviceRoleChecker);
$offlineIdentity = new OfflineIdentity($offlineProfileId, $offlineTenantId);
\Whity\register_service(OfflineIdentity::class, $offlineIdentity);
$rbacGate = new RbacGate($deviceRoleChecker, $offlineIdentity);

// ---- Request loop -----------------------------------------------------

$isWorker = function_exists('frankenphp_handle_request');
$maxRequests = (int) ($_ENV['MAX_REQUESTS'] ?? 0); // 0 = unbounded

$handle = static function () use ($router, $offlineTenantId, $loader, $rbacGate) {
    try {
        $request = Request::fromGlobals();

        // Host infrastructure endpoints (not plugin routes).
        if ($request->getMethod() === 'GET' && $request->getPath() === '/__whity/health') {
            Response::json(['ok' => true])->send();

            return;
        }

        if ($request->getMethod() === 'GET' && $request->getPath() === '/__whity/plugins') {
            Response::json([
                'loaded' => array_map(
                    static fn ($p) => ['fqcn' => $p->fqcn, 'name' => $p->plugin->getName(), 'version' => $p->plugin->getVersion()],
                    $loader->getLoadedPlugins()
                ),
                'quarantined' => $loader->getQuarantined(),
            ])->send();

            return;
        }

        TenantContext::setTenantId($offlineTenantId);

        $match = $router->match($request);
        if ($match === null) {
            $response = Response::error('Not found', 404);
        } else {
            $forbidden = $rbacGate->authorize($match['requiredRole'], $match['requiredPermission']);
            $response = $forbidden ?? ($match['handler'])($request, $match['params']);
        }
    } catch (\Throwable $e) {
        error_log('[php-host] ' . $e->getMessage());
        $response = Response::error('Internal server error', 500);
    } finally {
        TenantContext::reset();
    }

    $response->send();
};

if ($isWorker) {
    for ($n = 0; $maxRequests === 0 || $n < $maxRequests; $n++) {
        $keepRunning = frankenphp_handle_request($handle);
        if (!$keepRunning) {
            break;
        }
    }
} else {
    // Plain CGI/CLI-server fallback (e.g. `php -S` during manual testing).
    $handle();
}
