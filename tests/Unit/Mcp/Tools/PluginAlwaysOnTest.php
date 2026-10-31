<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;

/**
 * A plugin's always-on tool is listed directly only once the workspace's admin
 * turns it on, and only within the list budget (WHIT-754; owner decision 5;
 * design §5.2 and §5.6).
 */
final class PluginAlwaysOnTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::setTenantId(3);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAPluginsRequestAloneListsNothing(): void
    {
        self::assertSame(['init_session', 'search_operations', 'call_operation'], $this->bareList(1, []));
    }

    public function testAnEnabledToolIsListedBesideCores(): void
    {
        self::assertSame(['init_session', 'plugin0_status', 'search_operations', 'call_operation'], $this->bareList(1, ['plugin0_status']));
    }

    public function testTheListBudgetHoldsHoweverManyAreEnabled(): void
    {
        $enabled = array_map(static fn (int $i): string => "plugin{$i}_status", range(0, 19));
        $names   = $this->bareList(20, $enabled);

        self::assertLessThanOrEqual(DiscoveryTools::LIST_MAX_TOOLS, count($names));
        self::assertSame(['search_operations', 'call_operation'], array_slice($names, -2));
    }

    /**
     * @param list<string> $enabled
     * @return list<string>
     */
    private function bareList(int $plugins, array $enabled): array
    {
        $authored = new AuthoredToolRegistry();
        for ($i = 0; $i < $plugins; $i++) {
            $authored->register([
                'name' => "plugin{$i}_status", 'description' => 'Where things stand', 'inputSchema' => ['type' => 'object'],
                'handler' => static fn (): array => [], 'open' => true,
                'visibility' => 'always-on', 'annotations' => ['readOnlyHint' => true],
            ], "Plugins\\Plugin{$i}\\Plugin{$i}Plugin");
        }

        $router  = new Router('');
        $deriver = new ToolDeriver([], [], $router);
        $roles   = $this->createStub(RoleChecker::class);
        $tokens  = $this->createStub(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(new McpPrincipal(7, 7, 3, 'user', ['tools:call'], 'jti'));

        $discovery = new DiscoveryTools(
            new ToolsListHandler($deriver, $roles, $tokens, $authored),
            new ToolsCallHandler($deriver, $router, $roles, $tokens, authoredTools: $authored),
            $tokens,
            enabledPluginAlwaysOn: static fn (int $tenantId): array => $enabled,
        );

        return array_column(($discovery->defaultListHandler())(null, 'bearer')['tools'], 'name');
    }
}
