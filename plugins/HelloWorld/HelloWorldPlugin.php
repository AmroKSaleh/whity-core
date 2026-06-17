<?php

declare(strict_types=1);

namespace HelloWorld;

use HelloWorld\Api\GreetingsApiHandler;
use HelloWorld\Migrations\CreateHelloGreetingsTable;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;
use Whity\Sdk\PluginRolesInterface;

/**
 * HelloWorldPlugin
 *
 * A complete, copy-paste reference plugin that accompanies the
 * {@see docs/wiki/Plugin-Development.md} tutorial. It demonstrates every
 * capability surface exposed by the SDK:
 *
 *  - a public route: GET /api/hello
 *  - an admin-only route: GET /api/hello/admin
 *  - a tenant-scoped CRUD resource over its own table (WC-169):
 *    GET/POST /api/hello/greetings + PATCH/DELETE /api/hello/greetings/{id},
 *    gated on `hello:view` (reads) / `hello:manage` (writes) via the
 *    host-enforced route-level `requiredPermission` (SDK 1.2)
 *  - a frontend feature descriptor ({@see PluginFrontendInterface}, SDK 1.2)
 *    so the host admin UI renders a schema-driven screen for the resource
 *  - permissions declared in the mandated `resource:action` colon notation
 *  - a hook that runs custom logic before a user is created (`user.creating`)
 *  - a migration class registered for the platform migration runner
 *
 * It lives in its own directory (`plugins/HelloWorld/`) so the PluginLoader
 * resolves it under the `HelloWorld` namespace prefix (directory name) and
 * auto-discovers it without any manual registration.
 *
 * Since WC-162 the plugin's CONTRACT types come only from the standalone
 * `whity/plugin-sdk` package — never from whity-core — which is what makes it
 * distributable to any Whity-based host application. The one deliberate host
 * seam is data access: route handlers needing the database resolve the host's
 * shared lazy Database service from the `\Whity` service container at request
 * time (see {@see self::resolvePdo()}), analogous to the migration runner
 * injecting a PDO into plugin migrations.
 */
