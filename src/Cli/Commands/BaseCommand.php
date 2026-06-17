<?php

namespace Whity\Cli\Commands;

use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Sdk\Http\Response;
use Whity\Core\PluginLoader;
use Whity\Http\HttpKernel;
use Whity\Http\RbacMiddleware;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Hooks\HookManager;
use Whity\Database\Database;
use Whity\Api\UsersApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\TenantsApiHandler;
use Whity\Api\PermissionsApiHandler;
use Whity\Api\PluginsApiHandler;
use Whity\Api\MigrationsApiHandler;
use Whity\Api\OusApiHandler;
use Whity\Core\Deployment\DeploymentManager;

/**
 * Base Command class for CLI commands
 */
abstract class BaseCommand
{
    /**
     * @var HttpKernel
     */
    protected HttpKernel $kernel;

    /**
     * @var string|null Authentication token
     */
    protected ?string $token = null;

    /**
     * Execute the command
     *
     * @param array $argv Command arguments
     * @return int Exit code
     */
    abstract public function execute(array $argv): int;

    /**
     * Setup the application kernel for simulated API calls
     */
    protected function setupKernel(): void
    {
        $db = Database::connect();
        $router = new Router('');

        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        if ($appEnv !== 'development' && empty($_ENV['JWT_SECRET'])) {
            throw new \RuntimeException('JWT_SECRET environment variable must be set in production environments');
        }
        $jwtSecret = $_ENV['JWT_SECRET'] ?? 'dev_secret_key_change_in_production';
        $jwtParser = new JwtParser($jwtSecret);
        $permissionRegistry = new PermissionRegistry();

        $hookManager = new HookManager();
        \Whity\register_service(HookManager::class, $hookManager);

        $roleChecker = new RoleChecker($db, $permissionRegistry);
        $rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);
        $tenantIsolationMiddleware = new EnforceTenantIsolation($jwtParser);

        $this->kernel = new HttpKernel($router, $rbacMiddleware);
        $this->kernel->use($tenantIsolationMiddleware);

        $baseDir = dirname(__DIR__, 3);
        $pluginLoader = new PluginLoader($baseDir . '/plugins', $router);
        $pluginLoader->load();

        // Register API handlers (copied from public/index.php)
        // WC-203: permission-gated, mirroring public/index.php.
        $usersHandler = new UsersApiHandler($db->getPdo(), $hookManager);
        $router->register('GET',    '/api/users',          [$usersHandler, 'list'],   null, null, \Whity\Core\RBAC\CorePermissions::USERS_READ);
        $router->register('POST',   '/api/users',          [$usersHandler, 'create'], null, null, \Whity\Core\RBAC\CorePermissions::USERS_WRITE);
        $router->register('PATCH',  '/api/users/{id}',     [$usersHandler, 'update'], null, null, \Whity\Core\RBAC\CorePermissions::USERS_WRITE);
        $router->register('DELETE', '/api/users/{id}',     [$usersHandler, 'delete'], null, null, \Whity\Core\RBAC\CorePermissions::USERS_DELETE);

        $rolesHandler = new RolesApiHandler($db->getPdo(), $hookManager);
        $router->register('GET', '/api/roles', [$rolesHandler, 'list'], 'admin');
        $router->register('POST', '/api/roles', [$rolesHandler, 'create'], 'admin');
        $router->register('GET', '/api/roles/{id}', [$rolesHandler, 'get'], 'admin');
        $router->register('PATCH', '/api/roles/{id}', [$rolesHandler, 'update'], 'admin');
        $router->register('DELETE', '/api/roles/{id}', [$rolesHandler, 'delete'], 'admin');
        $router->register('GET', '/api/roles/{id}/permissions', [$rolesHandler, 'getPermissions'], 'admin');

        $tenantsHandler = new TenantsApiHandler($db->getPdo(), $hookManager);
        // Only GET allowed - tenants can view their own info
        // Create/update/delete restricted to system administrators (CLI only)
        $router->register('GET', '/api/tenants', [$tenantsHandler, 'list'], 'admin');

