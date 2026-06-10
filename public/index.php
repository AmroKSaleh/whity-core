<?php

/**
 * Whity Core FrankenPHP Entry Point
 *
 * Bootstrap entry point for FrankenPHP persistent workers.
 * Initializes all components and handles incoming HTTP requests in a persistent loop.
 * Also supports console commands when invoked via CLI.
 */

declare(strict_types=1);

// Check if running from CLI
$isCli = php_sapi_name() === 'cli';

if ($isCli && isset($argv[1])) {
    // Handle console commands - load autoloader first
    $command = $argv[1];

    // Load environment variables from .env file (skip if already set)
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                // Skip comments and empty lines
                if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
                    continue;
                }

                // Parse KEY=VALUE format
                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Only set if not already in environment
                    if (!getenv($key) && !isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }
    }

    // Require composer autoloader
    require dirname(__DIR__) . '/vendor/autoload.php';

    if ($command === 'generate:openapi') {
        $className = 'Whity\Console\GenerateOpenApiSchemaCommand';
        exit($className::execute($argv));
    }

    if ($command === 'migrate') {
        $migrationsCommand = new \Whity\Cli\Commands\MigrationsCommand();
        // Remove script name and command name, pass remaining arguments
        array_shift($argv); // Remove script name
        array_shift($argv); // Remove 'migrate' command
        exit($migrationsCommand->execute($argv));
    }

    if ($command === 'seed') {
        $seedCommand = new \Whity\Cli\Commands\SeedCommand();
        // Remove script name and command name, pass remaining arguments
        array_shift($argv); // Remove script name
        array_shift($argv); // Remove 'seed' command
        exit($seedCommand->execute($argv));
    }

    if ($command === 'revoked-tokens:cleanup') {
        $db = \Whity\Database\Database::connect();
        $cleanupCommand = new \Whity\Commands\RevokedTokensCleanupCommand($db->getPdo());
        $cleanupCommand->execute();
        exit(0);
    }

    echo "Unknown command: {$command}\n";
    echo "Available commands:\n";
    echo "  generate:openapi           Generate OpenAPI 3.0 schema\n";
    echo "  migrate                    Manage database migrations\n";
    echo "  seed                       Seed database with default data\n";
    echo "  revoked-tokens:cleanup     Cleanup expired revoked tokens\n";
    exit(1);
}

use Whity\Database\Database;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\PluginLoader;
use Whity\Auth\JwtParser;
use Whity\Auth\JwtSecretGuard;
use Whity\Auth\RoleChecker;
use Whity\Auth\AuthHandler;
use Whity\Http\RbacMiddleware;
use Whity\Http\HttpKernel;
use Whity\Http\Cors;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Api\UsersApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\TenantsApiHandler;
use Whity\Api\PermissionsApiHandler;
use Whity\Api\DeploymentApiHandler;
use Whity\Api\PluginsApiHandler;
use Whity\Api\MigrationsApiHandler;
use Whity\Api\AdminApiHandler;
use Whity\Api\OusApiHandler;
use Whity\Api\DelegationsApiHandler;
use Whity\Api\NavigationApiHandler;
use Whity\Api\HealthApiHandler;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Relations\RelationResolver;
use Whity\Api\PersonsApiHandler;
use Whity\Api\RelationsApiHandler;
use Whity\Api\TwoFactorHandler;
use Whity\Api\AuditLogApiHandler;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Deployment\DeploymentManager;
use Whity\Core\Log\ErrorLogLogger;
use Whity\Core\Tenant\TenantContext;
use Whity\Auth\TotpService;
use Whity\Auth\BackupCodesService;
use Whity\Auth\TokenValidator;

// Load environment variables from .env file (skip if already set)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Only set if not already in environment
                if (!getenv($key) && !isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                    putenv("{$key}={$value}");
                }
            }
        }
    }
}

// Require composer autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// Require helpers
require dirname(__DIR__) . '/src/helpers.php';

