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

    // The service-container helpers (\Whity\app / \Whity\register_service), as
    // bin/whity-cli has always loaded them. They are NOT autoloaded — helpers.php
    // is a plain function file, and the HTTP path below requires it explicitly —
    // so without this line every command dispatched through THIS entry point ran
    // with no container at all: `queue:work` could not register the Database
    // service a plugin's job handler resolves, and any plugin using the
    // documented container seam fatalled on an undefined function rather than
    // failing through the loader's error boundary. Same command reached through
    // whity-cli worked, which is what made it invisible.
    require_once dirname(__DIR__) . '/src/helpers.php';

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

    if ($command === 'update:check') {
        $updateCheckCommand = new \Whity\Cli\Commands\UpdateCheckCommand();
        array_shift($argv); // Remove script name
        array_shift($argv); // Remove 'update:check' command
        exit($updateCheckCommand->execute($argv));
    }

    if ($command === 'queue:work') {
        $queueWorkCommand = new \Whity\Cli\Commands\QueueWorkCommand();
        array_shift($argv); // Remove script name
        array_shift($argv); // Remove 'queue:work' command
        exit($queueWorkCommand->execute($argv));
    }

    if ($command === 'schedule:run') {
        $scheduleRunCommand = new \Whity\Cli\Commands\ScheduleRunCommand();
        array_shift($argv); // Remove script name
        array_shift($argv); // Remove 'schedule:run' command
        exit($scheduleRunCommand->execute($argv));
    }

    // WC-status-page: the service-health collector behind /status. Runs as its
    // own container so it keeps recording while the app tier is unreachable —
    // the one failure an in-app probe structurally cannot observe.
    if ($command === 'health:watch') {
        $healthWatchCommand = new \Whity\Cli\Commands\HealthWatchCommand();
        array_shift($argv); // Remove script name
        array_shift($argv); // Remove 'health:watch' command
        exit($healthWatchCommand->execute($argv));
    }

    echo "Unknown command: {$command}\n";
    echo "Available commands:\n";
    echo "  generate:openapi           Generate OpenAPI 3.0 schema\n";
    echo "  migrate                    Manage database migrations\n";
    echo "  seed                       Seed database with default data\n";
    echo "  revoked-tokens:cleanup     Cleanup expired revoked tokens\n";
    echo "  update:check               Compare the core version against the latest GitHub release\n";
    echo "  queue:work                 Run the durable async job worker loop\n";
    echo "  schedule:run               Run the cron-tick scheduler (exactly-once per minute)\n";
    echo "  health:watch               Sample service health for the public /status page\n";
    exit(1);
}

use Whity\Database\Database;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\PluginLoader;
use Whity\Core\PluginRoleSeeder;
use Whity\Auth\JwtParser;
use Whity\Auth\JwtSecretGuard;
use Whity\Auth\RoleChecker;
use Whity\Auth\AuthHandler;
use Whity\Http\RbacMiddleware;
use Whity\Http\HttpKernel;
use Whity\Http\Cors;
use Whity\Http\SecurityHeaders;
use Whity\Http\WorkerRuntime;
use Whity\Http\Middleware\CsrfGuard;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\Middleware\RequestBodyValidator;
use Whity\Api\UsersApiHandler;
use Whity\Api\EmailVerificationHandler;
use Whity\Api\RegisterApiHandler;
use Whity\Core\Identity\EmailVerificationService;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TenantEmailDomainPolicyService;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Identity\TokenEmailVerificationProvider;
use Whity\Core\Mail\MailerFactory;
use Whity\Api\RolesApiHandler;
use Whity\Api\TenantsApiHandler;
use Whity\Api\PermissionsApiHandler;
use Whity\Api\DeploymentApiHandler;
use Whity\Api\PluginsApiHandler;
use Whity\Api\MigrationsApiHandler;
use Whity\Api\AdminApiHandler;
use Whity\Api\OusApiHandler;
use Whity\Api\OuTypesApiHandler;
use Whity\Api\DelegationsApiHandler;
use Whity\Api\FrontendFeaturesApiHandler;
use Whity\Api\MeCapabilitiesApiHandler;
use Whity\Api\PermittedActionsApiHandler;
use Whity\Api\NavigationApiHandler;
use Whity\Api\HealthApiHandler;
use Whity\Api\OpenApiHandler;
use Whity\Api\IdentityProvidersApiHandler;
use Whity\Api\TenantEmailDomainApiHandler;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Relations\RelationResolver;
use Whity\Api\PersonsApiHandler;
use Whity\Api\RelationsApiHandler;
use Whity\Api\TwoFactorHandler;
use Whity\Api\AiPrincipalsApiHandler;
use Whity\Api\AuditLogApiHandler;
use Whity\Api\McpToolsAdminHandler;
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
use Whity\Auth\LoginThrottleService;
use Whity\Auth\TwoFactorPolicyResolver;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Core\RateLimit\SharedStoreRateLimitStore;
use Whity\Core\RateLimit\RateLimitMiddleware;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Mcp\Auth\McpTokenHandler;
use Whity\Mcp\Auth\McpTokenService;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\Lifecycle\CancelledNotificationHandler;
use Whity\Mcp\McpFeatureDisabledException;
use Whity\Mcp\Notifications\CatalogSignature;
use Whity\Mcp\Notifications\ListChangedNotifier;
use Whity\Mcp\RateLimit\McpRateLimiter;
use Whity\Mcp\Lifecycle\InitializeHandler;
use Whity\Mcp\Lifecycle\PingHandler;
use Whity\Mcp\Prompts\CorePrompts;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Prompts\PromptsGetHandler;
use Whity\Mcp\Prompts\PromptsListHandler;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Resources\ResourcesListHandler;
use Whity\Mcp\Resources\ResourcesReadHandler;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Mcp\Transport\McpTransportHandler;
use Whity\OpenAPI\CoreApiSchemas;

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
require_once dirname(__DIR__) . '/src/helpers.php';

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

// WC-d: error tracker for uncaught exceptions. Null until ERROR_TRACKER_DSN
// (or SENTRY_DSN) is configured; when active it captures each uncaught error
// with secret-free context (release, tenant_id, request_id, loaded plugins +
// versions). Selection is config-driven so activation is a deploy-time env change.
$errorTracker = \Whity\Core\Observability\ErrorTrackerFactory::fromEnv($_ENV);

// 1. Initialize database connection
$db = Database::connect();
// Expose the shared, lazy, self-healing Database service to plugin route
// handlers (WC-169): plugins resolve it at request time via
// \Whity\app(Database::class) — the same service container the HookManager
// already uses — so they reuse the worker's single connection instead of
// opening their own. Lazy: registering it does not open a socket.
\Whity\register_service(Database::class, $db); // @phpstan-ignore-line

// 2. Initialize router
// WC-206: '/v1' prefix applied to all versioned routes automatically so
// handlers can be registered as '/api/users' and resolve to '/api/v1/users'.
// Infrastructure probes (/api/health, /api/version, /api/openapi.json) use
// registerUnversioned() and are never prefixed.
$router = new Router('/v1');

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
// Registered as a service so a plugin resolving the permission catalogue gets
// the SAME instance the plugin loader fills below.
//
// It was not, and the failure was silent: the container's auto-instantiation
// fallback happily built a fresh, EMPTY PermissionRegistry (concrete class, one
// OPTIONAL constructor argument), so `\Whity\app(PermissionRegistry::class)
// ->exists('some_plugin:manage')` answered false for a permission the plugin
// had declared and the loader had accepted. The caller failed closed with
// nothing thrown and nothing logged. The registry is now marked
// {@see \Whity\Core\Container\HostWiredService} so a missing registration
// throws loudly instead of resolving to an empty catalogue — but the fix for
// THIS host is to register the real one, here and in the CLI kernel alike.
\Whity\register_service(PermissionRegistry::class, $permissionRegistry); // @phpstan-ignore-line

// 4b. Initialize hook manager (durable event spine wired in) and register it.
// dispatchAsync now PERSISTS each async event to domain_events + the relay
// outbox (WC-154/#162) via the shared per-worker connection — replacing the
// retired log-only Queue stub that dropped every event. Using the same $db
// connection as the API handlers lets an event enlist in the caller's
// transaction when one is open (transactional outbox).
$hookManager = new HookManager(
    new \Whity\Core\Events\DomainEventStore($db->getPdo()),
    $logger
);
\Whity\register_service(HookManager::class, $hookManager); // @phpstan-ignore-line

// 4c. Resource-type catalogue (WC-712 §2): the vocabulary of which KINDS of
// record may carry a role grant. Constructed AFTER the hook manager so a
// registration is announced on the durable spine; the plugin loader below fills
// it from the plugins actually loaded.
//
// Registered as a service so plugins and handlers resolve the SAME instance the
// loader populated. A second instance would answer "unregistered" for every
// plugin type and silently refuse grants that are in fact declared.
//
// Worker-level state rebuilt per boot, so unloading a plugin removes its types
// with no unregister API — the property PermissionRegistry already relies on.
$resourceTypeRegistry = new \Whity\Core\RBAC\ResourceTypeRegistry($hookManager);
$resourceTypeRegistry->registerCoreResourceTypes();
\Whity\register_service(\Whity\Core\RBAC\ResourceTypeRegistry::class, $resourceTypeRegistry); // @phpstan-ignore-line

// 4c-ante. Organizational-unit TYPE catalogue (#822): which KINDS of unit a
// plugin may contribute to a tenant's tree (campus, faculty, clinic, ward).
// Constructed after the hook manager so a registration is announced on the
// durable spine; the plugin loader below fills it from the plugins actually
// loaded, stamping each key with the plugin's own namespace.
//
// Registered as a service for the same reason as the resource-type catalogue:
// a handler that built its own instance would see none of the plugins'
// contributions, and `GET /api/ou-types/catalog` would tell an administrator
// that the type their plugin ships does not exist.
//
// Note this catalogue is NOT the vocabulary — that is per-tenant data in
// `ou_types` (migration 102), because one tenant's faculty is another's region.
// This governs only which keys CODE may contribute.
$ouTypeRegistry = new \Whity\Core\Ou\OuTypeRegistry($hookManager);
$ouTypeRegistry->registerCoreOuTypes();
\Whity\register_service(\Whity\Core\Ou\OuTypeRegistry::class, $ouTypeRegistry); // @phpstan-ignore-line

// 4c-bis. Status-page probe catalogue (WC-status-probes): WHICH components this
// deployment samples for /status. Core's four (database, queue, scheduler,
// render) are registered here; the plugin loader below adds whatever plugins
// contribute, namespaced under the plugin name IT supplies.
//
// Registered as a service for the same reason the resource-type catalogue is:
// a handler or plugin that built its own instance would see core's four and
// none of the contributions, and would then render a status page that quietly
// omits half of what is being watched.
$healthProbeRegistry = new \Whity\Core\Health\HealthProbeRegistry($hookManager);
$healthProbeRegistry->registerCoreProbes();
\Whity\register_service(\Whity\Core\Health\HealthProbeRegistry::class, $healthProbeRegistry); // @phpstan-ignore-line

// 4c-ter. Table ownership (WC-723 Piece 1): WHO owns each table. Core claims
// every table its own migrations create BEFORE any plugin loads, so a plugin
// claiming `memberships` loses the race by construction rather than by load
// order. The plugin loader below stamps each plugin's claims from
// $plugin->getName() — a plugin declares WHICH tables, never WHO said so.
//
// This is the foundation the data-type registry stands on: a referential guard
// is an aggregate over the referencing table, so declaring one over a table the
// plugin does not own would read data it cannot otherwise reach.
$tableOwnershipRegistry = new \Whity\Core\Tenant\TableOwnershipRegistry($hookManager);
$tableOwnershipRegistry->registerCoreTables();
\Whity\register_service(\Whity\Core\Tenant\TableOwnershipRegistry::class, $tableOwnershipRegistry); // @phpstan-ignore-line

// 4c-quater. Data-type catalogue (WC-723 Piece 2, `registerDataType`): the declared
// lifecycle and reference graph of plugin-owned records. Constructed with the
// ownership registry above so every declaration is validated against
// loader-stamped ownership, and registered as a service so plugins and handlers
// resolve the SAME instance the loader filled — a second instance would answer
// "unregistered" for every declared type and refuse every lifecycle call.
$dataTypeRegistry = new \Whity\Core\DataType\DataTypeRegistry($tableOwnershipRegistry, $hookManager);
\Whity\register_service(\Whity\Core\DataType\DataTypeRegistry::class, $dataTypeRegistry); // @phpstan-ignore-line

// 4c-quinquies. Plugin-declared SETTINGS catalogue (#713 item 1): the keys a
// plugin contributes to core's OWN settings tables, so a plugin stops building a
// private `tenant_settings` look-alike with no typing and no validation.
//
// Two objects, because there are two different things:
//
//  - PluginSettingsRegistry holds the MUTABLE half — the plugin contributions —
//    as an instance rebuilt per boot from the plugins actually loaded. It is not
//    a static, because a static is per FrankenPHP worker and a key missing from
//    one worker's catalogue does not throw, it reads as "unknown setting" (the
//    #701 / #727 hazard, landing in a layer that fails quietly).
//  - SettingsCatalog is the UNION VIEW over it and core's static const
//    catalogue. Core's ~330 static call sites keep resolving core-only and are
//    untouched; only consumers that treat keys as data — the settings service
//    and the settings API — see both halves.
$pluginSettingsRegistry = new \Whity\Core\Settings\PluginSettingsRegistry($hookManager);
\Whity\register_service(\Whity\Core\Settings\PluginSettingsRegistry::class, $pluginSettingsRegistry); // @phpstan-ignore-line

$settingsCatalog = new \Whity\Core\Settings\SettingsCatalog($pluginSettingsRegistry);
\Whity\register_service(\Whity\Core\Settings\SettingsCatalog::class, $settingsCatalog); // @phpstan-ignore-line

// 4b-bis. Durable async queue (WC-queue): the producer-side QueueService is
// registered so core services, hooks, and plugins enqueue work into the durable
// `jobs` table instead of the old log-only Queue stub. The consumer side
// (JobRegistry + JobRunner) is driven by the `queue:work` worker process
// (separate task); this bootstrap only needs the enqueue facade available.
$queueService = new \Whity\Core\Queue\QueueService(
    new \Whity\Core\Queue\JobRepository($db->getPdo())
);
\Whity\register_service(\Whity\Core\Queue\QueueService::class, $queueService); // @phpstan-ignore-line

