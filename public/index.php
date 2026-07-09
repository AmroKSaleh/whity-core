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

    if ($command === 'update:check') {
        $updateCheckCommand = new \Whity\Cli\Commands\UpdateCheckCommand();
        array_shift($argv); // Remove script name
        array_shift($argv); // Remove 'update:check' command
        exit($updateCheckCommand->execute($argv));
    }

    echo "Unknown command: {$command}\n";
    echo "Available commands:\n";
    echo "  generate:openapi           Generate OpenAPI 3.0 schema\n";
    echo "  migrate                    Manage database migrations\n";
    echo "  seed                       Seed database with default data\n";
    echo "  revoked-tokens:cleanup     Cleanup expired revoked tokens\n";
    echo "  update:check               Compare the core version against the latest GitHub release\n";
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
use Whity\Api\DelegationsApiHandler;
use Whity\Api\FrontendFeaturesApiHandler;
use Whity\Api\MeCapabilitiesApiHandler;
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
        // WC-175 (#191): mirrors GET /api/ous, gated on the 'admin' ROLE.
        'requiredRole' => 'admin',
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
        'id' => 'pending-registrations',
        'label' => 'Pending Registrations',
        'href' => '/admin/registrations',
        'icon' => 'user-check',
        'group' => 'admin',
        'order' => 9.5,
        // WC-235: system-tenant governance surface. Mirrors GET
        // /api/v1/registrations/pending, gated on registrations:approve AND the
        // system tenant (id 0) — a regular tenant admin holds the permission in
        // its own tenant but must never approve another workspace's owner, so
        // the item is hidden for them (the page also enforces both server-side).
        'requiredPermission' => \Whity\Core\RBAC\CorePermissions::REGISTRATIONS_APPROVE,
        'systemTenantOnly' => true,
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
        RateLimitRule::tenant(
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
    $logger
);

// 9b. Initialize deployment manager
$deploymentManager = new DeploymentManager($db->getPdo(), __DIR__ . '/../storage/deployments');