        $permissionsHandler = new PermissionsApiHandler($db->getPdo());
        $router->register('GET', '/api/permissions', [$permissionsHandler, 'list'], 'admin');

        $pluginsHandler = new PluginsApiHandler($baseDir . '/plugins', $pluginLoader, $db->getPdo());
        $router->register('GET', '/api/plugins', [$pluginsHandler, 'list'], 'admin');
        $router->register('POST', '/api/plugins/{id}/enable', [$pluginsHandler, 'enable'], 'admin');
        $router->register('POST', '/api/plugins/{id}/disable', [$pluginsHandler, 'disable'], 'admin');
        $router->register('POST', '/api/plugins/reload', [$pluginsHandler, 'reload'], 'admin');
        $router->register('POST', '/api/plugins/{id}/uninstall', [$pluginsHandler, 'uninstall'], 'admin');

        $migrationsHandler = new MigrationsApiHandler($db, $baseDir . '/database/migrations');
        $router->register('GET', '/api/migrations', [$migrationsHandler, 'list'], 'admin');
        $router->register('POST', '/api/migrations/run', [$migrationsHandler, 'run'], 'admin');
        $router->register('POST', '/api/migrations/rollback', [$migrationsHandler, 'rollback'], 'admin');

        $ousHandler = new OusApiHandler($db->getPdo(), $hookManager);
        $router->register('GET', '/api/ous', [$ousHandler, 'list'], 'admin');
        $router->register('POST', '/api/ous', [$ousHandler, 'create'], 'admin');
        $router->register('GET', '/api/ous/{id}', [$ousHandler, 'get'], 'admin');
        $router->register('PATCH', '/api/ous/{id}', [$ousHandler, 'update'], 'admin');
        $router->register('DELETE', '/api/ous/{id}', [$ousHandler, 'delete'], 'admin');
        $router->register('POST', '/api/ous/{id}/roles', [$ousHandler, 'assignRole'], 'admin');
        $router->register('DELETE', '/api/ous/{ouId}/roles/{roleId}', [$ousHandler, 'removeRole'], 'admin');

        // Generate a CLI token if none provided. This synthetic admin token is
        // authorised via JwtParser in the RBAC/tenant middleware (NOT via
        // TokenValidator's cookie path), so it is never epoch-checked; the
        // token_epoch claim is included only for issuance consistency (WC-185)
        // and pinned to the default 0.
        if (!$this->token) {
            $this->token = $jwtParser->create([
                'user_id' => 0,
                'sub' => 'cli-admin',
                'role' => 'admin',
                'tenant_id' => 1,
                'token_epoch' => 0
            ]);
        }
    }

    /**
     * Make a simulated API call
     *
     * @param string $method HTTP method
     * @param string $path API path
     * @param array|null $data POST/PATCH data
     * @return Response
     */
    protected function callApi(string $method, string $path, ?array $data = null): Response
    {
        if (!isset($this->kernel)) {
            $this->setupKernel();
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json'
        ];

        $body = $data ? json_encode($data) : '';
        $request = new Request($method, $path, $headers, $body);

        return $this->kernel->handle($request);
    }

    /**
     * Output a table to the console
     *
     * @param array $headers Table headers
     * @param array $rows Table rows
     */
    protected function renderTable(array $headers, array $rows): void
    {
        if (empty($rows)) {
            echo "No data available.\n";
            return;
        }

        // Calculate column widths
        $widths = [];
        foreach ($headers as $i => $header) {
            $widths[$i] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], strlen((string)$cell));
            }
        }

        // Render header
        foreach ($headers as $i => $header) {
            echo str_pad($header, $widths[$i] + 2);
        }
        echo "\n";

        foreach ($widths as $width) {
            echo str_repeat('-', $width) . "  ";
        }
        echo "\n";

        // Render rows
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                echo str_pad((string)$cell, $widths[$i] + 2);
            }
            echo "\n";
        }
    }
}
