<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Mcp\Transport\McpTransportHandler;
use Whity\Sdk\Http\Response;

/**
 * Discovery mode (WHIT-707): three tools reach the whole catalogue, and never
 * more of it than the caller could see in the full list.
 */
final class DiscoveryToolsTest extends TestCase
{
    private const BEARER    = 'bearer';
    private const USER_ID   = 7;
    private const TENANT_ID = 3;

    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    private Router $router;
    private ToolsListHandler $toolsList;
    private ToolsCallHandler $toolsCall;
    /** @var list<string> */
    private array $called = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::setTenantId(self::TENANT_ID);

        $this->router = new Router('');
        $record = function (string $what): \Closure {
            return function (Request $request, array $params) use ($what): Response {
                $this->called[] = $what . ' ' . json_encode($params);
                return Response::json(['did' => $what]);
            };
        };
        $this->router->registerUnversioned('GET', '/api/users', $record('list users'), null, null, 'users:read', ['summary' => 'List users']);
        $this->router->registerUnversioned('DELETE', '/api/users/{id}', $record('delete user'), null, null, 'users:manage', ['summary' => 'Delete a user']);
        $this->router->registerUnversioned('GET', '/api/invoices', $record('list invoices'), null, null, 'billing:read', ['summary' => 'List invoices']);
        $this->router->registerUnversioned('POST', '/api/login', $record('login'), null, null, null, ['summary' => 'Log in']);