// 0. Capture the worker boot timestamp (drives the health endpoint's uptime).
//    A FrankenPHP worker survives across many requests, so this is the start of
//    the worker process, not of any single request.
$bootTimestamp = time();

// 0b. Build the application PSR-3 logger (WC-18/WC-20 observability). A minimal
//     error_log-backed logger is used so structured audit/observability records
//     (tenant isolation bypass, plugin error boundaries) reach the container's
//     stderr without adding a logging dependency. Wired into TenantContext below
//     and the tenant isolation middleware.
$logger = new ErrorLogLogger();
TenantContext::setLogger($logger);

// 1. Initialize database connection
$db = Database::connect();

// 2. Initialize router
$router = new Router();

// 3. Initialize JWT parser
$appEnv = $_ENV['APP_ENV'] ?? 'production';
// Outside development the JWT secret must be present AND >= 32 chars; a missing or
// short secret is brute-forceable, so the app refuses to start (WC-53).
JwtSecretGuard::assertValid(
    isset($_ENV['JWT_SECRET']) ? (string)$_ENV['JWT_SECRET'] : null,
    $appEnv
);
$jwtSecret = $_ENV['JWT_SECRET'] ?? 'dev_secret_key_change_in_production';
$jwtParser = new JwtParser($jwtSecret);

// 3b. Resolve the TOTP secret-encryption key (WC-95).
// Single source of truth shared by the setup/confirm path and the login-validation path so the
// 2FA secret is always encrypted and decrypted with the SAME key. Fails fast in non-development
// when ENCRYPTION_KEY is missing/empty, mirroring the JWT_SECRET guard above.
$totpService = new TotpService(TotpService::resolveEncryptionKey());

// 4. Initialize permission registry
$permissionRegistry = new PermissionRegistry();
// Eagerly register the canonical core permission set (WC-13/PR #86). A lazy
// fallback exists in the registry, but registering up front is cleaner and makes
// the core catalogue available before the first request.
$permissionRegistry->registerCorePermissions();

// 4b. Initialize hook manager and register in service container
$hookManager = new HookManager();
\Whity\register_service(HookManager::class, $hookManager); // @phpstan-ignore-line

// 4c. Initialize the security audit-trail writer (WC-34) and subscribe it to the
// core CRUD lifecycle hooks. This is the SINGLE writer for the audit_log table:
// role/user/tenant/OU mutations are captured by subscribing to the hooks the
// handlers already fire (no per-handler audit code), while the auth/2FA paths —
// which do not fire hooks — receive the same logger and call record() directly.
// It is process-scoped infrastructure; per-request actor/IP live in AuditContext.
$auditLogger = new AuditLogger($db->getPdo(), $logger);
$auditLogger->subscribe($hookManager);

