<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use UiKitShowcase\Migrations\GrantUiKitViewToAdmin;
use UiKitShowcase\UiKitShowcasePlugin;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\Frontend\Blocks\BlockContract;
use Whity\Sdk\Frontend\Blocks\BlockValidator;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

require_once dirname(__DIR__, 2) . '/plugins/UiKitShowcase/UiKitShowcasePlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/UiKitShowcase/Migrations/GrantUiKitViewToAdmin.php';

/**
 * WC-228 / WC-232: the UiKitShowcase example plugin proves and documents the
 * full SP1 + SP2 block system end-to-end. It contributes ONE `screen: 'blocks'`
 * feature whose tree (a) passes {@see BlockValidator::validate()} and
 * (b) contains a live instance of EVERY block type in
 * {@see BlockContract::types()} — including the SP2 data-bound types
 * (dataTable, dataStat, dataList, added in WC-232) — beside the PHP snippet
 * that declares it.
 *
 * As of WC-232 the plugin also exposes two read-only demo endpoints
 * (`GET /api/uikit/demo/rows` and `GET /api/uikit/demo/metric`) that the
 * data-bound blocks bind to via their `source` prop.
 */
final class UiKitShowcasePluginTest extends TestCase
{
    public function testImplementsTheThreeSdkCapabilityInterfaces(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $this->assertInstanceOf(PluginInterface::class, $plugin);
        $this->assertInstanceOf(PluginRequirementsInterface::class, $plugin);
        $this->assertInstanceOf(PluginFrontendInterface::class, $plugin);

        $this->assertSame('UiKitShowcase', $plugin->getName());
        $this->assertSame('1.0.0', $plugin->getVersion());
    }

    public function testDeclaresTheSdkConstraintAndBackendSurface(): void
    {
        $plugin = new UiKitShowcasePlugin();

        // Interactive block types landed in SDK 1.8, so the plugin requires that range (WC-236).
        $this->assertSame('^1.8', $plugin->getSdkConstraint());
        $this->assertSame('', $plugin->getCoreConstraint());
        $this->assertSame([], $plugin->getPluginDependencies());

        // No hooks; migrations unchanged.
        $this->assertSame([], $plugin->getHooks());
        $this->assertSame([GrantUiKitViewToAdmin::class], $plugin->getMigrations());

        // Three demo routes now declared (SP2 GET rows+metric, SP3 POST echo, WC-236).
        $routes = $plugin->getRoutes();
        $this->assertNotSame([], $routes, 'The showcase now declares demo routes (SP2+SP3)');
    }

