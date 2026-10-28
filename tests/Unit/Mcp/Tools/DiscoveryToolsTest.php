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

    public function testListOffersExactlyTheThreeDiscoveryTools(): void
    {
        $tools = ($this->discovery()->listHandler())(null, self::BEARER)['tools'];

        self::assertSame(['search_tools', 'describe_tool', 'call_tool'], array_column($tools, 'name'));
        self::assertTrue($tools[0]['annotations']['readOnlyHint']);
        self::assertTrue($tools[1]['annotations']['readOnlyHint']);
        self::assertArrayNotHasKey('destructiveHint', $tools[2]['annotations'], 'call_tool may delete; it keeps the cautious default');
    }

    public function testSearchFindsToolsByWhatTheyDo(): void
    {
        $this->grantAll();

        $names = array_column($this->search('delete users')['matches'], 'name');

        self::assertSame(['delete_api_users_id'], $names, 'every term must match, and "users" finds "user"');
    }

    public function testSearchRanksAndReportsHints(): void
    {
        $this->grantAll();

        $found = $this->search('users');
        $byName = array_column($found['matches'], null, 'name');

        self::assertSame(2, $found['total']);
        self::assertTrue($byName['get_api_users']['readOnly']);
        self::assertFalse($byName['get_api_users']['destructive']);
        self::assertTrue($byName['delete_api_users_id']['destructive']);
    }

    public function testSearchNeverFindsWhatTheCallerCannotSee(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static fn (int $id, string $permission): bool => $permission === 'users:read');

        $everything = array_column($this->search('')['matches'], 'name');

        self::assertSame(['get_api_users'], $everything, 'no RBAC-denied tool, and no ungated route like login');
    }

    public function testSearchHonoursTheLimit(): void
    {
        $this->grantAll();

        $found = $this->search('', 1);

        self::assertCount(1, $found['matches']);
        self::assertSame(3, $found['total']);
        self::assertNotNull($found['hint']);
    }

    public function testDescribeReturnsTheFullToolForAVisibleName(): void
    {
        $this->grantAll();

        $tool = $this->decode($this->call('describe_tool', ['name' => 'delete_api_users_id']));

        self::assertSame('delete_api_users_id', $tool['name']);
        self::assertSame(['id'], $tool['inputSchema']['required']);
        self::assertTrue($tool['annotations']['destructiveHint']);
    }

    public function testDescribeTreatsHiddenAndUnknownAlike(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(false);

        $hidden  = $this->call('describe_tool', ['name' => 'get_api_users']);
        $unknown = $this->call('describe_tool', ['name' => 'no_such_tool']);

        self::assertTrue($hidden['isError']);
        self::assertSame(
            str_replace('get_api_users', 'X', $hidden['content'][0]['text']),
            str_replace('no_such_tool', 'X', $unknown['content'][0]['text']),
        );
    }

    public function testCallToolGoesThroughTheRealCallHandler(): void
    {
        $this->grantAll();

        $this->call('call_tool', ['name' => 'delete_api_users_id', 'arguments' => ['id' => '42']]);

        self::assertSame(['delete user {"id":"42"}'], $this->called);
    }

    public function testCallToolIsStillSubjectToRbac(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(false);

        try {
            $this->call('call_tool', ['name' => 'delete_api_users_id', 'arguments' => ['id' => '42']]);
            self::fail('the real call handler must refuse');
        } catch (McpException $e) {
            self::assertNotSame(0, $e->getCode());
        }
        self::assertSame([], $this->called);
    }

    public function testARealToolNameWorksDirectly(): void
    {
        $this->grantAll();

        $this->call('get_api_users', []);

        self::assertSame(['list users []'], $this->called);
    }

    public function testCallToolRefusesToCallADiscoveryTool(): void
    {
        $result = $this->call('call_tool', ['name' => 'call_tool', 'arguments' => []]);

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

        self::assertSame(['search_tools', 'describe_tool', 'call_tool'], $names($transport->handlePost($request('/mcp?tools=discover'))));
        self::assertContains('get_api_users', $names($transport->handlePost($request('/mcp'))));
        self::assertContains('get_api_users', $names($transport->handlePost($request('/mcp?tools=everything'))));
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
        return $this->decode($this->call('search_tools', $arguments));
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