// Register core navigation items
$hookManager->listen('navigation.register', function ($data, $context) {
    $items = $data['items'] ?? [];
    $items[] = [
        'id' => 'dashboard',
        'label' => 'Dashboard',
        'href' => '/admin',
        'icon' => 'dashboard',
        'group' => 'admin',
        'order' => 1,
    ];
    $items[] = [
        'id' => 'users',
        'label' => 'Users',
        'href' => '/admin/users',
        'icon' => 'users',
        'group' => 'admin',
        'order' => 2,
    ];
    $items[] = [
        'id' => 'roles',
        'label' => 'Roles',
        'href' => '/admin/roles',
        'icon' => 'lock',
        'group' => 'admin',
        'order' => 3,
    ];
    $items[] = [
        'id' => 'ous',
        'label' => 'Organizational Units',
        'href' => '/admin/ous',
        'icon' => 'building-community',
        'group' => 'admin',
        'order' => 4,
    ];
    $items[] = [
        'id' => 'delegations',
        'label' => 'Delegations',
        'href' => '/admin/delegations',
        'icon' => 'share',
        'group' => 'admin',
        'order' => 6,
        // WC-34: the delegations admin area is gated on the delegation:manage
        // permission. The nav item carries the requirement so a
        // permission-aware client/consumer can hide it; the page also enforces
        // it server-side via the RBAC-protected API (403 → access-denied state).
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::DELEGATION_MANAGE,
    ];
    $items[] = [
        'id' => 'relations',
        'label' => 'Family Relations',
        'href' => '/admin/relations',
        'icon' => 'users-group',
        'group' => 'admin',
        'order' => 7,
        // WC-65: the relations admin area is gated on relations:read. The nav
        // item carries the requirement so a permission-aware client can hide it;
        // the page also enforces it server-side via the RBAC-protected API (a 403
        // renders the access-denied state), matching the delegations pattern.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::RELATIONS_READ,
    ];
    $items[] = [
        'id' => 'tenants',
        'label' => 'Tenants',
        'href' => '/admin/tenants',
        'icon' => 'building',
        'group' => 'admin',
        'order' => 5,
    ];
    $items[] = [
        'id' => 'audit-logs',
        'label' => 'Audit Logs',
        'href' => '/admin/audit-logs',
        'icon' => 'history',
        'group' => 'admin',
        'order' => 6,
    ];
    $items[] = [
        'id' => 'settings',
        'label' => 'Settings',
        'href' => '/settings',
        'icon' => 'settings',
        'order' => 100,
    ];
    return ['items' => $items];
});

// 5. Initialize role checker(s) and the delegation service (WC-34).
//    The delegation service needs a RoleChecker to bound a grantor's delegable
//    set to their BASE RBAC effective permissions (direct role + hierarchy + OU),
//    so it is given a checker WITHOUT the delegation resolver — this both breaks
//    the construction cycle and prevents transitive re-delegation escalation
//    (you can only delegate what RBAC grants you, never what was delegated TO you).
//    The RoleChecker used by the middleware IS delegation-aware, so a live,
//    non-revoked delegation actually grants access through hasPermission().
$baseRoleChecker = new RoleChecker($db, $permissionRegistry);
$delegationRepository = new DelegationRepository($db->getPdo());
$delegationService = new DelegationService($delegationRepository, $baseRoleChecker, $permissionRegistry);

$roleChecker = new RoleChecker($db, $permissionRegistry, null, $delegationService);

// 6. Initialize RBAC middleware
$rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);

// 7. Initialize tenant isolation middleware
// Pass the PSR-3 logger (WC-20) so privileged cross-tenant bypasses are audited.
$tenantIsolationMiddleware = new EnforceTenantIsolation($jwtParser, $logger);

// 8. Initialize HTTP kernel and register middleware
$kernel = new HttpKernel($router, $rbacMiddleware);
// Register middleware in order (tenant isolation BEFORE RBAC)
$kernel->use($tenantIsolationMiddleware);

// 9. Initialize plugin loader and load plugins
// Wire the permission registry, hook manager, and logger (WC-9/WC-13) so plugin
// permissions/hooks register through core services and plugin error boundaries
// log structured records via the application logger.
$pluginLoader = new PluginLoader(
    __DIR__ . '/../plugins',
    $router,
    $permissionRegistry,
    $hookManager,
    $logger
);
$pluginLoader->load();

// 9b. Initialize deployment manager
$deploymentManager = new DeploymentManager($db->getPdo(), __DIR__ . '/../storage/deployments');

// 10. Register authentication handler
// Inject the shared $totpService (built at step 3b) so the login-path 2FA validation uses the
// SAME encryption key as the setup/confirm path (WC-95).
$authHandler = new AuthHandler($db->getPdo(), $jwtParser, null, null, $totpService, $logger, $auditLogger);
$router->register('POST', '/api/login', [$authHandler, 'handle'], null);
$router->register('POST', '/api/login/2fa', [$authHandler, 'handle2fa'], null);
$router->register('GET', '/api/me', [$authHandler, 'handleMe'], null);
$router->register('PATCH', '/api/me', [$authHandler, 'handleUpdateMe'], null);
$router->register('POST', '/api/auth/refresh', [$authHandler, 'handleRefresh'], null);
$router->register('POST', '/api/auth/logout', [$authHandler, 'handleLogout'], null);

