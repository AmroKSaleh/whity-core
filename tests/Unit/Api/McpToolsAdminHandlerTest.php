<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\McpToolsAdminHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
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
}
