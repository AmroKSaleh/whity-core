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
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\RoleCheckerPermissionResolver;
use Whity\Sdk\Rbac\PermissionResolver;
use Whity\Core\Hooks\HookManager;
use Whity\Database\Database;
use Whity\Core\Identity\AuthMethod;
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
        // Registered as a service exactly as public/index.php does. Without it,
        // the CLI kernel's simulated API could not serve a plugin route at all:
        // the documented plugin seam is `\Whity\app(Database::class)` (both
        // in-tree pilot plugins use it), Database's constructor is private, so
        // the lookup threw "not instantiable" and every plugin route reached
        // through a whity-cli command 500'd while the same route over HTTP
        // worked. Loud rather than silent, unlike the permission registry below,
        // but the same entry-point divergence (#717, #724).
        \Whity\register_service(Database::class, $db);

        $router = new Router('');

        $appEnv = $_ENV['APP_ENV'] ?? 'production';
        if ($appEnv !== 'development' && empty($_ENV['JWT_SECRET'])) {
            throw new \RuntimeException('JWT_SECRET environment variable must be set in production environments');
        }
        $jwtSecret = $_ENV['JWT_SECRET'] ?? 'dev_secret_key_change_in_production';
        $jwtParser = new JwtParser($jwtSecret);
        $permissionRegistry = new PermissionRegistry();
        // Registered as a service exactly as public/index.php does, so a plugin
        // reached through a CLI command resolves the same populated catalogue as
        // one reached over HTTP.
        //
        // Neither entry point registered it, and because the container used to
        // auto-instantiate any concrete class with no REQUIRED constructor
        // arguments, the lookup silently returned a fresh, EMPTY registry rather
        // than throwing: every plugin permission read as unregistered and the
        // caller failed closed with nothing to diagnose from.
        \Whity\register_service(PermissionRegistry::class, $permissionRegistry);

        $hookManager = new HookManager();
        \Whity\register_service(HookManager::class, $hookManager);

        // WC-712: mirror public/index.php's RBAC wiring exactly.
        //
        // This kernel previously built a RoleChecker with NO delegation
        // resolver, so the CLI's simulated API enforced a DIFFERENT
        // authorization policy from the HTTP API: a permission held only
        // through a live, non-revoked delegation opened a route over HTTP and
        // was invisible over the CLI. Two answers to the same authorization
        // question, decided by which entry point happened to ask.
        //
        // The bounding checker stays delegation-UNAWARE on purpose (a grantor
        // may delegate only what BASE RBAC grants them, never what was
        // delegated to them — no transitive re-delegation, WC-34); the checker
        // the middleware enforces with is the delegation-aware one.
        $baseRoleChecker = new RoleChecker($db, $permissionRegistry);
        $delegationService = new DelegationService(
            new DelegationRepository($db->getPdo()),
            $baseRoleChecker,
            $permissionRegistry
        );
        $roleChecker = new RoleChecker($db, $permissionRegistry, null, $delegationService);
        $rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);

        // Same read-only resolver contract the HTTP entry point registers, over
        // the same delegation-aware checker, so plugin code reached through a
        // CLI command resolves permissions identically to a web request.
        \Whity\register_service(
            PermissionResolver::class,
            new RoleCheckerPermissionResolver($roleChecker, $permissionRegistry)
        );

        $tenantIsolationMiddleware = new EnforceTenantIsolation($jwtParser);

        $this->kernel = new HttpKernel($router, $rbacMiddleware);
        $this->kernel->use($tenantIsolationMiddleware);

        // Resource-type catalogue (WC-712 §2), registered as a service exactly as
        // public/index.php does so a plugin reached through a CLI command sees
        // the same vocabulary as one reached over HTTP.
        $resourceTypeRegistry = new \Whity\Core\RBAC\ResourceTypeRegistry($hookManager);
        $resourceTypeRegistry->registerCoreResourceTypes();
        \Whity\register_service(\Whity\Core\RBAC\ResourceTypeRegistry::class, $resourceTypeRegistry);

        // OU-type catalogue (#822), registered as a service for exactly the same
        // reason: a plugin's contributed types must be adoptable whether the
        // request arrived over HTTP or through a command, and a CLI-only empty
        // catalogue would report that a plugin's type simply does not exist.
        $ouTypeRegistry = new \Whity\Core\Ou\OuTypeRegistry($hookManager);
        $ouTypeRegistry->registerCoreOuTypes();
        \Whity\register_service(\Whity\Core\Ou\OuTypeRegistry::class, $ouTypeRegistry);

        // Document ROUTING RULE catalogue (#947 item 3), registered as a service
        // for exactly the same reason. A command that issues or advances a route
        // — a scheduled escalation, an import that circulates what it created —
        // resolves its steps through this registry, and a CLI-only EMPTY
        // catalogue would answer that core's own `role` kind does not exist. The
        // route would then fail to resolve at send time rather than at
        // authoring, which is the worst place for it: the document is already
        // issued.
        //
        // Divergence between the two entry points here is the recurring bug class
        // this repo has already paid for twice (#717, #724), which is why the
        // core resolvers are registered identically in both.
        $routingRuleRegistry = new \Whity\Core\Document\Routing\RoutingRuleRegistry($hookManager);
        $routingRuleRegistry->registerCoreRoutingRules(
            new \Whity\Core\Document\Routing\RoleRuleResolver($db->getPdo()),
            new \Whity\Core\Document\Routing\RoleBelowActorRuleResolver($db->getPdo())
        );
        \Whity\register_service(\Whity\Core\Document\Routing\RoutingRuleRegistry::class, $routingRuleRegistry);

        // INBOX SOURCE catalogue (#881). Registered with core's routing source
        // already attached, so a command asking "what is awaiting this person"
        // gets the same answer the HTTP surface gives. An empty registry here
        // would report every inbox as EMPTY — which is the most ordinary answer
        // an inbox has, so nothing would look wrong.
        $inboxSourceRegistry = new \Whity\Core\Inbox\InboxSourceRegistry();
        $inboxSourceRegistry->registerCoreSource(
            \Whity\Core\Inbox\InboxSourceRegistry::CORE_DOCUMENT_ROUTING,
            new \Whity\Core\Document\Routing\DocumentRoutingInboxSource(
                new \Whity\Core\Document\Routing\RouteRecipientRepository($db->getPdo())
            )
        );
        \Whity\register_service(\Whity\Core\Inbox\InboxSourceRegistry::class, $inboxSourceRegistry);

        // DOCUMENT ORGANIZER registries (#978). Built here for the same reason
        // the routing rule catalogue above is: a CLI-driven caller resolving
        // either of these from the container must get the POPULATED one.
        //
        // The failure an empty one produces is the quietest in this file. An
        // empty view registry answers "this installation computes no document
        // folders" — which is a perfectly ordinary answer, indistinguishable
        // from a correctly-wired install whose substrates are genuinely absent,
        // and it is the exact absent-versus-empty conflation #978 exists to
        // prevent one layer up. Both classes are HostWiredService, so an
        // unregistered lookup throws instead of improvising; registering them
        // here is what keeps that throw from being the CLI's normal state.
        //
        // Per request on the HTTP side, per command here — never cached across
        // either. Availability is measured against the live schema, and a
        // cached answer would outlive the migration that changes it (#701).
        $documentSubstrateRegistry = new \Whity\Core\Document\Organizer\DocumentSubstrateRegistry(
            new \Whity\Core\Document\Organizer\PdoSchemaPresence($db->getPdo())
        );
        \Whity\Core\Document\Organizer\CoreDocumentSubstrates::registerInto($documentSubstrateRegistry);

        $documentViewRegistry = new \Whity\Core\Document\Organizer\DocumentViewRegistry($documentSubstrateRegistry);
        \Whity\Core\Document\Organizer\CoreDocumentViews::registerInto($documentViewRegistry);

        \Whity\register_service(
            \Whity\Core\Document\Organizer\DocumentSubstrateRegistry::class,
            $documentSubstrateRegistry
        );
        \Whity\register_service(
            \Whity\Core\Document\Organizer\DocumentViewRegistry::class,
            $documentViewRegistry
        );

        // Status-page probe catalogue (WC-status-probes), registered as a service
        // exactly as public/index.php does. A divergence between the two entry
        // points here is the recurring bug class this repo has already paid for
        // twice (#717, #724): a plugin reached through a CLI command would ask
        // for the catalogue and get a RuntimeException, or worse, build its own
        // empty one and conclude nothing is being watched.
        $healthProbeRegistry = new \Whity\Core\Health\HealthProbeRegistry($hookManager);
        $healthProbeRegistry->registerCoreProbes();
        \Whity\register_service(\Whity\Core\Health\HealthProbeRegistry::class, $healthProbeRegistry);

        // Table ownership + data types (WC-723), registered as services exactly as
        // public/index.php does. A divergence between these two entry points is a
        // recurring bug class here (#717 for the RoleChecker, #724 for the
        // permission and resource-type registries): without this, a plugin's
        // declared tables and data types would exist over HTTP and simply not
        // exist under the CLI, so a lifecycle transition performed by a command
        // would silently answer "unregistered type" for a type that is declared.
        $tableOwnershipRegistry = new \Whity\Core\Tenant\TableOwnershipRegistry($hookManager);
        $tableOwnershipRegistry->registerCoreTables();
        \Whity\register_service(\Whity\Core\Tenant\TableOwnershipRegistry::class, $tableOwnershipRegistry);

        $dataTypeRegistry = new \Whity\Core\DataType\DataTypeRegistry($tableOwnershipRegistry, $hookManager);
        \Whity\register_service(\Whity\Core\DataType\DataTypeRegistry::class, $dataTypeRegistry);

        // Plugin-declared SETTINGS (#713 item 1), registered as services exactly
        // as public/index.php does. The divergence this guards against is the
        // quietest one yet: without the catalogue here, a key a plugin declared
        // would exist over HTTP and simply not exist under the CLI, so a command
        // reading it would resolve the registry DEFAULT while the web host
        // resolved the operator's configured value — two different answers to
        // the same question, neither of which throws.
        $pluginSettingsRegistry = new \Whity\Core\Settings\PluginSettingsRegistry($hookManager);
        \Whity\register_service(\Whity\Core\Settings\PluginSettingsRegistry::class, $pluginSettingsRegistry);

        $settingsCatalog = new \Whity\Core\Settings\SettingsCatalog($pluginSettingsRegistry);
        \Whity\register_service(\Whity\Core\Settings\SettingsCatalog::class, $settingsCatalog);

        \Whity\register_service(
            \Whity\Core\Settings\SettingsService::class,
            new \Whity\Core\Settings\SettingsService(
                new \Whity\Core\Settings\GlobalSettingsRepository($db->getPdo()),
                new \Whity\Core\Settings\TenantSettingsRepository($db->getPdo()),
                $settingsCatalog
            )
        );

        $dataTypeLifecycle = new \Whity\Core\DataType\DataTypeLifecycleService(
            $db->getPdo(),
            $dataTypeRegistry,
            $hookManager
        );
        \Whity\register_service(\Whity\Core\DataType\DataTypeLifecycleService::class, $dataTypeLifecycle);
        \Whity\register_service(\Whity\Sdk\DataType\DataTypeGuard::class, $dataTypeLifecycle);
        // Same registration as public/index.php, for the same reason the two
        // above are mirrored here: a plugin reached through a CLI command must be
        // able to clear a record's remembered restore state after hard-deleting
        // it outside core. Registered in one entry point only, "clear the memory"
        // would work over HTTP and throw under the CLI — the divergence bug class
        // this file has already paid for in #717 and #724.
        \Whity\register_service(\Whity\Core\DataType\LifecycleStateMemory::class, $dataTypeLifecycle->stateMemory());

        // The WRITE contract, registered here for the same reason every service
        // above is: an entry-point divergence is this file's recurring bug class
        // (#717, #724, #727). Registered over HTTP only, a plugin trashing a
        // record through \Whity\app(DataTypeLifecycle::class) would work in a
        // request and throw inside a `whity-cli` command — and the command is
        // exactly where a bulk sweep (empty-trash, bulk retire) runs.
        \Whity\register_service(
            \Whity\Sdk\DataType\DataTypeLifecycle::class,
            new \Whity\Core\DataType\GatedDataTypeLifecycle(
                $dataTypeRegistry,
                $dataTypeLifecycle,
                $roleChecker
            )
        );

        // The host-owned sequence allocator (migration 092), mirrored here for
        // the same reason as everything above it: a plugin reached through a CLI
        // command — a queue worker running a numbering job, an import — must be
        // able to allocate a number. Registered in one entry point only, document
        // numbering would work over HTTP and throw under `queue:work`.
        $sequenceCounters = new \Whity\Database\SequenceCounters($db->getPdo());
        \Whity\register_service(\Whity\Sdk\Sql\SequenceAllocator::class, $sequenceCounters);
        \Whity\register_service(\Whity\Database\SequenceCounters::class, $sequenceCounters);

        $baseDir = dirname(__DIR__, 3);
        // The registries are passed HERE, not just constructed above: this loader
        // previously received neither, so plugin-declared permissions were never
        // registered in the CLI at all and a route gated on one failed closed —
        // the same HTTP/CLI divergence WC-712 fixed for the RoleChecker above,
        // one layer out.
        //
        // The audit writer is passed for the same reason, and the case that
        // makes it necessary rather than tidy is `queue:work`: a plugin JOB that
        // completes a record and dispatches its own event runs under THIS
        // loader, so a worker without the writer would leave exactly the
        // background half of a plugin's activity missing from the trail — and
        // missing invisibly, since an unsubscribed event raises nothing.
        //
        // Subscribed to core's CRUD hooks here, exactly as public/index.php
        // does (#844). Before this, the entry point audited a PLUGIN's events
        // and not core's — the worst of the two readings available, because an
        // operator who sees a plugin's action in the trail concludes the trail
        // covers this process, and the `user.deleted` beside it that never
        // appeared is the one they go looking for during an incident. A hole
        // nobody knows about is worse than either an honest gap or none at all.
        //
        // WHO the row names: nobody, unless something authenticated. A command
        // typed into a shell has no authenticated principal, so `actor_user_id`
        // stays NULL and the origin below records WHY it is null. Inventing a
        // default user id here would be worse than the missing row this replaces
        // — a row that reads as a person having done it, which nothing could
        // later distinguish from one that really was. If the CLI ever
        // authenticates a real operator, {@see AuditContext} carries them and
        // the row records both facts (see AuditOrigin's docblock).
        $auditLogger = new \Whity\Core\Audit\AuditLogger(
            $db->getPdo(),
            null,
            \Whity\Core\Audit\AuditOrigin::cli(self::invokedCommand())
        );
        $auditLogger->subscribe($hookManager);
        $pluginLoader = new PluginLoader(
            $baseDir . '/plugins',
            $router,
            $permissionRegistry,
            $hookManager,
            null,
            null,
            $resourceTypeRegistry,
            $healthProbeRegistry,
            $tableOwnershipRegistry,
            $dataTypeRegistry,
            $pluginSettingsRegistry,
            $auditLogger,
            $ouTypeRegistry,
            // Plugin-contributed document routing rules (#947 item 3, SDK 1.36).
            // Handed to the loader in BOTH entry points, so a route authored over
            // HTTP against a plugin's kind still resolves when a command advances
            // it.
            $routingRuleRegistry
        );
        $pluginLoader->load();

        // Register API handlers (copied from public/index.php)
        // WC-203: permission-gated, mirroring public/index.php.
        $usersHandler = new UsersApiHandler($db->getPdo(), $hookManager);
        $router->register('GET',    '/api/users',          [$usersHandler, 'list'],   null, null, \Whity\Core\RBAC\CorePermissions::USERS_READ);
        $router->register('GET',    '/api/users/{id}',     [$usersHandler, 'get'],    null, null, \Whity\Core\RBAC\CorePermissions::USERS_READ);
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
        $router->register('POST', '/api/roles/{id}/permissions', [$rolesHandler, 'grantPermissions'], 'admin');
        $router->register('DELETE', '/api/roles/{id}/permissions', [$rolesHandler, 'revokePermissions'], 'admin');

        $tenantsHandler = new TenantsApiHandler($db->getPdo(), $hookManager);
        // Only GET allowed - tenants can view their own info
        // Create/update/delete restricted to system administrators (CLI only)
        // All four, not just list (#928). `tenant create/update/delete` are
        // documented commands that were never registered in this router, so
        // they answered 405 — a second, independent defect from the 401, and
        // one the 401 hid completely: nothing reached routing to find out.
        $router->register('GET',    '/api/tenants',      [$tenantsHandler, 'list'],   'admin');
        $router->register('POST',   '/api/tenants',      [$tenantsHandler, 'create'], 'admin');
        $router->register('PATCH',  '/api/tenants/{id}', [$tenantsHandler, 'update'], 'admin');
        $router->register('DELETE', '/api/tenants/{id}', [$tenantsHandler, 'delete'], 'admin');

        $permissionsHandler = new PermissionsApiHandler($db->getPdo());
        $router->register('GET', '/api/permissions', [$permissionsHandler, 'list'], 'admin');

        // The audit writer reaches this handler for the same reason it is
        // subscribed above: `plugin enable` from a shell installs code into the
        // platform, and it was the one CLI-driven mutation whose audit rows the
        // handler already knew how to write and simply had no writer for.
        $pluginsHandler = new PluginsApiHandler($baseDir . '/plugins', $pluginLoader, $db->getPdo(), $auditLogger);
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

        // Generate a CLI token if none provided. This synthetic token is
        // authorised via JwtParser in the RBAC/tenant middleware (NOT via
        // TokenValidator's cookie path), so it is never epoch-checked; the
        // token_epoch claim is included only for issuance consistency (WC-185)
        // and pinned to the default 0.
        //
        // IT CARRIES A REAL PROFILE ID (#928). The pre-cutover shape
        // (`user_id`/`role`, no `profile_id`) stopped working at the identity
        // cutover: `RbacMiddleware` requires an integer `profile_id` and fails
        // closed without one, so every gated route answered
        // `401 Invalid token payload` and the whole CLI API surface was dead.
        // Nothing in the suite noticed because the command tests mock callApi().
        //
        // A fabricated id would not have helped either — the middleware then
        // checks the role against the AUTHORITATIVE STORE — so the claim names
        // the seeded service principal (migration 107), which genuinely holds
        // `admin` in the system tenant and which nothing can authenticate as.
        //
        // TENANT 0, not 1. `EnforceTenantIsolation` pins every request to the
        // token's tenant, so the old `tenant_id: 1` would have refused
        // `tenant update 5` even once the 401 was fixed. Tenant 0 is the
        // platform-wide scope these commands have always meant to operate at.
        if (!$this->token) {
            $this->token = $jwtParser->create([
                'profile_id'  => self::servicePrincipalId($db),
                'sub'         => 'cli-service-principal',
                'tenant_id'   => 0,
                'token_epoch' => 0,
            ]);
        }
    }

    /**
     * The id of the CLI service principal (#928).
     *
     * Resolved by the held fact rather than a fixed id or an email, so there is
     * no second convention to keep in step with migration 107 — and so a
     * deployment whose ids differ (a restored dump, a re-seeded database) needs
     * no special handling.
     *
     * A missing principal is fatal ON PURPOSE. The alternatives are to mint a
     * token with no profile (which reproduces the 401 this fixes, with a more
     * confusing message) or to borrow another identity, which would attribute
     * operator commands to a real person — the outcome migration 107 exists to
     * avoid. An unmigrated database should say so plainly.
     *
     * @throws \RuntimeException When the principal has not been seeded.
     */
    private static function servicePrincipalId(Database $db): int
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $db->getPdo()->query(
            "SELECT id FROM profiles WHERE auth_method = '" . AuthMethod::SERVICE . "' ORDER BY id ASC LIMIT 1"
        );
        $row = $stmt !== false ? $stmt->fetch() : false;

        if (is_array($row) && isset($row['id'])) {
            return (int) $row['id'];
        }

        throw new \RuntimeException(
            'The CLI service principal is missing. Run `php public/index.php migrate` to apply '
            . 'migration 107; without it the CLI cannot authorize and every gated command '
            . 'returns 401.'
        );
    }

    /**
     * The command word the operator typed, for the audit trail's origin stamp.
     *
     * `argv[1]` in both entry points that dispatch these commands
     * (`bin/whity-cli <command>` and `php public/index.php <command>`), and only
     * argv[1]: the rest of the line is arguments, which routinely carry secrets
     * and must never reach a tenant-readable audit row. {@see AuditOrigin::cli()}
     * drops anything that does not look like a command name, so a process with
     * some other argv shape (a test runner) records the channel and no command
     * rather than a stray path.
     *
     * @return string|null The command word, or null when there is no usable one.
     */
    private static function invokedCommand(): ?string
    {
        $argv = $_SERVER['argv'] ?? null;

        return is_array($argv) && isset($argv[1]) && is_string($argv[1]) ? $argv[1] : null;
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