// 10b. Register 2FA handler
// Reuses the single $totpService built at step 3b (WC-95) so setup/confirm and login share one key.
$dbWrapper = new \Whity\Auth\DatabaseQueryWrapper($db->getPdo());
$backupCodesService = new BackupCodesService($dbWrapper);
$tokenValidator = new TokenValidator($jwtParser, $db->getPdo());
$twoFactorHandler = new TwoFactorHandler($db->getPdo(), $totpService, $backupCodesService, $tokenValidator, $auditLogger);
$router->register('POST', '/api/auth/2fa/setup', [$twoFactorHandler, 'setup'], null);
$router->register('POST', '/api/auth/2fa/confirm', [$twoFactorHandler, 'confirm'], null);
$router->register('POST', '/api/auth/2fa/disable', [$twoFactorHandler, 'disable'], null);
$router->register('POST', '/api/auth/2fa/regenerate-codes', [$twoFactorHandler, 'regenerateCodes'], null);
$router->register('GET', '/api/auth/2fa/status', [$twoFactorHandler, 'status'], null);

// 11. Register API handlers
$usersHandler = new UsersApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/users', [$usersHandler, 'list'], 'admin');
$router->register('POST', '/api/users', [$usersHandler, 'create'], 'admin');
$router->register('PATCH', '/api/users/{id}', [$usersHandler, 'update'], 'admin');
$router->register('DELETE', '/api/users/{id}', [$usersHandler, 'delete'], 'admin');

$rolesHandler = new RolesApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/roles', [$rolesHandler, 'list'], 'admin');
$router->register('POST', '/api/roles', [$rolesHandler, 'create'], 'admin');
$router->register('GET', '/api/roles/{id}', [$rolesHandler, 'get'], 'admin');
$router->register('PATCH', '/api/roles/{id}', [$rolesHandler, 'update'], 'admin');
$router->register('DELETE', '/api/roles/{id}', [$rolesHandler, 'delete'], 'admin');
$router->register('GET', '/api/roles/{id}/permissions', [$rolesHandler, 'getPermissions'], 'admin');

$tenantsHandler = new TenantsApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/tenants', [$tenantsHandler, 'list'], 'admin');
$router->register('POST', '/api/tenants', [$tenantsHandler, 'create'], 'admin');
$router->register('PATCH', '/api/tenants/{id}', [$tenantsHandler, 'update'], 'admin');
$router->register('DELETE', '/api/tenants/{id}', [$tenantsHandler, 'delete'], 'admin');

$permissionsHandler = new PermissionsApiHandler($db->getPdo());
$router->register('GET', '/api/permissions', [$permissionsHandler, 'list'], 'admin');

$navigationHandler = new NavigationApiHandler($hookManager);
$router->register('GET', '/api/navigation', [$navigationHandler, 'list']);

// Health monitoring endpoint (WC-4). Registered with NO required role and NO
// required permission so it bypasses RBAC (fail-open), and it is listed as a
// public route in EnforceTenantIsolation so it bypasses tenant resolution too —
// the probe must answer without a JWT or tenant context. The handler is kept
// dependency-light (only the DB wrapper) so health stays meaningful when other
// subsystems are down. $bootTimestamp drives the reported worker uptime.
$healthHandler = new HealthApiHandler($db, $bootTimestamp);
$router->register('GET', '/api/health', [$healthHandler, 'handle']);