// 4c. Initialize the security audit-trail writer (WC-34) and subscribe it to the
// core CRUD lifecycle hooks. This is the SINGLE writer for the audit_log table:
// role/user/tenant/OU mutations are captured by subscribing to the hooks the
// handlers already fire (no per-handler audit code), while the auth/2FA paths —
// which do not fire hooks — receive the same logger and call record() directly.
// It is process-scoped infrastructure; per-request actor/IP live in AuditContext.
// A PLUGIN's own events reach the same trail by declaring them (SDK 1.29): the
// plugin loader is handed this instance below and subscribes it to each
// declaring plugin's events, namespaced under that plugin.
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
        // WC-175 (#191): mirrors the dashboard's primary API (GET /api/admin/stats),
        // which is gated on the 'admin' ROLE — so the nav item gates on the role.
        'requiredRole' => 'admin',
    ];
    $items[] = [
        'id' => 'users',
        'label' => 'Users',
        'href' => '/admin/users',
        'icon' => 'users',
        'group' => 'admin',
        'order' => 2,
        // WC-203: mirrors GET /api/users, now gated on users:read permission
        // (migration 022 grants this to admin). requiredRole is cleared so the
        // nav item is visible to any user who holds the permission, not just
        // those with the 'admin' role name.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::USERS_READ,
    ];
    $items[] = [
        'id' => 'roles',
        'label' => 'Roles',
        'href' => '/admin/roles',
        'icon' => 'lock',
        'group' => 'admin',
        'order' => 3,
        // WC-175 (#191): mirrors GET /api/roles, gated on the 'admin' ROLE.
        'requiredRole' => 'admin',
    ];
    $items[] = [
        'id' => 'ous',
        'label' => 'Organizational Units',
        'href' => '/admin/ous',
        'icon' => 'building-community',
        'group' => 'admin',
        'order' => 4,
        // Mirrors GET /api/ous, now gated on ous:read. requiredRole is cleared so
        // the item follows the route it mirrors rather than drifting from it —
        // that drift is what left the ous:* grants vestigial in the first place.
        // Not a visibility change: the only non-admin holder of ous:read was the
        // base `user` role, whose inert grant the revoke migration removes.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::OUS_READ,
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
        'id' => 'tag-groups',
        'label' => 'Tag Groups',
        'href' => '/admin/tag-groups',
        'icon' => 'tags',
        'group' => 'admin',
        'order' => 8,
        // WC-621: the taxonomy admin. The nav item carries tags:read so a
        // permission-aware client hides it; the schema-driven CrudScreen also
        // fails closed (a 403 on the list renders the access-denied state).
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::TAGS_READ,
    ];
    $items[] = [
        'id' => 'tags',
        'label' => 'Tags',
        'href' => '/admin/tags',
        'icon' => 'tag',
        'group' => 'admin',
        'order' => 9,
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::TAGS_READ,
    ];
    $items[] = [
        'id' => 'tenants',
        'label' => 'Tenants',
        'href' => '/admin/tenants',
        'icon' => 'building',
        'group' => 'admin',
        'order' => 5,
        // WC-175 (#191): mirrors GET /api/tenants, gated on the 'admin' ROLE.
        'requiredRole' => 'admin',
    ];
    $items[] = [
        'id' => 'audit-logs',
        'label' => 'Audit Logs',
        'href' => '/admin/audit-logs',
        'icon' => 'history',
        'group' => 'admin',
        'order' => 6,
        // WC-175 (#191): mirrors GET /api/audit-logs, gated on the audit:read
        // permission — so the nav item gates on the same permission.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::AUDIT_READ,
    ];
    $items[] = [
        'id' => 'errors',
        'label' => 'Errors',
        'href' => '/admin/errors',
        'icon' => 'alert-triangle',
        'group' => 'admin',
        'order' => 7,
        // WC-error-tracking: mirrors GET /api/errors, which is operator-only
        // (settings:manage + system tenant, enforced in the handler) — so the
        // nav item gates on the same permission. A tenant admin never sees it.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::SETTINGS_MANAGE,
    ];
    $items[] = [
        'id' => 'plugins',
        'label' => 'Plugins',
        'href' => '/admin/plugins',
        'icon' => 'plug',
        'group' => 'admin',
        'order' => 8,
        // WC-218: mirrors GET /api/plugins, gated on the plugins:read
        // permission. The nav item carries the requirement so a permission-aware
        // client can hide it; the page also enforces it server-side via the
        // RBAC-protected API (a 403 renders the access-denied state).
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::PLUGINS_READ,
    ];
    $items[] = [
        'id' => 'plugin-store',
        'label' => 'Plugin Store',
        'href' => '/admin/plugins/store',
        'icon' => 'building-store',
        'group' => 'admin',
        'order' => 9,
        // Browse + install from a trusted store. Mirrors GET
        // /api/plugins/store/catalog (gated plugins:read); the install action on
        // the page is separately gated plugins:upload server-side.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::PLUGINS_READ,
    ];
    $items[] = [
        'id' => 'website-settings',
        'label' => 'Website Settings',
        'href' => '/admin/settings',
        'icon' => 'settings',
        'group' => 'admin',
        'order' => 9,
        // Website Settings: mirrors GET /api/v1/settings, gated on the
        // settings:read permission (migration grants all three settings perms to
        // admin). The nav item carries the requirement so a permission-aware
        // client hides it; the page also enforces it server-side via the
        // RBAC-protected API (a 403 renders the access-denied state), matching
        // the plugins pattern.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::SETTINGS_READ,
    ];
    $items[] = [
        'id' => 'documents',
        'label' => 'Document Designer',
        'href' => '/admin/documents',
        'icon' => 'file-text',
        'group' => 'admin',
        'order' => 9.2,
        // WC-docdesigner: the document/label template designer. Mirrors GET
        // /api/document-templates (DocumentTemplatesApiHandler), gated on
        // documents:read. The nav item carries the requirement so a
        // permission-aware client hides it; the page/API also enforce it
        // server-side (write/publish/render are separately gated).
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::DOCUMENTS_READ,
    ];
    $items[] = [
        'id' => 'approval-gating',
        'label' => 'Approval Gating',
        'href' => '/admin/registrations',
        'icon' => 'user-check',
        'group' => 'admin',
        'order' => 9.5,
        // WC-password-reset-2fa-recovery: unified admin page (tabs: Signup /
        // Password reset / 2FA auth reset — see web/app/(protected)/admin/
        // approval-gating/). The first tab, at this href, is the WC-235
        // pending-registrations queue (folded in unchanged: system-tenant +
        // registrations:approve). Gated here on the broad 'admin' ROLE rather
        // than any single one of the three underlying permissions, since a
        // tenant admin who holds ONLY password_resets:approve or
        // two_factor_recovery:approve (never registrations:approve, and never
        // acting in the system tenant) must still see this entry to reach
        // their own tab — each tab enforces its OWN precise permission +
        // tenant/system-tenant scope server-side regardless of nav visibility.
        'requiredRole' => 'admin',
    ];
    $items[] = [
        'id' => 'ai-principals',
        'label' => 'AI Principals',
        'href' => '/admin/ai-principals',
        'icon' => 'robot',
        'group' => 'admin',
        'order' => 10,
        // WC-0208ce4d: mirrors GET /api/v1/admin/mcp/tokens, gated on the
        // mcp:tokens:manage permission. Nav item carries the same requirement so
        // permission-aware clients can hide it; the page enforces it server-side.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::MCP_TOKENS_MANAGE,
    ];
    $items[] = [
        'id' => 'mcp-tools',
        'label' => 'MCP Tools',
        'href' => '/admin/mcp-tools',
        'icon' => 'tools',
        'group' => 'admin',
        'order' => 11,
        // WC-0208ce4d: read-only view of MCP tools available in this tenant.
        // Gated on mcp:tokens:manage so only admins who manage AI credentials
        // can see which tools those credentials expose.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::MCP_TOKENS_MANAGE,
    ];
    $items[] = [
        'id' => 'languages',
        'label' => 'Languages',
        'href' => '/admin/languages',
        'icon' => 'language',
        'group' => 'admin',
        'order' => 9.6,
        // WC-583: languages are a GLOBAL catalogue (no tenant_id column at
        // all) — create/update/enable/disable is a SYSTEM-TENANT-ONLY
        // PLATFORM capability (mirrors the Feature Flags/Email/Storage
        // settings tabs), so the nav item itself is hidden from every other
        // tenant rather than 403ing on click.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::LANGUAGES_MANAGE,
        'systemTenantOnly' => true,
    ];
    $items[] = [
        'id' => 'translations',
        'label' => 'Translations',
        'href' => '/admin/translations',
        'icon' => 'world',
        'group' => 'admin',
        'order' => 9.7,
        // WC-583: translation rows ARE tenant-scoped (system default vs a
        // tenant's own override) — unlike Languages above, every tenant
        // holding translations:manage may reach this page to edit its own
        // overrides; the page itself branches on the caller's tenant (system
        // vs regular) for which column is editable.
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::TRANSLATIONS_MANAGE,
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

// 5b. Expose permission RESOLUTION to plugins (WC-712, issue #712).
//     A route's `requiredPermission` is a flat, one-shot gate: it answers a
//     single question before the handler runs. A plugin needing a second
//     decision INSIDE a handler ("may this caller see archived rows?") had no
//     way to ask the host — plugins receive only a raw PDO — so the only option
//     was to re-derive the answer in hand-written SQL. Real resolution is not
//     one join (active-membership gating, OU-ancestor chain, role hierarchy with
//     cycle/depth guards, live delegations, catalogue validation), so any
//     re-derivation drifts and the system ends up holding two different answers
//     to the same authorization question.
//
//     Registered under the SDK interface so an out-of-repo plugin can type-hint
//     it with only whity/plugin-sdk installed, and READ-ONLY (three question
//     methods; no cache invalidation, no DB handle) so it grants no authority.
//
//     It wraps THE SAME delegation-aware $roleChecker the RBAC middleware below
//     enforces with — passing $baseRoleChecker here instead would make a live
//     delegation unlock a route-level gate but not a plugin's in-handler check,
//     reinstating the exact divergence this closes.
$permissionResolver = new \Whity\Core\RBAC\RoleCheckerPermissionResolver($roleChecker, $permissionRegistry);
\Whity\register_service(\Whity\Sdk\Rbac\PermissionResolver::class, $permissionResolver); // @phpstan-ignore-line

// 6. Initialize RBAC middleware
$rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);

// 7. Initialize tenant isolation middleware
// Pass the PSR-3 logger (WC-20) so privileged cross-tenant bypasses are audited.
// The membership guard (WC-d4340daf) gates the new {profile_id, active_tenant_id}
// JWT claims against live `memberships` rows (typed 403 on a suspended/revoked
// membership); legacy tokens are unaffected during the dual-claim window.
$tenantIsolationMiddleware = new EnforceTenantIsolation(
    $jwtParser,
    $logger,
    new \Whity\Auth\ActiveTenantMembershipGuard($db->getPdo())
);

// 8. Initialize HTTP kernel and register middleware
$kernel = new HttpKernel($router, $rbacMiddleware);
// Register middleware in order. The body-envelope validator runs FIRST (WC-189):
// an oversized, wrong-content-type or malformed body is refused with a generic
// 400 before any CSRF/tenant/RBAC/database work, and a valid JSON object is
// stashed on the request for handlers (read via \Whity\Http\JsonBody::parsed()).
// Then the CSRF guard (cheap header check on the state-changing auth POSTs,
// WC-160), then tenant isolation BEFORE RBAC.
// Kernel rate limiting (WC-c0fb3700). One fixed-window engine over the shared
// store, split into two pipeline positions: a pre-auth per-IP limiter that sheds
// flood load before any auth/DB work, and a post-auth per-tenant/per-principal
// limiter that caps an authenticated caller's throughput (its rules read the
// TenantContext/AuditContext that EnforceTenantIsolation populates, and no-op on
// public/unauthenticated requests). Limits are env-tunable; RATE_LIMIT_ENABLED=0
// disables the whole layer. Defaults are generous so normal usage (and the e2e
// suite) is never throttled — operators tighten them per deployment.
$rateLimitEnabled    = (($_ENV['RATE_LIMIT_ENABLED'] ?? '1') !== '0');
$rateLimitStore      = new SharedStoreRateLimitStore(new DatabaseSharedStore($db->getPdo()));
$rateLimitExemptPaths = ['/api/health', '/api/version', '/api/openapi.json'];

$preAuthRateLimiter = new RateLimitMiddleware(
    $rateLimitStore,
    [
        // Platform-wide ceiling: a single shared counter over ALL requests — a
        // safety valve for the whole deployment (esp. a sovereign single-customer
        // box). Generous by default so it never throttles normal load; operators
        // tighten RATE_LIMIT_PLATFORM_* per deployment.
        RateLimitRule::platform(
            (int) ($_ENV['RATE_LIMIT_PLATFORM_LIMIT']  ?? 100000),
            (int) ($_ENV['RATE_LIMIT_PLATFORM_WINDOW'] ?? 60),
        ),
        RateLimitRule::ip(
            (int) ($_ENV['RATE_LIMIT_IP_LIMIT']  ?? 2000),
            (int) ($_ENV['RATE_LIMIT_IP_WINDOW'] ?? 60),
        ),
    ],
    enabled: $rateLimitEnabled,
    exemptPaths: $rateLimitExemptPaths,
    logger: $logger,
);

$postAuthRateLimiter = new RateLimitMiddleware(
    $rateLimitStore,
    [
        // Per-tenant budget is PLAN-DRIVEN: the tenant's ratelimit.rpm entitlement
        // (a plan raises/lowers it), falling back to the RATE_LIMIT_TENANT_*
        // baseline when the plan sets none (entitlement default -1). A fresh
        // EntitlementService here is stateless — $db is available this early,
        // avoiding the boot-order trap of the later shared $entitlementService.
        RateLimitRule::tenantEntitled(
            new \Whity\Core\Entitlement\EntitlementService(
                new \Whity\Core\Entitlement\TenantEntitlementRepository($db->getPdo())
            ),
            (int) ($_ENV['RATE_LIMIT_TENANT_LIMIT']  ?? 10000),
            (int) ($_ENV['RATE_LIMIT_TENANT_WINDOW'] ?? 60),
        ),
        RateLimitRule::principal(
            (int) ($_ENV['RATE_LIMIT_PRINCIPAL_LIMIT']  ?? 2000),
            (int) ($_ENV['RATE_LIMIT_PRINCIPAL_WINDOW'] ?? 60),
        ),
    ],
    enabled: $rateLimitEnabled,
    exemptPaths: $rateLimitExemptPaths,
    logger: $logger,
);

// Pre-auth IP limiter runs FIRST so a flood is shed before body/CSRF/tenant work.
$kernel->use($preAuthRateLimiter);
$kernel->use(new RequestBodyValidator());
$kernel->use(new CsrfGuard());
$kernel->use($tenantIsolationMiddleware);
// Post-auth limiter runs AFTER tenant/principal are resolved, before route dispatch.
$kernel->use($postAuthRateLimiter);
// NOTE: the payment wall is the LAST middleware but is registered further below,
// right after $settingsService is constructed (it depends on it). $kernel->use()
// order == execution order, so registering it after the block above still runs it
// after tenant isolation + the post-auth limiter, immediately before dispatch.

// 9. Initialize plugin loader and load plugins
// Wire the permission registry, hook manager, and logger (WC-9/WC-13) so plugin
// permissions/hooks register through core services and plugin error boundaries
// log structured records via the application logger.
// NOTE: the loader is CONSTRUCTED here (handlers below depend on the instance)
// but plugins are LOADED after every core route is registered — first
// registration wins in the Router (WC-169), so a plugin can never shadow a
// core route by claiming its path.
$pluginLoader = new PluginLoader(
    __DIR__ . '/../plugins',
    $router,
    $permissionRegistry,
    $hookManager,
    $logger,
    new PluginRoleSeeder($db->getPdo(), $logger),
    $resourceTypeRegistry,
    $healthProbeRegistry,
    $tableOwnershipRegistry,
    $dataTypeRegistry,
    $pluginSettingsRegistry,
    // Plugin-declared audited events (SDK 1.29): the loader subscribes this
    // writer to each declaring plugin's own events, beside that plugin's other
    // hook subscriptions, so a plugin's actions land in the SAME audit trail as
    // core's — namespaced under the plugin, and removed again when it is
    // disabled. Built at step 4c above, long before this point.
    $auditLogger,
    // Plugin-contributed OU types (#822): built at step 4c-ante above. The
    // loader stamps each declared slug with the plugin's own namespace.
    $ouTypeRegistry
);

// 9b. Initialize deployment manager
$deploymentManager = new DeploymentManager($db->getPdo(), __DIR__ . '/../storage/deployments');

// 10. Register authentication handler
// Inject the shared $totpService (built at step 3b) so the login-path 2FA validation uses the
// SAME encryption key as the setup/confirm path (WC-95).
// WC-0abcc29f: brute-force throttle uses the shared DatabaseSharedStore.
// Brute-force login throttle thresholds are operator-configurable (like the HTTP
// RATE_LIMIT_* rules); unset falls back to the service defaults (10/20/900s).
$loginThrottle = new LoginThrottleService(
    new DatabaseSharedStore($db->getPdo()),
    (int) ($_ENV['LOGIN_THROTTLE_USER_THRESHOLD']  ?? getenv('LOGIN_THROTTLE_USER_THRESHOLD')  ?: LoginThrottleService::DEFAULT_USER_THRESHOLD),
    (int) ($_ENV['LOGIN_THROTTLE_IP_THRESHOLD']    ?? getenv('LOGIN_THROTTLE_IP_THRESHOLD')    ?: LoginThrottleService::DEFAULT_IP_THRESHOLD),
    (int) ($_ENV['LOGIN_THROTTLE_WINDOW_SECONDS']  ?? getenv('LOGIN_THROTTLE_WINDOW_SECONDS')  ?: LoginThrottleService::DEFAULT_WINDOW_SECONDS),
);
// WC-525: admin-enforced 2FA policy resolver — checked at the session-issuing
// chokepoint inside AuthHandler for every login-completion path.
$twoFactorPolicyResolver = new TwoFactorPolicyResolver($db, $logger);
// WC-desktop-ttl: the settings service is needed by AuthHandler (the device-token
// exchange caps + echoes the per-tenant desktop-login TTL) and by
// DeviceCredentialService below, so it is constructed here — ahead of $authHandler
// (it depends only on $db). It is also reused by the payment wall / mailer /
// settings handlers further down.
$globalSettingsRepository = new \Whity\Core\Settings\GlobalSettingsRepository($db->getPdo());
$settingsService = new \Whity\Core\Settings\SettingsService(
    $globalSettingsRepository,
    new \Whity\Core\Settings\TenantSettingsRepository($db->getPdo()),
    // #713 item 1: resolve against the UNION of core's keys and the loaded
    // plugins' declarations, so a plugin key lands in these same two tables and
    // resolves through this same per-tenant ?? global ?? default chain.
    $settingsCatalog
);
\Whity\register_service(\Whity\Core\Settings\SettingsService::class, $settingsService); // @phpstan-ignore-line
$authHandler = new AuthHandler($db->getPdo(), $jwtParser, null, null, $totpService, $logger, $auditLogger, $loginThrottle, $twoFactorPolicyResolver, $settingsService);
$router->register('POST', '/api/login', [$authHandler, 'handle'], null);
// WC-235: public self-service registration — provisions a new tenant + owner
// (profile + primary email + active admin membership). Public + no required
// permission; the global rate-limiter (non-exempt path) throttles abuse.
// WC-235: email verification. The concrete provider issues a hashed, single-use,
// time-boxed token (EmailVerificationService) and delivers the link via the
// configured Mailer (MAIL_TRANSPORT; NullMailer by default). Registration hands
// off to it only when EMAIL_VERIFICATION_ENFORCED=1; the resend/confirm endpoints
// below share the same service. Binding a real provider here is harmless while
// the flag is off (RegisterApiHandler only calls it when enforcement is on).
// $globalSettingsRepository + $settingsService are constructed ABOVE, ahead of
// $authHandler (WC-desktop-ttl). They are reused by the settings/branding/mail
// handlers and the payment wall below; RegisterApiHandler reads the
// instance-governance flags (self-registration open? approval required?) from
// $settingsService — closed by default on a fresh instance. $secretStore holds
// the encryption key so the mail-settings handler can read + decrypt the
// out-of-registry encrypted SMTP password.
$secretStore = \Whity\Core\Security\EncryptedSecretStore::fromEnv($_ENV);

// WC-error-tracking: UPGRADE the boot-time error tracker now that settings, the
// secret store and the queue exist.
//
// The early construction at the top of this file is env-only on purpose — it has
// to work before the database is reachable, which is exactly when early-boot
// failures happen. From here on, an operator's choice in the admin UI takes
// over: `internal` records into this deployment's own database (no extra
// infrastructure), `sentry` ships to any Sentry-PROTOCOL backend using the
// ENCRYPTED DSN. With error tracking disabled this returns to the env behaviour,
// so a deployment configured purely from the environment is unaffected.
//
// Alerts are ENQUEUED, never sent inline: capture runs on the error path, and
// talking to SMTP there would add latency exactly when the system can least
// afford it (and a broken mail server would then break error capture too).
$errorTracker = \Whity\Core\Observability\ErrorTrackerFactory::fromSettings(
    $db->getPdo(),
    static fn(string $key): ?string => $globalSettingsRepository->get($key),
    static fn(string $ciphertext): string => $secretStore->decrypt($ciphertext),
    $_ENV,
    static function (int $groupId, string $reason) use ($db): void {
        try {
            (new \Whity\Core\Queue\JobRepository($db->getPdo()))->enqueue(
                0, // system tenant: error tracking is deployment-wide
                \Whity\Core\Observability\Jobs\NotifyErrorGroupJob::NAME,
                ['group_id' => $groupId, 'reason' => $reason],
                // One alert per group per reason, even if two workers capture
                // the same new error at the same instant.
                ['idempotency_key' => "error-notify:{$groupId}:{$reason}"]
            );
        } catch (\Throwable $e) {
            // Never let a failed enqueue break the request that errored.
            error_log('[error-tracking] could not enqueue alert: ' . $e->getMessage());
        }
    }
);

