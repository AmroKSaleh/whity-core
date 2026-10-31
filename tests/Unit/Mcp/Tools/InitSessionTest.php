<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Lifecycle\InitializeHandler;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\Discovery\SessionBriefing;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * `init_session` (WHIT-794): the briefing a model reads first, built from the
 * caller's own tool list, so it never claims more than the caller can do.
 */
final class InitSessionTest extends TestCase
{
    private const TENANT = 1;

    /** @var list<string> */
    private array $scope = ['tools:call'];
    private string $kind = 'user';

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

    public function testItIsAlwaysOnAndListedFirst(): void
    {
        $tools = ($this->discovery()->defaultListHandler())(null, 'bearer')['tools'];

        self::assertSame('init_session', $tools[0]['name']);
        self::assertTrue($tools[0]['annotations']['readOnlyHint']);
        self::assertStringStartsWith('Call this first', $tools[0]['description']);
        self::assertCount(5, array_filter($tools, static fn (array $t): bool => !in_array($t['name'], ['search_operations', 'call_operation'], true)), 'init_session plus four always-on operations');
    }

    public function testAPersonIsBriefedOnWhoTheyAreAndWhatTheyReach(): void
    {
        $briefing = $this->brief();

        self::assertSame('Rana Example', $briefing['you']['actingAs']);
        self::assertSame('Example Bakery', $briefing['you']['workspace']);
        self::assertContains('core.forms', array_column($briefing['canReach'], 'id'));
        self::assertArrayNotHasKey('askFirst', $briefing, 'a person acts as themselves');
        self::assertSame(60, $briefing['limits']['callsPerMinute']);
    }

    public function testANarrowedTokenChangesTheBriefing(): void
    {
        $this->scope = ['tools:call', 'toolset:core.forms'];
        $briefing    = $this->brief();

        self::assertSame(['core.forms'], array_column($briefing['canReach'], 'id'));
        self::assertSame(['core.forms'], $briefing['you']['limitedTo']);
    }

    public function testAnAgentIsToldWhatNeedsApprovalAndOffIsOnlyCounted(): void
    {
        $this->kind  = 'ai-agent';
        $this->scope = ['tools:call', 'grant:ous:read=off', 'grant:users:read=off'];
        $briefing    = $this->brief();

        self::assertStringStartsWith('an agent acting for Rana Example', $briefing['you']['actingAs']);
        self::assertStringContainsString('proposal', $briefing['askFirst']);
        self::assertStringContainsString('Never change documents directly', $briefing['rules'][0]);
        self::assertSame(2, $briefing['notGranted']);
        self::assertStringNotContainsString('ous:read', (string) json_encode($briefing), 'what is Off is never named');
    }

    public function testAnAgentsModesAreCountedPerArea(): void
    {
        $agent = new McpPrincipal(7, 7, self::TENANT, 'ai-agent', ['tools:call'], 'jti');
        $tools = [
            ['name' => 'get_api_forms', '_meta' => ['dev.whity/toolset' => 'core.forms', 'dev.whity/agentMode' => 'allow']],
            ['name' => 'post_api_forms', '_meta' => ['dev.whity/toolset' => 'core.forms', 'dev.whity/agentMode' => 'ask']],
        ];

        $reach = (new SessionBriefing())->brief($agent, $tools, 'en')['canReach'];

        self::assertSame([['id' => 'core.forms', 'title' => 'Forms', 'operations' => 2, 'allowed' => 1, 'askFirst' => 1]], $reach);
    }

    public function testArabic(): void
    {
        $briefing = $this->brief('ar');

        self::assertMatchesRegularExpression('/\p{Arabic}/u', $briefing['howToWork'][0]);
        self::assertContains('النماذج', array_column($briefing['canReach'], 'title'));
    }

    public function testItStaysSmallForAnAdministratorOfTheWholeCore(): void
    {
        $bytes = strlen((string) json_encode($this->brief()));

        // About 1.5k tokens at roughly four bytes a token.
        self::assertLessThan(6000, $bytes, "the briefing is {$bytes} bytes");
    }

    public function testInitializeCarriesTheShortVersion(): void
    {
        $result = (new InitializeHandler(instructions: SessionBriefing::instructions()))(null, null);

        self::assertStringStartsWith('Call init_session first', $result['instructions']);
        self::assertArrayNotHasKey('instructions', (new InitializeHandler())(null, null));
    }

    /** @return array<string, mixed> */
    private function brief(string $language = 'en'): array
    {
        $result = ($this->discovery()->callHandler())(['name' => 'init_session', 'arguments' => ['language' => $language]], 'bearer');
        self::assertIsArray($result);
        $decoded = json_decode($result['content'][0]['text'], true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function discovery(): DiscoveryTools
    {
        ToolDeriver::clearCache();
        $router  = new Router('');
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), $router, static fn (string $m): null => null);
        $roles   = $this->createStub(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturn(true);
        $roles->method('hasRoleForProfile')->willReturn(true);
        $tokens = $this->createStub(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(7, 7, self::TENANT, $this->kind, $this->scope, 'jti'));

        return new DiscoveryTools(
            new ToolsListHandler($deriver, $roles, $tokens),
            new ToolsCallHandler($deriver, $router, $roles, $tokens),
            $tokens,
            new SessionBriefing(static fn (int $p, int $t): array => ['name' => 'Rana Example', 'workspace' => 'Example Bakery']),
        );
    }
}
