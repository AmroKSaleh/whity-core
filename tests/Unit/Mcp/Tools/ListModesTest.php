<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Mcp\Tools\ToolSurfaceRequest;
use Whity\Mcp\Transport\McpTransportHandler;
use Whity\Sdk\Http\Response;

/**
 * List modes and `?toolsets=` (WHIT-748, docs/design/MCP-TOOL-SURFACE.md §5.3).
 */
final class ListModesTest extends TestCase
{
    private const BEARER = 'bearer';
    private const TENANT = 3;

    private McpTransportHandler $transport;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();

        $router = new Router('');
        $ok     = static fn (): Response => Response::json(['ok' => true]);
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/me', 'schema' => ['summary' => 'Me', 'tags' => ['me'], 'mcp' => true]],
            ['method' => 'GET', 'path' => '/api/forms', 'requiredPermission' => 'forms:read', 'schema' => ['summary' => 'List forms', 'tags' => ['forms']]],
            ['method' => 'GET', 'path' => '/api/ous', 'requiredPermission' => 'ous:read', 'schema' => ['summary' => 'List units', 'tags' => ['ous']]],
        ], [], $router);
        $router->registerUnversioned('GET', '/api/me', $ok);
        $router->registerUnversioned('GET', '/api/forms', $ok, null, null, 'forms:read');
        $router->registerUnversioned('GET', '/api/ous', $ok, null, null, 'ous:read');

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(7, 7, self::TENANT, 'user', ['tools:call'], 'jti'));

        $list      = new ToolsListHandler($deriver, $roles, $tokens);
        $call      = new ToolsCallHandler($deriver, $router, $roles, $tokens);
        $discovery = new DiscoveryTools($list, $call, $tokens);

        $this->transport = new McpTransportHandler(
            new Dispatcher(['tools/list' => $list, 'tools/call' => $call], $tokens),
            discoveryDispatcher: new Dispatcher(['tools/list' => $discovery->listHandler(), 'tools/call' => $discovery->callHandler()], $tokens),
            defaultDispatcher: new Dispatcher(['tools/list' => $discovery->defaultListHandler(), 'tools/call' => $discovery->callHandler()], $tokens),
        );
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testTheBareEndpointIsSearchAndCallAndFullKeepsTheOldList(): void
    {
        // WHIT-750 (owner decision 2): ToolSurfaceRequest::BARE_MODE.
        self::assertSame(['get_api_me', 'search_operations', 'call_operation'], $this->names('/mcp'));
        self::assertSame(['get_api_me', 'search_operations', 'call_operation'], $this->names('/mcp?tools=nonsense'));
        self::assertSame(['get_api_me', 'get_api_forms', 'get_api_ous'], $this->names('/mcp?tools=full'));
    }

    public function testDefaultModeListsTheAlwaysOnToolsThenDiscovery(): void
    {
        self::assertSame(['get_api_me', 'search_operations', 'call_operation'], $this->names('/mcp?tools=default'));
        self::assertSame(['search_operations', 'call_operation'], $this->names('/mcp?tools=discover'));
    }

    public function testToolsetsLimitTheListAndSearchButNotWhatCanBeCalled(): void
    {
        self::assertSame(['get_api_forms'], $this->names('/mcp?tools=full&toolsets=core.forms'));
        self::assertSame(['get_api_forms', 'get_api_ous'], $this->names('/mcp?tools=full&toolsets=core.forms,core.people'));
        self::assertSame(['search_operations', 'call_operation'], $this->names('/mcp?toolsets=core.forms'));

        $search = $this->rpc('/mcp?tools=discover&toolsets=core.forms', 'tools/call', ['name' => 'search_operations', 'arguments' => ['query' => 'api']]);
        self::assertSame(['get_api_forms'], array_column(json_decode($search['result']['content'][0]['text'], true)['matches'], 'operationId'));

        // Presentation only: a tool outside the selection is still callable by name.
        $called = $this->rpc('/mcp?tools=full&toolsets=core.forms', 'tools/call', ['name' => 'get_api_ous', 'arguments' => []]);
        self::assertArrayHasKey('result', $called);
    }

    public function testAClientCannotChooseTheSurfaceThroughMeta(): void
    {
        $result = $this->rpc('/mcp?tools=full', 'tools/list', ['_meta' => [ToolSurfaceRequest::META_KEY => ['mode' => 'full', 'toolsets' => ['core.forms']]]]);

        self::assertSame(['get_api_me', 'get_api_forms', 'get_api_ous'], array_column($result['result']['tools'], 'name'));
    }

    public function testTheQueryIsParsedStrictly(): void
    {
        $surface = ToolSurfaceRequest::fromQuery(['tools' => 'default', 'toolsets' => 'core.forms, acme.*, Bad Id,,core.forms']);

        self::assertSame('default', $surface->mode);
        self::assertSame(['core.forms', 'acme.*'], $surface->toolsets);
        self::assertTrue($surface->selects('acme.periods'));
        self::assertFalse($surface->selects('core.people'));
        self::assertSame(ToolSurfaceRequest::BARE_MODE, ToolSurfaceRequest::fromQuery([])->mode);
    }

    /** @return list<string> */
    private function names(string $path): array
    {
        return array_column($this->rpc($path, 'tools/list')['result']['tools'], 'name');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $path, string $method, array $params = []): array
    {
        TenantContext::reset();
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
        $response = $this->transport->handlePost(new Request('POST', $path, [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . self::BEARER,
        ], $body));

        return (array) json_decode($response->getBody(), true);
    }
}