$deploymentHandler = new DeploymentApiHandler($deploymentManager);
$router->register('POST', '/api/deployments/apply', [$deploymentHandler, 'apply'], 'admin');
$router->register('POST', '/api/deployments/rollback', [$deploymentHandler, 'rollback'], 'admin');
$router->register('GET', '/api/deployments/status', [$deploymentHandler, 'status'], 'admin');

// Plugins admin API (WC-9/PR #88, WC-10/PR #104). Pass the live $pluginLoader so
// list/enable/disable use the WC-9 lifecycle at runtime, and gate every route on
// the plugins:manage permission (6th positional arg to Router::register; 4th arg
// requiredRole stays null so RbacMiddleware enforces the permission).
$pluginsHandler = new PluginsApiHandler(__DIR__ . '/../plugins', $pluginLoader);
$router->register('GET', '/api/plugins', [$pluginsHandler, 'list'], null, null, CorePermissions::PLUGINS_MANAGE);
$router->register('POST', '/api/plugins/{name}/enable', [$pluginsHandler, 'enable'], null, null, CorePermissions::PLUGINS_MANAGE);
$router->register('POST', '/api/plugins/{name}/disable', [$pluginsHandler, 'disable'], null, null, CorePermissions::PLUGINS_MANAGE);
$router->register('POST', '/api/plugins/{id}/re-enable', [$pluginsHandler, 'reEnable'], null, null, CorePermissions::PLUGINS_MANAGE);
$router->register('POST', '/api/plugins/reload', [$pluginsHandler, 'reload'], null, null, CorePermissions::PLUGINS_MANAGE);

$migrationsHandler = new MigrationsApiHandler($db, __DIR__ . '/../database/migrations');
// Only allow read-only access to migration status via API
// Mutations (run/rollback) are performed via CLI only for security
$router->register('GET', '/api/migrations', [$migrationsHandler, 'list'], 'admin');

$adminHandler = new AdminApiHandler($db, __DIR__ . '/../database/migrations');
$router->register('GET', '/api/admin/stats', [$adminHandler, 'stats'], 'admin');

// 12. Register OUs API handler
$ousHandler = new OusApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/ous', [$ousHandler, 'list'], 'admin');
$router->register('POST', '/api/ous', [$ousHandler, 'create'], 'admin');
$router->register('GET', '/api/ous/{id}', [$ousHandler, 'get'], 'admin');
$router->register('PATCH', '/api/ous/{id}', [$ousHandler, 'update'], 'admin');
$router->register('DELETE', '/api/ous/{id}', [$ousHandler, 'delete'], 'admin');
$router->register('GET', '/api/ous/{id}/roles', [$ousHandler, 'roles'], 'admin');
$router->register('GET', '/api/ous/{id}/members', [$ousHandler, 'members'], 'admin');
$router->register('POST', '/api/ous/{id}/roles', [$ousHandler, 'assignRole'], 'admin');
$router->register('DELETE', '/api/ous/{ouId}/roles/{roleId}', [$ousHandler, 'removeRole'], 'admin');

// 12b. Register permission delegations API handler (WC-34). Gated on the
// delegation:manage permission (6th positional arg; requiredRole stays null so
// RbacMiddleware enforces the permission). The runtime subset-of-own-permissions
// invariant is enforced independently inside DelegationService.
$delegationsHandler = new DelegationsApiHandler($db->getPdo(), $delegationService, $logger);
$router->register('GET', '/api/delegations', [$delegationsHandler, 'list'], null, null, CorePermissions::DELEGATION_MANAGE);
$router->register('POST', '/api/delegations', [$delegationsHandler, 'create'], null, null, CorePermissions::DELEGATION_MANAGE);
$router->register('DELETE', '/api/delegations/{id}', [$delegationsHandler, 'revoke'], null, null, CorePermissions::DELEGATION_MANAGE);

