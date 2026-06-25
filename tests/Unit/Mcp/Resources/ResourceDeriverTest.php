<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Resources;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Sdk\Http\Response;

/**
 * TDD tests for ResourceDeriver (WC-30513809).
 *
 * Verifies that GET routes with schemas are exposed as MCP resources or
 * resourceTemplates, that the whity-api:// URI scheme is applied consistently,
 * and that routing constraints ({id:\d+}) are stripped from URI templates per
 * RFC 6570.
 */
final class ResourceDeriverTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(''); // no version prefix in unit tests
    }

    // ── Empty / filtering behaviour ───────────────────────────────────────────

    public function testDeriveResources_returnsEmptyArrays_whenNoDeclarations(): void
    {
        $deriver = new ResourceDeriver([]);
        $result  = $deriver->deriveResources();

        self::assertSame(['resources' => [], 'resourceTemplates' => []], $result);
    }

    public function testDeriveResources_skipsNonGetDeclarations(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'POST',   'path' => '/api/things', 'schema' => ['summary' => 'Create thing']],
            ['method' => 'PATCH',  'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Update thing']],
            ['method' => 'DELETE', 'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Delete thing']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame([], $result['resources']);
        self::assertSame([], $result['resourceTemplates']);
    }

    public function testDeriveResources_skipsGetRoutesWithoutSchema(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => null],
            ['method' => 'GET', 'path' => '/api/stuff',  'schema' => []],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame([], $result['resources']);
        self::assertSame([], $result['resourceTemplates']);
    }

    // ── Static resource (no path params) ─────────────────────────────────────

    public function testDeriveResources_classifiesRouteWithoutPathParams_asStaticResource(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things']],
        ]);
        $result = $deriver->deriveResources();

        self::assertCount(1, $result['resources']);
        self::assertSame([], $result['resourceTemplates']);
        self::assertArrayHasKey('uri', $result['resources'][0]);
        self::assertArrayNotHasKey('uriTemplate', $result['resources'][0]);
    }

    public function testDeriveResources_constructsUri_withWhityApiScheme_forStaticResource(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame('whity-api:///api/things', $result['resources'][0]['uri']);
    }

    public function testDeriveResources_usesSummary_asResourceName(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List all things']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame('List all things', $result['resources'][0]['name']);
    }

    public function testDeriveResources_generatesFallbackName_whenNoSummary(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/widgets', 'schema' => ['tags' => ['widgets']]],
        ]);
        $result = $deriver->deriveResources();

        self::assertStringContainsString('/api/widgets', $result['resources'][0]['name']);
    }

    public function testDeriveResources_setsApplicationJsonMimeType_forStaticResource(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame('application/json', $result['resources'][0]['mimeType']);
    }

    // ── Resource template (has path params) ───────────────────────────────────

    public function testDeriveResources_classifiesRouteWithPathParams_asResourceTemplate(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Get thing']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame([], $result['resources']);
        self::assertCount(1, $result['resourceTemplates']);
        self::assertArrayHasKey('uriTemplate', $result['resourceTemplates'][0]);
        self::assertArrayNotHasKey('uri', $result['resourceTemplates'][0]);
    }

    public function testDeriveResources_cleansConstraints_fromUriTemplate(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Get thing']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame('whity-api:///api/things/{id}', $result['resourceTemplates'][0]['uriTemplate']);
    }

    public function testDeriveResources_cleansAlphanumericConstraints_fromUriTemplate(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/items/{slug:[a-z0-9-]+}', 'schema' => ['summary' => 'Get item']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame('whity-api:///api/items/{slug}', $result['resourceTemplates'][0]['uriTemplate']);
    }

    public function testDeriveResources_setsApplicationJsonMimeType_forResourceTemplate(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Get thing']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame('application/json', $result['resourceTemplates'][0]['mimeType']);
    }

    // ── Mixed static + template ───────────────────────────────────────────────

    public function testDeriveResources_separatesStaticAndTemplate_correctly(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things',         'schema' => ['summary' => 'List things']],
            ['method' => 'GET', 'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Get thing']],
        ]);
        $result = $deriver->deriveResources();

        self::assertCount(1, $result['resources']);
        self::assertCount(1, $result['resourceTemplates']);
        self::assertSame('whity-api:///api/things', $result['resources'][0]['uri']);
        self::assertSame('whity-api:///api/things/{id}', $result['resourceTemplates'][0]['uriTemplate']);
    }

    // ── Router-native plugin routes ───────────────────────────────────────────

    public function testDeriveResources_includesRouterGetRoutesWithSchema(): void
    {
        $this->router->registerUnversioned(
            'GET', '/api/plugin-things', fn (): Response => Response::json([]),
            null, null, null, ['summary' => 'Plugin things']
        );

        $deriver = new ResourceDeriver([], $this->router);
        $result  = $deriver->deriveResources();

        self::assertCount(1, $result['resources']);
        self::assertSame('whity-api:///api/plugin-things', $result['resources'][0]['uri']);
    }

    public function testDeriveResources_skipsRouterGetRoutesWithoutSchema(): void
    {
        $this->router->registerUnversioned(
            'GET', '/api/no-schema', fn (): Response => Response::json([])
        );

        $deriver = new ResourceDeriver([], $this->router);
        $result  = $deriver->deriveResources();

        self::assertSame([], $result['resources']);
        self::assertSame([], $result['resourceTemplates']);
    }

    public function testDeriveResources_includesRouterTemplateRoutes_whenPathHasParams(): void
    {
        $this->router->registerUnversioned(
            'GET', '/api/plugin-items/{id:\d+}', fn (): Response => Response::json([]),
            null, null, null, ['summary' => 'Get plugin item']
        );

        $deriver = new ResourceDeriver([], $this->router);
        $result  = $deriver->deriveResources();

        self::assertCount(1, $result['resourceTemplates']);
        self::assertSame('whity-api:///api/plugin-items/{id}', $result['resourceTemplates'][0]['uriTemplate']);
    }

    // ── Version prefix handling ───────────────────────────────────────────────

    public function testDeriveResources_appliesVersionPrefix_toStaticDeclarations(): void
    {
        $router = new Router('/v1');

        $deriver = new ResourceDeriver(
            [['method' => 'GET', 'path' => '/api/stuff', 'schema' => ['summary' => 'Get stuff']]],
            $router,
        );
        $result = $deriver->deriveResources();

        self::assertSame('whity-api:///api/v1/stuff', $result['resources'][0]['uri']);
    }

    public function testDeriveResources_doesNotApplyVersionPrefix_whenEmpty(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things']],
        ]);
        $result = $deriver->deriveResources();

        self::assertSame('whity-api:///api/things', $result['resources'][0]['uri']);
    }

    // ── buildAccessMap() ──────────────────────────────────────────────────────

    public function testBuildAccessMap_returnsEmptyMap_whenNoDeclarations(): void
    {
        $deriver = new ResourceDeriver([]);

        self::assertSame([], $deriver->buildAccessMap());
    }

    public function testBuildAccessMap_mapsOpenResource_toNullPermissions(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things'], 'requiredRole' => null, 'requiredPermission' => null],
        ]);

        $map = $deriver->buildAccessMap();

        self::assertArrayHasKey('whity-api:///api/things', $map);
        self::assertNull($map['whity-api:///api/things']['requiredRole']);
        self::assertNull($map['whity-api:///api/things']['requiredPermission']);
    }

    public function testBuildAccessMap_mapsPermissionProtectedResource_toPermission(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things'], 'requiredRole' => null, 'requiredPermission' => 'things:read'],
        ]);

        $map = $deriver->buildAccessMap();

        self::assertSame('things:read', $map['whity-api:///api/things']['requiredPermission']);
    }

    public function testBuildAccessMap_mapsRoleProtectedResource_toRole(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things'], 'requiredRole' => 'admin', 'requiredPermission' => null],
        ]);

        $map = $deriver->buildAccessMap();

        self::assertSame('admin', $map['whity-api:///api/things']['requiredRole']);
    }

    public function testBuildAccessMap_skipsNonGetDeclarations(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'POST', 'path' => '/api/things', 'schema' => ['summary' => 'Create things'], 'requiredRole' => null, 'requiredPermission' => null],
        ]);

        self::assertSame([], $deriver->buildAccessMap());
    }

    public function testBuildAccessMap_appliesVersionPrefix_forStaticDeclarations(): void
    {
        $router  = new Router('/v1');
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/things', 'schema' => ['summary' => 'List things'], 'requiredRole' => null, 'requiredPermission' => 'things:read'],
        ], $router);

        $map = $deriver->buildAccessMap();

        self::assertArrayHasKey('whity-api:///api/v1/things', $map);
        self::assertSame('things:read', $map['whity-api:///api/v1/things']['requiredPermission']);
    }

    public function testBuildAccessMap_includesRouterNativeRoutes(): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/widgets', fn () => null, null, null, 'widgets:read', ['summary' => 'List widgets']);

        $deriver = new ResourceDeriver([], $router);
        $map     = $deriver->buildAccessMap();

        self::assertArrayHasKey('whity-api:///api/widgets', $map);
        self::assertSame('widgets:read', $map['whity-api:///api/widgets']['requiredPermission']);
    }

    public function testBuildAccessMap_mapKeysMatchUris_fromDeriveResources(): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/items',        fn () => null, null,    null, null,        ['summary' => 'List items']);
        $router->registerUnversioned('GET', '/api/items/{id:\d+}', fn () => null, 'admin', null, null,      ['summary' => 'Get item']);
        $router->registerUnversioned('GET', '/api/secure',       fn () => null, null,    null, 'sec:read',  ['summary' => 'Secure list']);

        $deriver   = new ResourceDeriver([], $router);
        $resources = $deriver->deriveResources();
        $map       = $deriver->buildAccessMap();

        foreach ($resources['resources'] as $resource) {
            $uri = $resource['uri'];
            self::assertArrayHasKey($uri, $map, "Resource URI '{$uri}' missing from access map");
        }
        foreach ($resources['resourceTemplates'] as $template) {
            $uri = $template['uriTemplate'];
            self::assertArrayHasKey($uri, $map, "Template URI '{$uri}' missing from access map");
        }
    }
}