        $deriver              = new ToolDeriver([], [], $this->router);
        $this->roleChecker    = $this->createMock(RoleChecker::class);
        $this->tokenValidator = $this->createMock(TokenValidator::class);
        $this->tokenValidator->method('validateBearerForMcp')->willReturn(
            new McpPrincipal(self::USER_ID, self::USER_ID, self::TENANT_ID, 'user', ['tools:call'], 'jti')
        );
        $this->toolsList = new ToolsListHandler($deriver, $this->roleChecker, $this->tokenValidator);
        $this->toolsCall = new ToolsCallHandler($deriver, $this->router, $this->roleChecker, $this->tokenValidator);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        ToolDeriver::clearCache();
    }

    public function testListOffersExactlySearchAndCall(): void
    {
        $tools = ($this->discovery()->listHandler())(null, self::BEARER)['tools'];

        self::assertSame(['search_operations', 'call_operation'], array_column($tools, 'name'));
        self::assertTrue($tools[0]['annotations']['readOnlyHint']);
        self::assertArrayNotHasKey('destructiveHint', $tools[1]['annotations'], 'call_operation may delete; it keeps the cautious default');
    }

    public function testAnEmptyQueryListsTheAreasNotTheOperations(): void
    {
        $this->grantAll();

        $toc = $this->decode($this->call('search_operations', ['query' => '']));

        self::assertArrayNotHasKey('matches', $toc);
        self::assertSame(3, array_sum(array_column($toc['toolsets'], 'operations')));
    }

    public function testTheWhit707NamesStillAnswer(): void
    {
        $this->grantAll();

        $found = $this->decode($this->call('search_tools', ['query' => 'delete users']));
        self::assertSame(['delete_api_users_id'], array_column($found['matches'], 'operationId'));

        $described = $this->decode($this->call('describe_tool', ['name' => 'delete_api_users_id']));
        self::assertSame('delete_api_users_id', $described['operationId']);

        $this->call('call_tool', ['name' => 'get_api_users', 'arguments' => []]);
        self::assertSame(['list users []'], $this->called);
    }

    public function testSearchFindsToolsByWhatTheyDo(): void
    {
        $this->grantAll();

        $names = array_column($this->search('delete users')['matches'], 'operationId');

        self::assertSame(['delete_api_users_id'], $names, 'every term must match, and "users" finds "user"');
    }

    public function testSearchRanksAndReportsHints(): void
    {
        $this->grantAll();

        $found = $this->search('users');
        $byName = array_column($found['matches'], null, 'operationId');

        self::assertSame(2, $found['total']);
        self::assertTrue($byName['get_api_users']['readOnly']);
        self::assertFalse($byName['get_api_users']['destructive']);
        self::assertTrue($byName['delete_api_users_id']['destructive']);
    }

    public function testSearchNeverFindsWhatTheCallerCannotSee(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static fn (int $id, string $permission): bool => $permission === 'users:read');

        $everything = array_column($this->search('api')['matches'], 'operationId');

        self::assertSame(['get_api_users'], $everything, 'no RBAC-denied tool, and no ungated route like login');
    }

    public function testSearchHonoursTheLimit(): void
    {
        $this->grantAll();

        $found = $this->search('api', 1);

        self::assertCount(1, $found['matches']);
        self::assertSame(3, $found['total']);
        self::assertNotNull($found['hint']);
    }

    public function testDescribeReturnsTheFullToolForAVisibleName(): void
    {
        $this->grantAll();

        $tool = $this->decode($this->call('search_operations', ['operationId' => 'delete_api_users_id']));

        self::assertSame('delete_api_users_id', $tool['operationId']);
        self::assertSame(['id'], $tool['inputSchema']['required']);
        self::assertTrue($tool['destructive']);
        self::assertSame('allow', $tool['agentPolicy']);
    }

    public function testDescribeTreatsHiddenAndUnknownAlike(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(false);

        $hidden  = $this->call('search_operations', ['operationId' => 'get_api_users']);
        $unknown = $this->call('search_operations', ['operationId' => 'no_such_tool']);

        self::assertTrue($hidden['isError']);
        self::assertSame(
            str_replace('get_api_users', 'X', $hidden['content'][0]['text']),
            str_replace('no_such_tool', 'X', $unknown['content'][0]['text']),
        );
    }

    public function testCallToolGoesThroughTheRealCallHandler(): void
    {
        $this->grantAll();

        $this->call('call_operation', ['operationId' => 'delete_api_users_id', 'arguments' => ['id' => '42']]);

        self::assertSame(['delete user {"id":"42"}'], $this->called);
    }

    public function testCallToolIsStillSubjectToRbac(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(false);

        // Refused by the real call handler, and reported to the model as a
        // tool error it can read (WHIT-710), not a protocol error.
        $result = $this->call('call_operation', ['operationId' => 'delete_api_users_id', 'arguments' => ['id' => '42']]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Not allowed', $result['content'][0]['text']);
        self::assertSame([], $this->called);
    }

    public function testARealToolNameWorksDirectly(): void
    {
        $this->grantAll();

        $this->call('get_api_users', []);

        self::assertSame(['list users []'], $this->called);
    }

    public function testCallToolTellsTheModelToSearchForAnUnknownName(): void
    {
        // WHIT-710: the name is an argument the model chose, so the error is a
        // tool result it can read, not a protocol error it may never see.
        $result = $this->call('call_operation', ['operationId' => 'delete_everything', 'arguments' => []]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Use search_operations', $result['content'][0]['text']);
    }

    public function testCallToolReportsBadArgumentsAsAToolError(): void
    {
        $this->grantAll();

        $result = $this->call('call_operation', ['operationId' => 'delete_api_users_id', 'arguments' => []]);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('Missing required argument: id', $result['content'][0]['text']);
        self::assertSame([], $this->called);
    }

    public function testCallToolRefusesToCallADiscoveryTool(): void
    {
        $result = $this->call('call_operation', ['operationId' => 'call_operation', 'arguments' => []]);

        self::assertTrue($result['isError']);
    }

    public function testMissingNameIsInvalidParams(): void
    {
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);
        ($this->discovery()->callHandler())(['arguments' => []], self::BEARER);
    }

    public function testTheTransportServesDiscoveryOnlyWhenTheUrlAsksForIt(): void
    {
        $full = new Dispatcher(['tools/list' => $this->toolsList], $this->tokenValidator);
        $discover = new Dispatcher(['tools/list' => $this->discovery()->listHandler()], $this->tokenValidator);
        $transport = new McpTransportHandler($full, discoveryDispatcher: $discover);
        $body = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
        $this->grantAll();
        // The dispatcher binds the tenant from the token itself, as in production.
        TenantContext::reset();

        $names = static function (Response $response): array {
            return array_column(json_decode($response->getBody(), true)['result']['tools'], 'name');
        };
        $request = static fn (string $path): Request => new Request('POST', $path, [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . self::BEARER,
        ], $body);

        self::assertSame(['search_operations', 'call_operation'], $names($transport->handlePost($request('/mcp?tools=discover'))));
        self::assertContains('get_api_users', $names($transport->handlePost($request('/mcp'))));
        self::assertContains('get_api_users', $names($transport->handlePost($request('/mcp?tools=everything'))));
    }

    public function testTheTransportReadsTheModeFromTheQueryProductionPutsIn_GET(): void
    {
        // Request::fromGlobals() keeps only the path; the query string is in $_GET.
        $transport = new McpTransportHandler(
            new Dispatcher(['tools/list' => $this->toolsList], $this->tokenValidator),
            discoveryDispatcher: new Dispatcher(['tools/list' => $this->discovery()->listHandler()], $this->tokenValidator),
        );
        $this->grantAll();
        TenantContext::reset();

        $_GET['tools'] = 'discover';
        try {
            $response = $transport->handlePost(new Request('POST', '/mcp', [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . self::BEARER,
            ], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
        } finally {
            unset($_GET['tools']);
        }

        self::assertSame(
            ['search_operations', 'call_operation'],
            array_column(json_decode($response->getBody(), true)['result']['tools'], 'name'),
        );
    }

    private function discovery(): DiscoveryTools
    {
        return new DiscoveryTools($this->toolsList, $this->toolsCall, $this->tokenValidator);
    }

    private function grantAll(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $name, array $arguments): array
    {
        $result = ($this->discovery()->callHandler())(['name' => $name, 'arguments' => $arguments], self::BEARER);
        self::assertIsArray($result);
        return $result;
    }

    /** @return array<string, mixed> */
    private function search(string $query, ?int $limit = null): array
    {
        $arguments = ['query' => $query] + ($limit !== null ? ['limit' => $limit] : []);
        return $this->decode($this->call('search_operations', $arguments));
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function decode(array $result): array
    {
        self::assertArrayNotHasKey('isError', $result, $result['content'][0]['text'] ?? '');
        $decoded = json_decode($result['content'][0]['text'], true);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