// 13. Register the audit-log read API (WC-34). Gated on the audit:read permission
// (6th positional arg; requiredRole stays null so RbacMiddleware enforces the
// permission). Tenant-scoped in the handler: the SYSTEM tenant (id 0) sees all
// tenants, every other tenant sees only its own entries.
$auditLogHandler = new AuditLogApiHandler($db->getPdo(), $roleChecker);
$router->register('GET', '/api/audit-logs', [$auditLogHandler, 'list'], null, null, CorePermissions::AUDIT_READ);

// 14. Register the family relations API (WC-65). Reads are gated on
// relations:read, writes on relations:manage (6th positional arg; requiredRole
// stays null so RbacMiddleware enforces the permission). All routes are
// tenant-scoped in the handlers: the SYSTEM tenant (id 0) sees all tenants,
// every other tenant sees only its own. Storage is uniform person→person; the
// resolver is the only unit that knows about user-vs-person refs and
// auto-provisions a user's shadow person on demand.
$personRepository = new PersonRepository($db->getPdo());
$relationRepository = new RelationRepository($db->getPdo());
$relationResolver = new RelationResolver($db->getPdo(), $personRepository, $relationRepository);
$personsHandler = new PersonsApiHandler($personRepository, $relationRepository);
$relationsHandler = new RelationsApiHandler($personRepository, $relationRepository, $relationResolver, $logger);

