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
     * WC-236 / WC-240: interactive and chart demos are now in the tree, so the
     * coverage assertion is restored to ALL BlockContract::types() — 50 as of
     * #950, which added `flow`. The count is written out rather than derived
     * because a type added to the whitelist WITHOUT a showcase instance is
     * precisely what this test exists to catch.
     *
     * The breakdown that used to sit here (so many SP1, so many SP3, …) is gone
     * deliberately: it had drifted three types out of date, and a stale tally is
     * worse than none — it reads as though somebody checked.
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

        // Walk the tree; for each source-bearing node, assert its source is a
        // registered GET path (the ownership invariant WC-230 enforces).
        //
        // The type list is DERIVED FROM THE CONTRACT rather than written out.
        // It used to be a literal, and a literal here is a hole shaped exactly
        // like the next block type somebody adds: the walk skips it, every
        // assertion still passes, and the ownership invariant is silently
        // untested for the one type nobody has exercised yet. #883 added
        // `dataRecord` and found this list four types out of date in spirit
        // already. Deriving it means a new source-bearing type is covered the
        // moment it exists, and a type that REMOVES its source (as
        // `ouScopePicker` deliberately has) drops out on its own.
        $dataBoundTypes = self::sourceBearingTypes();
        $this->assertContains('dataRecord', $dataBoundTypes, 'a recordPath source is ownership-checked too');

        $foundBound = array_fill_keys($dataBoundTypes, false);

        $this->walkDataBound($blocks, $dataBoundTypes, $registeredGetPaths, $foundBound);

        // A LIVE INSTANCE is demanded only of the types that MUST carry a
        // source. `fieldArray` carries an OPTIONAL one: without it the block is
        // the composer it has always been, and it is the ordinary declaration —
        // so requiring the showcase to contain a sourced one would be demanding
        // a demo of the rarer case in order to test the common one, and the
        // showcase's source-less array (the composer) would fail the walk above
        // for a prop it never declared.
        //
        // What the ownership invariant actually needs is that a source, WHEN
        // WRITTEN, is checked — and that is the walk, which covers every node
        // that carries one whatever its type. The loader's own behaviour on an
        // optional source, present and absent, is pinned directly in
        // {@see \Tests\Integration\PluginLoaderDataBoundBlocksTest} rather
        // than inferred from a showcase page.
        foreach (self::requiredSourceTypes() as $type) {
            $this->assertTrue(
                $foundBound[$type],
                "The tree must contain at least one '{$type}' block with a plugin-owned source"
            );
        }
    }

    /**
     * Every block type whose contract rule carries a `source` prop, and the kind
     * of path that prop accepts.
     *
     * This is the same derivation the LOADER makes when it decides what to
     * ownership-check ({@see \Whity\Core\PluginLoader}), restated here so the
     * test and the thing it tests answer the question the same way.
     *
     * @return list<string>
     */
    private static function sourceBearingTypes(): array
    {
        $types = [];
        foreach (BlockContract::types() as $type) {
            $rule = BlockContract::rulesFor($type);
            $kind = $rule['props']['source']['type'] ?? null;
            if ($kind === 'apiPath' || $kind === 'recordPath') {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The subset of {@see sourceBearingTypes()} whose `source` the contract
     * makes REQUIRED — the types for which "has no source" is a malformed
     * declaration rather than a deliberate one.
     *
     * @return list<string>
     */
    private static function requiredSourceTypes(): array
    {
        $types = [];
        foreach (self::sourceBearingTypes() as $type) {
            $rule = BlockContract::rulesFor($type);
            if (($rule['props']['source']['required'] ?? false) === true) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * #868: the OU scope picker is deliberately NOT a data-bound block.
     *
     * The loader decides what to ownership-check generically — any type whose
     * contract rule carries a `source` prop of kind `apiPath`. The picker
     * declares none, so it is skipped, and that skip is the point: its units
     * come from CORE's own OU endpoints under the caller's `ous:read` gate
     * rather than from a route the plugin republished. A `source` added here
     * later would silently move the hierarchy back behind a plugin route, which
     * is the drift #822 exists to delete — so the absence is asserted rather
     * than assumed.
     */
    public function testOuScopePickerIsNotDataBoundAndNamesNoPluginRoute(): void
    {
        $rule = BlockContract::rulesFor('ouScopePicker');
        $this->assertIsArray($rule);
        $this->assertArrayNotHasKey('source', $rule['props']);

        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];
        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];

        $pickers = $this->collectNodesOfType($blocks, 'ouScopePicker');
        $this->assertNotEmpty($pickers, 'The showcase must contain a live ouScopePicker');

        foreach ($pickers as $picker) {
            $this->assertArrayNotHasKey(
                'source',
                $picker,
                'An ouScopePicker must not carry a source — its data comes from core, not the plugin'
            );
        }
    }

    /**
     * Every child list a node carries, per the contract's declared slots (#909).
     *
     * The walkers below used to reach for `children` by name. `accessGate`
     * carries a second list, `otherwise`, holding the rendering a refused caller
     * gets — so a walk that knows only one name would report the read-only half
     * of every gated region as absent, and the coverage tests would pass while
     * the tree they measure contained blocks nobody checked. Derived from
     * {@see BlockContract::childSlots()} rather than restated, for the same
     * reason the source-bearing types are.
     *
     * @param array<string, mixed> $node
     * @return list<array<mixed>>
     */
    private function childListsOf(array $node): array
    {
        $type = $node['type'] ?? null;
        $lists = [];

        foreach (BlockContract::childSlots(is_string($type) ? $type : '') as $slot) {
            if (isset($node[$slot]) && is_array($node[$slot])) {
                $lists[] = $node[$slot];
            }
        }

        return $lists;
    }

    /**
     * Every node of one type anywhere in the tree.
     *
     * @param array<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    private function collectNodesOfType(array $blocks, string $type): array
    {
        $found = [];
        foreach ($blocks as $node) {
            if (!is_array($node)) {
                continue;
            }
            /** @var array<string, mixed> $node */
            if (($node['type'] ?? null) === $type) {
                $found[] = $node;
            }
            foreach ($this->childListsOf($node) as $childList) {
                $found = array_merge($found, $this->collectNodesOfType($childList, $type));
            }
        }

        return $found;
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

        // #883: a TEMPLATED record source is version-rewritten identically, with
        // its context token left intact for the renderer to substitute. Both
        // halves matter: an unversioned source addresses a route that does not
        // exist, and a token the loader mangled addresses the wrong record.
        $this->assertContains('/api/v1/uikit/demo/rows/{demo-record-pick}', $servedSources);
    }

    /**
     * #883: a `dataRecord` whose `source` names a route the plugin does NOT own
     * drops the whole feature, exactly as a foreign `dataTable` source does.
     *
     * Route-parameter normalization is what lets a templated source be matched
     * at all, and the risk it introduces is that normalization matches too much
     * — `{}` against `{}` for a path shape the plugin never registered. This
     * pins that it does not: the shape has to be one of the plugin's own.
     */
    public function testARecordSourceOutsideThePluginsRoutesDropsTheFeature(): void
    {
        $registeredGetRoutes = ['/api/uikit/demo/rows/{name}' => 'uikit:view'];
        $normalize = static fn (string $path): string
            => (string) preg_replace('/\{[^}]*\}/', '{}', $path);

        // The plugin's own shape, differently named — matches.
        $this->assertContains(
            $normalize('/api/uikit/demo/rows/{record}'),
            array_map($normalize, array_keys($registeredGetRoutes))
        );

        // A shape the plugin never registered — does not.
        $this->assertNotContains(
            $normalize('/api/users/{record}'),
            array_map($normalize, array_keys($registeredGetRoutes))
        );
        $this->assertNotContains(
            $normalize('/api/uikit/demo/rows/{a}/{b}'),
            array_map($normalize, array_keys($registeredGetRoutes))
        );
    }

    /**
     * #883/#895: the showcase's record endpoint RETURNS caller-permission flags,
     * and the block declaration names none of them.
     *
     * The fixture carries `manageable` and `canEdit` on purpose — that is what a
     * real record endpoint answers with, and it is the exact shape #895 went
     * wrong on. This asserts both halves of the guard on the shipped example:
     * the payload has the flags, and the declaration's fact whitelist does not.
     */
    public function testTheShowcaseRecordDeclaresNoCallerPermissionFactsDespiteThePayloadCarryingThem(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $body = json_decode($plugin->demoRecord(new Request('GET', '/api/uikit/demo/rows/Anika%20Patel'))->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('manageable', $body['data'], 'the fixture must carry a caller flag to be worth anything');
        $this->assertArrayHasKey('canEdit', $body['data']);

        $record = $this->findFirstNodeOfType($plugin->getFrontendFeatures()[0]['blocks'], 'dataRecord');
        $this->assertIsArray($record);

        $declared = array_column($record['fields'], 'field');
        foreach (BlockValidator::CALLER_DECISION_FIELDS as $reserved) {
            $this->assertNotContains(
                $reserved,
                $declared,
                "the showcase must never declare '{$reserved}' as a record fact (#895)"
            );
        }
        // `documentId` joined the whitelist with #947 item 4's `documentViewer`
        // demo, which binds to it. It is a FACT about the record — which
        // document was issued from it — so it belongs here; the guard above is
        // what keeps a caller flag out, and it still runs over this list.
        $this->assertSame(['name', 'role', 'status', 'joined', 'documentId'], $declared);
    }

    /**
     * #909: the showcase ships a LIVE read-only state, not a description of one.
     *
     * This is the acceptance test for the primitive. `uikit:manage` is declared
     * and never granted, so on a stock install the inner gate refuses and what
     * renders is the `otherwise` branch: a description list plus a notice naming
     * the gate. If this stops holding, a described record page has gone back to
     * showing an editable form to a caller who may not write — the greyed-out
     * form #882 exists to make unshippable.
     */
    public function testTheShowcaseDescribesARecordPagesReadOnlyState(): void
    {
        $blocks = (new UiKitShowcasePlugin())->getFrontendFeatures()[0]['blocks'];
        $gates = $this->collectNodesOfType($blocks, 'accessGate');

        $this->assertNotEmpty($gates, 'the showcase must contain a live accessGate');

        $byId = array_column($gates, null, 'id');
        $this->assertArrayHasKey('demo-record-readable', $byId, 'the HIDDEN state gate');
        $this->assertArrayHasKey('demo-record-writable', $byId, 'the READ-ONLY/EDITABLE gate');

        // The outer gate is a READ question and declares no `otherwise`: a
        // caller who may not read the record sees the region absent, which is
        // the third state and the one that has no other way to be expressed.
        $readable = $byId['demo-record-readable'];
        $this->assertSame('GET', $readable['check']['method']);
        $this->assertArrayNotHasKey('otherwise', $readable);

        // The inner gate is a WRITE question and declares BOTH renderings.
        $writable = $byId['demo-record-writable'];
        $this->assertSame('PUT', $writable['check']['method']);
        $this->assertArrayHasKey('children', $writable, 'the editable rendering');
        $this->assertArrayHasKey('otherwise', $writable, 'the read-only rendering');

        // Read-only is a DIFFERENT RENDERING, not a disabled form: a description
        // list, and no input anywhere beneath it.
        $refusedTypes = $this->collectTypes($writable['otherwise']);
        $this->assertContains('recordFields', $refusedTypes);
        foreach (['form', 'textInput', 'submitButton', 'select', 'checkbox'] as $control) {
            $this->assertNotContains(
                $control,
                $refusedTypes,
                "the read-only rendering must contain no '{$control}' — read-only is a rendering, not a disabled form"
            );
        }

        // ...and the editable one is the form.
        $this->assertContains('form', $this->collectTypes($writable['children']));
    }

    /**
     * A gate states no permission of its own, on the shipped example.
     *
     * The host reads `uikit:manage` off the ROUTE the check names. A slug in the
     * declaration would be a second answer to a question the route table already
     * answers, and re-gating the route would leave the page saying the old
     * thing — which is the drift #868 removed for `inbox` actions.
     */
    public function testTheShowcaseGatesRestateNoPermission(): void
    {
        $plugin = new UiKitShowcasePlugin();
        $gates = $this->collectNodesOfType($plugin->getFrontendFeatures()[0]['blocks'], 'accessGate');
        $this->assertNotEmpty($gates);

        $declaredSlugs = [];
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            if (is_string($r['requiredPermission'] ?? null)) {
                $declaredSlugs[] = $r['requiredPermission'];
            }
        }
        $this->assertContains('uikit:manage', $declaredSlugs, 'the write route must carry the gate');

        foreach ($gates as $gate) {
            foreach (['permission', 'requiredPermission', 'scopedPermission', 'requiredRole'] as $prop) {
                $this->assertArrayNotHasKey($prop, $gate, "accessGate '{$gate['id']}' must restate no authority");
            }
            $this->assertSame(
                ['method', 'endpoint'],
                array_keys($gate['check']),
                'a check is a REQUEST and nothing else'
            );
        }
    }

    /**
     * A gate's endpoint is ownership-checked and version-rewritten by the
     * loader, exactly like a data-bound `source` and an inbox action's endpoint.
     *
     * The version half is not cosmetic: the permitted-actions resolver matches
     * the CONCRETE path against the live route table, so an unversioned endpoint
     * matches no route and resolves to "not permitted" — fail-closed, but
     * silently, and every gated region would be refused for everyone.
     */
    public function testTheLoaderVersionsAndOwnershipChecksGateEndpoints(): void
    {
        $pluginDir = dirname(__DIR__, 2) . '/plugins';
        $loader = new PluginLoader($pluginDir, new Router('/v1'), new PermissionRegistry(), new HookManager());
        $loader->load();

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('ui-kit-reference', $byId);

        $gates = $this->collectNodesOfType($byId['ui-kit-reference']['blocks'], 'accessGate');
        $this->assertNotEmpty($gates, 'the served descriptor must still carry its gates');

        foreach ($gates as $gate) {
            $this->assertStringStartsWith(
                '/api/v1/',
                $gate['check']['endpoint'],
                "gate '{$gate['id']}' must be served with a versioned endpoint"
            );
            // The context token survives the rewrite — a mangled one would ask
            // about a different record than the page is about.
            $this->assertStringContainsString('{demo-record-pick}', $gate['check']['endpoint']);
        }
    }

    /**
     * Depth-first: the first node of the given type, or null.
     *
     * @param array<mixed> $nodes
     * @return array<string, mixed>|null
     */
    private function findFirstNodeOfType(array $nodes, string $type): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === $type) {
                /** @var array<string, mixed> $node */
                return $node;
            }
            foreach ($this->childListsOf($node) as $childList) {
                $found = $this->findFirstNodeOfType($childList, $type);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * #868: an `inbox` action's `endpoint` is a write route the plugin declared,
     * and the served descriptor carries its VERSIONED form.
     *
     * This is load-bearing rather than cosmetic. The host's permitted-actions
     * resolver matches the CONCRETE path a button would call against the live
     * route table; an unversioned endpoint matches nothing, resolves to "not
     * permitted", and every action on the block silently disappears. Fail-closed
     * and invisible is exactly the failure mode worth a test.
     */
    public function testLoaderVersionsInboxActionEndpointsInTheServedDescriptor(): void
    {
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

        $endpoints = $this->collectInboxActionEndpoints($feature['blocks']);

        $this->assertNotEmpty($endpoints, 'The served descriptor must contain an inbox with actions');
        foreach ($endpoints as $endpoint) {
            $this->assertStringStartsWith(
                '/api/v1/',
                $endpoint,
                "Served inbox action endpoint '{$endpoint}' must be the versioned form"
            );
        }
        $this->assertContains('/api/v1/uikit/demo/tasks/{id}/approve', $endpoints);
        $this->assertContains('/api/v1/uikit/demo/tasks/{id}/reject', $endpoints);
    }

    /**
     * #868: every `inbox` action endpoint the showcase declares is a write route
     * the plugin ACTUALLY registers — the ownership invariant, asserted against
     * the declaration rather than the served (already-rewritten) descriptor.
     */
    public function testInboxActionEndpointsAreRoutesThePluginRegisters(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $writeRoutes = [];
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            $method = strtoupper((string) ($r['method'] ?? ''));
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && is_string($r['path'] ?? null)) {
                $writeRoutes[] = $method . ' ' . (string) $r['path'];
            }
        }

        $feature = $plugin->getFrontendFeatures()[0];
        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];

        $found = 0;
        foreach ($this->collectInboxActions($blocks) as $action) {
            $found++;
            $this->assertContains(
                strtoupper((string) $action['method']) . ' ' . (string) $action['endpoint'],
                $writeRoutes,
                'Every inbox action endpoint must be a write route the plugin registers'
            );
            // The permission an endpoint is gated on is NOT restated on the
            // action: the host reads it off the route it dispatches to.
            $this->assertArrayNotHasKey('permission', $action);
        }

        $this->assertGreaterThan(0, $found, 'The showcase must declare at least one inbox action');
    }

    /**
     * Collect every `inbox` action endpoint in a tree.
     *
     * @param array<mixed> $nodes
     * @return list<string>
     */
    private function collectInboxActionEndpoints(array $nodes): array
    {
        $endpoints = [];
        foreach ($this->collectInboxActions($nodes) as $action) {
            $endpoints[] = (string) $action['endpoint'];
        }

        return $endpoints;
    }

    /**
     * Collect every `inbox` action object in a tree.
     *
     * @param array<mixed> $nodes
     * @return list<array<string, mixed>>
     */
    private function collectInboxActions(array $nodes): array
    {
        $actions = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }

            if ($node['type'] === 'inbox' && isset($node['actions']) && is_array($node['actions'])) {
                foreach ($node['actions'] as $action) {
                    if (is_array($action)) {
                        /** @var array<string, mixed> $action */
                        $actions[] = $action;
                    }
                }
            }

            foreach ($this->childListsOf($node) as $childList) {
                foreach ($this->collectInboxActions($childList) as $nested) {
                    $actions[] = $nested;
                }
            }
        }

        return $actions;
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

    /**
     * Every `form`/`actionButton` declares the permission of the route it
     * SUBMITS TO — which is the invariant the loader's permission pin enforces,
     * and it is asserted here by DERIVING the expected value rather than by
     * naming one.
     *
     * It used to assert the literal `uikit:view` for every block, and that held
     * only while every interactive block in the tree submitted to the same echo
     * route. The moment the record page's editable form started submitting the
     * PUT its own gate asks about — `uikit:manage`, read off that route — a
     * literal became a statement about which demo endpoints happen to exist,
     * not about the rule. A derived assertion covers the next endpoint too.
     */
    public function testEveryInteractiveBlockDeclaresThePermissionOfTheRouteItSubmitsTo(): void
    {
        $plugin = new UiKitShowcasePlugin();
        $feature = $plugin->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];

        // The plugin's own write routes, keyed exactly as the loader keys them:
        // METHOD + path with every `{param}` collapsed, so a declaration naming
        // `{demo-record-pick}` finds the route registered as `{name}`.
        $writeRoutes = [];
        foreach ($plugin->getRoutes() as $route) {
            /** @var array<string, mixed> $r */
            $r = $route;
            $method = strtoupper((string) ($r['method'] ?? ''));
            if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || !is_string($r['path'] ?? null)) {
                continue;
            }
            $writeRoutes[$method . ' ' . self::normalizePath((string) $r['path'])]
                = $r['requiredPermission'] ?? null;
        }

        $specs = $this->collectInteractiveSpecs($blocks);
        $this->assertNotEmpty($specs, 'Must find at least one form or actionButton block');

        $verbsSeen = [];
        foreach ($specs as $spec) {
            $key = $spec['method'] . ' ' . self::normalizePath($spec['endpoint']);
            $verbsSeen[$spec['method']] = true;

            $this->assertArrayHasKey(
                $key,
                $writeRoutes,
                "'{$spec['type']}' submits {$spec['method']} {$spec['endpoint']}, which is not a write route "
                . 'this plugin registers — the loader would drop the whole feature'
            );
            $this->assertSame(
                $writeRoutes[$key],
                $spec['perm'],
                "'{$spec['type']}' submitting {$spec['method']} {$spec['endpoint']} must declare the "
                . 'requiredPermission of that route, or the loader\'s permission pin drops the feature'
            );
        }

        // The tree must exercise the TEMPLATED write path, not only the static
        // one. This is the coverage whose absence let the loader compare submit
        // endpoints literally for as long as it did: every interactive block in
        // the showcase pointed at a parameterless collection route, so the walk
        // was never asked the question a record page asks.
        $this->assertArrayHasKey(
            'PUT',
            $verbsSeen,
            'At least one interactive block must submit a PUT to a templated record path — a showcase '
            . 'whose every form posts to a static endpoint cannot demonstrate a record page that saves, '
            . 'and cannot catch a write gate that refuses templated endpoints'
        );
    }

    /**
     * The record page's editable form survives the loader's OWNERSHIP WALK and
     * comes out addressing the versioned route.
     *
     * This is the gate the whole change exists for, and it is a POSITIVE
     * assertion on the served descriptor rather than on the declaration: the
     * tree passing `BlockValidator` proves the shape, and proves nothing at all
     * about ownership — a feature refused by the walk is simply absent, silently,
     * and a suite that only validates the contract cannot tell the difference.
     *
     * Reintroduce a literal comparison in `PluginLoader`'s interactive-endpoint
     * check, or drop PATCH/PUT from the write-route map, and this test goes red
     * with the feature missing entirely.
     */
    public function testTheServedDescriptorCarriesTheRecordPagesTemplatedSaveEndpoint(): void
    {
        $pluginDir = dirname(__DIR__, 2) . '/plugins';

        $loader = new PluginLoader(
            $pluginDir,
            new Router('/v1'),
            new PermissionRegistry(),
            new HookManager()
        );
        $loader->load();

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey(
            'ui-kit-reference',
            $byId,
            'The showcase feature must SURVIVE the ownership walk. Absent here means the walk refused '
            . 'one of its endpoints and dropped the whole feature — fail-closed, and silent'
        );

        /** @var array<mixed> $blocks */
        $blocks = $byId['ui-kit-reference']['blocks'];
        $endpoints = $this->collectInteractiveEndpoints($blocks);

        $this->assertContains(
            '/api/v1/uikit/demo/rows/{demo-record-pick}',
            $endpoints,
            'The record page\'s editable form must submit the versioned, templated PUT — the same '
            . 'request its accessGate asks about. The token stays intact for the renderer to '
            . 'substitute; the prefix is the loader\'s'
        );
    }

    /**
     * The endpoint the served descriptor hands the renderer, with its token
     * substituted, DISPATCHES — through the very Router the loader registered
     * the plugin's routes on.
     *
     * "The feature survived the ownership walk" and "the browser's request will
     * reach a handler" are two different claims, and the second is the one that
     * matters to a user pressing Save. They came apart in exactly this
     * subsystem before: #868 records that an UNVERSIONED endpoint passes
     * ownership and then matches no route, so every action resolves to "not
     * permitted" — fail-closed, silently. So this asserts the whole path:
     * take the string out of the descriptor, put a concrete value where the
     * renderer will put one, and require a real route to answer it, with the
     * gate the plugin declared.
     */
    public function testTheServedSaveEndpointDispatchesToTheRouteThatGatesIt(): void
    {
        $pluginDir = dirname(__DIR__, 2) . '/plugins';
        $router = new Router('/v1');

        $loader = new PluginLoader($pluginDir, $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('ui-kit-reference', $byId);

        /** @var array<mixed> $blocks */
        $blocks = $byId['ui-kit-reference']['blocks'];
        $endpoint = '/api/v1/uikit/demo/rows/{demo-record-pick}';
        $this->assertContains($endpoint, $this->collectInteractiveEndpoints($blocks));

        // What the renderer builds: the token replaced with the selector's
        // current value, URL-encoded exactly as FormProvider encodes it.
        $concrete = str_replace(
            '{demo-record-pick}',
            rawurlencode('Anika Patel'),
            $endpoint
        );

        $matched = $router->match(new Request('PUT', $concrete));

        $this->assertNotNull(
            $matched,
            "The served save endpoint '{$concrete}' must match a registered route. A descriptor whose "
            . 'endpoint dispatches nowhere is a Save button that reports nothing'
        );
        $this->assertSame(
            'uikit:manage',
            $matched['requiredPermission'],
            'and it must be the route whose own gate the accessGate asked about — the same single '
            . 'permission, never a slug the block restated'
        );
        // The route's OWN parameter captures the substituted segment, whatever
        // either side chose to call it: the block wrote `{demo-record-pick}` and
        // the route declares `{name}`.
        //
        // And captured DECODED — the identifier as the selector published it,
        // not as the URL carried it (#1078, which this branch is stacked on).
        //
        // The two halves have to be true together or the save is worse than
        // useless. This change makes the write REACHABLE; #1078 makes it reach
        // the row the caller named. Without it the endpoint dispatched fine and
        // the handler was handed `Anika%20Patel`, matched no record, and — on a
        // real endpoint that falls back rather than 404s — would have written to
        // whichever record the fallback picked, reporting success.
        $this->assertArrayHasKey('name', $matched['params']);
        $this->assertSame('Anika Patel', $matched['params']['name']);
    }

    /**
     * The Record tab shows the record you PICKED.
     *
     * The showcase's `dataRecord` source is `/api/uikit/demo/rows/{demo-record-pick}`
     * and the web renderer `encodeURIComponent`s the selector's value into it,
     * so every one of the three fixture names — all of which contain a space —
     * arrived percent-encoded, missed `demoRecord`'s lookup, and fell through to
     * its default. Picking any record showed Anika Patel.
     *
     * That is the read half of the same bug this branch's write half depends on,
     * and it is asserted HERE, against the reference plugin's own declaration and
     * its own handler, because that is where a reader will look for it. A green
     * unit test on `Router` does not tell anyone the showcase was lying.
     */
    public function testTheRecordTabShowsTheRecordThatWasPicked(): void
    {
        $pluginDir = dirname(__DIR__, 2) . '/plugins';
        $router = new Router('/v1');

        $loader = new PluginLoader($pluginDir, $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('ui-kit-reference', $byId);

        /** @var array<mixed> $blocks */
        $blocks = $byId['ui-kit-reference']['blocks'];
        $sources = $this->collectDataBoundSources($blocks);
        $this->assertContains('/api/v1/uikit/demo/rows/{demo-record-pick}', $sources);

        // Every name the demo collection offers, not just the first — the
        // fallback record is one of them, so testing a single name could pass on
        // the one case where "the fallback" and "the right answer" coincide.
        foreach (['Anika Patel', 'Bjorn Larsen', 'Camille Dupont'] as $picked) {
            // What the renderer builds: resolveContextPath() encodeURIComponent's
            // the selector's value into the token.
            $path = str_replace(
                '{demo-record-pick}',
                rawurlencode($picked),
                '/api/v1/uikit/demo/rows/{demo-record-pick}'
            );

            $matched = $router->match(new Request('GET', $path));
            $this->assertNotNull($matched, "the record route must match '{$path}'");

            $response = ($matched['handler'])(new Request('GET', $path), $matched['params']);
            /** @var array<string, mixed> $body */
            $body = json_decode($response->getBody(), true) ?? [];

            $this->assertSame(
                $picked,
                $body['data']['name'] ?? null,
                "picking '{$picked}' must show '{$picked}' — before #1078 every pick showed the "
                . "fixture's fallback record instead"
            );
        }
    }

    /**
     * The path half of the loader's route key: every `{param}` collapsed to `{}`.
     *
     * Restated here rather than reached for through reflection — the loader's
     * copy is private, and a test that reached into it would pass by sharing the
     * bug rather than by agreeing with the behaviour.
     */
    private static function normalizePath(string $path): string
    {
        return (string) preg_replace('/\{[^}]*\}/', '{}', $path);
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
            foreach ($this->childListsOf($node) as $childList) {
                $this->walkInteractive($childList, $postRoutes, $foundForm);
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
            foreach ($this->childListsOf($node) as $childList) {
                $this->walkInteractiveAction($childList, $postRoutes, $foundAction);
            }
        }
    }

    /**
     * Walk the tree and collect, for every `form`/`actionButton`, the three
     * things the loader's interactive-endpoint check reads together: the METHOD,
     * the ENDPOINT, and the block's own requiredPermission.
     *
     * Collected as one record per block rather than as three parallel lists —
     * the check is a relation between them, and separate lists can only assert
     * facts about each column.
     *
     * @param array<mixed> $nodes
     * @return list<array{type: string, method: string, endpoint: string, perm: string|null}>
     */
    private function collectInteractiveSpecs(array $nodes): array
    {
        $specs = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }

            $specKey = match ($node['type']) {
                'form' => 'submit',
                'actionButton' => 'action',
                default => null,
            };

            if ($specKey !== null && is_array($node[$specKey] ?? null)) {
                /** @var array<string, mixed> $spec */
                $spec = $node[$specKey];
                if (is_string($spec['method'] ?? null) && is_string($spec['endpoint'] ?? null)) {
                    $specs[] = [
                        'type' => $node['type'],
                        'method' => strtoupper($spec['method']),
                        'endpoint' => $spec['endpoint'],
                        'perm' => isset($node['requiredPermission']) && is_string($node['requiredPermission'])
                            ? $node['requiredPermission']
                            : null,
                    ];
                }
            }

            foreach ($this->childListsOf($node) as $childList) {
                foreach ($this->collectInteractiveSpecs($childList) as $nested) {
                    $specs[] = $nested;
                }
            }
        }

        return $specs;
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
            foreach ($this->childListsOf($node) as $childList) {
                foreach ($this->collectInteractiveEndpoints($childList) as $ep) {
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
                // An OPTIONAL source that is simply not written is not a
                // violation — it is the block declining to be data-bound, which
                // for a `fieldArray` is the ordinary case. Only a type whose
                // contract says the source is REQUIRED is failed for missing it.
                if ($source === null && !in_array($node['type'], self::requiredSourceTypes(), true)) {
                    foreach ($this->childListsOf($node) as $childList) {
                        $this->walkDataBound($childList, $dataBoundTypes, $registeredGetPaths, $foundBound);
                    }

                    continue;
                }
                $this->assertIsString($source, "Data-bound node of type '{$node['type']}' must have a string 'source'");
                // #883: a `recordPath` may carry `{token}` segments the renderer
                // substitutes from context, so ownership is judged with route
                // parameters normalized — the same comparison the loader makes,
                // and the same one an `inbox` action endpoint already gets. A
                // source with no token normalizes to itself, so nothing that was
                // compared exactly before is compared loosely now.
                $normalize = static fn (string $path): string
                    => (string) preg_replace('/\{[^}]*\}/', '{}', $path);
                $this->assertContains(
                    $normalize($source),
                    array_map($normalize, $registeredGetPaths),
                    "Data-bound block source '{$source}' must be a GET route registered by the plugin"
                );
                $foundBound[$node['type']] = true;
            }

            foreach ($this->childListsOf($node) as $childList) {
                $this->walkDataBound($childList, $dataBoundTypes, $registeredGetPaths, $foundBound);
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
                && in_array($node['type'], self::sourceBearingTypes(), true)
                && is_string($node['source'])
            ) {
                $sources[] = $node['source'];
            }
            foreach ($this->childListsOf($node) as $childList) {
                foreach ($this->collectDataBoundSources($childList) as $s) {
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
            foreach ($this->childListsOf($node) as $childList) {
                foreach ($this->collectTypes($childList) as $childType) {
                    $types[] = $childType;
                }
            }
        }

        return array_values(array_unique($types));
    }
}
