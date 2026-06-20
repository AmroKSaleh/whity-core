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

        // Data-bound block types landed in SDK 1.7, so the plugin requires that range.
        $this->assertSame('^1.7', $plugin->getSdkConstraint());
        $this->assertSame('', $plugin->getCoreConstraint());
        $this->assertSame([], $plugin->getPluginDependencies());

        // No hooks; migrations unchanged.
        $this->assertSame([], $plugin->getHooks());
        $this->assertSame([GrantUiKitViewToAdmin::class], $plugin->getMigrations());

        // Two demo routes now declared (SP2, WC-232).
        $routes = $plugin->getRoutes();
        $this->assertNotSame([], $routes, 'The showcase now declares demo routes (SP2)');
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

    public function testGetRoutesIncludesTheTwoDemoEndpoints(): void
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
     * WC-232: the data-bound demos are now in the tree, so the coverage
     * assertion covers SP1 display + SP2 data-bound block types.
     *
     * WC-233: interactive types (form, submitButton, actionButton, textInput,
     * textArea, numberInput, select, checkbox, slider, dateInput, fileInput,
     * colorInput) are temporarily excluded here — their demo endpoint
     * (/api/uikit/demo/echo) and interactive showcase section are added in
     * WC-236 once the host-side endpoint ownership + web renderers exist.
     * This exclusion is removed in WC-236 when the interactive demos land.
     */
    public function testTheBlocksTreeCoversEverySp1AndSp2BlockType(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];
        $present = $this->collectTypes($blocks);

        foreach (self::nonInteractiveBlockTypes() as $type) {
            $this->assertContains(
                $type,
                $present,
                "The showcase must include at least one '{$type}' block"
            );
        }

        // The set present is a SUPERSET of all non-interactive block types.
        $expected = self::nonInteractiveBlockTypes();
        $this->assertSame(
            $expected,
            array_values(array_filter($expected, static fn (string $t): bool => in_array($t, $present, true))),
            'Every non-interactive block type must be present at least once'
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

        // The exposed tree covers SP1 + SP2 non-interactive block types and validates.
        // Interactive types (WC-233) are excluded here — their demos land in WC-236
        // once the demo endpoint and host-side renderers exist.
        $present = $this->collectTypes($feature['blocks']);
        foreach (self::nonInteractiveBlockTypes() as $type) {
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

    // ---- helpers ----

    /**
     * The 12 SP3 interactive block types whose showcase demos land in WC-236.
     * Excluded from the coverage assertions until that slice is complete.
     *
     * @return list<string>
     */
    private static function interactiveBlockTypes(): array
    {
        return [
            'form', 'submitButton', 'actionButton',
            'textInput', 'textArea', 'numberInput', 'select',
            'checkbox', 'slider', 'dateInput', 'fileInput', 'colorInput',
        ];
    }

    /**
     * All block types EXCEPT the 12 SP3 interactive types that are demoed in WC-236.
     *
     * @return list<string>
     */
    private static function nonInteractiveBlockTypes(): array
    {
        $interactive = self::interactiveBlockTypes();

        return array_values(
            array_filter(
                BlockContract::types(),
                static fn (string $t): bool => !in_array($t, $interactive, true)
            )
        );
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
