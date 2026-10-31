<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\McpToolsAdminHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\ToolDeriver;

/**
 * The admin tool catalogue lists what an MCP client can be offered — no more
 * (ADR-0006 §4, WHIT-695; aligned in WHIT-697).
 */
final class McpToolsAdminHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::setTenantId(3);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        ToolDeriver::clearCache();
    }

    public function testListsExposedToolsOnly(): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/widgets', fn () => null, null, null, 'widgets:read', ['summary' => 'List widgets']);
        $router->registerUnversioned('GET', '/api/inbox', fn () => null, null, null, null, ['summary' => 'My inbox', 'mcp' => true]);
        $router->registerUnversioned('GET', '/api/session', fn () => null, null, null, null, ['summary' => 'Current session']);

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')->willReturn(true);

        $request       = new Request('GET', '/api/admin/mcp/tools');
        $request->user = (object) ['profile_id' => 7];

        $response = (new McpToolsAdminHandler(new ToolDeriver([], [], $router), $roleChecker))->list($request);
        $names    = array_column(json_decode($response->getBody(), true)['data'], 'name');

        self::assertSame(200, $response->getStatusCode());
        self::assertEqualsCanonicalizing(['get_api_widgets', 'get_api_inbox'], $names);
    }

    /** WHIT-754: the tool surface per tool, plugin tools included, and what each list mode costs. */
    public function testShowsEachToolsPlaceOnTheSurfaceAndTheListSizes(): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/widgets', fn () => null, null, null, 'widgets:read', ['summary' => 'List widgets', 'tags' => ['forms']]);
        $authored = new AuthoredToolRegistry();
        $authored->register([
            'name' => 'period_close_status', 'description' => 'Where the period stands', 'inputSchema' => ['type' => 'object'],
            'handler' => static fn (): array => [], 'requiredPermission' => 'periods:read',
            'visibility' => 'always-on', 'annotations' => ['readOnlyHint' => true],
        ], 'Plugins\PeriodClose\PeriodClosePlugin');
        $authored->register([
            'name' => 'period_close_lock', 'description' => 'Lock a period', 'inputSchema' => ['type' => 'object'],
            'handler' => static fn (): array => [], 'requiredPermission' => 'periods:manage', 'agentPolicy' => 'propose',
        ], 'Plugins\PeriodClose\PeriodClosePlugin');

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')->willReturn(true);
        $request       = new Request('GET', '/api/admin/mcp/tools');
        $request->user = (object) ['profile_id' => 7];

        $list = static function (array $enabled) use ($router, $roleChecker, $authored, $request): array {
            ToolDeriver::clearCache();
            $handler = new McpToolsAdminHandler(new ToolDeriver([], [], $router), $roleChecker, $authored, static fn (int $t): array => $enabled);
            return json_decode($handler->list($request)->getBody(), true);
        };

        $off  = $list([]);
        $rows = array_column($off['data'], null, 'name');
        self::assertSame('core.forms', $rows['get_api_widgets']['toolset']);
        self::assertSame('period-close', $rows['period_close_status']['toolset']);
        self::assertSame('Plugins\PeriodClose\PeriodClosePlugin', $rows['period_close_status']['plugin']);
        self::assertTrue($rows['period_close_status']['alwaysOnRequested']);
        self::assertFalse($rows['period_close_status']['alwaysOnEnabled'], 'a request alone lists nothing');
        self::assertSame('propose', $rows['period_close_lock']['agentPolicy']);
        self::assertSame(3, $off['sizes']['default']['tools'], 'init_session plus search and call');
        self::assertSame(3, $off['sizes']['full']['tools']);

        $on = $list(['period_close_status']);
        self::assertTrue(array_column($on['data'], null, 'name')['period_close_status']['alwaysOnEnabled']);
        self::assertSame(4, $on['sizes']['default']['tools']);
        self::assertGreaterThan($off['sizes']['default']['bytes'], $on['sizes']['default']['bytes']);
    }
}