// 10. Register authentication handler
// Inject the shared $totpService (built at step 3b) so the login-path 2FA validation uses the
// SAME encryption key as the setup/confirm path (WC-95).
// WC-0abcc29f: brute-force throttle uses the shared DatabaseSharedStore.
$loginThrottle = new LoginThrottleService(new DatabaseSharedStore($db->getPdo()));
$authHandler = new AuthHandler($db->getPdo(), $jwtParser, null, null, $totpService, $logger, $auditLogger, $loginThrottle);
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
$mailer = MailerFactory::fromEnv($_ENV, $logger);
$emailVerificationService = new EmailVerificationService($db->getPdo());
$profileEmailRepository = new ProfileEmailRepository($db->getPdo());
$verifyUrlBase = (string) ($_ENV['EMAIL_VERIFICATION_URL'] ?? getenv('EMAIL_VERIFICATION_URL')
    ?: (rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/') . '/verify-email'));
$emailVerificationProvider = new TokenEmailVerificationProvider(
    $emailVerificationService,
    $profileEmailRepository,
    $mailer,
    $verifyUrlBase
);
$registerHandler = new RegisterApiHandler($db->getPdo(), $emailVerificationProvider);
$router->register('POST', '/api/register', [$registerHandler, 'register'], null);

// WC-235: public email verification — (re)send a link + confirm a token. Both are
// unauthenticated (a new owner has no session yet; a confirm link carries no JWT),
// so both are on EnforceTenantIsolation::PUBLIC_ROUTES. Rate-limited via the
// shared store; audited as system-level (tenant 0) identity events.
// WC-9b87: on a successful confirm the handler applies the tenant email-domain
// policy (accept invite / auto-provision membership) for the now-verified email.
$emailDomainPolicy = new TenantEmailDomainPolicyService(
    new TenantEmailDomainsRepository($db->getPdo()),
    new MembershipRepository($db->getPdo())
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
$deviceService = new \Whity\Auth\DeviceCredentialService($db->getPdo(), $jwtParser);
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

// 11. Register API handlers
$usersHandler = new UsersApiHandler($db->getPdo(), $hookManager);
// WC-203: gate users routes on fine-grained permission grants instead of the
// bare 'admin' role. requiredRole is cleared (null) so the check is driven
// entirely by requiredPermission; migration 022 grants all three to admin.
$router->register('GET',    '/api/users',           [$usersHandler, 'list'],   null, null, CorePermissions::USERS_READ);
$router->register('POST',   '/api/users',           [$usersHandler, 'create'], null, null, CorePermissions::USERS_WRITE);
$router->register('PATCH',  '/api/users/{id:\d+}',  [$usersHandler, 'update'], null, null, CorePermissions::USERS_WRITE);
$router->register('DELETE', '/api/users/{id:\d+}',  [$usersHandler, 'delete'], null, null, CorePermissions::USERS_DELETE);

$rolesHandler = new RolesApiHandler($db->getPdo(), $hookManager);
$router->register('GET', '/api/roles', [$rolesHandler, 'list'], 'admin');
$router->register('POST', '/api/roles', [$rolesHandler, 'create'], 'admin');
$router->register('GET', '/api/roles/{id:\d+}', [$rolesHandler, 'get'], 'admin');
$router->register('PATCH', '/api/roles/{id:\d+}', [$rolesHandler, 'update'], 'admin');
$router->register('DELETE', '/api/roles/{id:\d+}', [$rolesHandler, 'delete'], 'admin');
$router->register('GET', '/api/roles/{id:\d+}/permissions', [$rolesHandler, 'getPermissions'], 'admin');

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
$router->register('GET', '/api/ous/{id:\d+}', [$ousHandler, 'get'], 'admin');
$router->register('PATCH', '/api/ous/{id:\d+}', [$ousHandler, 'update'], 'admin');
$router->register('DELETE', '/api/ous/{id:\d+}', [$ousHandler, 'delete'], 'admin');
$router->register('GET', '/api/ous/{id:\d+}/roles', [$ousHandler, 'roles'], 'admin');
$router->register('GET', '/api/ous/{id:\d+}/members', [$ousHandler, 'members'], 'admin');
$router->register('POST', '/api/ous/{id:\d+}/roles', [$ousHandler, 'assignRole'], 'admin');
$router->register('DELETE', '/api/ous/{ouId:\d+}/roles/{roleId:\d+}', [$ousHandler, 'removeRole'], 'admin');

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

// 13b. Register the Website Settings API (global defaults + per-tenant
// overrides). Reads are gated on settings:read, current-tenant override writes
// on settings:write, and global-default reads/writes on settings:manage (6th
// positional arg; requiredRole stays null so RbacMiddleware enforces the
// permission). The handler issues NO SQL — all access goes through
// SettingsService and its repositories; the tenant always comes from
// TenantContext, so a caller can only edit its own tenant's overrides.
$settingsService = new \Whity\Core\Settings\SettingsService(
    new \Whity\Core\Settings\GlobalSettingsRepository($db->getPdo()),
    new \Whity\Core\Settings\TenantSettingsRepository($db->getPdo())
);
$settingsHandler = new \Whity\Api\SettingsApiHandler($settingsService, $roleChecker);
$router->register('GET',   '/api/settings',        [$settingsHandler, 'get'],         null, null, CorePermissions::SETTINGS_READ);
$router->register('PATCH', '/api/settings',        [$settingsHandler, 'patch'],       null, null, CorePermissions::SETTINGS_WRITE);
$router->register('GET',   '/api/settings/global', [$settingsHandler, 'getGlobal'],   null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('PATCH', '/api/settings/global', [$settingsHandler, 'patchGlobal'], null, null, CorePermissions::SETTINGS_MANAGE);

// 13c. Register the Tenant Branding API (WC-233). Public GET /api/v1/branding
// resolves the caller's tenant by JWT context → custom branding_host → slug
// subdomain of BRANDING_BASE_DOMAIN; falls back to the global default. Asset
// serving is also public. Upload/clear/host-management endpoints are protected
// by settings:write or settings:manage. The handler issues NO SQL — all access
// goes through BrandingService and its repositories; the tenant always comes
// from TenantContext (for write paths) or host resolution (for public read).
$storageRoot = getenv('STORAGE_ROOT') ?: (__DIR__ . '/../storage');
$brandingService = new \Whity\Core\Branding\BrandingService(
    $settingsService,
    new \Whity\Storage\LocalStorageDriver($storageRoot),
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
    new \Whity\Storage\LocalStorageDriver($storageRoot)
);
$router->register('GET',    '/api/branding',                        [$brandingHandler, 'get'],          null, null, null);
$router->register('GET',    '/api/branding/asset/{tenantId}/{name}', [$brandingHandler, 'serveAsset'],   null, null, null);
$router->register('POST',   '/api/branding/assets/{key}',           [$brandingHandler, 'uploadTenant'],  null, null, CorePermissions::SETTINGS_WRITE);
$router->register('DELETE', '/api/branding/assets/{key}',           [$brandingHandler, 'clearTenant'],   null, null, CorePermissions::SETTINGS_WRITE);
$router->register('POST',   '/api/branding/global/assets/{key}',    [$brandingHandler, 'uploadGlobal'],  null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('DELETE', '/api/branding/global/assets/{key}',    [$brandingHandler, 'clearGlobal'],   null, null, CorePermissions::SETTINGS_MANAGE);
$router->register('PUT',    '/api/tenants/{id}/branding-host',      [$brandingHandler, 'setBrandingHost'], null, null, CorePermissions::SETTINGS_MANAGE);

// 13c-bis. Register the pending-registration review API (WC-235). Gated on
// registrations:approve (6th positional arg) AND — inside the handler — on the
// SYSTEM tenant (id 0): approving a registration activates another tenant's
// owner, a platform operation a regular tenant admin must never perform. Active
// only matters when ADMIN_APPROVAL_ENFORCED is on; the routes are always wired.
$registrationsHandler = new \Whity\Api\RegistrationsApiHandler($db->getPdo(), $roleChecker);
$router->register('GET',  '/api/registrations/pending',      [$registrationsHandler, 'listPending'], null, null, CorePermissions::REGISTRATIONS_APPROVE);
$router->register('POST', '/api/registrations/{id}/approve', [$registrationsHandler, 'approve'],     null, null, CorePermissions::REGISTRATIONS_APPROVE);
$router->register('POST', '/api/registrations/{id}/reject',  [$registrationsHandler, 'reject'],      null, null, CorePermissions::REGISTRATIONS_APPROVE);

// 13d. Register the Tenant Email-Domain API (WC-9b87). Admin-gated; tenant-scoped
// in the handler via TenantContext so a caller can only manage its own domains.
$emailDomainHandler = new TenantEmailDomainApiHandler($db->getPdo());
$router->register('GET',    '/api/email-domains',           [$emailDomainHandler, 'list'],   'admin');
$router->register('POST',   '/api/email-domains',           [$emailDomainHandler, 'create'], 'admin');
$router->register('DELETE', '/api/email-domains/{id:\d+}',  [$emailDomainHandler, 'delete'], 'admin');

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
    $externalIdentityRepository,
    $profileEmailRepository,
    $hostResolver,
    $jwtParser,
    \Whity\Core\Security\EncryptedSecretStore::fromEnv($_ENV),
    $authHandler,
    new \Whity\Core\Identity\FederatedIdentityLinker($db->getPdo(), $externalIdentityRepository, $profileEmailRepository),
    (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: '')
);
$router->register('GET', '/api/auth/sso/{provider:[a-z0-9_]+}/start',    [$ssoAuthHandler, 'start'],    null);
$router->register('GET', '/api/auth/sso/{provider:[a-z0-9_]+}/callback', [$ssoAuthHandler, 'callback'], null);

// 13g. Authenticated "connected accounts" management (WC-f3b17bd2): the caller
// lists / unlinks their own federated identities. Self-authenticating via the
// access token (cookie or Bearer), scoped to the caller's profile.
$meIdentitiesHandler = new \Whity\Api\MeIdentitiesApiHandler($tokenValidator, $externalIdentityRepository, $db->getPdo());
$router->register('GET',    '/api/me/identities',            [$meIdentitiesHandler, 'list'],   null);
$router->register('DELETE', '/api/me/identities/{id:\d+}',   [$meIdentitiesHandler, 'unlink'], null);

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
$mcpTransportHandler = new McpTransportHandler(
    new Dispatcher([
        'initialize'              => new InitializeHandler(),
        'ping'                    => new PingHandler(),
        'notifications/cancelled' => new CancelledNotificationHandler(),
        'tools/list'              => new ToolsListHandler($toolDeriver, $roleChecker, $tokenValidator),
        'tools/call'              => new ToolsCallHandler($toolDeriver, $router, $roleChecker, $tokenValidator, auditLogger: $auditLogger),
        'resources/list'          => new ResourcesListHandler($resourceDeriver, $roleChecker, $tokenValidator),
        'resources/read'          => new ResourcesReadHandler($router, $roleChecker, $tokenValidator, auditLogger: $auditLogger),
        'prompts/list'            => new PromptsListHandler($promptRegistry, $roleChecker, $tokenValidator),
        'prompts/get'             => new PromptsGetHandler($promptRegistry, $roleChecker, $tokenValidator),
    ], $tokenValidator, $mcpRateLimiter, $tenantMcpEnabled),
    enabled: (bool) filter_var($_ENV['MCP_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
);
$router->registerUnversioned('POST', '/mcp', [$mcpTransportHandler, 'handlePost']);
$router->registerUnversioned('GET',  '/mcp', [$mcpTransportHandler, 'handleGet']);

// Load plugins AFTER every core route (WC-169): the Router refuses duplicate
// method+path registrations (first wins), so core routes can never be
// shadowed by a plugin claiming the same path.
$pluginLoader->load();
$pluginLoader->collectMcpPrompts($promptRegistry);

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
                // withHeaders() preserves the concrete response type (StreamedResponse etc.)
                // so the streamer is not lost when merging headers.
                $response = $response->withHeaders(array_merge($corsHeaders, $securityHeaders));

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
        // withHeaders() preserves the concrete response type (StreamedResponse etc.)
        // so the streamer is not lost when merging headers.
        $response = $response->withHeaders(array_merge($corsHeaders, $securityHeaders));

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
    }
}

