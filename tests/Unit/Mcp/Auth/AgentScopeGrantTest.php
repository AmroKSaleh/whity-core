<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Auth\McpScope;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Sdk\Http\Response;

/**
 * An agent's scope grant: Off, Ask first or Allowed per permission (WHIT-753,
 * register Scope grant, owner decisions 9, 11 and 12).
 */
final class AgentScopeGrantTest extends TestCase
{
    private const TENANT = 4;

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

    // ---- the grant ----

    public function testDefaultsAreReadsAllowedAndEverythingElseAskFirst(): void
    {
        $grant = AgentScopeGrant::fromScope(['tools:call']);

        self::assertSame('allow', $grant->modeFor('invoices:read', true));
        self::assertSame('ask', $grant->modeFor('invoices:send', false));
        self::assertSame('allow', $grant->modeFor(null, true), 'an ungated read');
        self::assertSame('ask', $grant->modeFor(null, false), 'a role-only or ungated write');
    }

    public function testTheGrantNamesAModePerPermission(): void
    {
        $grant = AgentScopeGrant::fromScope(['tools:call', 'grant:invoices:send=allow', 'grant:invoices:read=off']);

        self::assertSame('allow', $grant->modeFor('invoices:send', false));
        self::assertSame('off', $grant->modeFor('invoices:read', true));
    }

    public function testCeilingsAndMissingUndoLowerTheMode(): void
    {
        $allowed = AgentScopeGrant::fromScope(['grant:invoices:send=allow']);

        self::assertSame('allow', $allowed->effectiveMode('invoices:send', false, 'allow', true));
        self::assertSame('ask', $allowed->effectiveMode('invoices:send', false, 'allow', false), 'no undo means Ask first');
        self::assertSame('ask', $allowed->effectiveMode('invoices:send', false, 'propose', true));
        self::assertSame('ask', $allowed->effectiveMode('invoices:send', false, 'suggest-only', true));
        self::assertSame('off', $allowed->effectiveMode('invoices:send', false, 'refuse', true));
        self::assertSame('allow', $allowed->effectiveMode('invoices:read', true, 'allow', false), 'a read needs no undo');
    }

    public function testGrantEntriesAreNotPermissionsAndAreCheckedAtIssuance(): void
    {
        // A grant entry must not narrow the token to "only tools gated by it".
        self::assertTrue((new McpScope(['tools:call', 'grant:users:read=allow']))->allowsPermission('forms:read'));

        self::assertSame([], McpScope::issuanceErrors(['tools:call', 'grant:users:read=allow'], ['users:read']));
        self::assertSame(
            ['malformed grant: grant:users:read=maybe (use grant:<permission>=off|ask|allow)', 'unknown permission in grant: grant:nope:read=off'],
            McpScope::issuanceErrors(['tools:call', 'grant:users:read=maybe', 'grant:nope:read=off'], ['users:read']),
        );
    }

    // ---- tools/list and tools/call for an agent ----

    public function testAnAgentSeesItsModeAndNotWhatIsOff(): void
    {
        [$list] = $this->handlers(['tools:call', 'grant:ous:read=off']);
        $tools  = $list->listFor($this->agent(['tools:call', 'grant:ous:read=off']), self::TENANT);

        self::assertSame(['get_api_forms' => 'allow', 'post_api_forms' => 'ask'], array_column(array_map(
            static fn (array $t): array => ['name' => $t['name'], 'mode' => $t['_meta'][ToolsListHandler::META_AGENT_MODE]],
            $tools,
        ), 'mode', 'name'));
    }

    public function testAnAgentRunsAllowedOperationsAndIsRefusedTheRest(): void
    {
        [, $call] = $this->handlers(['tools:call', 'grant:ous:read=off']);

        self::assertIsArray($call(['name' => 'get_api_forms', 'arguments' => []], 'bearer'));

        foreach (['get_api_ous' => 'not granted', 'post_api_forms' => 'approve'] as $name => $why) {
            try {
                $call(['name' => $name, 'arguments' => []], 'bearer');
                self::fail("{$name} ran");
            } catch (McpException $e) {
                self::assertSame(ErrorCode::FORBIDDEN, $e->errorCode);
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    public function testAPersonsOwnClientHasNoGrant(): void
    {
        [$list, $call] = $this->handlers(['tools:call'], 'user');

        $tools = $list->listFor(new McpPrincipal(7, 7, self::TENANT, 'user', ['tools:call'], 'jti'), self::TENANT);
        self::assertCount(3, $tools);
        self::assertArrayNotHasKey(ToolsListHandler::META_AGENT_MODE, $tools[0]['_meta']);
        self::assertIsArray($call(['name' => 'post_api_forms', 'arguments' => []], 'bearer'));
    }

    /**
     * @param list<string> $scope
     * @return array{ToolsListHandler, ToolsCallHandler}
     */
    private function handlers(array $scope, string $kind = AgentScopeGrant::AGENT_KIND): array
    {
        $router = new Router('');
        $ok     = static fn (): Response => Response::json(['ok' => true]);
        $router->registerUnversioned('GET', '/api/forms', $ok, null, null, 'forms:read');
        $router->registerUnversioned('POST', '/api/forms', $ok, null, null, 'forms:manage');
        $router->registerUnversioned('GET', '/api/ous', $ok, null, null, 'ous:read');
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/forms', 'requiredPermission' => 'forms:read', 'schema' => ['summary' => 'List forms', 'tags' => ['forms']]],
            ['method' => 'POST', 'path' => '/api/forms', 'requiredPermission' => 'forms:manage', 'schema' => ['summary' => 'Create a form', 'tags' => ['forms'], 'request' => ['type' => 'object']]],
            ['method' => 'GET', 'path' => '/api/ous', 'requiredPermission' => 'ous:read', 'schema' => ['summary' => 'List units', 'tags' => ['ous']]],
        ], [], $router, static fn (string $m): null => null);

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(7, 7, self::TENANT, $kind, $scope, 'jti-agent'));

        return [new ToolsListHandler($deriver, $roles, $tokens), new ToolsCallHandler($deriver, $router, $roles, $tokens)];
    }

    /** @param list<string> $scope */
    private function agent(array $scope): McpPrincipal
    {
        return new McpPrincipal(7, 7, self::TENANT, AgentScopeGrant::AGENT_KIND, $scope, 'jti-agent');
    }
}