$router->register('GET', '/api/relationship-types', [$relationsHandler, 'listTypes'], null, null, CorePermissions::RELATIONS_READ);
$router->register('GET', '/api/persons', [$personsHandler, 'list'], null, null, CorePermissions::RELATIONS_READ);
$router->register('POST', '/api/persons', [$personsHandler, 'create'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('GET', '/api/persons/{id}', [$personsHandler, 'get'], null, null, CorePermissions::RELATIONS_READ);
$router->register('PATCH', '/api/persons/{id}', [$personsHandler, 'update'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('DELETE', '/api/persons/{id}', [$personsHandler, 'delete'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('GET', '/api/persons/{id}/relations', [$personsHandler, 'relations'], null, null, CorePermissions::RELATIONS_READ);
$router->register('GET', '/api/relations', [$relationsHandler, 'listEdges'], null, null, CorePermissions::RELATIONS_READ);
$router->register('GET', '/api/users/{id}/relations', [$relationsHandler, 'userRelations'], null, null, CorePermissions::RELATIONS_READ);
$router->register('POST', '/api/relations', [$relationsHandler, 'create'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('DELETE', '/api/relations/{id}', [$relationsHandler, 'delete'], null, null, CorePermissions::RELATIONS_MANAGE);

// Handle requests (persistent worker mode or fallback single-request mode)
$isWorker = function_exists('frankenphp_handle_request');

if ($isWorker) {
    // Dispatch boot hook
    error_log("[FrankenPHP Worker] Booting...");
    $hookManager->dispatch('worker.boot', []);

    // Get max requests from env
    $maxRequests = (int)($_ENV['MAX_REQUESTS'] ?? $_SERVER['MAX_REQUESTS'] ?? 0);

    for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
        $keepRunning = \frankenphp_handle_request(static function () use ($kernel, $hookManager, $pluginLoader, $db) {
            try {
                // Dispatch request start hook
                error_log("[FrankenPHP Worker] Request start");
                $hookManager->dispatch('worker.request.start', []);

                // Plugin hot-reload (WC-8/PR #83): pick up plugins added, modified,
                // or removed on disk since the last request without restarting the
                // worker. This is a cheap no-op when the plugin tree is unchanged,
                // and runs before the kernel handles the request so the new routes
                // are live for this iteration.
                $pluginLoader->reload();

                // Create request from PHP superglobals
                $request = Request::fromGlobals();

                // Resolve CORS headers once per request from the allowlist (WC-53).
                $corsHeaders = Cors::headers($request->getHeader('Origin'));

                // Handle OPTIONS preflight requests for CORS
                if ($request->getMethod() === 'OPTIONS') {
                    $response = new Response(204, '', $corsHeaders);
                    $response->send();
                    return;
                }

                // Handle request through kernel
                $response = $kernel->handle($request);

                // Merge CORS headers into the response.
                $headers = array_merge($response->getHeaders(), $corsHeaders);

                // Create new response with CORS headers (correct parameter order: statusCode, body, headers)
                $response = new Response($response->getStatusCode(), $response->getBody(), $headers);

                // Send response to client
                $response->send();
            } catch (\Throwable $e) {
                // Handle any uncaught exceptions
                try {
                    $errorResponse = Response::error('Internal server error', 500);
                    $errorHeaders = array_merge(
                        $errorResponse->getHeaders(),
                        Cors::headers($_SERVER['HTTP_ORIGIN'] ?? null)
                    );
                    $errorResponse = new Response(500, $errorResponse->getBody(), $errorHeaders);
                    $errorResponse->send();
                } catch (\Throwable $sendError) {
                    // If send also fails, just log it
                    error_log('Failed to send error response: ' . $sendError->getMessage());
                }
                error_log($e->getMessage() . "\n" . $e->getTraceAsString());
            } finally {
                // Dispatch request end hook
                error_log("[FrankenPHP Worker] Request end");
                $hookManager->dispatch('worker.request.end', []);
                // Reset tenant context to prevent cross-request leakage
                \Whity\Core\Tenant\TenantContext::reset();
                // Reset the audit actor/IP context for the same reason (WC-34).
                AuditContext::reset();
                // DB session hygiene (WC-21/PR #84): after the response is sent,
                // roll back any dangling transaction and DISCARD ALL session-local
                // state on the shared worker connection so nothing request-specific
                // (temp tables, SET LOCAL, prepared plans) leaks into the next
                // request the worker serves. No-op when no connection is open.
                $db->resetSessionState();
            }
        });

        // Force garbage collection to prevent memory bloat
        gc_collect_cycles();

        if ($kernel->hasExceededMemoryLimit()) {
            error_log("[FrankenPHP Worker] Memory limit exceeded. Recycling worker gracefully.");
            // Release the worker's database connection eagerly on the memory-recycle
            // break path (WC-21/PR #84) so the dropped worker does not leave its
            // backend lingering until process teardown.
            $db->disconnect();
            break;
        }

        if (!$keepRunning) {
            // Worker shutdown (FrankenPHP asked us to stop). Release the database
            // connection eagerly, rolling back anything left open (WC-21/PR #84).
            $db->disconnect();
            break;
        }
    }
} else {
    // Fallback mode: Handle single request
    try {
        // Create request from PHP superglobals
        $request = Request::fromGlobals();

        // Resolve CORS headers once per request from the allowlist (WC-53).
        $corsHeaders = Cors::headers($request->getHeader('Origin'));

        // Handle OPTIONS preflight requests for CORS
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response(204, '', $corsHeaders);
            $response->send();
            exit;
        }

        // Handle request through kernel
        $response = $kernel->handle($request);

        // Merge CORS headers into the response.
        $headers = array_merge($response->getHeaders(), $corsHeaders);

        // Create new response with CORS headers (correct parameter order: statusCode, body, headers)
        $response = new Response($response->getStatusCode(), $response->getBody(), $headers);

        // Send response to client
        $response->send();
    } catch (\Throwable $e) {
        // Handle any uncaught exceptions
        try {
            $errorResponse = Response::error('Internal server error', 500);
            $errorHeaders = array_merge(
                $errorResponse->getHeaders(),
                Cors::headers($_SERVER['HTTP_ORIGIN'] ?? null)
            );
            $errorResponse = new Response(500, $errorResponse->getBody(), $errorHeaders);
            $errorResponse->send();
        } catch (\Throwable $sendError) {
            // If send also fails, just log it
            error_log('Failed to send error response: ' . $sendError->getMessage());
        }
        error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    } finally {
        \Whity\Core\Tenant\TenantContext::reset();
        AuditContext::reset();
    }
}

