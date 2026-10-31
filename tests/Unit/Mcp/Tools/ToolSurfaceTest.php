<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Mcp\McpExposure;
use Whity\Mcp\Protocol\McpProtocol;
use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolSurface;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Toolsets, visibility and agent policy on derived operations (WHIT-747,
 * docs/design/MCP-TOOL-SURFACE.md §5.1, §5.2, §5.8, §5.9).
 */
final class ToolSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    // ---- the schema's `mcp` key ----

    public function testMcpTrueStillMeansExpose(): void
    {
        self::assertSame(
            ['expose' => true, 'visibility' => null, 'toolset' => null, 'agentPolicy' => null],
            ToolSurface::routeOptions(['mcp' => true]),
        );
    }

    public function testTheArrayFormIsReadAndBadValuesAreDropped(): void
    {
        self::assertSame(
            ['expose' => null, 'visibility' => 'always-on', 'toolset' => 'core.forms', 'agentPolicy' => 'propose'],
            ToolSurface::routeOptions(['mcp' => ['visibility' => 'always-on', 'toolset' => 'core.forms', 'agentPolicy' => 'propose']]),
        );
        self::assertSame(
            ['expose' => null, 'visibility' => null, 'toolset' => null, 'agentPolicy' => null],
            ToolSurface::routeOptions(['mcp' => ['expose' => 'no', 'visibility' => 'default', 'toolset' => 'Core Forms', 'agentPolicy' => 'maybe']]),
        );
    }

    public function testExposeFalseKeepsEvenAGatedRouteOff(): void
    {
        $gated = ['requiredPermission' => 'users:write', 'requiredRole' => null];

        self::assertTrue(McpExposure::exposes($gated + ['schema' => ['summary' => 'Import users']]));
        self::assertFalse(McpExposure::exposes($gated + ['schema' => ['mcp' => ['expose' => false]]]));
        self::assertFalse(McpExposure::exposes($gated + ['schema' => ['mcp' => ['visibility' => 'off']]]));
        self::assertTrue(McpExposure::exposes(['requiredPermission' => null, 'requiredRole' => null, 'schema' => ['mcp' => ['expose' => true]]]));
    }

    // ---- core toolsets ----

    public function testCoreRoutesTakeTheirToolsetFromTheFirstTag(): void
    {
        $meta = $this->metaOf([[
            'method' => 'GET', 'path' => '/api/forms', 'requiredPermission' => 'forms:read',
            'schema' => ['summary' => 'List forms', 'tags' => ['forms']],
        ]], 'get_api_forms');

        self::assertSame(['dev.whity/toolset' => 'core.forms', 'dev.whity/visibility' => 'discoverable', 'dev.whity/agentPolicy' => 'allow'], $meta);
    }

    public function testThePermissionMapWinsOverTheTag(): void
    {
        $meta = $this->metaOf([[
            'method' => 'GET', 'path' => '/api/plugins', 'requiredPermission' => 'plugins:read',
            'schema' => ['summary' => 'List plugins', 'tags' => ['platform-ops']],
        ]], 'get_api_plugins');

        self::assertSame('core.plugins', $meta['dev.whity/toolset']);
    }

    public function testCoreAlwaysOnPicksAreAlwaysOn(): void
    {
        $meta = $this->metaOf([[
            'method' => 'GET', 'path' => '/api/me', 'requiredPermission' => null,
            'schema' => ['summary' => 'Me', 'tags' => ['me'], 'mcp' => true],
        ]], 'get_api_me');

        self::assertSame('always-on', $meta['dev.whity/visibility']);
        self::assertSame('core.me', $meta['dev.whity/toolset']);
    }

    public function testACoreRouteMayNameACoreSubToolsetButNotAnUnknownOne(): void
    {
        $declarations = [
            ['method' => 'GET', 'path' => '/api/document-themes', 'requiredPermission' => 'documents:read',
             'schema' => ['summary' => 'List themes', 'tags' => ['documents'], 'mcp' => ['toolset' => 'core.documents.themes', 'agentPolicy' => 'refuse']]],
            ['method' => 'GET', 'path' => '/api/oddities', 'requiredPermission' => 'forms:read',
             'schema' => ['summary' => 'Odd', 'tags' => ['forms'], 'mcp' => ['toolset' => 'acme']]],
        ];

        self::assertSame('core.documents.themes', $this->metaOf($declarations, 'get_api_document_themes')['dev.whity/toolset']);
        self::assertSame('refuse', $this->metaOf($declarations, 'get_api_document_themes')['dev.whity/agentPolicy']);
        self::assertSame('core.forms', $this->metaOf($declarations, 'get_api_oddities')['dev.whity/toolset']);
    }

    // ---- plugin toolsets ----

    public function testPluginRoutesLandInThePluginsOwnToolset(): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/periods', $this->noop(), null, 'Plugins\\PeriodClose', 'periods:read', ['summary' => 'List periods']);
        $router->registerUnversioned('GET', '/api/periods/locks', $this->noop(), null, 'Plugins\\PeriodClose', 'periods:read', ['summary' => 'Locks', 'mcp' => ['toolset' => 'period-close.locks', 'visibility' => 'always-on']]);
        $router->registerUnversioned('GET', '/api/periods/sneaky', $this->noop(), null, 'Plugins\\PeriodClose', 'periods:read', ['summary' => 'Sneaky', 'mcp' => ['toolset' => 'core.documents']]);

        $meta = $this->metaByName((new ToolDeriver([], [], $router))->deriveTools());

        self::assertSame('period-close', $meta['get_api_periods']['dev.whity/toolset']);
        self::assertSame('discoverable', $meta['get_api_periods']['dev.whity/visibility']);
        self::assertSame('period-close.locks', $meta['get_api_periods_locks']['dev.whity/toolset']);
        self::assertSame('always-on', $meta['get_api_periods_locks']['dev.whity/visibility']);
        // A plugin can't claim a core toolset, or any other plugin's.
        self::assertSame('period-close', $meta['get_api_periods_sneaky']['dev.whity/toolset']);
    }

    public function testPluginToolsetIdsComeFromTheNamespace(): void
    {
        self::assertSame('hello-world', CoreToolsets::pluginToolsetId('Plugins\\HelloWorld'));
        self::assertSame('hello-world', CoreToolsets::pluginToolsetIdForClass('Plugins\\HelloWorld\\HelloWorldPlugin'));
        self::assertSame('ui-kit-showcase', CoreToolsets::pluginToolsetId('Whity\\Plugins\\UiKitShowcase'));
        self::assertSame('core-plugin', CoreToolsets::pluginToolsetId('Vendor\\Core'));
        self::assertSame('acme', CoreToolsets::pluginToolsetIdForClass('acme'));
    }

    // ---- the core catalogue ----

    public function testEveryExposedCoreRouteMapsToAKnownToolset(): void
    {
        $unmapped = [];
        foreach (CoreApiSchemas::routes() as $route) {
            if ($route['schema'] === [] || !McpExposure::exposes($route)) {
                continue;
            }
            $toolset = CoreToolsets::mappedToolsetFor($route);
            if ($toolset === null || !isset(CoreToolsets::TOOLSETS[$toolset])) {
                $unmapped[] = $route['method'] . ' ' . $route['path'] . ' (tags: ' . implode(',', (array) ($route['schema']['tags'] ?? [])) . ')';
            }
        }

        self::assertSame([], $unmapped, 'Map these routes\' tags in CoreToolsets::TAG_TOOLSET');
    }

    public function testEveryCoreToolsetHasBothTitlesAndAShortDescription(): void
    {
        foreach (CoreToolsets::TOOLSETS as $id => $toolset) {
            self::assertTrue(ToolSurface::isToolsetId($id), $id);
            self::assertStringStartsWith('core.', $id);
            self::assertNotSame('', $toolset['title'], $id);
            self::assertLessThanOrEqual(40, mb_strlen($toolset['title']), $id);
            self::assertMatchesRegularExpression('/\p{Arabic}/u', $toolset['titleAr'], $id);
            self::assertLessThanOrEqual(160, mb_strlen($toolset['description']), $id);
        }
        foreach (CoreToolsets::TAG_TOOLSET + CoreToolsets::PERMISSION_TOOLSET as $toolset) {
            self::assertArrayHasKey($toolset, CoreToolsets::TOOLSETS);
        }
    }

    public function testTheAlwaysOnPicksExistAndOnlyRead(): void
    {
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null);
        $tools   = [];
        foreach ($deriver->deriveTools() as $tool) {
            $tools[$tool['name']] = $tool;
        }

        self::assertCount(5, CoreToolsets::ALWAYS_ON);
        foreach (CoreToolsets::ALWAYS_ON as $name) {
            self::assertArrayHasKey($name, $tools, "always-on {$name} is not a core operation");
            self::assertTrue($deriver->isExposed($name), "always-on {$name} is not exposed");
            self::assertTrue($tools[$name]['annotations']['readOnlyHint'] ?? false, "always-on {$name} must only read");
            self::assertSame('always-on', $tools[$name]['_meta']['dev.whity/visibility']);
        }
    }

    /**
     * Issuing a document can't be undone (a numbered, QR-verified document;
     * editor M4c), so an agent only ever proposes it: the three routes that
     * issue declare `propose`, and reading documents stays `allow`.
     */
    public function testIssuingADocumentIsAlwaysAProposalForAnAgent(): void
    {
        ToolDeriver::clearCache();
        $meta = $this->metaByName((new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null))->deriveTools());

        foreach (['post_api_documents', 'post_api_documents_id_render', 'post_api_document_sources_id_render'] as $name) {
            self::assertArrayHasKey($name, $meta);
            self::assertSame('propose', $meta[$name]['dev.whity/agentPolicy'], $name);
        }
        self::assertSame('allow', $meta['get_api_documents']['dev.whity/agentPolicy']);
    }

    // ---- the wire ----

    public function testMetaIsLeftOutForTheOldestProtocolVersion(): void
    {
        $result = ['tools' => [['name' => 'a', 'description' => 'A', 'inputSchema' => ['type' => 'object'], '_meta' => ['dev.whity/toolset' => 'core.me']]]];

        self::assertArrayNotHasKey('_meta', McpProtocol::adaptResult('tools/list', $result, '2025-03-26')['tools'][0]);
        self::assertSame('core.me', McpProtocol::adaptResult('tools/list', $result, '2025-06-18')['tools'][0]['_meta']['dev.whity/toolset']);
    }

    /**
     * @param list<array<string, mixed>> $declarations
     * @return array<string, string>
     */
    private function metaOf(array $declarations, string $name): array
    {
        ToolDeriver::clearCache();
        $meta = $this->metaByName((new ToolDeriver($declarations, [], null, static fn (string $m): null => null))->deriveTools());
        self::assertArrayHasKey($name, $meta);
        return $meta[$name];
    }

    /**
     * @param list<array<string, mixed>> $tools
     * @return array<string, array<string, string>>
     */
    private function metaByName(array $tools): array
    {
        $out = [];
        foreach ($tools as $tool) {
            $out[$tool['name']] = $tool['_meta'];
        }
        return $out;
    }

    private function noop(): \Closure
    {
        return static fn (): SdkResponse => SdkResponse::json([]);
    }
}