// Payment wall (WC-billing) — registered here (not up with the other middleware)
// because it depends on $settingsService just constructed above. It is still the
// LAST $kernel->use(), so it runs AFTER tenant isolation + the post-auth limiter,
// immediately before route dispatch. After tenant resolution it blocks a LAPSED
// tenant's requests per its effective enforcement mode; NEVER walls the system
// tenant, public routes, or the billing/subscription-management routes (so an
// admin can always pay/upgrade — payment can happen externally). Dormant until a
// tenant is lapsed AND has an enforcing mode; env BILLING_WALL_ENABLED is the
// master off switch, BILLING_URL sets the 402 Link target.
$kernel->use(new \Whity\Http\Middleware\PaymentWall(
    new \Whity\Core\Subscription\SubscriptionService(
        new \Whity\Core\Subscription\SubscriptionRepository($db->getPdo()),
        $settingsService
    ),
    enabled: (($_ENV['BILLING_WALL_ENABLED'] ?? '1') !== '0'),
    exemptPrefixes: ['/api/v1/subscription'],
    billingUrl: ($_ENV['BILLING_URL'] ?? getenv('BILLING_URL')) ?: null,
    logger: $logger,
));

// WC-email: the Mailer is built from the GLOBAL instance mail.* settings
// (transport none/log/smtp; SMTP password decrypted from the encrypted-secret
// store), replacing the old MAIL_TRANSPORT-env-only wiring. It is built LAZILY,
// per send, via LazyMailer: reading the settings hits the database, and doing
// that at worker boot would let a transient DB issue (or a migration lagging a
// deploy) crash every worker — and would freeze the transport until a restart.
// Building per send instead means boot never touches the DB for mail, settings
// changes take effect immediately, and a settings-read failure degrades to a
// no-op (email is best-effort) rather than taking down the process.
$mailer = new \Whity\Core\Mail\LazyMailer(
    static fn(): \Whity\Core\Mail\Mailer =>
        MailerFactory::fromSettings($settingsService, $globalSettingsRepository, $secretStore, $logger)
);
$emailVerificationService = new EmailVerificationService($db->getPdo());
$profileEmailRepository = new ProfileEmailRepository($db->getPdo());
$verifyUrlBase = (string) ($_ENV['EMAIL_VERIFICATION_URL'] ?? getenv('EMAIL_VERIFICATION_URL')
    ?: (rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/') . '/verify-email'));
$emailVerificationProvider = new TokenEmailVerificationProvider(
    $emailVerificationService,
    $profileEmailRepository,
    $mailer,
    $verifyUrlBase,
    // WC-email: render the link as a branded HTML email (with a text fallback),
    // driven by the instance branding/mail settings.
    new \Whity\Core\Mail\EmailLayout(),
    $settingsService
);
// WC-email: customer lifecycle emails. The subscriber listens on lifecycle hooks
// (welcome on registration today; approval/invitation to follow) and sends via
// the branded EmailLayout, gated per-event on the mail.events.* toggles. Sends
// are best-effort — a failure can never break the originating request.
$appUrl = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
$emailNotifications = new \Whity\Core\Mail\EmailNotifications(
    $mailer,
    new \Whity\Core\Mail\EmailLayout(),
    $settingsService,
    $appUrl,
    $logger
);
$emailNotifications->subscribe($hookManager);

$registerHandler = new RegisterApiHandler($db->getPdo(), $settingsService, $emailVerificationProvider, $hookManager);
$router->register('POST', '/api/register', [$registerHandler, 'register'], null);

// WC-235: public email verification — (re)send a link + confirm a token. Both are
// unauthenticated (a new owner has no session yet; a confirm link carries no JWT),
// so both are on EnforceTenantIsolation::PUBLIC_ROUTES. Rate-limited via the
// shared store; audited as system-level (tenant 0) identity events.
// WC-9b87: on a successful confirm the handler applies the tenant email-domain
// policy (accept invite / auto-provision membership) for the now-verified email.
// The MembershipRepository is handed the hook manager (#889): auto-provisioning
// a membership from a verified email domain is a real authority grant, and it
// used to happen with nothing recording it. The repository announces the write
// so this path — and the two SSO ones below — are audited without each service
// having to remember to.
$emailDomainPolicy = new TenantEmailDomainPolicyService(
    new TenantEmailDomainsRepository($db->getPdo()),
    new MembershipRepository($db->getPdo(), $hookManager)
);
$emailVerificationHandler = new EmailVerificationHandler(
    $emailVerificationService,
    $profileEmailRepository,
    $emailVerificationProvider,
    new DatabaseSharedStore($db->getPdo()),
    $auditLogger,
    $emailDomainPolicy
);
$router->register('POST', '/api/email/request-verification', [$emailVerificationHandler, 'request'], null);
$router->register('POST', '/api/email/verify', [$emailVerificationHandler, 'confirm'], null);
$router->register('POST', '/api/login/2fa', [$authHandler, 'handle2fa'], null);
// ADR 0005 §6: multi-membership tenant selection. Public like /api/login/2fa —
// the caller holds only the short-lived selection cookie (not a full session);
// the handler re-validates the chosen tenant against the caller's active
// memberships before minting the session.
$router->register('POST', '/api/auth/select-tenant', [$authHandler, 'handleSelectTenant'], null);
$router->register('GET', '/api/me', [$authHandler, 'handleMe'], null);
$router->register('PATCH', '/api/me', [$authHandler, 'handleUpdateMe'], null);
$router->register('POST', '/api/auth/refresh', [$authHandler, 'handleRefresh'], null);
$router->register('POST', '/api/auth/logout', [$authHandler, 'handleLogout'], null);
// WC-b-logout-others: sign out of all OTHER sessions & devices (bump token_epoch
// then re-mint the current session). Self-authenticating like /me and refresh.
$router->register('POST', '/api/me/logout-others', [$authHandler, 'handleLogoutOthers'], null);
// WC-f8164c87: authenticated tenant switch. Requires a full session (access
// token cookie), validates active membership in the target tenant, re-mints
// session JWT with the new active_tenant_id. NOT a public route — unlike
// select-tenant (which runs pre-session), this runs POST-login with a full
// access token, so it is NOT in PUBLIC_ROUTES and goes through the same
// tenant-isolation middleware as refresh/logout.
$router->register('POST', '/api/auth/switch-tenant', [$authHandler, 'handleSwitchTenant'], null);

// 10b. Register 2FA handler
// Reuses the single $totpService built at step 3b (WC-95) so setup/confirm and login share one key.
$dbWrapper = new \Whity\Auth\DatabaseQueryWrapper($db->getPdo());
$backupCodesService = new BackupCodesService($dbWrapper);
$tokenValidator = new TokenValidator($jwtParser, $db->getPdo());

// 10a-bis. Device (native-client) enrollment + credential exchange (WC-b-device-tokens).
// Management endpoints (register/list/revoke) are session-gated in-handler (cookie
// OR Bearer access token) and scoped to the caller's own profile — NOT public. The
// exchange endpoint IS public: it self-authenticates via the device credential
// (like the MCP bearer surface) and is added to PUBLIC_ROUTES as /api/v1/devices/token.
$deviceService = new \Whity\Auth\DeviceCredentialService($db->getPdo(), $jwtParser, $settingsService);
$deviceHandler = new \Whity\Api\DeviceApiHandler($tokenValidator, $deviceService);
$router->register('POST',   '/api/devices',       [$deviceHandler, 'register'], null);
$router->register('GET',    '/api/devices',       [$deviceHandler, 'list'], null);
$router->register('DELETE', '/api/devices/{id}',  [$deviceHandler, 'revoke'], null);
$router->register('POST',   '/api/devices/token', [$authHandler, 'handleDeviceTokenExchange'], null);

// 10a-ter. Interactive session management (WC-f-sessions-table). Session-gated
// in-handler (cookie OR Bearer access token), scoped to the caller's own
// profile. Interactive logins only — native devices are managed via /api/devices.
$sessionsHandler = new \Whity\Api\SessionsApiHandler($tokenValidator, new \Whity\Auth\SessionService($db->getPdo()));
$router->register('GET',    '/api/me/sessions',      [$sessionsHandler, 'list'], null);
$router->register('DELETE', '/api/me/sessions/{id}', [$sessionsHandler, 'revoke'], null);
$router->register('DELETE', '/api/me/sessions',      [$sessionsHandler, 'revokeOthers'], null);

$twoFactorHandler = new TwoFactorHandler($db->getPdo(), $totpService, $backupCodesService, $tokenValidator, $auditLogger);
$router->register('POST', '/api/auth/2fa/setup', [$twoFactorHandler, 'setup'], null);
$router->register('POST', '/api/auth/2fa/confirm', [$twoFactorHandler, 'confirm'], null);
$router->register('POST', '/api/auth/2fa/disable', [$twoFactorHandler, 'disable'], null);
$router->register('POST', '/api/auth/2fa/regenerate-codes', [$twoFactorHandler, 'regenerateCodes'], null);
$router->register('GET', '/api/auth/2fa/status', [$twoFactorHandler, 'status'], null);

// 10c. Forgotten-password + "lost my 2FA device" recovery
// (WC-password-reset-2fa-recovery). Public, unauthenticated, rate-limited
// endpoints (mirroring the WC-235 email-verification wiring above) plus the
// tenant-scoped admin approval queues.
$passwordResetService = new \Whity\Core\Identity\PasswordResetService($db->getPdo());
$resetUrlBase = (string) ($_ENV['PASSWORD_RESET_URL'] ?? getenv('PASSWORD_RESET_URL')
    ?: (rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/') . '/reset-password'));
$passwordResetMailer = new \Whity\Core\Identity\PasswordResetMailer(
    $mailer,
    $resetUrlBase,
    new \Whity\Core\Mail\EmailLayout(),
    $settingsService
);
$passwordResetHandler = new \Whity\Api\PasswordResetHandler(
    $passwordResetService,
    $profileEmailRepository,
    $passwordResetMailer,
    new DatabaseSharedStore($db->getPdo()),
    $auditLogger,
    $settingsService
);
$router->register('POST', '/api/auth/password/forgot', [$passwordResetHandler, 'forgot'], null);
$router->register('POST', '/api/auth/password/reset', [$passwordResetHandler, 'reset'], null);

$passwordResetApprovalsHandler = new \Whity\Api\PasswordResetApprovalsApiHandler(
    $passwordResetService,
    $roleChecker,
    $auditLogger,
    $passwordResetMailer
);
$router->register('GET',  '/api/password-resets/pending',      [$passwordResetApprovalsHandler, 'listPending'], null, null, CorePermissions::PASSWORD_RESETS_APPROVE);
$router->register('POST', '/api/password-resets/{id:\d+}/approve', [$passwordResetApprovalsHandler, 'approve'], null, null, CorePermissions::PASSWORD_RESETS_APPROVE);
$router->register('POST', '/api/password-resets/{id:\d+}/reject',  [$passwordResetApprovalsHandler, 'reject'],  null, null, CorePermissions::PASSWORD_RESETS_APPROVE);

// WC-797: the administrator-facing half of the same domain. "Send this user a
// reset link" reuses the service and mailer built above rather than adding a
// second way to change someone's password; the coverage endpoint answers
// "would this change leave the tenant with nobody able to approve a reset?".
// Coverage is gated on USERS_READ, not PASSWORD_RESETS_APPROVE — the whole point
// is to warn an administrator who is NOT an approver.
$adminPasswordResetHandler = new \Whity\Api\AdminPasswordResetApiHandler(
    $db->getPdo(),
    $passwordResetService,
    $passwordResetMailer,
    $profileEmailRepository,
    $auditLogger,
    $settingsService,
    $roleChecker
);
$router->register('POST', '/api/users/{id:\d+}/password-reset',      [$adminPasswordResetHandler, 'sendResetLink'],     null, null, CorePermissions::USERS_WRITE);
$router->register('GET',  '/api/password-resets/approver-coverage',  [$adminPasswordResetHandler, 'approverCoverage'], null, null, CorePermissions::USERS_READ);

$twoFactorRecoveryService = new \Whity\Core\Identity\TwoFactorRecoveryService(
    $db->getPdo(),
    $passwordResetService,
    $backupCodesService
);
$twoFactorRecoveryConfirmUrlBase = (string) ($_ENV['TWO_FACTOR_RECOVERY_URL'] ?? getenv('TWO_FACTOR_RECOVERY_URL')
    ?: (rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/') . '/account-recovery'));
$twoFactorRecoveryMailer = new \Whity\Core\Identity\TwoFactorRecoveryMailer(
    $mailer,
    $twoFactorRecoveryConfirmUrlBase,
    new \Whity\Core\Mail\EmailLayout(),
    $settingsService
);
$twoFactorRecoveryHandler = new \Whity\Api\TwoFactorRecoveryHandler(
    $twoFactorRecoveryService,
    $profileEmailRepository,
    $twoFactorRecoveryMailer,
    new DatabaseSharedStore($db->getPdo()),
    $auditLogger,
    $settingsService
);
$router->register('POST', '/api/auth/2fa-recovery/request', [$twoFactorRecoveryHandler, 'request'], null);
$router->register('POST', '/api/auth/2fa-recovery/confirm', [$twoFactorRecoveryHandler, 'confirm'], null);

$twoFactorRecoveryApprovalsHandler = new \Whity\Api\TwoFactorRecoveryApprovalsApiHandler(
    $twoFactorRecoveryService,
    $roleChecker,
    $auditLogger,
    $passwordResetMailer
);
$router->register('GET',  '/api/2fa-recovery/pending',           [$twoFactorRecoveryApprovalsHandler, 'listPending'], null, null, CorePermissions::TWO_FACTOR_RECOVERY_APPROVE);
$router->register('POST', '/api/2fa-recovery/{id:\d+}/approve',  [$twoFactorRecoveryApprovalsHandler, 'approve'],     null, null, CorePermissions::TWO_FACTOR_RECOVERY_APPROVE);
$router->register('POST', '/api/2fa-recovery/{id:\d+}/reject',   [$twoFactorRecoveryApprovalsHandler, 'reject'],      null, null, CorePermissions::TWO_FACTOR_RECOVERY_APPROVE);
// Secondary fallback (no prior request): an admin forces the same primitive
// directly onto a named profile, e.g. when the locked-out user cannot even
// receive email and reaches an admin out-of-band.
$router->register('POST', '/api/2fa-recovery/force-reset',       [$twoFactorRecoveryApprovalsHandler, 'forceReset'],  null, null, CorePermissions::TWO_FACTOR_RECOVERY_APPROVE);

// 10d. Tenant invitations (WHIT-417 / #797 item 3) — how a tenant administrator
// onboards somebody without an operator typing a password. The admin surface is
// tenant-scoped and gated on the SAME users:read/users:write a role needs to add
// a user by hand; the accept pair is public and unauthenticated, because the
// invitee has no session and may have no account at all.
$invitationService = new \Whity\Core\Identity\InvitationService(
    $db->getPdo(),
    new \Whity\Core\Identity\ProfileProvisioner($db->getPdo()),
    // Accepting an invitation is how most people GET a role here; without this
    // the trail recorded the invitation and never the membership it produced (#889).
    $hookManager
);
$invitationUrlBase = (string) ($_ENV['INVITATION_ACCEPT_URL'] ?? getenv('INVITATION_ACCEPT_URL')
    ?: (rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/') . '/accept-invitation'));
$invitationMailer = new \Whity\Core\Identity\InvitationMailer(
    $mailer,
    $invitationUrlBase,
    new \Whity\Core\Mail\EmailLayout(),
    $settingsService
);
$invitationsHandler = new \Whity\Api\InvitationsApiHandler(
    $db->getPdo(),
    $invitationService,
    $roleChecker,
    $auditLogger,
    new DatabaseSharedStore($db->getPdo()),
    $settingsService,
    $invitationMailer
);
$invitationAcceptHandler = new \Whity\Api\InvitationAcceptHandler(
    $invitationService,
    new DatabaseSharedStore($db->getPdo()),
    $auditLogger
);
// The public pair is registered FIRST so `/invitations/accept` can never be
// shadowed by a future `/invitations/{something}` route.
$router->register('GET',  '/api/invitations/accept', [$invitationAcceptHandler, 'preview'], null);
$router->register('POST', '/api/invitations/accept', [$invitationAcceptHandler, 'accept'], null);
$router->register('GET',    '/api/invitations',                  [$invitationsHandler, 'list'],   null, null, CorePermissions::USERS_READ);
$router->register('POST',   '/api/invitations',                  [$invitationsHandler, 'create'], null, null, CorePermissions::USERS_WRITE);
$router->register('POST',   '/api/invitations/{id:\d+}/resend',  [$invitationsHandler, 'resend'], null, null, CorePermissions::USERS_WRITE);
$router->register('DELETE', '/api/invitations/{id:\d+}',         [$invitationsHandler, 'revoke'], null, null, CorePermissions::USERS_WRITE);

// 11. Register API handlers
$usersHandler = new UsersApiHandler($db->getPdo(), $hookManager);
// WC-203: gate users routes on fine-grained permission grants instead of the
// bare 'admin' role. requiredRole is cleared (null) so the check is driven
// entirely by requiredPermission; migration 022 grants all three to admin.
$router->register('GET',    '/api/users',           [$usersHandler, 'list'],   null, null, CorePermissions::USERS_READ);
// #882: read ONE user. The handler has had this method since the identity
// cutover but no route reached it, so every surface that wanted one person had
// to fetch the list and search it — which caps at the page size and answers
// "who is profile 412?" with silence once a tenant passes 100 people. A record
// page is addressable by definition (a pasted URL must work), so it needs the
// single-record read rather than a filtered list.
$router->register('GET',    '/api/users/{id:\d+}',  [$usersHandler, 'get'],    null, null, CorePermissions::USERS_READ);
$router->register('POST',   '/api/users',           [$usersHandler, 'create'], null, null, CorePermissions::USERS_WRITE);
$router->register('PATCH',  '/api/users/{id:\d+}',  [$usersHandler, 'update'], null, null, CorePermissions::USERS_WRITE);
$router->register('DELETE', '/api/users/{id:\d+}',  [$usersHandler, 'delete'], null, null, CorePermissions::USERS_DELETE);

// WC-712 §1: a profile may hold more than one role in a tenant (migration 094).
// The user LIST shows one row per person with their PRIMARY role, so these are
// where an additional role is seen, granted and revoked. Same permission gates
// as the user routes above — granting a role is a user write.
$router->register('GET',    '/api/users/{id:\d+}/memberships',                      [$usersHandler, 'listMemberships'],  null, null, CorePermissions::USERS_READ);
$router->register('POST',   '/api/users/{id:\d+}/memberships',                      [$usersHandler, 'addMembership'],    null, null, CorePermissions::USERS_WRITE);
$router->register('DELETE', '/api/users/{id:\d+}/memberships/{membershipId:\d+}',   [$usersHandler, 'removeMembership'], null, null, CorePermissions::USERS_WRITE);

$rolesHandler = new RolesApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/roles', [$rolesHandler, 'list'], 'admin');
$router->register('POST', '/api/roles', [$rolesHandler, 'create'], 'admin');
$router->register('GET', '/api/roles/{id:\d+}', [$rolesHandler, 'get'], 'admin');
$router->register('PATCH', '/api/roles/{id:\d+}', [$rolesHandler, 'update'], 'admin');
$router->register('DELETE', '/api/roles/{id:\d+}', [$rolesHandler, 'delete'], 'admin');
$router->register('GET', '/api/roles/{id:\d+}/permissions', [$rolesHandler, 'getPermissions'], 'admin');
// #712: additive/subtractive grants, so concurrent admins editing one role stop
// clobbering each other through the read-modify-write PATCH forces on them.
$router->register('POST', '/api/roles/{id:\d+}/permissions', [$rolesHandler, 'grantPermissions'], 'admin');
$router->register('DELETE', '/api/roles/{id:\d+}/permissions', [$rolesHandler, 'revokePermissions'], 'admin');
// #882: who holds this role, newest grant first — the record page's headcount
// and its recent-assignment list in one request (the count is the pagination
// total). Same 'admin' gate as its siblings: a new permission slug would ship a
// grant migration reaching only the seeded admin role (#834).
$router->register('GET', '/api/roles/{id:\d+}/assignments', [$rolesHandler, 'assignments'], 'admin');

$tenantsHandler = new TenantsApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/tenants', [$tenantsHandler, 'list'], 'admin');
$router->register('POST', '/api/tenants', [$tenantsHandler, 'create'], 'admin');
$router->register('PATCH', '/api/tenants/{id:\d+}', [$tenantsHandler, 'update'], 'admin');
$router->register('DELETE', '/api/tenants/{id:\d+}', [$tenantsHandler, 'delete'], 'admin');

$permissionsHandler = new PermissionsApiHandler($db->getPdo());
$router->register('GET', '/api/permissions', [$permissionsHandler, 'list'], 'admin');

// Navigation menu items (WC-175, #191). Registered with NO required
// role/permission — any authenticated caller may ask which menu items they may
// see — but the handler fails closed itself (unresolved tenant or missing user
// => 403) and filters every item per caller against RoleChecker server-side,
// mirroring /api/frontend/features. Pass the delegation-aware $roleChecker so a
// live delegation actually unlocks gated items.
$navigationHandler = new NavigationApiHandler($hookManager, $roleChecker);
$router->register('GET', '/api/navigation', [$navigationHandler, 'list']);

// Caller capabilities (WC-176, #205). Registered with NO required
// role/permission — any authenticated caller may ask which permissions they
// hold — but the handler fails closed itself (unresolved tenant or missing
// user => 403), mirroring /api/navigation and /api/frontend/features. It is
// NOT a public route (see EnforceTenantIsolation::PUBLIC_ROUTES): unlike
// /api/me (which answers from JWT claims alone), it needs a RESOLVED tenant for
// RoleChecker. Pass the SAME delegation-aware $roleChecker the siblings use so
// the returned set includes live delegated permissions. The exact-path router
// keeps this distinct from /api/me (no prefix collision).
$meCapabilitiesHandler = new MeCapabilitiesApiHandler($roleChecker);
$router->register('GET', '/api/me/capabilities', [$meCapabilitiesHandler, 'list']);

// Batch permitted-action resolution (#868). The server half of the `inbox`
// block type: given the concrete {method, path} requests a screen is about to
// render affordances for, answer which ones this caller may actually make.
//
// Registered with NO required role/permission for the same reason as
// /api/me/capabilities directly above — any authenticated caller may ask about
// their OWN authority — and the handler fails closed itself (unresolved tenant
// or missing user => 403). Profile and tenant come from the resolved request,
// never the body, so it cannot be used to probe another user's authority.
//
// It is handed the SAME $router the kernel dispatches through, deliberately:
// the answer is derived from the live route table's RBAC descriptors and the
// same $roleChecker RbacMiddleware enforces with, so "what the user is shown"
// and "what the middleware admits" are one computation, not two that agree.
$permittedActionsHandler = new PermittedActionsApiHandler($roleChecker, $router);
$router->register('POST', '/api/me/permitted-actions', [$permittedActionsHandler, 'resolve']);

// Plugin frontend feature descriptors (WC-169). Registered with NO required
// role/permission — any authenticated caller may ask which screens they may
// see — but the handler fails closed itself (unresolved tenant or missing
// user => 403) and filters every descriptor per caller against RoleChecker
// server-side. Descriptors are UI metadata only; the underlying plugin API
// routes keep their own route-level RBAC.
// WC-175 (#199): the handler also reads $router to compute each feature's
// per-caller write capabilities (canCreate/canEdit/canDelete) server-side from
// the resource's registered routes' RBAC, so the renderer can hide controls the
// caller may not use.
// WC-226: pass $logger so a plugin's `screen:'blocks'` feature whose block tree
// fails host validation is dropped fail-closed with a structured, secret-free
// reason (feature id + validator errors) — never leaked to the client.
$frontendFeaturesHandler = new FrontendFeaturesApiHandler($pluginLoader, $roleChecker, $router, $logger);
$router->register('GET', '/api/frontend/features', [$frontendFeaturesHandler, 'list'], null);

// Health monitoring endpoint (WC-4). Registered UNVERSIONED so load-balancer
// probes that target GET /api/health never break regardless of the API version.
// No required role/permission (fail-open); listed as a PUBLIC route in
// EnforceTenantIsolation so it bypasses tenant resolution too — the probe must
// answer without a JWT or tenant context. The handler is kept dependency-light
// (only the DB wrapper) so health stays meaningful when other subsystems are down.
// $bootTimestamp drives the reported worker uptime.
$healthHandler = new HealthApiHandler($db, $bootTimestamp);
$router->registerUnversioned('GET', '/api/health', [$healthHandler, 'handle']);

// WC-status-page: the public service-status surface behind /status. Registered
// versioned (bare path — the router prepends /v1) and, like /api/health,
// deliberately unauthenticated: the people who most need to know whether the
// service is up are the ones who cannot sign in. It only READS the
// health_samples time series written by `health:watch`, so it stays cheap and
// cannot add load during an incident.
// The probe catalogue is passed so a PLUGIN-contributed component appears on the
// page as its own card rather than being collected into health_samples and then
// never rendered — the registry instance is shared and already populated by the
// plugin loader above.
$statusHandler = new \Whity\Api\StatusApiHandler(
    new \Whity\Core\Health\StatusReport(
        new \Whity\Core\Health\HealthSampleRepository($db->getPdo()),
        $healthProbeRegistry
    )
);
$router->register('GET', '/api/status', [$statusHandler, 'get'], null);

// WC-206: unversioned version-discovery endpoint. Returns the current API
// version, the full supported set, and the default. No auth required — it is a
// public metadata probe analogous to /api/health.
$router->registerUnversioned('GET', '/api/version', static function () use ($router): \Whity\Core\Response {
    $prefix = ltrim($router->getVersionPrefix(), '/'); // 'v1'
    $version = ltrim($prefix, 'v');                    // '1'
    return new \Whity\Core\Response(
        200,
        (string) json_encode([
            'version'   => $version,
            'supported' => [$version],
            'default'   => $version,
        ], JSON_THROW_ON_ERROR),
        ['Content-Type' => 'application/json']
    );
});

// WHIT-587: platform version state for operators without a shell. The running
// core + plugin-SDK versions, and the latest-release comparison that until now
// only `update:check` could answer. Both routes are settings:manage AND system
// tenant (the second half enforced in the handler) — this describes the whole
// deployment, which on a shared install is none of a tenant admin's business.
// Read-only on purpose: applying an update stays the manual runbook
// (docs/wiki/Core-Update.md), because "apply" cannot mean the same thing for a
// source checkout as for an immutable container image.
$platformVersionHandler = new \Whity\Api\PlatformVersionApiHandler(
    $roleChecker,
    new \Whity\Core\Update\LatestReleaseCheck()
);
$router->register('GET', '/api/platform/version',        [$platformVersionHandler, 'version'], null, null, CorePermissions::SETTINGS_MANAGE);
// Separate route because it reaches out to the release stream: the local
// snapshot above must stay instant and offline-safe.
$router->register('GET', '/api/platform/version/latest', [$platformVersionHandler, 'latest'],  null, null, CorePermissions::SETTINGS_MANAGE);

// WC-209: dynamic OpenAPI document. Regenerates the spec from the LIVE router
// at request time, so a plugin installed/uninstalled/reloaded after the last
// manual `generate:openapi` is immediately reflected — the schema-driven plugin
// CRUD UI fetches this instead of the (core-only) committed static file. The
// handler reads $router/$pluginLoader at dispatch time, so plugins loaded below
// (and any runtime reload) are always included. Registered UNVERSIONED and
// PUBLIC (no auth; listed in EnforceTenantIsolation::PUBLIC_ROUTES), matching
// how the static /openapi.json is already served unauthenticated by Caddy — it
// exposes only route shapes (method/path/schema), never any tenant data.
$openApiHandler = new OpenApiHandler($router, $pluginLoader);
$router->registerUnversioned('GET', '/api/openapi.json', [$openApiHandler, 'handle']);

$deploymentHandler = new DeploymentApiHandler($deploymentManager);
$router->register('POST', '/api/deployments/apply', [$deploymentHandler, 'apply'], 'admin');
$router->register('POST', '/api/deployments/rollback', [$deploymentHandler, 'rollback'], 'admin');
$router->register('GET', '/api/deployments/status', [$deploymentHandler, 'status'], 'admin');

// Plugins admin API (WC-9/PR #88, WC-10/PR #104). Pass the live $pluginLoader so
// list/enable/disable use the WC-9 lifecycle at runtime. WC-218: each route is
// gated by its OWN per-action plugin permission (6th positional arg to
// Router::register; 4th arg requiredRole stays null so RbacMiddleware enforces
// the permission). enable and re-enable share PLUGINS_ENABLE; the rest are 1:1.
// WC-208: pass the PDO so the orchestrated uninstall (disable → migration
// rollback → directory removal) has a DB connection for tracking-row cleanup.
// WC-220: pass $auditLogger so staged uploads (plugin.upload) and enable
// (plugin.enable / plugin.enable.migrate_failed) emit secret-free audit rows.
$pluginsHandler = new PluginsApiHandler(__DIR__ . '/../plugins', $pluginLoader, $db->getPdo(), $auditLogger);
$router->register('GET', '/api/plugins', [$pluginsHandler, 'list'], null, null, CorePermissions::PLUGINS_READ);
// WC-220: staged plugin upload/install. Multipart field name "package"; the
// installer lands the artifact DISABLED and migration-on-enable applies its
// migrations on the subsequent enable.
$router->register('POST', '/api/plugins/upload', [$pluginsHandler, 'upload'], null, null, CorePermissions::PLUGINS_UPLOAD);
$router->register('POST', '/api/plugins/{name}/enable', [$pluginsHandler, 'enable'], null, null, CorePermissions::PLUGINS_ENABLE);
$router->register('POST', '/api/plugins/{name}/disable', [$pluginsHandler, 'disable'], null, null, CorePermissions::PLUGINS_DISABLE);
$router->register('POST', '/api/plugins/{id}/re-enable', [$pluginsHandler, 'reEnable'], null, null, CorePermissions::PLUGINS_ENABLE);
$router->register('POST', '/api/plugins/{id}/uninstall', [$pluginsHandler, 'uninstall'], null, null, CorePermissions::PLUGINS_UNINSTALL);
$router->register('POST', '/api/plugins/reload', [$pluginsHandler, 'reload'], null, null, CorePermissions::PLUGINS_RELOAD);
// WC-install-from-store: fetch a package from a TRUSTED plugin store (host must
// be on the operator `plugins.store_allowed_hosts` allowlist; empty ⇒ disabled)
// and stage it through the SAME hardened installer as an upload (lands DISABLED).
// Same permission as upload — it is the same install action, remotely sourced.
$installFromStoreHandler = new \Whity\Api\InstallFromStoreApiHandler(
    __DIR__ . '/../plugins',
    $settingsService,
    $pluginLoader,
    $auditLogger
);
$router->register('POST', '/api/plugins/install-from-store', [$installFromStoreHandler, 'install'], null, null, CorePermissions::PLUGINS_UPLOAD);
// Store BROWSE surface for the admin UI (read-only, allowlist-gated proxy to a
// trusted store's public catalogue). Gated on PLUGINS_READ — browsing needs no
// more than the plugins list itself; installing still needs PLUGINS_UPLOAD.
$router->register('GET', '/api/plugins/store/allowed', [$installFromStoreHandler, 'allowedStores'], null, null, CorePermissions::PLUGINS_READ);
$router->register('GET', '/api/plugins/store/catalog', [$installFromStoreHandler, 'browseCatalog'], null, null, CorePermissions::PLUGINS_READ);

// Desktop plugin release catalog/download (WC-desktop-plugins). Consumed by an
// already-enrolled desktop device using the SAME bearer access token it uses
// for every other authenticated call (issued by POST /api/v1/devices/token) —
// no new auth mechanism, just the standard RBAC route pipeline gated on the
// distinct DESKTOP_PLUGINS_READ permission (a different trust boundary than
// PLUGINS_READ, which is the server's own installed-plugin list). Global
// catalog in v1 (no tenant scoping); per-tenant entitlement is a deferred
// follow-up.
$desktopPluginsHandler = new \Whity\Api\DesktopPluginsApiHandler(__DIR__ . '/../storage/desktop-plugins', $db->getPdo());
$router->register('GET', '/api/desktop-plugins', [$desktopPluginsHandler, 'catalog'], null, null, CorePermissions::DESKTOP_PLUGINS_READ);
$router->register('GET', '/api/desktop-plugins/{name}/versions/{version}/download', [$desktopPluginsHandler, 'download'], null, null, CorePermissions::DESKTOP_PLUGINS_READ);

// Desktop app self-update manifest (WC-app-self-update). Same authenticated-
// endpoint posture as the desktop-plugins routes above (device bearer token,
// a dedicated permission) rather than a public unauthenticated manifest —
// consistent with this instance's existing trust model for this exact
// client. Checked BEFORE plugin sync on the desktop side, since a plugin
// package assumes a compatible app runtime.
$desktopAppUpdateHandler = new \Whity\Api\DesktopAppUpdateApiHandler($db->getPdo());
$router->register('GET', '/api/desktop-app-updates/latest', [$desktopAppUpdateHandler, 'latest'], null, null, CorePermissions::DESKTOP_APP_UPDATES_READ);

$migrationsHandler = new MigrationsApiHandler($db, __DIR__ . '/../database/migrations');
// Only allow read-only access to migration status via API
// Mutations (run/rollback) are performed via CLI only for security
$router->register('GET', '/api/migrations', [$migrationsHandler, 'list'], 'admin');

$adminHandler = new AdminApiHandler($db, __DIR__ . '/../database/migrations');
$router->register('GET', '/api/admin/stats', [$adminHandler, 'stats'], 'admin');

// 12. Register OUs API handler. Gated on the seeded ous:* PERMISSIONS (6th
// positional arg; requiredRole stays null so RbacMiddleware enforces the
// permission alone). The bare `admin` role gate these routes used to carry left
// a downstream plugin that aliases OU management with no slug to reuse: it had
// to mirror the role or invent its own, and an invented slug would let a caller
// holding only that plugin's permissions mutate platform-wide OUs while holding
// no OU permission at all. The permission is deliberately the WHOLE gate —
// keeping the role alongside it would make core stricter than any plugin
// aliasing the same slug, which is that same hazard in mirror image.
//
// ous:create/ous:update are seeded by migration 005 but are absent from
// CorePermissions, so the registry does not know them and RoleChecker refuses
// them outright; ous:write is the create/update slug the admin UI's capability
// check already uses. ous:assign gates the two routes that ASSIGN roles to an
// OU — verbatim what migration 005 seeded it for.
$ousHandler = new OusApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/ous', [$ousHandler, 'list'], null, null, CorePermissions::OUS_READ);
$router->register('POST', '/api/ous', [$ousHandler, 'create'], null, null, CorePermissions::OUS_WRITE);
$router->register('GET', '/api/ous/{id:\d+}', [$ousHandler, 'get'], null, null, CorePermissions::OUS_READ);
$router->register('PATCH', '/api/ous/{id:\d+}', [$ousHandler, 'update'], null, null, CorePermissions::OUS_WRITE);
$router->register('DELETE', '/api/ous/{id:\d+}', [$ousHandler, 'delete'], null, null, CorePermissions::OUS_DELETE);
$router->register('GET', '/api/ous/{id:\d+}/roles', [$ousHandler, 'roles'], null, null, CorePermissions::OUS_READ);
$router->register('GET', '/api/ous/{id:\d+}/members', [$ousHandler, 'members'], null, null, CorePermissions::OUS_READ);
$router->register('POST', '/api/ous/{id:\d+}/roles', [$ousHandler, 'assignRole'], null, null, CorePermissions::OUS_ASSIGN);
$router->register('DELETE', '/api/ous/{ouId:\d+}/roles/{roleId:\d+}', [$ousHandler, 'removeRole'], null, null, CorePermissions::OUS_ASSIGN);

// 12a. Register the OU TYPE vocabulary API (#822) — the campus/faculty/department
// levels a tenant's tree is built from, so a consumer can ask for "every faculty"
// instead of "every unit at depth 1" (which returns a different kind of thing on
// every installation, and changes the moment somebody inserts a parent above an
// existing unit).
//
// Gated on the SAME ous:* permissions as the routes above, deliberately, and not
// on a new `ou_types:*` pair. A new permission ships with a grant migration that
// can only reach the seeded `admin` role, so every operator running a custom
// administrative role would silently lose the capability on upgrade and discover
// it as a 403 — #834 is that exact failure, here, already having happened once.
// DELETE takes ous:write rather than ous:delete because it destroys no unit: its
// forced path is an UPDATE that sets ou_type_id = NULL.
$ouTypesHandler = new OuTypesApiHandler(
    new \Whity\Core\Ou\OuTypeRepository($db->getPdo()),
    $ouTypeRegistry
);
$router->register('GET', '/api/ou-types', [$ouTypesHandler, 'list'], null, null, CorePermissions::OUS_READ);
$router->register('GET', '/api/ou-types/catalog', [$ouTypesHandler, 'catalog'], null, null, CorePermissions::OUS_READ);
$router->register('POST', '/api/ou-types', [$ouTypesHandler, 'create'], null, null, CorePermissions::OUS_WRITE);
$router->register('GET', '/api/ou-types/{id:\d+}', [$ouTypesHandler, 'get'], null, null, CorePermissions::OUS_READ);
$router->register('PATCH', '/api/ou-types/{id:\d+}', [$ouTypesHandler, 'update'], null, null, CorePermissions::OUS_WRITE);
$router->register('DELETE', '/api/ou-types/{id:\d+}', [$ouTypesHandler, 'delete'], null, null, CorePermissions::OUS_WRITE);

// 12b. Register permission delegations API handler (WC-34). Gated on the
// delegation:manage permission (6th positional arg; requiredRole stays null so
// RbacMiddleware enforces the permission). The runtime subset-of-own-permissions
// invariant is enforced independently inside DelegationService.
$delegationsHandler = new DelegationsApiHandler($db->getPdo(), $delegationService, $logger);
$router->register('GET', '/api/delegations', [$delegationsHandler, 'list'], null, null, CorePermissions::DELEGATION_MANAGE);
$router->register('POST', '/api/delegations', [$delegationsHandler, 'create'], null, null, CorePermissions::DELEGATION_MANAGE);
$router->register('DELETE', '/api/delegations/{id:\d+}', [$delegationsHandler, 'revoke'], null, null, CorePermissions::DELEGATION_MANAGE);

// 13. Register the audit-log read API (WC-34). Gated on the audit:read permission
// (6th positional arg; requiredRole stays null so RbacMiddleware enforces the
// permission). Tenant-scoped in the handler: the SYSTEM tenant (id 0) sees all
// tenants, every other tenant sees only its own entries.
$auditLogHandler = new AuditLogApiHandler($db->getPdo(), $roleChecker);
$router->register('GET', '/api/audit-logs', [$auditLogHandler, 'list'], null, null, CorePermissions::AUDIT_READ);

// 13a. Self-service analogue (no permission gate — every authenticated user
// may see their OWN activity; requiredPermission stays null and listOwn()
// pins actor_user_id to the caller itself, never a caller-supplied value).
$router->register('GET', '/api/me/audit-logs', [$auditLogHandler, 'listOwn'], null);

// 13b. Register the Website Settings API (global defaults + per-tenant
// overrides). Reads are gated on settings:read, current-tenant override writes
// on settings:write, and global-default reads/writes on settings:manage (6th
// positional arg; requiredRole stays null so RbacMiddleware enforces the
// permission). The handler issues NO SQL — all access goes through
// SettingsService and its repositories; the tenant always comes from
// TenantContext, so a caller can only edit its own tenant's overrides.
// ($settingsService is constructed once, earlier, near the register handler.)
$settingsHandler = new \Whity\Api\SettingsApiHandler($settingsService, $roleChecker);
$router->register('GET',   '/api/settings',        [$settingsHandler, 'get'],         null, null, CorePermissions::SETTINGS_READ);
$router->register('PATCH', '/api/settings',        [$settingsHandler, 'patch'],       null, null, CorePermissions::SETTINGS_WRITE);
$router->register('GET',   '/api/settings/global', [$settingsHandler, 'getGlobal'],   null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('PATCH', '/api/settings/global', [$settingsHandler, 'patchGlobal'], null, null, CorePermissions::SETTINGS_MANAGE);
// WC-tabs-nav-be: the settings console's tab bar, RBAC-filtered server-side
// per tab — no single required permission gates the route itself (a caller
// holding only auth_providers:manage must still see the SSO tab), mirroring
// /api/navigation's permission-free registration.
$router->register('GET', '/api/settings/tabs', [$settingsHandler, 'tabs']);

// WC-error-tracking: the built-in error inbox and its write-only DSN. Every
// route is operator-only (settings:manage + system tenant, enforced in the
// handler) because error tracking is deployment-wide — it captures errors from
// every tenant, and errors that belong to no tenant at all.
$errorsHandler = new \Whity\Api\ErrorsApiHandler(
    new \Whity\Core\Observability\ErrorGroupRepository($db->getPdo()),
    $globalSettingsRepository,
    $secretStore,
    $roleChecker
);
$router->register('GET',   '/api/errors',                      [$errorsHandler, 'list'],   null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('GET',   '/api/errors/{id:\d+}',             [$errorsHandler, 'get'],    null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('PATCH', '/api/errors/{id:\d+}',             [$errorsHandler, 'update'], null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('GET',   '/api/settings/error-tracking',     [$errorsHandler, 'status'], null, null, CorePermissions::SETTINGS_MANAGE);
// Write-only, like the SMTP password: the DSN is a credential and is never read back.
$router->register('PUT',   '/api/settings/error-tracking/dsn', [$errorsHandler, 'setDsn'], null, null, CorePermissions::SETTINGS_MANAGE);

// 12. Languages API (WC-i18n). i18n language management and user language preference.
// GET /api/v1/languages — public endpoint, returns list of available languages (no auth required).
// GET /api/v1/settings/language — authenticated, returns user's language preference.
// PATCH /api/v1/settings/language — authenticated, updates user's language preference.
// Language preference is stored per-profile (language_code column) and follows the user across
// all tenant memberships. NULL = use tenant default, explicit code = user's choice.
$languageRepository = new \Whity\Core\i18n\LanguageRepository($db->getPdo());
$translationRepository = new \Whity\Core\i18n\TranslationRepository($db->getPdo());
$languageRegistry = new \Whity\Core\i18n\LanguageRegistry(
    $languageRepository,
    $translationRepository,
    new \Whity\Core\Tenant\StaticTenantContextAdapter()
);
// A missing/unseeded languages table must not take the whole API down: the
// registry falls back to returning the key itself, so boot failure degrades
// translation only.
try {
    $languageRegistry->boot();
} catch (\Throwable $e) {
    error_log("[whity] LanguageRegistry boot failed (continuing untranslated): {$e->getMessage()}");
}

// Registered versioned (bare paths) so the router prepends /v1 itself —
// writing '/api/v1/...' here would double-prefix to '/api/v1/v1/...'.
// $settingsService (constructed earlier, near the register handler) supplies the
// `i18n.enabled` feature flag: with it off the handler reports the default
// language for everyone and refuses preference writes. See its class docblock.
$languagesHandler = new \Whity\Api\LanguagesApiHandler($db->getPdo(), $languageRegistry, $languageRepository, $roleChecker, $settingsService);
$router->register('GET',   '/api/languages',         [$languagesHandler, 'list'],          null);
$router->register('GET',   '/api/settings/language', [$languagesHandler, 'getLanguage'],   null);
$router->register('PATCH', '/api/settings/language', [$languagesHandler, 'patchLanguage'], null);
// WC-583: admin language management. languages:manage is necessary but not
// sufficient — the handler additionally requires the SYSTEM tenant (id 0),
// since languages carry no tenant_id column at all (see LanguagesApiHandler
// class docblock).
$router->register('POST',  '/api/languages',        [$languagesHandler, 'create'], null, null, CorePermissions::LANGUAGES_MANAGE);
$router->register('PATCH', '/api/languages/{id:\d+}', [$languagesHandler, 'update'], null, null, CorePermissions::LANGUAGES_MANAGE);
// Admin listing (id + disabled languages included) for the languages
// management page — the public list above deliberately omits both.
$router->register('GET', '/api/admin/languages', [$languagesHandler, 'adminList'], null, null, CorePermissions::LANGUAGES_MANAGE);

// Translations API handler — GET /api/translations/{language_code}/{domain} is
// a public endpoint for fetching translated strings before a session exists
// (the login screen needs its own language). WC-583 adds admin CRUD over
// individual translation rows, gated on translations:manage: GET /api/translations
// (raw rows for a language+domain, admin listing), POST (create), PATCH/DELETE
// /api/translations/{id} (update/delete) — tenant-scoped per the System-Tenant
// Context convention (see TranslationsApiHandler class docblock).
$translationsHandler = new \Whity\Api\TranslationsApiHandler(
    $languageRepository,
    $translationRepository,
    new \Whity\Core\Tenant\StaticTenantContextAdapter(),
    $roleChecker,
    $languageRegistry
);
// Registered BEFORE the two-segment public bundle route so a literal path can
// never be read as a {language_code} — the segment counts differ, but the
// ordering makes that independent of how the matcher is implemented.
$router->register('GET',    '/api/translations/coverage',   [$translationsHandler, 'coverage'],  null, null, CorePermissions::TRANSLATIONS_MANAGE);
$router->register('GET',    '/api/translations/{language_code}/{domain}', [$translationsHandler, 'getTranslations'], null);
$router->register('GET',    '/api/translations',            [$translationsHandler, 'adminList'], null, null, CorePermissions::TRANSLATIONS_MANAGE);
$router->register('POST',   '/api/translations',            [$translationsHandler, 'create'],    null, null, CorePermissions::TRANSLATIONS_MANAGE);
$router->register('PATCH',  '/api/translations/{id:\d+}',   [$translationsHandler, 'update'],    null, null, CorePermissions::TRANSLATIONS_MANAGE);
$router->register('DELETE', '/api/translations/{id:\d+}',   [$translationsHandler, 'delete'],    null, null, CorePermissions::TRANSLATIONS_MANAGE);

// First-run instance lifecycle (WC-instance-first-run). InstanceService reuses
// the already-constructed $globalSettingsRepository (the flag lives in a reserved
// app_settings key, NOT a registry setting). GET /instance/status is authenticated
// but unpermissioned (any signed-in caller reads it to drive onboarding routing);
// POST /instance/complete-setup is settings:manage AND system-tenant-only (enforced
// in the handler), mirroring the global-settings write surface.
$instanceHandler = new \Whity\Api\InstanceApiHandler(
    new \Whity\Core\Instance\InstanceService($globalSettingsRepository)
);
$router->register('GET',  '/api/instance/status',         [$instanceHandler, 'status'],        null, null, null);
$router->register('POST', '/api/instance/complete-setup', [$instanceHandler, 'completeSetup'], null, null, CorePermissions::SETTINGS_MANAGE);

// 13a-bis. Operator per-tenant entitlements API (WC-ent): the platform owner
// grants/limits a TARGET tenant's capabilities per subscription tier. Gated on
// entitlements:manage (RbacMiddleware) AND — enforced in the handler — the system
// tenant (id 0), so a regular tenant admin can never reach another tenant's
// entitlements. The target tenant comes from the path, never the body.
$entitlementService = new \Whity\Core\Entitlement\EntitlementService(
    new \Whity\Core\Entitlement\TenantEntitlementRepository($db->getPdo())
);
$tenantEntitlementsHandler = new \Whity\Api\TenantEntitlementsApiHandler(
    $db->getPdo(),
    $entitlementService,
    $roleChecker
);
$router->register('GET',   '/api/tenants/{id:\d+}/entitlements', [$tenantEntitlementsHandler, 'get'],   null, null, CorePermissions::ENTITLEMENTS_MANAGE);
$router->register('PATCH', '/api/tenants/{id:\d+}/entitlements', [$tenantEntitlementsHandler, 'patch'], null, null, CorePermissions::ENTITLEMENTS_MANAGE);

// 13a-ter. Per-tenant storage backend self-service (WC-storage): a tenant admin
// configures its OWN object-storage backend. Tenant-scoped via TenantContext and
// gated on storage:manage (RbacMiddleware); a WRITE additionally requires the
// storage.custom_backend entitlement (enforced in the handler), so the plan gate
// applies. The secret is encrypted at rest and never returned.
$tenantStorageConfigHandler = new \Whity\Api\TenantStorageConfigApiHandler(
    $db->getPdo(),
    $secretStore,
    $entitlementService
);
$router->register('GET',    '/api/storage-config', [$tenantStorageConfigHandler, 'get'],    null, null, CorePermissions::STORAGE_MANAGE);
$router->register('PUT',    '/api/storage-config', [$tenantStorageConfigHandler, 'put'],    null, null, CorePermissions::STORAGE_MANAGE);
$router->register('DELETE', '/api/storage-config', [$tenantStorageConfigHandler, 'delete'], null, null, CorePermissions::STORAGE_MANAGE);

// 13a-quinquies. Admin-enforced 2FA policy CRUD + status (WC-525 PR-3).
// Tenant-scoped via TenantContext and gated on security:manage. Reuses the
// SAME $twoFactorPolicyResolver instance AuthHandler's login-time enforcement
// consults (built above), so status() reports the exact deadlines login
// would actually apply.
$twoFactorPoliciesHandler = new \Whity\Api\TwoFactorPoliciesApiHandler(
    $db->getPdo(),
    $twoFactorPolicyResolver,
    $auditLogger
);
$router->register('GET',    '/api/2fa-policies',        [$twoFactorPoliciesHandler, 'list'],   null, null, CorePermissions::SECURITY_MANAGE);
$router->register('POST',   '/api/2fa-policies',        [$twoFactorPoliciesHandler, 'create'], null, null, CorePermissions::SECURITY_MANAGE);
$router->register('GET',    '/api/2fa-policies/status', [$twoFactorPoliciesHandler, 'status'], null, null, CorePermissions::SECURITY_MANAGE);
$router->register('PATCH',  '/api/2fa-policies/{id:\d+}', [$twoFactorPoliciesHandler, 'update'], null, null, CorePermissions::SECURITY_MANAGE);
$router->register('DELETE', '/api/2fa-policies/{id:\d+}', [$twoFactorPoliciesHandler, 'delete'], null, null, CorePermissions::SECURITY_MANAGE);

// 13a-quater. Operator subscription-plan admin API (WC-plans, ADR 0010): catalog
// CRUD of plans + their entitlement bundles, and applying a plan to a target
// tenant (which materialises the bundle into that tenant's entitlements). Gated
// on plans:manage (RbacMiddleware) AND — enforced in the handler — the system
// tenant (id 0), so a regular tenant admin can never touch the catalog or another
// tenant's plan.
$planService = new \Whity\Core\Plan\PlanService(
    new \Whity\Core\Plan\PlanRepository($db->getPdo()),
    $entitlementService,
    $db->getPdo()
);
$plansHandler = new \Whity\Api\PlansApiHandler($planService, $roleChecker, $db->getPdo());
$router->register('GET',    '/api/plans',                       [$plansHandler, 'list'],            null, null, CorePermissions::PLANS_MANAGE);
$router->register('POST',   '/api/plans',                       [$plansHandler, 'create'],          null, null, CorePermissions::PLANS_MANAGE);
$router->register('GET',    '/api/plans/{id:\d+}',              [$plansHandler, 'show'],            null, null, CorePermissions::PLANS_MANAGE);
$router->register('PATCH',  '/api/plans/{id:\d+}',              [$plansHandler, 'update'],          null, null, CorePermissions::PLANS_MANAGE);
$router->register('DELETE', '/api/plans/{id:\d+}',              [$plansHandler, 'destroy'],         null, null, CorePermissions::PLANS_MANAGE);
$router->register('PUT',    '/api/plans/{id:\d+}/entitlements', [$plansHandler, 'setEntitlements'], null, null, CorePermissions::PLANS_MANAGE);
$router->register('POST',   '/api/tenants/{id:\d+}/plan',       [$plansHandler, 'applyToTenant'],   null, null, CorePermissions::PLANS_MANAGE);
$router->register('GET',    '/api/tenants/{id:\d+}/plan',       [$plansHandler, 'getTenantPlan'],   null, null, CorePermissions::PLANS_MANAGE);

// 13a-quinquies. Subscription (billing-state) API (WC-billing). Operator routes
// (system-tenant gated) reflect an out-of-band payment into a tenant's state and
// apply a plan (materialising the tier's entitlements); the tenant-self GET is a
// read-only view, gated on settings:read and EXEMPT from the payment wall so a
// lapsed tenant can still see why it is blocked and reach billing.
$subscriptionHandler = new \Whity\Api\SubscriptionApiHandler(
    new \Whity\Core\Subscription\SubscriptionService(
        new \Whity\Core\Subscription\SubscriptionRepository($db->getPdo()),
        $settingsService
    ),
    $planService,
    $roleChecker,
    $db->getPdo()
);
$router->register('GET', '/api/tenants/{id:\d+}/subscription', [$subscriptionHandler, 'getForTenant'], null, null, CorePermissions::SUBSCRIPTIONS_MANAGE);
$router->register('PUT', '/api/tenants/{id:\d+}/subscription', [$subscriptionHandler, 'setForTenant'], null, null, CorePermissions::SUBSCRIPTIONS_MANAGE);
$router->register('GET', '/api/subscription',                  [$subscriptionHandler, 'getSelf'],      null, null, CorePermissions::SETTINGS_READ);

// 13a-storage. The platform STORAGE DRIVER, built once and shared.
//
// Selected from global instance settings (local by default; S3 when the operator
// sets storage.driver='s3' + the storage.s3.* config, secret from the
// STORAGE_S3_SECRET_KEY env). Wrapped in the per-tenant routing driver
// (WC-storage): a tenant that BOTH holds the storage.custom_backend entitlement
// AND has a tenant_storage_config row uses its own object-storage backend; every
// other tenant transparently uses the platform default. Routing keys off the
// tenant segment in the storage key (tenants/{id}/...), so it also works on the
// PUBLIC, context-less branding asset path.
//
// This USED to be constructed further down, immediately above the branding
// handler that was its only consumer. It moved up here (#947 item 1) because
// persisted documents are a second consumer and are registered above branding —
// PHP has no hoisting for these, so leaving it in place would have meant either
// a null driver at document-wiring time (a boot-time fatal on every request, not
// a test failure) or a SECOND driver built from the same settings, which is the
// split-backend hazard StorageDriverFactory's own docblock warns about. One
// driver, built before anything that needs it.
$storageRoot = getenv('STORAGE_ROOT') ?: (__DIR__ . '/../storage');
$defaultStorageDriver = \Whity\Storage\StorageDriverFactory::fromSettings($settingsService, $_ENV, $storageRoot);
$storageResolver = new \Whity\Storage\TenantStorageResolver(
    $defaultStorageDriver,
    new \Whity\Storage\TenantStorageConfigRepository($db->getPdo()),
    $entitlementService,
    $secretStore
);
$storageDriver = new \Whity\Storage\TenantRoutingStorageDriver($defaultStorageDriver, $storageResolver);

// 13a-sexies. Document/label designer templates API (WC-docdesigner). Tenant-
// scoped, RBAC-gated CRUD. The route permission (documents:read on GET,
// documents:write on writes) is the baseline; the handler ADDITIONALLY row-
// filters list/get by scope + required_permission (server-side, so a caller only
// receives templates it may see) and gates publishing on documents:publish.
$documentTemplateRepository = new \Whity\Core\Document\DocumentTemplateRepository($db->getPdo());
$documentAccessPolicy = new \Whity\Core\Document\DocumentAccessPolicy();
$documentTemplatesHandler = new \Whity\Api\DocumentTemplatesApiHandler(
    $documentTemplateRepository,
    $documentAccessPolicy,
    $roleChecker
);
$router->register('GET',    '/api/document-templates',          [$documentTemplatesHandler, 'list'],   null, null, CorePermissions::DOCUMENTS_READ);
$router->register('POST',   '/api/document-templates',          [$documentTemplatesHandler, 'create'], null, null, CorePermissions::DOCUMENTS_WRITE);
$router->register('GET',    '/api/document-templates/{id:\d+}', [$documentTemplatesHandler, 'show'],   null, null, CorePermissions::DOCUMENTS_READ);
$router->register('PATCH',  '/api/document-templates/{id:\d+}', [$documentTemplatesHandler, 'update'], null, null, CorePermissions::DOCUMENTS_WRITE);
$router->register('DELETE', '/api/document-templates/{id:\d+}', [$documentTemplatesHandler, 'delete'], null, null, CorePermissions::DOCUMENTS_WRITE);

// 13a-septies. Document/label designer BLOCKS API (WC-521) — mirrors the
// templates handler above exactly. The one thing beyond CRUD is the reference-
// integrity delete guard: a block is pointer-referenced by templates via a
// `blockInstance` element, so delete() 409s when DocumentTemplateRepository::
// referencesBlock() finds a live reference anywhere in the tenant's templates
// (never silently orphans the pointer).
$documentBlockRepository = new \Whity\Core\Document\DocumentBlockRepository($db->getPdo());
$documentBlocksHandler = new \Whity\Api\DocumentBlocksApiHandler(
    $documentBlockRepository,
    $documentTemplateRepository,
    $documentAccessPolicy,
    $roleChecker
);
$router->register('GET',    '/api/document-blocks',          [$documentBlocksHandler, 'list'],   null, null, CorePermissions::DOCUMENTS_READ);
$router->register('POST',   '/api/document-blocks',          [$documentBlocksHandler, 'create'], null, null, CorePermissions::DOCUMENTS_WRITE);
$router->register('GET',    '/api/document-blocks/{id:\d+}', [$documentBlocksHandler, 'show'],   null, null, CorePermissions::DOCUMENTS_READ);
$router->register('PATCH',  '/api/document-blocks/{id:\d+}', [$documentBlocksHandler, 'update'], null, null, CorePermissions::DOCUMENTS_WRITE);
$router->register('DELETE', '/api/document-blocks/{id:\d+}', [$documentBlocksHandler, 'delete'], null, null, CorePermissions::DOCUMENTS_WRITE);

// 13a-nonies. Server-side document/label render (ADR 0012 / WC-docdesigner
// Track 2): POST /api/document-templates/{id}/render calls out to the separate
// `whity_render` Docker service (headless Chromium + Puppeteer, opt-in `render`
// compose profile) over internal HTTP, streaming back a PDF. The handler checks
// the documents.render_enabled GLOBAL setting FIRST (default off — a heavyweight
// optional add-on) before ever attempting the call; batch limits are tenant-
// overridable settings, not hardcoded. RENDER_SERVICE_URL/RENDER_SHARED_SECRET
// are deployment config (like JWT_SECRET), not settings — an unset/short secret
// makes the client report itself unusable and every render 503s cleanly rather
// than calling out with no auth.
//
// #947 item 1 split the render MECHANICS out into DocumentRenderer (ceilings,
// dataRows normalisation, blockInstance resolution, the internal call) so the
// re-render route below runs the same code rather than a second copy of it, and
// added the optional `persist` flag that turns a render into a document record.
$documentRenderer = new \Whity\Core\Document\Render\DocumentRenderer(
    $documentBlockRepository,
    $settingsService,
    new \Whity\Core\Document\Render\RenderServiceClient(
        (string) ($_ENV['RENDER_SERVICE_URL'] ?? 'http://render:8130'),
        (string) ($_ENV['RENDER_SHARED_SECRET'] ?? ''),
        (int) ($_ENV['RENDER_TIMEOUT_SECONDS'] ?? 30)
    )
);
$documentRepository = new \Whity\Core\Document\DocumentRepository($db->getPdo());
$documentArtifactRepository = new \Whity\Core\Document\DocumentArtifactRepository($db->getPdo());
// The SAME per-tenant routing driver branding uses (built in 13a-storage above):
// an entitled tenant's documents land in its own bucket, everyone else's on the
// platform default, and there is exactly one storage story to keep correct.
$documentArtifactStore = new \Whity\Core\Document\DocumentArtifactStore($storageDriver);
$documentIssuer = new \Whity\Core\Document\DocumentIssuer(
    $db->getPdo(),
    $documentRepository,
    $documentArtifactRepository,
    $documentArtifactStore
);
$documentRenderHandler = new \Whity\Api\DocumentRenderApiHandler(
    $documentTemplateRepository,
    $documentAccessPolicy,
    $roleChecker,
    $settingsService,
    $documentRenderer,
    $documentIssuer
);
$router->register('POST', '/api/document-templates/{id:\d+}/render', [$documentRenderHandler, 'render'], null, null, CorePermissions::DOCUMENTS_RENDER);

// 13a-nonies-bis. ISSUED DOCUMENTS (#947 item 1) — the records a persisted
// render creates, and their immutable artifacts. Reads are gated on
// documents:read at the route and row-filtered by DocumentVisibilityPolicy on
// top (you raised it, or you hold documents:read:all); the re-render is gated on
// documents:render and APPENDS an artifact rather than replacing one, so an
// artifact URL handed out today still resolves to those bytes after a
// correction. Item 3 (routing) keys off documents.id; item 5 (the browser)
// derives its folders from these rows rather than from a stored tree, which is
// why there is no folder surface here.
$documentsHandler = new \Whity\Api\DocumentsApiHandler(
    $documentRepository,
    $documentArtifactRepository,
    $documentArtifactStore,
    new \Whity\Core\Document\DocumentVisibilityPolicy(),
    $documentTemplateRepository,
    $documentAccessPolicy,
    $documentRenderer,
    $documentIssuer,
    $roleChecker,
    $settingsService
);
$router->register('GET',  '/api/documents',                                            [$documentsHandler, 'list'],            null, null, CorePermissions::DOCUMENTS_READ);
$router->register('GET',  '/api/documents/{id:\d+}',                                   [$documentsHandler, 'show'],            null, null, CorePermissions::DOCUMENTS_READ);
$router->register('GET',  '/api/documents/{id:\d+}/content',                           [$documentsHandler, 'content'],         null, null, CorePermissions::DOCUMENTS_READ);
$router->register('GET',  '/api/documents/{id:\d+}/artifacts/{artifactId:\d+}/content', [$documentsHandler, 'artifactContent'], null, null, CorePermissions::DOCUMENTS_READ);
$router->register('POST', '/api/documents/{id:\d+}/render',                            [$documentsHandler, 'rerender'],        null, null, CorePermissions::DOCUMENTS_RENDER);

// 13a-octies. Per-tenant starter document/label seeding (WC-515 REMAINING #3):
// a brand-new tenant should never open the designer to an empty library. The
// SYNC 'tenant.created' hook (not '.async') is used deliberately — seeding
// must complete before the tenant-creation response returns, same as the
// AuditLogger audit-log write already subscribed to this same event just
// above (so a sync DB-writing listener on this hook is an established
// pattern, not a first use). Wrapped in its own try/catch AND
// DocumentStarterSeeder::seedForTenant() itself never throws (see its
// docblock) — a seeding failure must never turn a successful tenant creation
// into a 500 for the caller.
$documentStarterSeeder = new \Whity\Core\Document\DocumentStarterSeeder(
    $documentTemplateRepository,
    $documentBlockRepository,
    $logger
);
$hookManager->listen('tenant.created', function ($data, $context) use ($documentStarterSeeder) {
    $documentStarterSeeder->seedForTenant((int) $data['id'], (string) ($data['name'] ?? ''));
    return $data;
});

// 13b-ter. Native taxonomy/tagging API (WC-621): a domain-neutral tagging
// primitive. Tenant-scoped, RBAC-gated CRUD for tag groups + tags, plus a
// polymorphic tag<->entity association surface (entity_type is an opaque
// plugin-supplied string, so ANY resource is taggable). Reads require tags:read,
// writes require tags:manage; every query binds tenant_id.
$tagGroupRepository = new \Whity\Core\Taxonomy\TagGroupRepository($db->getPdo());
$tagRepository = new \Whity\Core\Taxonomy\TagRepository($db->getPdo());
$entityTagRepository = new \Whity\Core\Taxonomy\EntityTagRepository($db->getPdo());

$tagGroupsHandler = new \Whity\Api\TagGroupsApiHandler($tagGroupRepository, $roleChecker, $auditLogger);
$router->register('GET',    '/api/tag-groups',          [$tagGroupsHandler, 'list'],   null, null, CorePermissions::TAGS_READ);
$router->register('POST',   '/api/tag-groups',          [$tagGroupsHandler, 'create'], null, null, CorePermissions::TAGS_MANAGE);
$router->register('GET',    '/api/tag-groups/{id:\d+}', [$tagGroupsHandler, 'show'],   null, null, CorePermissions::TAGS_READ);
$router->register('PATCH',  '/api/tag-groups/{id:\d+}', [$tagGroupsHandler, 'update'], null, null, CorePermissions::TAGS_MANAGE);
$router->register('DELETE', '/api/tag-groups/{id:\d+}', [$tagGroupsHandler, 'delete'], null, null, CorePermissions::TAGS_MANAGE);

$tagsHandler = new \Whity\Api\TagsApiHandler($tagRepository, $tagGroupRepository, $roleChecker, $auditLogger);
$router->register('GET',    '/api/tags',          [$tagsHandler, 'list'],   null, null, CorePermissions::TAGS_READ);
$router->register('POST',   '/api/tags',          [$tagsHandler, 'create'], null, null, CorePermissions::TAGS_MANAGE);
$router->register('GET',    '/api/tags/{id:\d+}', [$tagsHandler, 'show'],   null, null, CorePermissions::TAGS_READ);
$router->register('PATCH',  '/api/tags/{id:\d+}', [$tagsHandler, 'update'], null, null, CorePermissions::TAGS_MANAGE);
$router->register('DELETE', '/api/tags/{id:\d+}', [$tagsHandler, 'delete'], null, null, CorePermissions::TAGS_MANAGE);

$entityTagsHandler = new \Whity\Api\EntityTagsApiHandler($entityTagRepository, $tagRepository, $roleChecker);
$router->register('GET',    '/api/entity-tags', [$entityTagsHandler, 'list'],   null, null, CorePermissions::TAGS_READ);
$router->register('POST',   '/api/entity-tags', [$entityTagsHandler, 'attach'], null, null, CorePermissions::TAGS_MANAGE);
$router->register('DELETE', '/api/entity-tags', [$entityTagsHandler, 'detach'], null, null, CorePermissions::TAGS_MANAGE);
// WC-714 §6: a plugin's record-delete cleanup hook. Distinct path so a
// malformed single-detach body can never degrade into "remove everything".
$router->register('DELETE', '/api/entity-tags/all', [$entityTagsHandler, 'detachAll'], null, null, CorePermissions::TAGS_MANAGE);

// 13b-quater. Resource-scoped role grants (WC-712 §3) — the WRITE path for
// `resource_role_assignments`.
//
// §2 shipped resolution but no way to create what it resolves, so a consumer
// could ask the platform "does this profile hold this role at this record?"
// while still having to store that authority in its own table — two sources of
// truth for one question, which is what resource-scoped grants exist to remove.
//
// Gated on the EXISTING roles:read / roles:manage rather than a new permission.
// A new one needs a grant migration, and such a migration reaches the `admin`
// role only: every operator running a custom administrative role would silently
// not have the feature.
$resourceRoleGrantsHandler = new \Whity\Api\ResourceRoleGrantsApiHandler(
    $db->getPdo(),
    new \Whity\Core\RBAC\ResourceRoleAssignmentRepository($db->getPdo(), $resourceTypeRegistry, $logger),
    $resourceTypeRegistry,
    $roleChecker,
    $hookManager
);
$router->register('GET',    '/api/resource-role-grants',          [$resourceRoleGrantsHandler, 'list'],   null, null, CorePermissions::ROLES_READ);
$router->register('POST',   '/api/resource-role-grants',          [$resourceRoleGrantsHandler, 'create'], null, null, CorePermissions::ROLES_MANAGE);
$router->register('DELETE', '/api/resource-role-grants/{id:\d+}', [$resourceRoleGrantsHandler, 'revoke'], null, null, CorePermissions::ROLES_MANAGE);
// WC-712 §4: the record-delete cleanup an owner runs when it deletes the record
// itself, mirroring DELETE /api/entity-tags/all. A distinct `/all` path (never
// `\d+`, so it cannot collide with the by-id revoke above) rather than an
// argument-shape variant, so a malformed single-revoke can never degrade into
// "remove everything".
$router->register('DELETE', '/api/resource-role-grants/all', [$resourceRoleGrantsHandler, 'revokeAll'], null, null, CorePermissions::ROLES_MANAGE);

// 13b-ter. Generated lifecycle surface for plugin-declared data types
// (WC-723 Door 2). One handler serves EVERY registered type: the shape of a
// trash view, a restore, a retire and a delete-that-refuses is identical across
// plugins, so it is generated once here and the differences arrive as
// declarations.
//
// The lifecycle service is also registered under the SDK's read-only
// DataTypeGuard contract. That is not a convenience: a plugin keeping its own
// delete route — the escape hatch that must stay open — resolves the SAME
// evaluator core enforces with, so the two paths cannot drift into two
// different answers to "what still references this?".
//
// Registered with NO route-level requiredPermission because permissions vary
// PER TYPE; the handler gates itself against the same RoleChecker the
// middleware uses, and fails closed on an unresolved tenant, an action the type
// does not offer, or an action whose declared permission the caller lacks.
$dataTypeLifecycle = new \Whity\Core\DataType\DataTypeLifecycleService(
    $db->getPdo(),
    $dataTypeRegistry,
    $hookManager,
    $auditLogger
);
\Whity\register_service(\Whity\Core\DataType\DataTypeLifecycleService::class, $dataTypeLifecycle); // @phpstan-ignore-line
\Whity\register_service(\Whity\Sdk\DataType\DataTypeGuard::class, $dataTypeLifecycle); // @phpstan-ignore-line

// The restore-state memory, registered so a plugin that hard-deletes a record
// OUTSIDE core can clear its row. It is the service's OWN instance, not a second
// one over the same connection. Without this registration the class existed and
// was simply unreachable — the container refuses to build it (it takes a PDO) —
// so an adopter's only remaining option was a hand-written DELETE against a
// core-owned table. The row it leaves behind carries no foreign key and no
// cascade, so for a client-supplied key a later record re-using that key
// inherits a dead record's state and can be restored into a state it never held.
\Whity\register_service(\Whity\Core\DataType\LifecycleStateMemory::class, $dataTypeLifecycle->stateMemory()); // @phpstan-ignore-line

// The WRITE half of the plugin-facing lifecycle surface. `DataTypeGuard` above
// is read-only by design and stays that way — it answers questions and changes
// nothing — but core told adopters to route their writes through core and then
// published only a read contract, so they duck-typed DataTypeLifecycleService, a
// core internal. This registers the supported path.
//
// It is the SAME object the generated endpoints gate themselves with (passed to
// the handler below), which is what makes "an in-process call cannot skip a check
// the endpoint enforces" true by construction rather than by two implementations
// written to agree.
$gatedDataTypeLifecycle = new \Whity\Core\DataType\GatedDataTypeLifecycle(
    $dataTypeRegistry,
    $dataTypeLifecycle,
    $roleChecker
);
\Whity\register_service(\Whity\Sdk\DataType\DataTypeLifecycle::class, $gatedDataTypeLifecycle); // @phpstan-ignore-line

$dataTypesHandler = new \Whity\Api\DataTypesApiHandler(
    $dataTypeRegistry,
    $dataTypeLifecycle,
    $gatedDataTypeLifecycle,
    $settingsService
);

// The host-owned sequence allocator (migration 092). Registered under the SDK
// INTERFACE, which is the name a plugin can reference without depending on
// core; the concrete class is registered too so host code can ask for it by its
// own type. Both entry points register it — a service wired in only one of them
// is the divergence bug class #717 and #724 already paid for.
$sequenceCounters = new \Whity\Database\SequenceCounters($db->getPdo());
\Whity\register_service(\Whity\Sdk\Sql\SequenceAllocator::class, $sequenceCounters); // @phpstan-ignore-line
\Whity\register_service(\Whity\Database\SequenceCounters::class, $sequenceCounters); // @phpstan-ignore-line

$router->register('GET',    '/api/data-types',                       [$dataTypesHandler, 'list']);
$router->register('GET',    '/api/data-types/{type}/{id}',           [$dataTypesHandler, 'show']);
$router->register('POST',   '/api/data-types/{type}/{id}/trash',     [$dataTypesHandler, 'trash']);
$router->register('POST',   '/api/data-types/{type}/{id}/restore',   [$dataTypesHandler, 'restore']);
$router->register('POST',   '/api/data-types/{type}/{id}/retire',    [$dataTypesHandler, 'retire']);
$router->register('DELETE', '/api/data-types/{type}/{id}',           [$dataTypesHandler, 'delete']);
// The batch surface (WC-746). THREE segments and a POST, which is what keeps it
// unambiguous: the single-record transitions are four-segment POSTs, and the
// three-segment `{type}/{id}` routes are GET and DELETE. Router::match() returns
// the FIRST pattern that matches, so a bulk path that could also parse as
// `{type}/{id}/<action>` would make the surface depend on registration order and
// quietly reserve `bulk` as an id nobody could address. The action rides in the
// body instead, beside the ids it applies to.
$router->register('POST',   '/api/data-types/{type}/bulk',           [$dataTypesHandler, 'bulk']);

// 13b-quater. Generic async-job submission + status API (WC-jobs-api). Wraps the
// durable queue: POST enqueues an ALLOW-LISTED job for the caller's tenant (with
// result retention so it can be polled), GET reads status/progress/result. The
// JobRegistry (seeded with the core submittable jobs) fail-closes submission to
// handlers that explicitly opted in — the same registry the queue:work worker
// runs. Submit requires jobs:submit; reads require jobs:read; every query binds
// tenant_id and a foreign-tenant id is 404.
// 13b-quinquies. Notification subsystem wiring (WC-notifications). A shared
// TransportRegistry (built-in log transports by default; real transports override
// per channel in their own slices) + the dispatcher that persists a notification,
// records a per-channel delivery, and enqueues the durable send job. Also
// subscribed on the HookManager, so firing the `notification.dispatch` hook (from
// core or a plugin) dispatches a notification (hook-subscriber mode).
$transportRegistry = \Whity\Core\Notification\CoreTransports::make($logger);
$notificationRepository = new \Whity\Core\Notification\NotificationRepository($db->getPdo());
// Per-user preference resolver — the dispatcher consults it to filter a
// recipient's channels (transactional types always bypass; #c56a6455).
$notificationPreferenceRepository = new \Whity\Core\Notification\NotificationPreferenceRepository($db->getPdo());
$notificationPreferenceResolver = new \Whity\Core\Notification\NotificationPreferenceResolver($notificationPreferenceRepository);
$notificationDispatcher = new \Whity\Core\Notification\NotificationDispatcher(
    $notificationRepository,
    $transportRegistry,
    new \Whity\Core\Queue\QueueService(new \Whity\Core\Queue\JobRepository($db->getPdo())),
    new \Whity\Core\Notification\DatabaseNotificationRenderer(
        new \Whity\Core\Notification\NotificationTemplateRepository($db->getPdo())
    ),
    $logger,
    $notificationPreferenceResolver,
    $auditLogger
);
$notificationDispatcher->subscribe($hookManager);

// In-app notification INBOX (WC-notifications, 6e10d9ea). Self-scoped to the
// caller's (tenant, profile) — session-gated, no RBAC permission (like
// /api/me/sessions). Reads the notifications the dispatcher persisted.
$inboxHandler = new \Whity\Api\InboxApiHandler($tokenValidator, $notificationRepository);
$router->register('GET',  '/api/me/notifications',                [$inboxHandler, 'list'],         null);
$router->register('GET',  '/api/me/notifications/unread-count',   [$inboxHandler, 'unreadCount'],  null);
$router->register('POST', '/api/me/notifications/read-all',       [$inboxHandler, 'markAllRead'],  null);
$router->register('POST', '/api/me/notifications/{id:\d+}/read',  [$inboxHandler, 'markRead'],     null);

// Per-user notification PREFERENCES (WC-notifications, c56a6455). Self-scoped,
// session-gated, no RBAC permission. Transactional types cannot be disabled.
$notificationPreferencesHandler = new \Whity\Api\NotificationPreferencesApiHandler(
    $tokenValidator,
    $notificationPreferenceRepository,
    $notificationPreferenceResolver
);
$router->register('GET', '/api/me/notification-preferences', [$notificationPreferencesHandler, 'list'],   null);
$router->register('PUT', '/api/me/notification-preferences', [$notificationPreferencesHandler, 'update'], null);

// Per-tenant notification SENDER configuration (WC-notifications, d70c6083). A
// tenant admin manages their OWN tenant's per-channel sender (from/reply-to,
// transport, encrypted creds). settings:manage gated; creds are write-only.
$tenantNotificationSettingsHandler = new \Whity\Api\TenantNotificationSettingsApiHandler(
    $roleChecker,
    new \Whity\Core\Notification\TenantNotificationSettingsRepository($db->getPdo()),
    $secretStore,
    $logger
);
$router->register('GET',    '/api/notification-settings',                       [$tenantNotificationSettingsHandler, 'list'],          null, null, CorePermissions::NOTIFICATION_SETTINGS_MANAGE);
$router->register('PUT',    '/api/notification-settings/{channel}',             [$tenantNotificationSettingsHandler, 'updateChannel'], null, null, CorePermissions::NOTIFICATION_SETTINGS_MANAGE);
$router->register('PUT',    '/api/notification-settings/{channel}/credentials', [$tenantNotificationSettingsHandler, 'setCredentials'], null, null, CorePermissions::NOTIFICATION_SETTINGS_MANAGE);
$router->register('DELETE', '/api/notification-settings/{channel}',             [$tenantNotificationSettingsHandler, 'deleteChannel'], null, null, CorePermissions::NOTIFICATION_SETTINGS_MANAGE);

// Notification delivery METRICS (WC-notifications, 4d40cc1c). Read-only admin
// observability — per-status counts, failure rate, queue depth, avg latency —
// aggregated from notification_deliveries, tenant-scoped, notifications:manage.
$notificationMetricsHandler = new \Whity\Api\NotificationMetricsApiHandler(
    $roleChecker,
    new \Whity\Core\Notification\NotificationMetricsRepository($db->getPdo())
);
$router->register('GET', '/api/notification-metrics', [$notificationMetricsHandler, 'show'], null, null, CorePermissions::NOTIFICATIONS_MANAGE);

$jobsRegistry = new \Whity\Core\Queue\JobRegistry();
// Share the transport registry so the (internal, non-submittable) delivery job is
// registered here too — the same handler the queue:work worker runs.
\Whity\Core\Queue\CoreJobs::register($jobsRegistry, $db->getPdo(), $transportRegistry, $logger);
$jobsHandler = new \Whity\Api\JobsApiHandler(
    new \Whity\Core\Queue\JobRepository($db->getPdo()),
    $jobsRegistry,
    $roleChecker
);
$router->register('POST', '/api/jobs',          [$jobsHandler, 'create'], null, null, CorePermissions::JOBS_SUBMIT);
$router->register('GET',  '/api/jobs',          [$jobsHandler, 'list'],   null, null, CorePermissions::JOBS_READ);
$router->register('GET',  '/api/jobs/{id:\d+}', [$jobsHandler, 'show'],   null, null, CorePermissions::JOBS_READ);

// 13b-bis. Email settings API (WC-email): the operator-only mail surface. The
// plaintext mail.* config is edited via /api/settings/global above; these add the
// write-only SMTP password (encrypted at rest, never returned) and a live "send
// test" against the current transport. All three require settings:manage AND —
// enforced in the handler — the system tenant (global mail config is instance-wide).
$mailSettingsHandler = new \Whity\Api\MailSettingsApiHandler(
    $settingsService,
    $globalSettingsRepository,
    $secretStore,
    $roleChecker,
    $logger
);
$router->register('GET', '/api/settings/mail/status',        [$mailSettingsHandler, 'status'],      null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('PUT', '/api/settings/mail/smtp-password', [$mailSettingsHandler, 'setPassword'], null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('POST', '/api/settings/mail/test',         [$mailSettingsHandler, 'test'],        null, null, CorePermissions::SETTINGS_MANAGE);

// 13c. Register the Tenant Branding API (WC-233). Public GET /api/v1/branding
// resolves the caller's tenant by JWT context → custom branding_host → slug
// subdomain of BRANDING_BASE_DOMAIN; falls back to the global default. Asset
// serving is also public. Upload/clear/host-management endpoints are protected
// by settings:write or settings:manage. The handler issues NO SQL — all access
// goes through BrandingService and its repositories; the tenant always comes
// from TenantContext (for write paths) or host resolution (for public read).
// $storageDriver is built EARLIER, in section 13a-storage: branding was its only
// consumer until persisted documents became a second one, and those are wired
// above this point. It is the same per-tenant routing driver described there —
// shared, never rebuilt, so reads and writes can never split across backends.
$brandingService = new \Whity\Core\Branding\BrandingService(
    $settingsService,
    $storageDriver,
    new \Whity\Core\Branding\BrandingAssetValidator(new \Whity\Core\Branding\SvgSanitizer())
);
$brandingHostRepo = new \Whity\Core\Branding\TenantHostRepository($db->getPdo());
$hostResolver = new \Whity\Core\Branding\HostResolver(
    $brandingHostRepo,
    getenv('BRANDING_BASE_DOMAIN') ?: ''
);
$brandingHandler = new \Whity\Api\BrandingApiHandler(
    $brandingService,
    $hostResolver,
    $roleChecker,
    $brandingHostRepo,
    $storageDriver
);
$router->register('GET',    '/api/branding',                        [$brandingHandler, 'get'],          null, null, null);
$router->register('GET',    '/api/branding/asset/{tenantId}/{name}', [$brandingHandler, 'serveAsset'],   null, null, null);
$router->register('POST',   '/api/branding/assets/{key}',           [$brandingHandler, 'uploadTenant'],  null, null, CorePermissions::SETTINGS_WRITE);
$router->register('DELETE', '/api/branding/assets/{key}',           [$brandingHandler, 'clearTenant'],   null, null, CorePermissions::SETTINGS_WRITE);
$router->register('POST',   '/api/branding/global/assets/{key}',    [$brandingHandler, 'uploadGlobal'],  null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('DELETE', '/api/branding/global/assets/{key}',    [$brandingHandler, 'clearGlobal'],   null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('PUT',    '/api/tenants/{id}/branding-host',      [$brandingHandler, 'setBrandingHost'], null, null, CorePermissions::SETTINGS_MANAGE);

// 12b. Theme Override API (WC-242) — public GET, unauthenticated by design
// (like branding, called on every page load before login is even possible).
// The handler enforces whatever permission the contributing plugin's OWN
// route declares internally and always degrades to {"data": {}} rather than
// erroring, so this route itself is unprotected at the router level.
$themeHandler = new \Whity\Api\ThemeApiHandler($pluginLoader, $roleChecker);
$router->register('GET', '/api/theme', [$themeHandler, 'get'], null, null, null);

// 13c-bis. Register the pending-registration review API (WC-235). Gated on
// registrations:approve (6th positional arg) AND — inside the handler — on the
// SYSTEM tenant (id 0): approving a registration activates another tenant's
// owner, a platform operation a regular tenant admin must never perform. Active
// only matters when ADMIN_APPROVAL_ENFORCED is on; the routes are always wired.
$registrationsHandler = new \Whity\Api\RegistrationsApiHandler($db->getPdo(), $roleChecker, $hookManager);
$router->register('GET',  '/api/registrations/pending',      [$registrationsHandler, 'listPending'], null, null, CorePermissions::REGISTRATIONS_APPROVE);
$router->register('POST', '/api/registrations/{id}/approve', [$registrationsHandler, 'approve'],     null, null, CorePermissions::REGISTRATIONS_APPROVE);
$router->register('POST', '/api/registrations/{id}/reject',  [$registrationsHandler, 'reject'],      null, null, CorePermissions::REGISTRATIONS_APPROVE);

// 13d. Register the Tenant Email-Domain API (WC-9b87). Admin-gated; tenant-scoped
// in the handler via TenantContext so a caller can only manage its own domains.
$emailDomainHandler = new TenantEmailDomainApiHandler(
    $db->getPdo(),
    new \Whity\Core\Identity\DomainOwnershipVerifier(new \Whity\Core\Identity\SystemDnsTxtResolver())
);
$router->register('GET',    '/api/email-domains',              [$emailDomainHandler, 'list'],   'admin');
$router->register('POST',   '/api/email-domains',              [$emailDomainHandler, 'create'], 'admin');
$router->register('POST',   '/api/email-domains/{id:\d+}/verify', [$emailDomainHandler, 'verify'], 'admin');
$router->register('DELETE', '/api/email-domains/{id:\d+}',     [$emailDomainHandler, 'delete'], 'admin');

// 13e. Register the per-tenant identity-provider (SSO/OIDC) admin API (WC-e6287).
// Gated on auth_providers:manage (6th positional arg; role stays null so
// RbacMiddleware enforces the permission) and tenant-scoped in the handler. The
// client secret is encrypted at rest via the shared EncryptedSecretStore and is
// never returned in a response.
$identityProvidersHandler = new IdentityProvidersApiHandler(
    $db->getPdo(),
    \Whity\Core\Security\EncryptedSecretStore::fromEnv($_ENV)
);
$router->register('GET',    '/api/identity-providers',          [$identityProvidersHandler, 'list'],   null, null, CorePermissions::AUTH_PROVIDERS_MANAGE);
$router->register('POST',   '/api/identity-providers',          [$identityProvidersHandler, 'create'], null, null, CorePermissions::AUTH_PROVIDERS_MANAGE);
$router->register('PATCH',  '/api/identity-providers/{id:\d+}', [$identityProvidersHandler, 'update'], null, null, CorePermissions::AUTH_PROVIDERS_MANAGE);
$router->register('DELETE', '/api/identity-providers/{id:\d+}', [$identityProvidersHandler, 'delete'], null, null, CorePermissions::AUTH_PROVIDERS_MANAGE);

// 13f. Federated sign-in ("Sign in with Google") over OIDC (WC-ae16). Two PUBLIC
// GET routes (unauthenticated by design; a pre-login user has no session). GET is
// CSRF-exempt; `state` is the CSRF defense. The engine's outbound fetches use the
// SSRF-guarded HttpFetcher; JWKS are cached. The callback resolves the verified
// identity via FederatedIdentityLinker (existing link / link-by-verified-email /
// provision), with anti-takeover refusals (WC-f3b17bd2).
$oidcEngine = new \Whity\Auth\Oidc\OidcEngine(
    new \Whity\Core\Http\HttpFetcher(),
    new \Whity\Auth\Oidc\JwksProvider(
        static fn(string $uri): array => (new \Whity\Core\Http\HttpFetcher())->getJson($uri) ?? []
    ),
    $jwtParser
);
$externalIdentityRepository = new \Whity\Core\Identity\ExternalIdentityRepository($db->getPdo());
$ssoAuthHandler = new \Whity\Api\SsoAuthHandler(
    $oidcEngine,
    new \Whity\Core\Identity\IdentityProviderRepository($db->getPdo()),
    $profileEmailRepository,
    $hostResolver,
    $jwtParser,
    \Whity\Core\Security\EncryptedSecretStore::fromEnv($_ENV),
    $authHandler,
    new \Whity\Core\Identity\FederatedIdentityLinker(
        $db->getPdo(),
        $externalIdentityRepository,
        $profileEmailRepository,
        // SSO JIT provisioning grants authority with no administrator in the
        // loop at all, which makes it the path an audit trail can least afford
        // to be silent about (#889).
        new MembershipRepository($db->getPdo(), $hookManager),
        new \Whity\Core\Identity\TenantEmailDomainsRepository($db->getPdo()),
    ),
    $settingsService,
    (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '')
);
// Public list of ENABLED providers for the current tenant, so the login page can
// render "Sign in with …" buttons (display-safe fields only; no secrets).
$router->register('GET', '/api/auth/sso/providers',                      [$ssoAuthHandler, 'providers'], null);
$router->register('GET', '/api/auth/sso/{provider:[a-z0-9_]+}/start',    [$ssoAuthHandler, 'start'],    null);
$router->register('GET', '/api/auth/sso/{provider:[a-z0-9_]+}/callback', [$ssoAuthHandler, 'callback'], null);

// 13g. Authenticated "connected accounts" management (WC-f3b17bd2): the caller
// lists / unlinks their own federated identities. Self-authenticating via the
// access token (cookie or Bearer), scoped to the caller's profile.
$meIdentitiesHandler = new \Whity\Api\MeIdentitiesApiHandler($tokenValidator, $externalIdentityRepository, $db->getPdo());
$router->register('GET',    '/api/me/identities',            [$meIdentitiesHandler, 'list'],   null);
$router->register('DELETE', '/api/me/identities/{id:\d+}',   [$meIdentitiesHandler, 'unlink'], null);

// 13h. Authenticated self-service multi-email management: the caller lists,
// adds, resends verification for, promotes, and removes their own email
// addresses. Self-authenticating (cookie or Bearer), scoped to the caller's
// profile — same wiring pattern as the connected-accounts surface above.
$meEmailsHandler = new \Whity\Api\MeEmailsApiHandler(
    $tokenValidator,
    $profileEmailRepository,
    $emailVerificationProvider,
    new DatabaseSharedStore($db->getPdo()),
    $auditLogger,
);
$router->register('GET',    '/api/me/emails',                              [$meEmailsHandler, 'list'],              null);
$router->register('POST',   '/api/me/emails',                              [$meEmailsHandler, 'add'],               null);
$router->register('POST',   '/api/me/emails/{id:\d+}/resend-verification', [$meEmailsHandler, 'resendVerification'], null);
$router->register('POST',   '/api/me/emails/{id:\d+}/set-primary',         [$meEmailsHandler, 'setPrimary'],         null);
$router->register('DELETE', '/api/me/emails/{id:\d+}',                     [$meEmailsHandler, 'remove'],            null);

// 14. Register the family relations API (WC-65). Reads are gated on
// relations:read, writes on relations:manage (6th positional arg; requiredRole
// stays null so RbacMiddleware enforces the permission). All routes are
// tenant-scoped in the handlers: the SYSTEM tenant (id 0) sees all tenants,
// every other tenant sees only its own. Storage is uniform person→person; the
// resolver is the only unit that knows about profile-vs-person refs and
// auto-provisions a profile's shadow person on demand.
$personRepository = new PersonRepository($db->getPdo());
$relationRepository = new RelationRepository($db->getPdo());
$relationResolver = new RelationResolver($db->getPdo(), $personRepository, $relationRepository);
$personsHandler = new PersonsApiHandler($personRepository, $relationRepository);
$relationsHandler = new RelationsApiHandler($personRepository, $relationRepository, $relationResolver, $logger);

$router->register('GET', '/api/relationship-types', [$relationsHandler, 'listTypes'], null, null, CorePermissions::RELATIONS_READ);
$router->register('GET', '/api/persons', [$personsHandler, 'list'], null, null, CorePermissions::RELATIONS_READ);
$router->register('POST', '/api/persons', [$personsHandler, 'create'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('GET', '/api/persons/{id:\d+}', [$personsHandler, 'get'], null, null, CorePermissions::RELATIONS_READ);
$router->register('PATCH', '/api/persons/{id:\d+}', [$personsHandler, 'update'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('DELETE', '/api/persons/{id:\d+}', [$personsHandler, 'delete'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('GET', '/api/persons/{id:\d+}/relations', [$personsHandler, 'relations'], null, null, CorePermissions::RELATIONS_READ);
$router->register('GET', '/api/relations', [$relationsHandler, 'listEdges'], null, null, CorePermissions::RELATIONS_READ);
$router->register('GET', '/api/profiles/{id:\d+}/relations', [$relationsHandler, 'profileRelations'], null, null, CorePermissions::RELATIONS_READ);
$router->register('POST', '/api/relations', [$relationsHandler, 'create'], null, null, CorePermissions::RELATIONS_MANAGE);
$router->register('DELETE', '/api/relations/{id:\d+}', [$relationsHandler, 'delete'], null, null, CorePermissions::RELATIONS_MANAGE);

// WC-2686308f: MCP token management endpoints (issue / list / revoke).
// WC-149b2fc9: create and revoke are gated by mcp:tokens:manage so an admin
// controls who may mint AI credentials. List is read-only and ungated.
$mcpTokenHandler = new McpTokenHandler($tokenValidator, new McpTokenService($db->getPdo(), $jwtParser));
$router->register('POST',   '/api/mcp/tokens',       [$mcpTokenHandler, 'create'], null, null, CorePermissions::MCP_TOKENS_MANAGE);
$router->register('GET',    '/api/mcp/tokens',       [$mcpTokenHandler, 'list']);
$router->register('DELETE', '/api/mcp/tokens/{jti}', [$mcpTokenHandler, 'revoke'], null, null, CorePermissions::MCP_TOKENS_MANAGE);

// WC-0208ce4d: AI-principal admin endpoints (tenant-scoped, admin surface).
// Gated by mcp:tokens:manage so only admins who hold the credential-management
// permission can list or revoke any token in their tenant. Mirrors the per-user
// McpTokenHandler routes but operates across the whole tenant rather than a
// single user's issued tokens.
$aiPrincipalsHandler = new AiPrincipalsApiHandler($db->getPdo(), $roleChecker);
$router->register('GET',    '/api/admin/mcp/tokens',       [$aiPrincipalsHandler, 'list'],   null, null, CorePermissions::MCP_TOKENS_MANAGE);
$router->register('DELETE', '/api/admin/mcp/tokens/{jti}', [$aiPrincipalsHandler, 'revoke'], null, null, CorePermissions::MCP_TOKENS_MANAGE);

// WC-c10b292e / WC-001754c6: build the ToolDeriver here, before both the
// admin tool-catalogue endpoint and the MCP transport, so both share the
// SAME instance (and its static cache). The Router reference enables lazy
// inclusion of plugin routes: they are read at tools/list call time rather
// than at construction, so plugins loaded below are naturally included.
$toolDeriver = new ToolDeriver(
    CoreApiSchemas::routes(),
    CoreApiSchemas::components(),
    $router,
);

// WC-0208ce4d: admin read-only view of available MCP tools + access
// requirements. Uses the same ToolDeriver instance as the MCP transport so
// the admin sees exactly what an MCP client would receive from tools/list,
// without per-caller RBAC filtering (the page is for audit/planning).
$mcpToolsAdminHandler = new McpToolsAdminHandler($toolDeriver, $roleChecker);
$router->register('GET', '/api/admin/mcp/tools', [$mcpToolsAdminHandler, 'list'], null, null, CorePermissions::MCP_TOKENS_MANAGE);

// WC-c10b292e: MCP Streamable-HTTP endpoint. Registered UNVERSIONED so the
// path is exactly /mcp (not /api/v1/mcp). No requiredRole/requiredPermission —
// the transport delegates auth to the dispatcher (ADR-0006 per-call contract).
// Bypasses tenant isolation middleware (see EnforceTenantIsolation::PUBLIC_ROUTES).
$resourceDeriver = new ResourceDeriver(
    CoreApiSchemas::routes(),
    $router,
);
$promptRegistry = new PromptRegistry();
CorePrompts::register($promptRegistry);
// WC-a89ece0d: per-tenant and per-principal call budgets. Limits are tunable
// via env vars so operators can adjust without a code deploy.
$mcpRateLimiter = new McpRateLimiter(
    new DatabaseSharedStore($db->getPdo()),
    tenantLimit:    (int) ($_ENV['MCP_RATE_TENANT_LIMIT']    ?? 300),
    principalLimit: (int) ($_ENV['MCP_RATE_PRINCIPAL_LIMIT'] ?? 60),
);
// WC-149b2fc9: per-tenant MCP opt-in — read mcp.enabled from settings. Default
// off so new tenants must explicitly enable the endpoint.
$tenantMcpEnabled = static function (int $tenantId) use ($settingsService): bool {
    $settings = $settingsService->effective($tenantId);
    return ($settings[SettingsRegistry::MCP_ENABLED] ?? 'false') === 'true';
};
// #952: MCP clients cache the discovery lists at connection time, so a client
// that connected before a plugin rebuild kept its stale tool definitions
// indefinitely — which is how records were written double-encoded against a
// server that was already serving the corrected schema. The signature is derived
// from the catalogue this worker would actually serve, so each of the eight
// FrankenPHP workers answers for itself and none announces a change it cannot
// then serve; the shared store is what keeps eight workers from each announcing
// the same change to the same client.
$mcpCatalogSignature = new CatalogSignature($toolDeriver, $resourceDeriver, $promptRegistry);
$mcpListChangedNotifier = new ListChangedNotifier(
    $mcpCatalogSignature,
    new DatabaseSharedStore($db->getPdo()),
);
$mcpTransportHandler = new McpTransportHandler(
    new Dispatcher([
        'initialize'              => new InitializeHandler(listChanged: true),
        'ping'                    => new PingHandler(),
        'notifications/cancelled' => new CancelledNotificationHandler(),
        'tools/list'              => new ToolsListHandler($toolDeriver, $roleChecker, $tokenValidator),
        'tools/call'              => new ToolsCallHandler($toolDeriver, $router, $roleChecker, $tokenValidator, auditLogger: $auditLogger),
        'resources/list'          => new ResourcesListHandler($resourceDeriver, $roleChecker, $tokenValidator),
        'resources/read'          => new ResourcesReadHandler($router, $roleChecker, $tokenValidator, auditLogger: $auditLogger),
        'prompts/list'            => new PromptsListHandler($promptRegistry, $roleChecker, $tokenValidator),
        'prompts/get'             => new PromptsGetHandler($promptRegistry, $roleChecker, $tokenValidator),
    ], $tokenValidator, $mcpRateLimiter, $tenantMcpEnabled, $mcpListChangedNotifier),
    enabled: (bool) filter_var($_ENV['MCP_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
);
$router->registerUnversioned('POST', '/mcp', [$mcpTransportHandler, 'handlePost']);
$router->registerUnversioned('GET',  '/mcp', [$mcpTransportHandler, 'handleGet']);

// Load plugins AFTER every core route (WC-169): the Router refuses duplicate
// method+path registrations (first wins), so core routes can never be
// shadowed by a plugin claiming the same path.
$pluginLoader->load();
$pluginLoader->collectMcpPrompts($promptRegistry);

// #952: everything the MCP layer memoized off the plugin registry is rebuilt
// whenever the registry changes. Announcing a change while continuing to serve
// the pre-change catalogue would be worse than the silence this issue is about:
// the client would refetch, be handed the stale list again, and now believe it
// is current. ToolDeriver's caches and the prompt registry are per-worker, so
// this runs in whichever worker did the reload — the others converge through the
// worker-recycle contract (WC-212), and until they do their catalogue signature
// still reports what they are actually serving, so they stay quiet.
//
// The prompt registry is cleared before re-seeding: the prompts handlers hold
// this exact instance, so it cannot be replaced, and appending to it would leave
// an uninstalled plugin's prompts listed forever.
$refreshMcpCatalog = static function (string $trigger) use (
    $promptRegistry,
    $pluginLoader,
    $mcpCatalogSignature
): void {
    ToolDeriver::clearCache();
    $promptRegistry->reset();
    CorePrompts::register($promptRegistry);
    $pluginLoader->collectMcpPrompts($promptRegistry);
    // Last: the signature must be computed from the refreshed catalogue, not the
    // one being replaced.
    $mcpCatalogSignature->invalidate();
};
$pluginLoader->onRegistryChanged($refreshMcpCatalog);
// The producer end of the queue learns the plugin handlers too. $jobsRegistry is
// the SAME object JobsApiHandler already holds, so registering into it now is
// what makes a plugin's submittable job acceptable at POST /api/jobs — and, just
// as importantly, keeps the two ends agreeing: a name the API accepts is a name
// the worker (which discovers the same declarations) can actually run.
$pluginLoader->collectJobs($jobsRegistry);

// Descriptor-derived navigation (WC-169): every validated plugin frontend
// feature gets a menu entry pointing at the dynamic screen route /admin/x/{id}.
// Features are read at dispatch time, so runtime disable drops the entry.
\Whity\Core\PluginNavigationBridge::subscribe($hookManager, $pluginLoader);

// Handle requests (persistent worker mode or fallback single-request mode)
$isWorker = function_exists('frankenphp_handle_request');

// Resolve the security hardening headers once (WC-187). They depend only on
// APP_ENV, which is fixed for the worker's lifetime, so this is computed outside
// the request loop and merged into EVERY response below (success, OPTIONS/204
// preflight and the 500 error path) alongside the per-request CORS headers.
$securityHeaders = SecurityHeaders::headers($appEnv);

if ($isWorker) {
    // Dispatch boot hook
    error_log("[FrankenPHP Worker] Booting...");
    $hookManager->dispatch('worker.boot', []);

    // Get max requests from env
    $maxRequests = (int)($_ENV['MAX_REQUESTS'] ?? $_SERVER['MAX_REQUESTS'] ?? 0);

    for ($nbRequests = 0; !$maxRequests || $nbRequests < $maxRequests; ++$nbRequests) {
        // WC-182: the per-request lifecycle log lines are useful when tracing
        // locally but flood the production log (one pair per request), so they
        // are gated behind development/DEBUG. Decision is computed once per
        // iteration and captured by the request closure below.
        $logLifecycle = WorkerRuntime::shouldLogLifecycle($_ENV);
        $keepRunning = \frankenphp_handle_request(static function () use ($kernel, $hookManager, $pluginLoader, $db, $logLifecycle, $securityHeaders, $errorTracker) {
            try {
                // Dispatch request start hook
                if ($logLifecycle) {
                    error_log("[FrankenPHP Worker] Request start");
                }
                $hookManager->dispatch('worker.request.start', []);

                // Plugin hot-reload (WC-8/PR #83): pick up plugins added, modified,
                // or removed on disk since the last request without restarting the
                // worker. This is a cheap no-op when the plugin tree is unchanged,
                // and runs before the kernel handles the request so the new routes
                // are live for this iteration.
                // WC-160: development only. In any other env a file dropped into
                // plugins/ must NOT start executing on the next request (deploy-
                // less code execution); changes take effect via restart/deploy or
                // an explicit, RBAC-protected admin action.
                if (($_ENV['APP_ENV'] ?? 'production') === 'development') {
                    $pluginLoader->reload();
                }

                // Create request from PHP superglobals
                $request = Request::fromGlobals();

                // Resolve CORS headers once per request from the allowlist (WC-53).
                $corsHeaders = Cors::headers($request->getHeader('Origin'));

                // Handle OPTIONS preflight requests for CORS
                if ($request->getMethod() === 'OPTIONS') {
                    // Even the empty 204 preflight carries the hardening headers (WC-187).
                    $response = new Response(204, '', array_merge($corsHeaders, $securityHeaders));
                    $response->send();
                    return;
                }

                // Handle request through kernel
                $response = $kernel->handle($request);

                // Merge CORS + security hardening headers into the response (WC-53, WC-187).
                // respectingHandlerCsp() lets a handler-set Content-Security-Policy survive
                // (WC-531) — e.g. a plugin serving a self-contained HTML screen — instead of
                // being silently clobbered by the strict JSON-API default. withHeaders()
                // preserves the concrete response type (StreamedResponse etc.) so the
                // streamer is not lost when merging headers.
                $response = $response->withHeaders(array_merge(
                    $corsHeaders,
                    SecurityHeaders::respectingHandlerCsp($securityHeaders, $response->getHeaders())
                ));

                // Send response to client
                $response->send();
            } catch (\Throwable $e) {
                // Handle any uncaught exceptions
                try {
                    $errorResponse = Response::error('Internal server error', 500);
                    // The 500 path is a response a client can receive, so it gets the
                    // hardening headers too (WC-187).
                    $errorHeaders = array_merge(
                        $errorResponse->getHeaders(),
                        Cors::headers($_SERVER['HTTP_ORIGIN'] ?? null),
                        $securityHeaders
                    );
                    $errorResponse = new Response(500, $errorResponse->getBody(), $errorHeaders);
                    $errorResponse->send();
                } catch (\Throwable $sendError) {
                    // If send also fails, just log it
                    error_log('Failed to send error response: ' . $sendError->getMessage());
                }
                $requestId = \Whity\Core\Observability\ErrorContext::newRequestId();
                try {
                    $errorTracker->captureException(
                        $e,
                        \Whity\Core\Observability\ErrorContext::gather($pluginLoader->getPluginMetadata(), $requestId)
                    );
                } catch (\Throwable) {
                    // Telemetry must never mask the original error.
                }
                error_log('[' . $requestId . '] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            } finally {
                // Dispatch request end hook
                if ($logLifecycle) {
                    error_log("[FrankenPHP Worker] Request end");
                }
                $hookManager->dispatch('worker.request.end', []);
                // Reset tenant context to prevent cross-request leakage
                \Whity\Core\Tenant\TenantContext::reset();
                // Reset the audit actor/IP context for the same reason (WC-34).
                AuditContext::reset();
                // RoleChecker's effective-permission cache is PROCESS-level, so
                // it outlives the request that filled it. Every mutating write
                // calls RoleChecker::clearCache(), but that only clears the ONE
                // worker that served the write — the other workers keep serving
                // the stale set until they recycle. That means a granted
                // permission can stay invisible and, worse, a REVOKED one can
                // stay live. Scope the cache to the request instead; it still
                // de-duplicates the repeated resolutions within a single one.
                RoleChecker::clearCache();
                // DB session hygiene (WC-21/PR #84): after the response is sent,
                // roll back any dangling transaction and DISCARD ALL session-local
                // state on the shared worker connection so nothing request-specific
                // (temp tables, SET LOCAL, prepared plans) leaks into the next
                // request the worker serves. No-op when no connection is open.
                $db->resetSessionState();
            }
        });

        // WC-182: a forced full cycle collection on EVERY request adds avoidable
        // CPU work to the hot path. It is now opportunistic: every request in
        // development/DEBUG (so leaks surface eagerly while iterating), and only
        // every WorkerRuntime::GC_CADENCE iterations in production. PHP's
        // automatic cycle collector handles the gaps, and the memory-recycle
        // backstop below remains the hard safety net regardless.
        if (WorkerRuntime::shouldCollectCycles($nbRequests, $_ENV)) {
            gc_collect_cycles();
        }

        // WC-212: a development reload() that detected a MODIFIED already-loaded
        // plugin cannot redefine the class in-process, so it requested a worker
        // recycle. The response for THIS request has already been sent above, so
        // recycling now is safe: FrankenPHP respawns a fresh worker that
        // re-bootstraps and recompiles the (opcache-invalidated) plugin source,
        // serving the new code. Gated to development, mirroring the reload() call.
        if (
            ($_ENV['APP_ENV'] ?? 'production') === 'development'
            && $pluginLoader->consumePendingWorkerRecycle()
        ) {
            error_log("[FrankenPHP Worker] Recycling worker to load modified plugin code.");
            $db->disconnect();
            break;
        }

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
            // Even the empty 204 preflight carries the hardening headers (WC-187).
            $response = new Response(204, '', array_merge($corsHeaders, $securityHeaders));
            $response->send();
            exit;
        }

        // Handle request through kernel
        $response = $kernel->handle($request);

        // Merge CORS + security hardening headers into the response (WC-53, WC-187).
        // respectingHandlerCsp() lets a handler-set Content-Security-Policy survive
        // (WC-531) — e.g. a plugin serving a self-contained HTML screen — instead of
        // being silently clobbered by the strict JSON-API default. withHeaders()
        // preserves the concrete response type (StreamedResponse etc.) so the
        // streamer is not lost when merging headers.
        $response = $response->withHeaders(array_merge(
            $corsHeaders,
            SecurityHeaders::respectingHandlerCsp($securityHeaders, $response->getHeaders())
        ));

        // Send response to client
        $response->send();
    } catch (\Throwable $e) {
        // Handle any uncaught exceptions
        try {
            $errorResponse = Response::error('Internal server error', 500);
            // The 500 path is a response a client can receive, so it gets the
            // hardening headers too (WC-187).
            $errorHeaders = array_merge(
                $errorResponse->getHeaders(),
                Cors::headers($_SERVER['HTTP_ORIGIN'] ?? null),
                $securityHeaders
            );
            $errorResponse = new Response(500, $errorResponse->getBody(), $errorHeaders);
            $errorResponse->send();
        } catch (\Throwable $sendError) {
            // If send also fails, just log it
            error_log('Failed to send error response: ' . $sendError->getMessage());
        }
        $requestId = \Whity\Core\Observability\ErrorContext::newRequestId();
        try {
            $errorTracker->captureException(
                $e,
                \Whity\Core\Observability\ErrorContext::gather($pluginLoader->getPluginMetadata(), $requestId)
            );
        } catch (\Throwable) {
            // Telemetry must never mask the original error.
        }
        error_log('[' . $requestId . '] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    } finally {
        \Whity\Core\Tenant\TenantContext::reset();
        AuditContext::reset();
        // Same reasoning as the worker loop above: the effective-permission
        // cache must not outlive the request that filled it.
        RoleChecker::clearCache();
    }
}

