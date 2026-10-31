<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Token toolsets (WHIT-749, docs/design/MCP-TOOL-SURFACE.md §5.4): a token
 * whose scope names `toolset:` entries reaches only tools in them, in
 * tools/list AND tools/call (owner decision 3). A token naming none is
 * unchanged.
 */
final class McpScopeToolsetTest extends TestCase
{
    private const TENANT = 7;
    private const USER   = 42;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    // ---- the scope ----

    public function testATokenWithoutToolsetsReachesEveryToolset(): void
    {
        $scope = new McpScope(['tools:call', 'users:read']);

        self::assertSame([], $scope->toolsetEntries());
        self::assertTrue($scope->allowsToolset('core.forms'));
        self::assertTrue($scope->allowsToolset(null));
    }

    public function testToolsetEntriesNarrowByIdAndPrefix(): void
    {
        $scope = new McpScope(['tools:call', 'toolset:core.forms', 'toolset:acme.*']);

        self::assertSame(['core.forms', 'acme.*'], $scope->toolsetEntries());
        self::assertTrue($scope->allowsToolset('core.forms'));
        self::assertFalse($scope->allowsToolset('core.forms-extra'));
        self::assertFalse($scope->allowsToolset('core.documents'));
        self::assertTrue($scope->allowsToolset('acme'));
        self::assertTrue($scope->allowsToolset('acme.periods'));
        self::assertFalse($scope->allowsToolset('acmeish'));
        self::assertFalse($scope->allowsToolset(null));
    }

    public function testToolsetEntriesAreNotPermissionsAndDoNotNarrowPermissions(): void
    {
        $scope = new McpScope(['tools:call', 'toolset:core.forms']);

        // No permission entry, so the principal keeps its full RBAC.
        self::assertTrue($scope->allowsPermission('users:read'));
        self::assertTrue($scope->allowsPermission(null));
    }

    public function testIssuanceChecksToolsets(): void
    {
        self::assertSame([], McpScope::issuanceErrors(['tools:call', 'toolset:core.forms', 'toolset:core.documents.themes', 'toolset:core.*', 'toolset:acme.*'], []));
        self::assertSame(
            ['unknown toolset: toolset:core.nope', 'malformed toolset: toolset:Bad Name'],
            McpScope::issuanceErrors(['tools:call', 'toolset:core.nope', 'toolset:Bad Name'], []),
        );
    }

    // ---- tools/list and tools/call ----

    public function testTheListShowsOnlyTheTokensToolsets(): void
    {
        [$list] = $this->handlers(['tools:call', 'toolset:core.forms']);

        self::assertSame(['get_api_forms'], array_column($list->listFor($this->principal(['tools:call', 'toolset:core.forms']), self::TENANT), 'name'));
    }

    public function testACallOutsideTheTokensToolsetsIsRefusedNotHidden(): void
    {
        [, $call] = $this->handlers(['tools:call', 'toolset:core.forms']);

        try {
            $call(['name' => 'get_api_ous', 'arguments' => []], 'bearer');
            self::fail('a call outside the token\'s toolsets ran');
        } catch (McpException $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->errorCode);
            self::assertStringContainsString('core.forms', $e->getMessage());
        }

        $result = $call(['name' => 'get_api_forms', 'arguments' => []], 'bearer');
        self::assertIsArray($result);
    }

    public function testAuthoredToolsAreNarrowedByTheirPluginsToolset(): void
    {
        [$list, $call] = $this->handlers(['tools:call', 'toolset:core.forms']);

        self::assertNotContains('period_close_lock', array_column($list->listFor($this->principal(['tools:call', 'toolset:core.forms']), self::TENANT), 'name'));
        $this->expectException(McpException::class);
        $call(['name' => 'period_close_lock', 'arguments' => []], 'bearer');
    }

    /**
     * @param list<string> $scope
     * @return array{ToolsListHandler, ToolsCallHandler}
     */
    private function handlers(array $scope): array
    {
        $router = new Router('');
        $ok     = static fn (): SdkResponse => SdkResponse::json(['ok' => true]);
        $router->registerUnversioned('GET', '/api/forms', $ok, null, null, 'forms:read');
        $router->registerUnversioned('GET', '/api/ous', $ok, null, null, 'ous:read');
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/forms', 'requiredPermission' => 'forms:read', 'schema' => ['summary' => 'List forms', 'tags' => ['forms']]],
            ['method' => 'GET', 'path' => '/api/ous', 'requiredPermission' => 'ous:read', 'schema' => ['summary' => 'List units', 'tags' => ['ous']]],
        ], [], $router);

        $authored = new AuthoredToolRegistry();
        $authored->register([
            'name' => 'period_close_lock', 'description' => 'Lock a period', 'inputSchema' => ['type' => 'object'],
            'handler' => static fn (): array => ['locked' => true], 'open' => true,
        ], 'Plugins\\PeriodClose\\PeriodClosePlugin');

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn($this->principal($scope));

        return [
            new ToolsListHandler($deriver, $roles, $tokens, $authored),
            new ToolsCallHandler($deriver, $router, $roles, $tokens, authoredTools: $authored),
        ];
    }

    /** @param list<string> $scope */
    private function principal(array $scope): McpPrincipal
    {
        return new McpPrincipal(self::USER, self::USER, self::TENANT, 'user', $scope, 'jti-toolsets');
    }
}
