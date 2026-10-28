<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Connect;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Mcp\Connect\McpConnectHandler;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsListHandler;

/**
 * The connect wizard's setup and scope preview (WHIT-697).
 */
final class McpConnectHandlerTest extends TestCase
{
    private const PROFILE_ID = 7;
    private const TENANT_ID  = 3;

    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    private PermissionRegistry $registry;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();

        $this->tokenValidator = $this->createMock(TokenValidator::class);
        $this->tokenValidator->method('validateAccessToken')->willReturn([
            'profile_id'       => self::PROFILE_ID,
            'active_tenant_id' => self::TENANT_ID,
        ]);
        $this->roleChecker = $this->createMock(RoleChecker::class);
        $this->registry    = new PermissionRegistry();
        $this->registry->register('widgets-plugin', ['widgets:read', 'widgets:manage']);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    public function testInfoReportsWhereAndWhetherAnAppCanConnect(): void
    {
        $body = $this->json($this->handler(tenantEnabled: false, serverEnabled: true, endpoint: 'https://api.example.com/mcp')
            ->info($this->request()));

        self::assertSame('https://api.example.com/mcp', $body['endpoint']);
        self::assertTrue($body['serverEnabled']);
        self::assertFalse($body['tenantEnabled']);
    }

    public function testInfoAsksTheTenantCheckAboutTheCallersTenant(): void
    {
        $asked = null;
        $handler = new McpConnectHandler(
            $this->tokenValidator,
            $this->toolsList(),
            $this->registry,
            static function (int $tenantId) use (&$asked): bool {
                $asked = $tenantId;
                return true;
            },
            true,
            null,
        );

        $handler->info($this->request());

        self::assertSame(self::TENANT_ID, $asked);
    }

    public function testEverythingPresetIsTheCapabilitiesAlone(): void
    {
        $body = $this->json($this->handler()->info($this->request()));

        self::assertSame(['tools:call', 'resources:read', 'prompts:read'], $body['presets']['everything']);
    }

    public function testReadOnlyPresetNamesEveryReadPermissionAndNoOther(): void
    {
        $body = $this->json($this->handler()->info($this->request()));
        $permissions = array_values(array_diff($body['presets']['readOnly'], ['tools:call', 'resources:read', 'prompts:read']));

        self::assertContains('widgets:read', $permissions);
        self::assertNotContains('widgets:manage', $permissions);
        foreach ($permissions as $permission) {
            self::assertStringEndsWith(':read', $permission);
        }
    }

    public function testAreasGroupPermissionsByResource(): void
    {
        $body  = $this->json($this->handler()->info($this->request()));
        $areas = array_column($body['areas'], 'permissions', 'area');

        self::assertSame(['widgets:manage', 'widgets:read'], $areas['widgets']);
    }

    public function testEndpointIsDerivedFromAppUrl(): void
    {
        self::assertSame('https://api.example.com/mcp', McpConnectHandler::endpointFor(' https://api.example.com/ '));
        self::assertNull(McpConnectHandler::endpointFor(''));
    }

    public function testPreviewShowsOnlyWhatTheScopeAllows(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        $readOnly = $this->toolNames($this->handler()->preview($this->request(['scope' => ['tools:call', 'widgets:read']])));
        $all      = $this->toolNames($this->handler()->preview($this->request(['scope' => ['tools:call']])));

        self::assertSame(['get_api_widgets'], $readOnly);
        self::assertEqualsCanonicalizing(['get_api_widgets', 'delete_api_widgets_id', 'get_api_inbox'], $all);
    }

    public function testPreviewNeverShowsMoreThanTheCallerMayDo(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static fn (int $profileId, string $permission): bool => $permission === 'widgets:read');

        $tools = $this->toolNames($this->handler()->preview($this->request(['scope' => ['tools:call', 'widgets:*']])));

        self::assertNotContains('delete_api_widgets_id', $tools, 'a scope narrows the caller; it never grants');
        self::assertContains('get_api_widgets', $tools);
    }

    public function testPreviewChecksTheCallersOwnGrantsInTheirTenant(): void
    {
        $this->roleChecker->expects(self::atLeastOnce())->method('hasPermissionForProfile')
            ->with(self::PROFILE_ID, self::anything(), self::TENANT_ID)
            ->willReturn(true);

        $this->handler()->preview($this->request(['scope' => ['tools:call']]));
    }

    public function testPreviewRefusesAScopeIssuanceWouldRefuse(): void
    {
        $handler = $this->handler();

        self::assertSame(422, $handler->preview($this->request(['scope' => ['widgets:read']]))->getStatusCode(), 'no capability');
        self::assertSame(422, $handler->preview($this->request(['scope' => ['tools:call', 'widgest:read']]))->getStatusCode(), 'typo');
        self::assertSame(422, $handler->preview($this->request(['scope' => []]))->getStatusCode());
        self::assertSame(422, $handler->preview($this->request(['scope' => ['tools:call', 5]]))->getStatusCode());
        self::assertSame(422, $handler->preview($this->request([]))->getStatusCode());
    }

    public function testUnauthenticatedCallerIsRefused(): void
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateAccessToken')->willReturn(null);
        $handler = new McpConnectHandler($validator, $this->toolsList(), $this->registry, static fn (): bool => true, true, null);

        self::assertSame(401, $handler->info($this->request())->getStatusCode());
        self::assertSame(401, $handler->preview($this->request(['scope' => ['tools:call']]))->getStatusCode());
    }

    private function handler(bool $tenantEnabled = true, bool $serverEnabled = true, ?string $endpoint = null): McpConnectHandler
    {
        return new McpConnectHandler(
            $this->tokenValidator,
            $this->toolsList(),
            $this->registry,
            static fn (int $tenantId): bool => $tenantEnabled,
            $serverEnabled,
            $endpoint,
        );
    }

    private function toolsList(): ToolsListHandler
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/widgets', fn () => null, null, null, 'widgets:read', ['summary' => 'List widgets']);
        $router->registerUnversioned('DELETE', '/api/widgets/{id}', fn () => null, null, null, 'widgets:manage', ['summary' => 'Delete a widget']);
        $router->registerUnversioned('GET', '/api/inbox', fn () => null, null, null, null, ['summary' => 'My inbox', 'mcp' => true]);
        $router->registerUnversioned('GET', '/api/session', fn () => null, null, null, null, ['summary' => 'Current session']);

        return new ToolsListHandler(new ToolDeriver([], [], $router), $this->roleChecker, $this->tokenValidator);
    }

    /** @param array<string, mixed>|null $body */
    private function request(?array $body = null): Request
    {
        return new Request('POST', '/api/mcp/connect/preview', [], $body === null ? '' : (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private function json(\Whity\Core\Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        return $decoded;
    }

    /** @return list<string> */
    private function toolNames(\Whity\Core\Response $response): array
    {
        return array_column($this->json($response)['tools'], 'name');
    }
}
