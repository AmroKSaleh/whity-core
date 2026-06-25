<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Mcp\Tools\ToolDeriver;

/**
 * TDD tests for ToolDeriver (WC-001754c6).
 *
 * Verifies that route declarations (method + path + schema) are converted into
 * valid MCP tool definitions with correct name, description, and inputSchema.
 */
final class ToolDeriverTest extends TestCase
{
    // ── Filtering ─────────────────────────────────────────────────────────────

    public function testDeriveTools_skipsRoutes_withoutSchema(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => null],
            ['method' => 'POST', 'path' => '/api/users', 'schema' => []],
        ]);

        self::assertSame([], $deriver->deriveTools());
    }

    // ── Tool name (operationId) ───────────────────────────────────────────────

    public function testDeriveTools_usesExplicitOperationId(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => [
                'operationId' => 'list_users',
                'summary' => 'List users',
            ]],
        ]);

        $tools = $deriver->deriveTools();
        self::assertCount(1, $tools);
        self::assertSame('list_users', $tools[0]['name']);
    }

    public function testDeriveTools_derivesOperationId_fromMethodAndPath(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => ['summary' => 'List users']],
        ]);

        $tools = $deriver->deriveTools();
        self::assertSame('get_api_users', $tools[0]['name']);
    }

    public function testDeriveTools_derivesOperationId_forPathWithId(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'PATCH', 'path' => '/api/users/{id:\d+}', 'schema' => ['summary' => 'Update user']],
        ]);

        $tools = $deriver->deriveTools();
        self::assertSame('patch_api_users_id', $tools[0]['name']);
    }

    public function testDeriveTools_derivesOperationId_forDeleteWithNestedPath(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'DELETE', 'path' => '/api/roles/{id:\d+}', 'schema' => ['summary' => 'Delete role']],
        ]);

        $tools = $deriver->deriveTools();
        self::assertSame('delete_api_roles_id', $tools[0]['name']);
    }

    // ── Description ──────────────────────────────────────────────────────────

    public function testDeriveTools_usesSummaryAsDescription(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => [
                'summary' => 'List the tenant\'s users',
            ]],
        ]);

        $tools = $deriver->deriveTools();
        self::assertSame('List the tenant\'s users', $tools[0]['description']);
    }

    public function testDeriveTools_fallsBackToGeneratedSummary_whenMissing(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => ['operationId' => 'list_users']],
        ]);

        $tools = $deriver->deriveTools();
        self::assertStringContainsString('Get', $tools[0]['description']);
    }

    // ── inputSchema: path parameters ─────────────────────────────────────────

    public function testDeriveTools_inputSchema_includesPathParams_asRequired(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users/{id:\d+}', 'schema' => ['summary' => 'Get user']],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertSame('object', $input['type']);
        self::assertArrayHasKey('id', $input['properties']);
        self::assertSame('integer', $input['properties']['id']['type']);
        self::assertContains('id', $input['required']);
    }

    public function testDeriveTools_inputSchema_stringPathParam_forUnconstrainedSegment(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/mcp/tokens/{jti}', 'schema' => ['summary' => 'Get token']],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertSame('string', $input['properties']['jti']['type']);
        self::assertContains('jti', $input['required']);
    }

    public function testDeriveTools_inputSchema_noRequiredOrProperties_forSimpleRoute(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => ['summary' => 'List users']],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertSame('object', $input['type']);
        self::assertArrayNotHasKey('properties', $input);
        self::assertArrayNotHasKey('required', $input);
    }

    // ── inputSchema: query parameters ─────────────────────────────────────────

    public function testDeriveTools_inputSchema_includesQueryParams(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => [
                'summary' => 'List users',
                'parameters' => [
                    ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Search term'],
                    ['name' => 'page', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
            ]],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertArrayHasKey('search', $input['properties']);
        self::assertSame('string', $input['properties']['search']['type']);
        self::assertSame('Search term', $input['properties']['search']['description']);

        self::assertArrayHasKey('page', $input['properties']);
        self::assertContains('page', $input['required']);
        self::assertNotContains('search', $input['required']);
    }

    public function testDeriveTools_inputSchema_skipsNonQueryParams(): void
    {
        // Header params should be skipped (MCP sends args, not raw HTTP headers)
        $deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/test', 'schema' => [
                'summary' => 'Test',
                'parameters' => [
                    ['name' => 'X-Custom-Header', 'in' => 'header', 'schema' => ['type' => 'string']],
                ],
            ]],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertArrayNotHasKey('X-Custom-Header', $input['properties'] ?? []);
    }

    // ── inputSchema: request body (component reference) ───────────────────────

    public function testDeriveTools_inputSchema_mergesComponentProperties(): void
    {
        $components = [
            'UserCreateRequest' => [
                'type' => 'object',
                'required' => ['email', 'role_id'],
                'properties' => [
                    'email'   => ['type' => 'string', 'format' => 'email'],
                    'role_id' => ['type' => 'integer'],
                    'name'    => ['type' => 'string'],
                ],
            ],
        ];

        $deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/users', 'schema' => [
                'summary' => 'Create user',
                'request' => 'UserCreateRequest',
            ]],
        ], $components);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertArrayHasKey('email', $input['properties']);
        self::assertArrayHasKey('role_id', $input['properties']);
        self::assertArrayHasKey('name', $input['properties']);
        self::assertSame('string', $input['properties']['email']['type']);
        self::assertContains('email', $input['required']);
        self::assertContains('role_id', $input['required']);
        self::assertNotContains('name', $input['required']);
    }

    public function testDeriveTools_inputSchema_handlesUnknownComponentGracefully(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/items', 'schema' => [
                'summary' => 'Create item',
                'request' => 'NonExistentComponent',
            ]],
        ], []);

        $tools = $deriver->deriveTools();
        // Should not throw; inputSchema is still a valid (empty) object schema
        self::assertSame('object', $tools[0]['inputSchema']['type']);
    }

    // ── inputSchema: request body (inline schema) ─────────────────────────────

    public function testDeriveTools_inputSchema_mergesInlineRequestBody(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/tokens', 'schema' => [
                'summary' => 'Create token',
                'request' => [
                    'type' => 'object',
                    'required' => ['name'],
                    'properties' => [
                        'name'  => ['type' => 'string'],
                        'scope' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
            ]],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertArrayHasKey('name', $input['properties']);
        self::assertArrayHasKey('scope', $input['properties']);
        self::assertContains('name', $input['required']);
    }

    public function testDeriveTools_inputSchema_skipsMultipartRequestBody(): void
    {
        // Multipart request bodies have a 'content' key — skip body properties
        $deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/upload', 'schema' => [
                'summary' => 'Upload',
                'request' => [
                    'content' => ['multipart/form-data' => ['schema' => ['type' => 'object']]],
                ],
            ]],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        // Properties from multipart body should NOT be merged
        self::assertArrayNotHasKey('properties', $input);
    }

    // ── Combined path + body ──────────────────────────────────────────────────

    public function testDeriveTools_inputSchema_combinesPathAndBodyParams(): void
    {
        $components = [
            'UserUpdateRequest' => [
                'type' => 'object',
                'required' => ['name'],
                'properties' => [
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ],
            ],
        ];

        $deriver = new ToolDeriver([
            ['method' => 'PATCH', 'path' => '/api/users/{id:\d+}', 'schema' => [
                'summary' => 'Update user',
                'request' => 'UserUpdateRequest',
            ]],
        ], $components);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        // id from path
        self::assertArrayHasKey('id', $input['properties']);
        self::assertContains('id', $input['required']);

        // name, email from body
        self::assertArrayHasKey('name', $input['properties']);
        self::assertArrayHasKey('email', $input['properties']);
        self::assertContains('name', $input['required']);
    }

    // ── Multiple routes ───────────────────────────────────────────────────────

    public function testDeriveTools_returnsOneToolPerDeclarationWithSchema(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET',    'path' => '/api/users', 'schema' => ['summary' => 'List users']],
            ['method' => 'POST',   'path' => '/api/users', 'schema' => ['summary' => 'Create user']],
            ['method' => 'DELETE', 'path' => '/api/logs',  'schema' => null],
        ]);

        $tools = $deriver->deriveTools();
        self::assertCount(2, $tools);
    }

    // ── ToolsListHandler (smoke) ──────────────────────────────────────────────

    public function testToolsListHandler_returnsToolsWrappedInList(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/users', 'schema' => ['summary' => 'List users'], 'requiredRole' => null, 'requiredPermission' => null],
        ]);
        $roleChecker    = $this->createMock(\Whity\Auth\RoleChecker::class);
        $tokenValidator = $this->createMock(\Whity\Auth\TokenValidator::class);
        $tokenValidator->method('validateMcpToken')->willReturn(null);

        $handler = new \Whity\Mcp\Tools\ToolsListHandler($deriver, $roleChecker, $tokenValidator);
        $result  = $handler(null, null);

        self::assertIsArray($result);
        self::assertArrayHasKey('tools', $result);
        self::assertCount(1, $result['tools']);
    }

    // ── buildAccessMap() ──────────────────────────────────────────────────────

    public function testBuildAccessMap_returnsEmptyMap_whenNoDeclarations(): void
    {
        $deriver = new ToolDeriver([]);

        self::assertSame([], $deriver->buildAccessMap());
    }

    public function testBuildAccessMap_skipsDeclarationsWithoutSchema(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => null, 'requiredRole' => null, 'requiredPermission' => null],
        ]);

        self::assertSame([], $deriver->buildAccessMap());
    }

    public function testBuildAccessMap_mapsOpenTool_toNullPermissions(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things'], 'requiredRole' => null, 'requiredPermission' => null],
        ]);

        $map = $deriver->buildAccessMap();

        self::assertArrayHasKey('get_api_things', $map);
        self::assertNull($map['get_api_things']['requiredRole']);
        self::assertNull($map['get_api_things']['requiredPermission']);
    }

    public function testBuildAccessMap_mapsPermissionProtectedTool_toPermission(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things'], 'requiredRole' => null, 'requiredPermission' => 'things:read'],
        ]);

        $map = $deriver->buildAccessMap();

        self::assertSame('things:read', $map['get_api_things']['requiredPermission']);
        self::assertNull($map['get_api_things']['requiredRole']);
    }

    public function testBuildAccessMap_mapsRoleProtectedTool_toRole(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things'], 'requiredRole' => 'admin', 'requiredPermission' => null],
        ]);

        $map = $deriver->buildAccessMap();

        self::assertSame('admin', $map['get_api_things']['requiredRole']);
        self::assertNull($map['get_api_things']['requiredPermission']);
    }

    public function testBuildAccessMap_usesOperationId_asKey_whenPresent(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['operationId' => 'list_things', 'summary' => 'List things'], 'requiredRole' => null, 'requiredPermission' => 'things:read'],
        ]);

        $map = $deriver->buildAccessMap();

        self::assertArrayHasKey('list_things', $map);
        self::assertSame('things:read', $map['list_things']['requiredPermission']);
    }

    public function testBuildAccessMap_includesRouterNativeRoutes(): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/widgets', fn () => null, null, null, 'widgets:read', ['summary' => 'List widgets']);

        $deriver = new ToolDeriver([], [], $router);
        $map     = $deriver->buildAccessMap();

        self::assertArrayHasKey('get_api_widgets', $map);
        self::assertSame('widgets:read', $map['get_api_widgets']['requiredPermission']);
    }

    public function testBuildAccessMap_mapKeysMatchToolNames_fromDeriveTools(): void
    {
        $decls = [
            ['method' => 'GET',    'path' => '/api/things',           'schema' => ['summary' => 'List'],   'requiredRole' => null,    'requiredPermission' => null],
            ['method' => 'POST',   'path' => '/api/things',           'schema' => ['summary' => 'Create'], 'requiredRole' => 'admin',  'requiredPermission' => null],
            ['method' => 'DELETE', 'path' => '/api/things/{id:\d+}',  'schema' => ['summary' => 'Delete'], 'requiredRole' => null,     'requiredPermission' => 'things:delete'],
        ];
        $deriver = new ToolDeriver($decls);

        $tools   = $deriver->deriveTools();
        $map     = $deriver->buildAccessMap();
        $toolNames = array_column($tools, 'name');

        foreach ($toolNames as $name) {
            self::assertArrayHasKey($name, $map, "Tool '{$name}' missing from access map");
        }
    }

    // ── Route-scoped components (WC-d93f7ea2) ────────────────────────────────

    public function testDeriveTools_inputSchema_resolvesComponent_fromRouteScopedComponents(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/greetings', 'schema' => [
                'summary'    => 'Create greeting',
                'request'    => 'GreetingCreateRequest',
                'components' => [
                    'GreetingCreateRequest' => [
                        'type'       => 'object',
                        'required'   => ['message'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'lang'    => ['type' => 'string'],
                        ],
                    ],
                ],
            ]],
        ]);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        self::assertArrayHasKey('message', $input['properties']);
        self::assertArrayHasKey('lang', $input['properties']);
        self::assertContains('message', $input['required']);
    }

    public function testDeriveTools_inputSchema_globalComponentsTakePriority_overRouteScopedComponents(): void
    {
        $globalComponents = [
            'SharedRequest' => [
                'type'       => 'object',
                'required'   => ['global_field'],
                'properties' => ['global_field' => ['type' => 'string']],
            ],
        ];

        $deriver = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/things', 'schema' => [
                'summary'    => 'Create thing',
                'request'    => 'SharedRequest',
                'components' => [
                    'SharedRequest' => [
                        'type'       => 'object',
                        'required'   => ['route_field'],
                        'properties' => ['route_field' => ['type' => 'string']],
                    ],
                ],
            ]],
        ], $globalComponents);

        $tools = $deriver->deriveTools();
        $input = $tools[0]['inputSchema'];

        // Global wins
        self::assertArrayHasKey('global_field', $input['properties']);
        self::assertArrayNotHasKey('route_field', $input['properties']);
    }

    public function testDeriveTools_inputSchema_routerNativeRoute_resolvesComponent_fromRouteScopedComponents(): void
    {
        $router = new Router('');
        $router->registerUnversioned(
            'POST', '/api/plugin/items', fn () => null, null, null, 'items:manage',
            [
                'summary'    => 'Create plugin item',
                'request'    => 'PluginItemRequest',
                'components' => [
                    'PluginItemRequest' => [
                        'type'       => 'object',
                        'required'   => ['title'],
                        'properties' => ['title' => ['type' => 'string']],
                    ],
                ],
            ]
        );

        $deriver = new ToolDeriver([], [], $router);
        $tools   = $deriver->deriveTools();
        $input   = $tools[0]['inputSchema'];

        self::assertArrayHasKey('title', $input['properties']);
        self::assertContains('title', $input['required']);
    }

    // ── Lint warnings (WC-d93f7ea2) ──────────────────────────────────────────

    public function testDeriveTools_emitsWarning_whenRequestComponentNotResolvable(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/items', 'schema' => [
                'summary' => 'Create item',
                'request' => 'MissingComponent',
            ]],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('MissingComponent', $warnings[0]);
        self::assertStringContainsString('/api/items', $warnings[0]);
    }

    public function testDeriveTools_doesNotEmitWarning_whenComponentResolvable_fromGlobalComponents(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/items', 'schema' => [
                'summary' => 'Create item',
                'request' => 'ItemRequest',
            ]],
        ], [
            'ItemRequest' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertSame([], $warnings);
    }

    public function testDeriveTools_doesNotEmitWarning_whenComponentResolvable_fromRouteScopedComponents(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/items', 'schema' => [
                'summary'    => 'Create item',
                'request'    => 'ItemRequest',
                'components' => [
                    'ItemRequest' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                ],
            ]],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertSame([], $warnings);
    }

    public function testDeriveTools_emitsWarning_forMutationRouteWithNoRequest(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/items', 'schema' => ['summary' => 'Create item']],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertCount(1, $warnings);
        self::assertStringContainsString('/api/items', $warnings[0]);
    }

    public function testDeriveTools_emitsWarning_forPutRouteWithNoRequest(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'PUT', 'path' => '/api/items/{id:\d+}', 'schema' => ['summary' => 'Replace item']],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertCount(1, $warnings);
    }

    public function testDeriveTools_emitsWarning_forPatchRouteWithNoRequest(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'PATCH', 'path' => '/api/items/{id:\d+}', 'schema' => ['summary' => 'Update item']],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertCount(1, $warnings);
    }

    public function testDeriveTools_doesNotEmitWarning_forGetRouteWithNoRequest(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/items', 'schema' => ['summary' => 'List items']],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertSame([], $warnings);
    }

    public function testDeriveTools_doesNotEmitWarning_forDeleteRouteWithNoRequest(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'DELETE', 'path' => '/api/items/{id:\d+}', 'schema' => ['summary' => 'Delete item']],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertSame([], $warnings);
    }

    public function testDeriveTools_doesNotEmitWarning_forMutationRouteWithInlineRequest(): void
    {
        $warnings = [];
        $deriver  = new ToolDeriver([
            ['method' => 'POST', 'path' => '/api/items', 'schema' => [
                'summary' => 'Create item',
                'request' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ]],
        ], [], null, static function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
        });

        $deriver->deriveTools();

        self::assertSame([], $warnings);
    }
}