final class HelloWorldPlugin implements PluginInterface, PluginRequirementsInterface, PluginFrontendInterface, PluginRolesInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'HelloWorld';
    }

    /**
     * The SDK range this plugin supports (WC-165 version gate).
     *
     * @inheritDoc
     */
    public function getSdkConstraint(): string
    {
        // Requires SDK 1.2: PluginFrontendInterface + host-enforced
        // route-level requiredPermission.
        return '^1.2';
    }

    /**
     * No host core-version constraint (WC-211): HelloWorld runs against any
     * core version that ships the SDK range it requires.
     *
     * @inheritDoc
     */
    public function getCoreConstraint(): string
    {
        return '';
    }

    /**
     * HelloWorld depends on no other plugin.
     *
     * @inheritDoc
     */
    public function getPluginDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.1.0';
    }

    /**
     * @inheritDoc
     */
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/hello',
                'handler' => [$this, 'hello'],
                'requiredRole' => null,
                // Typed OpenAPI declaration (WC-166): the host's
                // generate:openapi emits this as a $ref'd response and hoists
                // the Greeting component into components.schemas.
                'schema' => [
                    'summary' => 'Public greeting',
                    'tags' => ['hello'],
                    'responses' => [
                        200 => 'Greeting',
                    ],
                    'components' => [
                        'Greeting' => [
                            'type' => 'object',
                            'required' => ['message', 'plugin', 'version'],
                            'properties' => [
                                'message' => ['type' => 'string'],
                                'plugin' => ['type' => 'string'],
                                'version' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/hello/admin',
                'handler' => [$this, 'adminHello'],
                'requiredRole' => 'admin',
            ],
            // ---- the tenant-scoped greetings CRUD resource (WC-169) ----
            // Reads are gated on hello:view, writes on hello:manage via the
            // route-level requiredPermission the host enforces (SDK 1.2). The
            // typed 'schema' declarations publish the resource's contract
            // through the host's generate:openapi.
            [
                'method' => 'GET',
                'path' => '/api/hello/greetings',
                'handler' => [$this, 'listGreetings'],
                'requiredRole' => null,
                'requiredPermission' => 'hello:view',
                'schema' => [
                    'summary' => 'List the tenant\'s greetings (newest first)',
                    'tags' => ['hello'],
                    'responses' => [
                        200 => 'HelloGreetingListResponse',
                        403 => ['description' => 'Missing hello:view or unresolved tenant context'],
                    ],
                    'components' => self::greetingComponents(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/hello/greetings',
                'handler' => [$this, 'createGreeting'],
                'requiredRole' => null,
                'requiredPermission' => 'hello:manage',
                'schema' => [
                    'summary' => 'Create a greeting in the caller\'s tenant',
                    'tags' => ['hello'],
                    'request' => 'HelloGreetingCreateRequest',
                    'responses' => [
                        201 => 'HelloGreetingResponse',
                        400 => ['description' => 'message missing, empty, or longer than 255 characters'],
                        403 => ['description' => 'Missing hello:manage or unresolved tenant context'],
                    ],
                    'components' => self::greetingComponents(),
                ],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/hello/greetings/{id:\d+}',
                'handler' => [$this, 'updateGreeting'],
                'requiredRole' => null,
                'requiredPermission' => 'hello:manage',
                'schema' => [
                    'summary' => 'Update a greeting (tenant-scoped 404 semantics)',
                    'tags' => ['hello'],
                    'request' => 'HelloGreetingCreateRequest',
                    'responses' => [
                        200 => 'HelloGreetingResponse',
                        400 => ['description' => 'message missing, empty, or longer than 255 characters'],
                        403 => ['description' => 'Missing hello:manage or unresolved tenant context'],
                        404 => ['description' => 'Greeting not found in the caller\'s tenant'],
                    ],
                    'components' => self::greetingComponents(),
                ],
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/hello/greetings/{id:\d+}',
                'handler' => [$this, 'deleteGreeting'],
                'requiredRole' => null,
                'requiredPermission' => 'hello:manage',
                'schema' => [
                    'summary' => 'Delete a greeting (tenant-scoped 404 semantics)',
                    'tags' => ['hello'],
                    'responses' => [
                        // MutationResponse-like confirmation, declared inline
                        // because plugin specs stay self-contained (no $refs
                        // into host-owned components).
                        200 => [
                            'description' => 'Deletion confirmation',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['data'],
                                        'properties' => [
                                            'data' => [
                                                'type' => 'object',
                                                'required' => ['id', 'message'],
                                                'properties' => [
                                                    'id' => ['type' => 'integer'],
                                                    'message' => ['type' => 'string'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        403 => ['description' => 'Missing hello:manage or unresolved tenant context'],
                        404 => ['description' => 'Greeting not found in the caller\'s tenant'],
                    ],
                    'components' => self::greetingComponents(),
                ],
            ],
        ];
    }

    /**
     * The OpenAPI component schemas the greetings resource publishes.
     *
     * Shared by every greetings route declaration; the host's generator
     * hoists them into components.schemas (idempotent re-contribution).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function greetingComponents(): array
    {
        return [
            'HelloGreeting' => [
                'type' => 'object',
                'required' => ['id', 'tenantId', 'message', 'createdAt'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'tenantId' => ['type' => 'integer'],
                    'message' => ['type' => 'string'],
                    'createdAt' => ['type' => 'string', 'nullable' => true],
                ],
            ],
            'HelloGreetingListResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/HelloGreeting'],
                    ],
                ],
            ],
            'HelloGreetingResponse' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/HelloGreeting'],
                ],
            ],
            'HelloGreetingCreateRequest' => [
                'type' => 'object',
                'required' => ['message'],
                'properties' => [
                    'message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                ],
            ],
        ];
    }

    /**
     * Declare the admin-UI screen for the greetings resource (SDK 1.2).
     *
     * UI metadata only — the descriptor grants nothing; the host validates it,
     * filters it per caller against `hello:view`, and serves it via
     * GET /api/frontend/features. Data access stays enforced by the
     * route-level RBAC declared in {@see getRoutes()}.
     *
     * @inheritDoc
     */
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'hello-greetings',
                'label' => 'Greetings',
                'icon' => 'message-circle',
                'group' => 'plugins',
                'order' => 10,
                'screen' => 'crud',
                'resource' => [
                    'basePath' => '/api/hello/greetings',
                    'titleField' => 'message',
                ],
                'requiredPermission' => 'hello:view',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getPermissions(): array
    {
        // Permissions use the mandated `resource:action` colon notation,
        // validated against /^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/ by the RBAC layer.
        return [
            'hello:view',
            'hello:manage',
        ];
    }

    /**
     * Declare a hello_viewer role to seed on activation (PluginRolesInterface).
     *
     * The hello_viewer role demonstrates the native role-seeding capability: when
     * this plugin activates, the host creates this role (if absent) and grants it
     * the hello:view permission so users assigned the role can access the greetings
     * read endpoints out-of-the-box without any manual configuration.
     *
     * @inheritDoc
     */
    public function getRoles(): array
    {
        return [
            'hello_viewer' => ['description' => 'Can view Hello World content'],
        ];
    }

    /**
     * Grant hello:view to the hello_viewer role on activation.
     *
     * @inheritDoc
     */
    public function getRolePermissions(): array
    {
        return [
            'hello_viewer' => ['hello:view'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getHooks(): array
    {
        // Events::USER_CREATING is the real filter hook dispatched by the host
        // immediately before a user row is inserted. Listeners receive the
        // (mutable) user payload and must return it.
        return [
            Events::USER_CREATING => [
                'callback' => [$this, 'onUserCreating'],
                'priority' => 10,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getMigrations(): array
    {
        return [
            CreateHelloGreetingsTable::class,
            // WC-169: seed hello:view/hello:manage into the persisted catalogue
            // and grant them to the admin role(s), so the frontend feature works
            // out-of-the-box on a fresh install.
            Migrations\GrantGreetingsPermissionsToAdmin::class,
        ];
    }

    /**
     * Handle the public greeting route: GET /api/hello.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response A JSON greeting.
     */
    public function hello(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello, World!',
            'plugin' => $this->getName(),
            'version' => $this->getVersion(),
        ]);
    }

    /**
     * Handle the admin-only greeting route: GET /api/hello/admin.
     *
     * The router enforces the `requiredRole` of `admin` declared in
     * {@see getRoutes()} before this handler is ever invoked.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response A JSON greeting for administrators.
     */
    public function adminHello(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello, administrator!',
            'plugin' => $this->getName(),
        ]);
    }

    /**
     * Handle GET /api/hello/greetings (requires hello:view).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters.
     * @return Response The tenant-scoped greeting list.
     */
    public function listGreetings(Request $request, array $params = []): Response
    {
        return $this->greetingsHandler()->list($request);
    }

    /**
     * Handle POST /api/hello/greetings (requires hello:manage).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters.
     * @return Response The created greeting (201) or a validation error.
     */
    public function createGreeting(Request $request, array $params = []): Response
    {
        return $this->greetingsHandler()->create($request);
    }

    /**
     * Handle PATCH /api/hello/greetings/{id} (requires hello:manage).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters ('id').
     * @return Response The updated greeting or a tenant-scoped 404.
     */
    public function updateGreeting(Request $request, array $params = []): Response
    {
        return $this->greetingsHandler()->update($request, $params);
    }

    /**
     * Handle DELETE /api/hello/greetings/{id} (requires hello:manage).
     *
     * @param Request $request The incoming HTTP request.
     * @param array<string, string> $params Captured path parameters ('id').
     * @return Response A deletion confirmation or a tenant-scoped 404.
     */
    public function deleteGreeting(Request $request, array $params = []): Response
    {
        return $this->greetingsHandler()->delete($request, $params);
    }

    /**
     * Build the greetings handler with a freshly resolved PDO.
     *
     * Resolved PER REQUEST (not cached) so the host's connection self-healing
     * and recycling are honoured — a cached handle would pin a connection the
     * host may have already replaced.
     *
     * @return GreetingsApiHandler The DB-backed CRUD handler.
     */
    private function greetingsHandler(): GreetingsApiHandler
    {
        return new GreetingsApiHandler($this->resolvePdo());
    }

    /**
     * Resolve a live PDO from the host's service container.
     *
     * The host registers its shared, lazy, self-healing
     * {@see \Whity\Database\Database} service under its class name in the
     * `\Whity` service container (public/index.php) — the same container the
     * HookManager already travels through. This is the documented wiring seam
     * for plugin data access; a host without the service yields a safe 500
     * via the loader's per-plugin error boundary.
     *
     * @return \PDO Live database connection.
     */
    private function resolvePdo(): \PDO
    {
        $database = \Whity\app(\Whity\Database\Database::class);
        if (!$database instanceof \Whity\Database\Database) {
            throw new \RuntimeException('The host did not register the shared Database service');
        }

        return $database->getPdo();
    }

    /**
     * Run custom logic before a user is created.
     *
     * Subscribed to the `user.creating` filter hook. The platform passes the
     * pending user payload (`email`, `password`, `role_id`); a filter hook may
     * inspect and mutate it, then MUST return the (possibly modified) array so
     * downstream listeners and the core see the change.
     *
     * Here we normalise the email to lower-case and stamp the payload so the
     * effect is observable, without performing any I/O.
     *
     * @param array<string, mixed> $data    The pending user payload.
     * @param array<string, mixed> $context Execution context (tenant_id, timestamp).
     * @return array<string, mixed> The filtered payload.
     */
    public function onUserCreating(array $data, array $context): array
    {
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        $data['hello_world_greeted'] = true;

        return $data;
    }
}
