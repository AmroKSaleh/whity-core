<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsListHandler;

/**
 * TDD tests for ToolsListHandler (WC-e8c4d228).
 *
 * Verifies permission-aware filtering: open tools are returned to everyone;
 * role/permission-protected tools are filtered by the caller's grants.
 * Unauthenticated callers receive only open tools (soft-auth, no exception).
 */
final class ToolsListHandlerTest extends TestCase
{
    private const BEARER    = 'test.bearer.token';
    private const USER_ID   = 7;
    private const TENANT_ID = 3;

    private ToolDeriver $toolDeriver;
    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    private ToolsListHandler $handler;

    protected function setUp(): void
    {
        ToolDeriver::clearCache();

        $router = new Router('');

        $router->registerUnversioned('GET', '/api/open',   fn () => null, null, null, null,          ['summary' => 'Open tool']);
        $router->registerUnversioned('GET', '/api/perm',   fn () => null, null, null, 'things:read', ['summary' => 'Permission tool']);
        $router->registerUnversioned('GET', '/api/role',   fn () => null, 'admin', null, null,       ['summary' => 'Role tool']);

        $this->toolDeriver    = new ToolDeriver([], [], $router);
        $this->roleChecker    = $this->createMock(RoleChecker::class);
        $this->tokenValidator = $this->createMock(TokenValidator::class);

        $principal = new McpPrincipal(self::USER_ID, self::USER_ID, self::TENANT_ID, 'user', ['tools:list'], 'jti-tools');
        $this->tokenValidator->method('validateBearerForMcp')->willReturn($principal);

        $this->handler = new ToolsListHandler(
            $this->toolDeriver,
            $this->roleChecker,
            $this->tokenValidator,
        );

        TenantContext::setTenantId(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        ToolDeriver::clearCache();
    }

    // ── Response structure ────────────────────────────────────────────────────

    public function testInvoke_returnsToolsKey_inResponse(): void
    {
        $result = ($this->handler)(null, self::BEARER);

        self::assertIsArray($result);
        self::assertArrayHasKey('tools', $result);
        self::assertIsArray($result['tools']);
    }

    // ── Open tools ────────────────────────────────────────────────────────────

    public function testInvoke_includesOpenTool_forAuthenticatedCaller(): void
    {
        $result = ($this->handler)(null, self::BEARER);

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_open', $names);
    }

    public function testInvoke_includesOpenTool_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_open', $names);
    }

    public function testInvoke_includesOpenTool_whenBearerTokenInvalid(): void
    {
        $this->tokenValidator = $this->createMock(TokenValidator::class);
        $this->tokenValidator->method('validateBearerForMcp')->willReturn(null);
        $this->handler = new ToolsListHandler($this->toolDeriver, $this->roleChecker, $this->tokenValidator);

        $result = ($this->handler)(null, 'invalid-token');

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_open', $names);
    }

    // ── Permission-protected tools ────────────────────────────────────────────

    public function testInvoke_excludesPermissionProtectedTool_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $names = array_column($result['tools'], 'name');
        self::assertNotContains('get_api_perm', $names);
    }

    public function testInvoke_excludesPermissionProtectedTool_whenPermissionNotGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(false);

        $result = ($this->handler)(null, self::BEARER);

        $names = array_column($result['tools'], 'name');
        self::assertNotContains('get_api_perm', $names);
    }

    public function testInvoke_includesPermissionProtectedTool_whenPermissionGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        $result = ($this->handler)(null, self::BEARER);

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_perm', $names);
    }

    // ── Role-protected tools ──────────────────────────────────────────────────

    public function testInvoke_excludesRoleProtectedTool_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $names = array_column($result['tools'], 'name');
        self::assertNotContains('get_api_role', $names);
    }

    public function testInvoke_excludesRoleProtectedTool_whenRoleNotGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(false);

        $result = ($this->handler)(null, self::BEARER);

        $names = array_column($result['tools'], 'name');
        self::assertNotContains('get_api_role', $names);
    }

    public function testInvoke_includesRoleProtectedTool_whenRoleGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(true);

        $result = ($this->handler)(null, self::BEARER);

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_role', $names);
    }

    // ── Mixed open + protected ────────────────────────────────────────────────

    public function testInvoke_returnsOnlyOpenTools_forUnauthenticatedCaller_whenMixed(): void
    {
        $result = ($this->handler)(null, null);

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_open', $names);
        self::assertNotContains('get_api_perm', $names);
        self::assertNotContains('get_api_role', $names);
    }

    public function testInvoke_returnsAllGrantedTools_forAuthenticatedCallerWithAllGrants(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);
        $this->roleChecker->method('hasRole')->willReturn(true);

        $result = ($this->handler)(null, self::BEARER);

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_open', $names);
        self::assertContains('get_api_perm', $names);
        self::assertContains('get_api_role', $names);
    }

    // ── Tool structure preserved ──────────────────────────────────────────────

    public function testInvoke_preservesToolStructure_forVisibleTool(): void
    {
        $result = ($this->handler)(null, null);

        $openTool = null;
        foreach ($result['tools'] as $tool) {
            if ($tool['name'] === 'get_api_open') {
                $openTool = $tool;
                break;
            }
        }

        self::assertNotNull($openTool);
        self::assertArrayHasKey('name', $openTool);
        self::assertArrayHasKey('description', $openTool);
        self::assertArrayHasKey('inputSchema', $openTool);
    }

    // ── Static declaration filtering ──────────────────────────────────────────

    public function testInvoke_filtersStaticDeclarations_byPermission(): void
    {
        $deriver = new ToolDeriver([
            ['method' => 'GET', 'path' => '/api/open-static', 'schema' => ['summary' => 'Open static'], 'requiredRole' => null, 'requiredPermission' => null],
            ['method' => 'POST', 'path' => '/api/secure-static', 'schema' => ['summary' => 'Secure static'], 'requiredRole' => null, 'requiredPermission' => 'secure:write'],
        ]);
        $handler = new ToolsListHandler($deriver, $this->roleChecker, $this->tokenValidator);
        $this->roleChecker->method('hasPermission')->willReturn(false);

        $result = ($handler)(null, self::BEARER);

        $names = array_column($result['tools'], 'name');
        self::assertContains('get_api_open_static', $names);
        self::assertNotContains('post_api_secure_static', $names);
    }
}
