<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Tool derivation for plugin-contributed routes (WC-31468883).
 *
 * Plugins contribute MCP *tools* by registering schema-bearing routes on the
 * Router (the {@see \Whity\Sdk\PluginMcpInterface} contributes prompts, not
 * tools). {@see ToolDeriver} reads the Router at derive time, so a route
 * registered after construction — exactly how the plugin loader registers
 * plugin routes — surfaces as a tool.
 *
 * {@see \Tests\Unit\Mcp\Tools\ToolDeriverTest} already covers the access map and
 * inputSchema for Router-native routes. This fills the remaining gaps: a
 * Router-sourced route appears by NAME in deriveTools(), findDeclarationByName()
 * resolves it, and a static (core) declaration takes precedence over a
 * Router route that derives the same name.
 */
final class ToolDeriverPluginRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    public function testDeriveTools_includesRouterContributedRoute_byName(): void
    {
        $router = new Router('');
        $router->registerUnversioned(
            'GET', '/api/plugin/things', $this->noopHandler(), null, null, null,
            ['operationId' => 'plugin_list_things', 'summary' => 'List plugin things'],
        );

        $deriver = new ToolDeriver([], [], $router);
        $names   = array_column($deriver->deriveTools(), 'name');

        self::assertContains('plugin_list_things', $names);
    }

    public function testFindDeclarationByName_resolvesRouterContributedRoute(): void
    {
        $router = new Router('');
        $router->registerUnversioned(
            'POST', '/api/plugin/things', $this->noopHandler(), null, null, 'things:write',
            ['operationId' => 'plugin_create_thing', 'summary' => 'Create plugin thing'],
        );

        $deriver = new ToolDeriver([], [], $router);
        $decl    = $deriver->findDeclarationByName('plugin_create_thing');

        self::assertIsArray($decl);
        self::assertSame('POST', $decl['method']);
        self::assertSame('/api/plugin/things', $decl['path']);
        self::assertSame('things:write', $decl['requiredPermission']);
    }

    public function testStaticDeclaration_takesPrecedence_overRouterRouteWithSameName(): void
    {
        // A static (core) declaration and a Router route both derive the name
        // 'get_api_things'. mergedDeclarations() lists static entries first and
        // findDeclarationByName() returns the first match, so core wins — a
        // plugin route can never shadow a core tool of the same name.
        $static = [[
            'method'             => 'GET',
            'path'               => '/api/things',
            'schema'             => ['summary' => 'Core list things'],
            'requiredPermission' => 'core:read',
        ]];

        $router = new Router('');
        $router->registerUnversioned(
            'GET', '/api/things', $this->noopHandler(), null, null, 'plugin:read',
            ['summary' => 'Plugin list things'],
        );

        $deriver = new ToolDeriver($static, [], $router);
        $decl    = $deriver->findDeclarationByName('get_api_things');

        self::assertIsArray($decl);
        self::assertSame('Core list things', $decl['schema']['summary'], 'core declaration must win');
        self::assertSame('core:read', $decl['requiredPermission']);
    }

    private function noopHandler(): callable
    {
        return static fn (): SdkResponse => SdkResponse::json([]);
    }
}