    public function testDeclaresTheSingleColonNotationPermission(): void
    {
        $permissions = (new UiKitShowcasePlugin())->getPermissions();

        $this->assertContains('uikit:view', $permissions);

        foreach ($permissions as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/',
                $permission
            );
        }
    }

    public function testDeclaresExactlyOneBlocksFeatureGatedOnUikitView(): void
    {
        $features = (new UiKitShowcasePlugin())->getFrontendFeatures();

        $this->assertCount(1, $features, 'The showcase contributes exactly one feature');

        $feature = $features[0];
        $this->assertSame('ui-kit-reference', $feature['id']);
        $this->assertSame('blocks', $feature['screen']);
        $this->assertSame('uikit:view', $feature['requiredPermission']);
        $this->assertSame('plugins', $feature['group']);
        $this->assertIsString($feature['label']);
        $this->assertNotSame('', $feature['label']);
        $this->assertArrayHasKey('blocks', $feature);
        $this->assertIsArray($feature['blocks']);
    }

    // ---- WC-232: demo routes ----

    public function testGetRoutesIncludesAllDemoEndpoints(): void
    {
        $plugin = new UiKitShowcasePlugin();
        $routes = $plugin->getRoutes();

        /** @var array<string, array<string, mixed>> $byPath */
        $byPath = [];
        foreach ($routes as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            $method = is_string($r['method'] ?? null) ? (string) $r['method'] : '';
            $path   = is_string($r['path'] ?? null)   ? (string) $r['path']   : '';
            if ($method !== '' && $path !== '') {
                $byPath[$method . ' ' . $path] = $r;
            }
        }

        $this->assertArrayHasKey(
            'GET /api/uikit/demo/rows',
            $byPath,
            'getRoutes() must include GET /api/uikit/demo/rows'
        );
        $this->assertArrayHasKey(
            'GET /api/uikit/demo/metric',
            $byPath,
            'getRoutes() must include GET /api/uikit/demo/metric'
        );

        // WC-236: the interactive echo endpoint.
        $this->assertArrayHasKey(
            'POST /api/uikit/demo/echo',
            $byPath,
            'getRoutes() must include POST /api/uikit/demo/echo (WC-236)'
        );

        foreach (['GET /api/uikit/demo/rows', 'GET /api/uikit/demo/metric'] as $key) {
            $this->assertSame(
                'uikit:view',
                $byPath[$key]['requiredPermission'] ?? null,
                "Route {$key} must carry requiredPermission='uikit:view'"
            );
            $this->assertNull(
                $byPath[$key]['requiredRole'] ?? null,
                "Route {$key} must carry requiredRole=null"
            );
        }

        $this->assertSame(
            'uikit:view',
            $byPath['POST /api/uikit/demo/echo']['requiredPermission'] ?? null,
            'POST /api/uikit/demo/echo must carry requiredPermission=\'uikit:view\''
        );
        $this->assertNull(
            $byPath['POST /api/uikit/demo/echo']['requiredRole'] ?? null,
            'POST /api/uikit/demo/echo must carry requiredRole=null'
        );
    }

    // ---- WC-236: echo handler ----

    public function testEchoHandlerReturns200WithDataForValidBody(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $handler = null;
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'POST' && ($r['path'] ?? '') === '/api/uikit/demo/echo') {
                $handler = is_callable($r['handler']) ? $r['handler'] : null;
            }
        }
        $this->assertNotNull($handler, 'Must find a handler for POST /api/uikit/demo/echo');

        $body = json_encode(['name' => 'Alice', 'role' => 'admin', 'active' => true]) ?: '';
        $request = new Request('POST', '/api/uikit/demo/echo', [], $body);
        /** @var Response $response */
        $response = $handler($request, []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $parsed = json_decode($response->getBody(), true);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('data', $parsed, 'Valid body must return {data}');
        $this->assertIsArray($parsed['data']);
        $this->assertArrayHasKey('received', $parsed['data'], 'data must contain "received"');
    }

    public function testEchoHandlerReturns422ForMissingNameField(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $handler = null;
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'POST' && ($r['path'] ?? '') === '/api/uikit/demo/echo') {
                $handler = is_callable($r['handler']) ? $r['handler'] : null;
            }
        }
        $this->assertNotNull($handler, 'Must find a handler for POST /api/uikit/demo/echo');

        // Missing 'name' field — must return 422 with issues.
        $body = json_encode(['role' => 'editor']) ?: '';
        $request = new Request('POST', '/api/uikit/demo/echo', [], $body);
        /** @var Response $response */
        $response = $handler($request, []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $parsed = json_decode($response->getBody(), true);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('issues', $parsed, 'Missing name must return {issues}');
        $this->assertIsArray($parsed['issues']);
        $this->assertNotEmpty($parsed['issues'], 'issues must not be empty');

        $issue = $parsed['issues'][0];
        $this->assertIsArray($issue);
        $this->assertSame('error', $issue['severity'] ?? null);
        $this->assertSame('name', $issue['column'] ?? null);
    }

    public function testEchoHandlerReturns200ForEmptyBody(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $handler = null;
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'POST' && ($r['path'] ?? '') === '/api/uikit/demo/echo') {
                $handler = is_callable($r['handler']) ? $r['handler'] : null;
            }
        }
        $this->assertNotNull($handler, 'Must find a handler for POST /api/uikit/demo/echo');

        // actionButton sends an empty {} payload — must return 200 (no form data to validate).
        $body = json_encode([]) ?: '';
        $request = new Request('POST', '/api/uikit/demo/echo', [], $body);
        /** @var Response $response */
        $response = $handler($request, []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $parsed = json_decode($response->getBody(), true);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('data', $parsed, 'Empty body must return {data}');
    }

    public function testEchoHandlerReturns422ForEmptyNameField(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $handler = null;
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'POST' && ($r['path'] ?? '') === '/api/uikit/demo/echo') {
                $handler = is_callable($r['handler']) ? $r['handler'] : null;
            }
        }
        $this->assertNotNull($handler, 'Must find a handler for POST /api/uikit/demo/echo');

        // Empty 'name' — must return 422.
        $body = json_encode(['name' => '', 'role' => 'editor']) ?: '';
        $request = new Request('POST', '/api/uikit/demo/echo', [], $body);
        /** @var Response $response */
        $response = $handler($request, []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(422, $response->getStatusCode());

        $parsed = json_decode($response->getBody(), true);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('issues', $parsed, 'Empty name must return {issues}');
    }

    public function testDemoRowsHandlerReturnsDataArrayWithNameAndRole(): void
    {
        $plugin = new UiKitShowcasePlugin();

        // Find the handler for GET /api/uikit/demo/rows and invoke it directly.
        $handler = null;
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'GET' && ($r['path'] ?? '') === '/api/uikit/demo/rows') {
                $handler = is_callable($r['handler']) ? $r['handler'] : null;
            }
        }
        $this->assertNotNull($handler, 'Must find a handler for GET /api/uikit/demo/rows');

        $request = new Request('GET', '/api/uikit/demo/rows', [], '');
        $response = $handler($request, []);

        $this->assertInstanceOf(Response::class, $response);

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body, 'Response body must have a "data" key');

        /** @var mixed $data */
        $data = $body['data'];
        $this->assertIsArray($data, '"data" must be an array (collection)');
        $this->assertNotEmpty($data, '"data" must contain at least one row');

        $first = $data[0];
        $this->assertIsArray($first);
        $this->assertArrayHasKey('name', $first, 'Each row must have a "name" key');
        $this->assertArrayHasKey('role', $first, 'Each row must have a "role" key');
        $this->assertIsString($first['name']);
        $this->assertIsString($first['role']);
    }

    public function testDemoMetricHandlerReturnsDataObjectWithValueKey(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $handler = null;
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'GET' && ($r['path'] ?? '') === '/api/uikit/demo/metric') {
                $handler = is_callable($r['handler']) ? $r['handler'] : null;
            }
        }
        $this->assertNotNull($handler, 'Must find a handler for GET /api/uikit/demo/metric');

        $request = new Request('GET', '/api/uikit/demo/metric', [], '');
        $response = $handler($request, []);

        $this->assertInstanceOf(Response::class, $response);

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body, 'Response body must have a "data" key');

        /** @var mixed $data */
        $data = $body['data'];
        $this->assertIsArray($data, '"data" must be an object (associative array)');
        $this->assertArrayHasKey('value', $data, '"data" must have a "value" key');
        $this->assertIsString($data['value']);
        $this->assertNotSame('', $data['value']);
    }

    // ---- SP1 + SP2 contract: blocks tree ----

    public function testTheBlocksTreePassesTheContractValidator(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];
        $result = BlockValidator::validate($blocks);

        $this->assertTrue(
            $result['ok'],
            'The showcase block tree must be valid; errors: ' . implode('; ', $result['errors'])
        );
        $this->assertSame([], $result['errors']);
    }

    /**
     * WC-236: interactive demos are now in the tree, so the coverage assertion
     * is restored to ALL BlockContract::types() (SP1 + SP2 + SP3 interactive).
     * Total: 33 types (21 SP1+SP2 + 12 SP3 interactive).
     */
    public function testTheBlocksTreeCoversEveryBlockType(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];
        $present = $this->collectTypes($blocks);

        foreach (BlockContract::types() as $type) {
            $this->assertContains(
                $type,
                $present,
                "The showcase must include at least one '{$type}' block"
            );
        }

        // The set present is a SUPERSET of ALL block types (SP1 + SP2 + SP3 = 33).
        $expected = BlockContract::types();
        $this->assertSame(
            $expected,
            array_values(array_filter($expected, static fn (string $t): bool => in_array($t, $present, true))),
            'Every block type must be present at least once'
        );
    }

    public function testDataBoundBlocksHaveSourcesThatArePluginOwnedGetRoutes(): void
    {
        $plugin = new UiKitShowcasePlugin();
        $feature = $plugin->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];

        // Collect every GET route path the plugin registers.
        $registeredGetPaths = [];
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'GET' && is_string($r['path'] ?? null)) {
                $registeredGetPaths[] = (string) $r['path'];
            }
        }

        // Walk the tree; for each data-bound node, assert its source is a
        // registered GET path (the ownership invariant that WC-230 enforces).
        $dataBoundTypes = ['dataTable', 'dataStat', 'dataList'];
        $foundBound = ['dataTable' => false, 'dataStat' => false, 'dataList' => false];

        $this->walkDataBound($blocks, $dataBoundTypes, $registeredGetPaths, $foundBound);

        foreach ($dataBoundTypes as $type) {
            $this->assertTrue(
                $foundBound[$type],
                "The tree must contain at least one '{$type}' block with a plugin-owned source"
            );
        }
    }

    // ---- WC-232: loader integration — versioned sources ----

    public function testTheRealLoaderDiscoversTheShowcaseAndExposesTheBlocksFeature(): void
    {
        $pluginDir = dirname(__DIR__, 2) . '/plugins';

        $loader = new PluginLoader(
            $pluginDir,
            new Router(''),
            new PermissionRegistry(),
            new HookManager()
        );
        $loader->load();

        $names = array_map(
            static fn (PluginInterface $p): string => $p->getName(),
            $loader->getPlugins()
        );
        $this->assertContains('UiKitShowcase', $names);

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('ui-kit-reference', $byId);
        $feature = $byId['ui-kit-reference'];
        $this->assertSame('UiKitShowcase', $feature['plugin']);
        $this->assertSame('blocks', $feature['screen']);
        $this->assertSame('uikit:view', $feature['requiredPermission']);
        $this->assertArrayHasKey('blocks', $feature);
        $this->assertIsArray($feature['blocks']);

        // WC-236: interactive demos are now in the tree, so assert ALL block types.
        $present = $this->collectTypes($feature['blocks']);
        foreach (BlockContract::types() as $type) {
            $this->assertContains($type, $present, "Loader-exposed tree must include '{$type}'");
        }
        $this->assertTrue(BlockValidator::validate($feature['blocks'])['ok']);
    }

    public function testLoaderVersionsDataBoundSourcesInTheServedDescriptor(): void
    {
        // The loader (WC-230) rewrites each data-bound block's `source` from
        // the unversioned form the plugin declares (e.g. '/api/uikit/demo/rows')
        // to the versioned URL the browser calls (e.g. '/api/v1/uikit/demo/rows').
        // A Router('') has an empty version prefix, so sources are NOT rewritten
        // when the prefix is empty. Instantiate with '/v1' to exercise the rewrite.
        $pluginDir = dirname(__DIR__, 2) . '/plugins';

        $loader = new PluginLoader(
            $pluginDir,
            new Router('/v1'),
            new PermissionRegistry(),
            new HookManager()
        );
        $loader->load();

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('ui-kit-reference', $byId);
        $feature = $byId['ui-kit-reference'];
        $this->assertIsArray($feature['blocks']);

        // Collect every source value from data-bound nodes in the served descriptor.
        $servedSources = $this->collectDataBoundSources($feature['blocks']);

        $this->assertNotEmpty($servedSources, 'The served descriptor must contain data-bound blocks');

        foreach ($servedSources as $source) {
            $this->assertStringStartsWith(
                '/api/v1/',
                $source,
                "Served data-bound source '{$source}' must be the versioned form"
            );
        }

        // Verify the two specific paths.
        $this->assertContains('/api/v1/uikit/demo/rows', $servedSources);
        $this->assertContains('/api/v1/uikit/demo/metric', $servedSources);
    }

    // ---- WC-236: interactive demos in the blocks tree ----

    public function testTheBlocksTreeContainsAFormWithSubmitButtonAndEndpoint(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];

        $plugin = new UiKitShowcasePlugin();
        $postRoutes = [];
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'POST' && is_string($r['path'] ?? null)) {
                $postRoutes[(string) $r['path']] = $r;
            }
        }

        // Walk tree and assert a form block exists with a plugin-owned POST endpoint.
        $foundForm = false;
        $this->walkInteractive($blocks, $postRoutes, $foundForm);
        $this->assertTrue($foundForm, 'The tree must contain a form block with a plugin-owned submit endpoint');
    }

    public function testTheBlocksTreeContainsAnActionButtonWithPluginOwnedEndpoint(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];

        $plugin = new UiKitShowcasePlugin();
        $postRoutes = [];
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (($r['method'] ?? '') === 'POST' && is_string($r['path'] ?? null)) {
                $postRoutes[(string) $r['path']] = $r;
            }
        }

        $foundAction = false;
        $this->walkInteractiveAction($blocks, $postRoutes, $foundAction);
        $this->assertTrue($foundAction, 'The tree must contain an actionButton block with a plugin-owned action endpoint');
    }

    public function testFormAndActionButtonDeclareMatchingRequiredPermission(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];

        // Walk tree and collect form and actionButton blocks.
        $formPerms = [];
        $actionPerms = [];
        $this->collectInteractivePerms($blocks, $formPerms, $actionPerms);

        $this->assertNotEmpty($formPerms, 'Must find at least one form block');
        $this->assertNotEmpty($actionPerms, 'Must find at least one actionButton block');

        // Each must declare requiredPermission = 'uikit:view' (matching the echo route).
        foreach ($formPerms as $perm) {
            $this->assertSame('uikit:view', $perm, "form.requiredPermission must be 'uikit:view'");
        }
        foreach ($actionPerms as $perm) {
            $this->assertSame('uikit:view', $perm, "actionButton.requiredPermission must be 'uikit:view'");
        }
    }

    public function testLoaderVersionsInteractiveEndpointsInTheServedDescriptor(): void
    {
        // WC-236: verify the echo endpoint is versioned by the loader (/api/v1/uikit/demo/echo).
        $pluginDir = dirname(__DIR__, 2) . '/plugins';

        $loader = new PluginLoader(
            $pluginDir,
            new Router('/v1'),
            new PermissionRegistry(),
            new HookManager()
        );
        $loader->load();

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('ui-kit-reference', $byId);
        $feature = $byId['ui-kit-reference'];
        $this->assertIsArray($feature['blocks']);

        // The loader must rewrite form.submit.endpoint and actionButton.action.endpoint.
        $versionedEndpoints = $this->collectInteractiveEndpoints($feature['blocks']);

        $this->assertNotEmpty($versionedEndpoints, 'The served descriptor must contain interactive endpoint blocks');

        foreach ($versionedEndpoints as $endpoint) {
            $this->assertStringStartsWith(
                '/api/v1/',
                $endpoint,
                "Served interactive endpoint '{$endpoint}' must be the versioned form"
            );
        }

        $this->assertContains('/api/v1/uikit/demo/echo', $versionedEndpoints);
    }

    // ---- helpers ----

    /**
     * Walk the tree depth-first and find a form block with a plugin-owned submit endpoint.
     *
     * @param array<mixed>                     $nodes
     * @param array<string, array<string, mixed>> $postRoutes
     */
    private function walkInteractive(array $nodes, array $postRoutes, bool &$foundForm): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }
            if ($node['type'] === 'form' && isset($node['submit']) && is_array($node['submit'])) {
                $endpoint = $node['submit']['endpoint'] ?? '';
                if (is_string($endpoint) && array_key_exists($endpoint, $postRoutes)) {
                    $foundForm = true;
                }
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $this->walkInteractive($node['children'], $postRoutes, $foundForm);
            }
        }
    }

    /**
     * Walk the tree depth-first and find an actionButton block with a plugin-owned action endpoint.
     *
     * @param array<mixed>                     $nodes
     * @param array<string, array<string, mixed>> $postRoutes
     */
    private function walkInteractiveAction(array $nodes, array $postRoutes, bool &$foundAction): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }
            if ($node['type'] === 'actionButton' && isset($node['action']) && is_array($node['action'])) {
                $endpoint = $node['action']['endpoint'] ?? '';
                if (is_string($endpoint) && array_key_exists($endpoint, $postRoutes)) {
                    $foundAction = true;
                }
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $this->walkInteractiveAction($node['children'], $postRoutes, $foundAction);
            }
        }
    }

    /**
     * Walk the tree and collect requiredPermission values for form and actionButton blocks.
     *
     * @param array<mixed>    $nodes
     * @param list<string|null> $formPerms
     * @param list<string|null> $actionPerms
     */
    private function collectInteractivePerms(array $nodes, array &$formPerms, array &$actionPerms): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }
            if ($node['type'] === 'form') {
                $formPerms[] = isset($node['requiredPermission']) && is_string($node['requiredPermission'])
                    ? $node['requiredPermission']
                    : null;
            }
            if ($node['type'] === 'actionButton') {
                $actionPerms[] = isset($node['requiredPermission']) && is_string($node['requiredPermission'])
                    ? $node['requiredPermission']
                    : null;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $this->collectInteractivePerms($node['children'], $formPerms, $actionPerms);
            }
        }
    }

    /**
     * Walk the tree and collect endpoint strings from form.submit and actionButton.action.
     *
     * @param array<mixed> $nodes
     * @return list<string>
     */
    private function collectInteractiveEndpoints(array $nodes): array
    {
        $endpoints = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (
                isset($node['type'], $node['submit'])
                && $node['type'] === 'form'
                && is_array($node['submit'])
                && isset($node['submit']['endpoint'])
                && is_string($node['submit']['endpoint'])
            ) {
                $endpoints[] = $node['submit']['endpoint'];
            }
            if (
                isset($node['type'], $node['action'])
                && $node['type'] === 'actionButton'
                && is_array($node['action'])
                && isset($node['action']['endpoint'])
                && is_string($node['action']['endpoint'])
            ) {
                $endpoints[] = $node['action']['endpoint'];
            }
            if (isset($node['children']) && is_array($node['children'])) {
                foreach ($this->collectInteractiveEndpoints($node['children']) as $ep) {
                    $endpoints[] = $ep;
                }
            }
        }

        return array_values(array_unique($endpoints));
    }

    /**
     * Walk `$nodes` depth-first; for each node whose type is in `$dataBoundTypes`,
     * assert its `source` is in `$registeredGetPaths` and mark the type as found.
     *
     * @param array<mixed>  $nodes
     * @param list<string>  $dataBoundTypes
     * @param list<string>  $registeredGetPaths
     * @param array<string, bool> $foundBound mutated in-place
     */
    private function walkDataBound(
        array $nodes,
        array $dataBoundTypes,
        array $registeredGetPaths,
        array &$foundBound
    ): void {
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }

            if (in_array($node['type'], $dataBoundTypes, true)) {
                $source = $node['source'] ?? null;
                $this->assertIsString($source, "Data-bound node of type '{$node['type']}' must have a string 'source'");
                $this->assertContains(
                    $source,
                    $registeredGetPaths,
                    "Data-bound block source '{$source}' must be a GET route registered by the plugin"
                );
                $foundBound[$node['type']] = true;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $this->walkDataBound($node['children'], $dataBoundTypes, $registeredGetPaths, $foundBound);
            }
        }
    }

    /**
     * Walk the tree and collect every `source` value from data-bound nodes.
     *
     * @param array<mixed> $nodes
     * @return list<string>
     */
    private function collectDataBoundSources(array $nodes): array
    {
        $sources = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (
                isset($node['type'], $node['source'])
                && is_string($node['type'])
                && in_array($node['type'], ['dataTable', 'dataStat', 'dataList'], true)
                && is_string($node['source'])
            ) {
                $sources[] = $node['source'];
            }
            if (isset($node['children']) && is_array($node['children'])) {
                foreach ($this->collectDataBoundSources($node['children']) as $s) {
                    $sources[] = $s;
                }
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * Walk the tree depth-first and collect the distinct `type` of every node.
     *
     * @param array<mixed> $nodes
     *
     * @return list<string>
     */
    private function collectTypes(array $nodes): array
    {
        $types = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }
            $types[] = $node['type'];
            if (isset($node['children']) && is_array($node['children'])) {
                foreach ($this->collectTypes($node['children']) as $childType) {
                    $types[] = $childType;
                }
            }
        }

        return array_values(array_unique($types));
    }
}
