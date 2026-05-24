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
use Whity\Auth\RoleChecker;
use Whity\Auth\AuthHandler;
use Whity\Http\RbacMiddleware;
use Whity\Http\HttpKernel;
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
use Whity\Api\NavigationApiHandler;
use Whity\Api\TwoFactorHandler;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Deployment\DeploymentManager;
use Whity\Auth\TotpService;
use Whity\Auth\BackupCodesService;
use Whity\Auth\TokenValidator;

// Load environment variables from .env file (skip if already set)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
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

// Require composer autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

// Require helpers
require dirname(__DIR__) . '/src/helpers.php';

// 1. Initialize database connection
$db = Database::connect();

// 2. Initialize router
$router = new Router();

// 3. Initialize JWT parser
$jwtParser = new JwtParser($_ENV['JWT_SECRET'] ?? 'dev_secret');

// 4. Initialize permission registry
$permissionRegistry = new PermissionRegistry();

// 4b. Initialize hook manager and register in service container
$hookManager = new HookManager();
\Whity\register_service(HookManager::class, $hookManager);

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
        'id' => 'tenants',
        'label' => 'Tenants',
        'href' => '/admin/tenants',
        'icon' => 'building',
        'group' => 'admin',
        'order' => 5,
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

// 5. Initialize role checker
$roleChecker = new RoleChecker($db, $permissionRegistry);

// 6. Initialize RBAC middleware
$rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);

// 7. Initialize tenant isolation middleware
$tenantIsolationMiddleware = new EnforceTenantIsolation($jwtParser);

// 8. Initialize HTTP kernel and register middleware
$kernel = new HttpKernel($router, $rbacMiddleware);
// Register middleware in order (tenant isolation BEFORE RBAC)
$kernel->use($tenantIsolationMiddleware);

// 9. Initialize plugin loader and load plugins
$pluginLoader = new PluginLoader(__DIR__ . '/../plugins', $router);
$pluginLoader->load();

// 9b. Initialize deployment manager
$deploymentManager = new DeploymentManager($db->getPdo(), __DIR__ . '/../storage/deployments');

// 10. Register authentication handler
$authHandler = new AuthHandler($db->getPdo(), $jwtParser);
$router->register('POST', '/api/login', [$authHandler, 'handle'], null);
$router->register('POST', '/api/login/2fa', [$authHandler, 'handle2fa'], null);
$router->register('GET', '/api/me', [$authHandler, 'handleMe'], null);
$router->register('POST', '/api/auth/refresh', [$authHandler, 'handleRefresh'], null);
$router->register('POST', '/api/auth/logout', [$authHandler, 'handleLogout'], null);

// 10b. Register 2FA handler
$totpService = new TotpService($_ENV['ENCRYPTION_KEY'] ?? 'dev_secret');
$dbWrapper = new \Whity\Auth\DatabaseQueryWrapper($db->getPdo());
$backupCodesService = new BackupCodesService($dbWrapper);
$tokenValidator = new TokenValidator($jwtParser, $db->getPdo());
$twoFactorHandler = new TwoFactorHandler($db->getPdo(), $totpService, $backupCodesService, $tokenValidator);
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

$deploymentHandler = new DeploymentApiHandler($deploymentManager);
$router->register('POST', '/api/deployments/apply', [$deploymentHandler, 'apply'], 'admin');
$router->register('POST', '/api/deployments/rollback', [$deploymentHandler, 'rollback'], 'admin');
$router->register('GET', '/api/deployments/status', [$deploymentHandler, 'status'], 'admin');

$pluginsHandler = new PluginsApiHandler(__DIR__ . '/../plugins');
$router->register('GET', '/api/plugins', [$pluginsHandler, 'list'], 'admin');
$router->register('POST', '/api/plugins/{id}/enable', [$pluginsHandler, 'enable'], 'admin');
$router->register('POST', '/api/plugins/{id}/disable', [$pluginsHandler, 'disable'], 'admin');
$router->register('POST', '/api/plugins/reload', [$pluginsHandler, 'reload'], 'admin');

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
$router->register('POST', '/api/ous/{id}/roles', [$ousHandler, 'assignRole'], 'admin');
$router->register('DELETE', '/api/ous/{ouId}/roles/{roleId}', [$ousHandler, 'removeRole'], 'admin');

// Handle single request
try {
    // Create request from PHP superglobals
    $request = Request::fromGlobals();

    // Handle OPTIONS preflight requests for CORS
    if ($request->getMethod() === 'OPTIONS') {
        $response = new Response(204, '', [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
        ]);
        $response->send();
        exit;
    }

    // Handle request through kernel
    $response = $kernel->handle($request);

    // Get current headers and add CORS
    $headers = $response->getHeaders();
    $headers['Access-Control-Allow-Origin'] = '*';
    $headers['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
    $headers['Access-Control-Allow-Headers'] = 'Content-Type, Authorization';

    // Create new response with CORS headers (correct parameter order: statusCode, body, headers)
    $response = new Response($response->getStatusCode(), $response->getBody(), $headers);

    // Send response to client
    $response->send();
} catch (\Throwable $e) {
    // Handle any uncaught exceptions
    try {
        $errorResponse = Response::error('Internal server error', 500);
        $errorHeaders = $errorResponse->getHeaders();
        $errorHeaders['Access-Control-Allow-Origin'] = '*';
        $errorHeaders['Access-Control-Allow-Methods'] = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
        $errorHeaders['Access-Control-Allow-Headers'] = 'Content-Type, Authorization';
        $errorResponse = new Response(500, $errorResponse->getBody(), $errorHeaders);
        $errorResponse->send();
    } catch (\Throwable $sendError) {
        // If send also fails, just log it
        error_log('Failed to send error response: ' . $sendError->getMessage());
    }
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
}
