<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Resources;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Resources\ResourcesListHandler;

/**
 * TDD tests for ResourcesListHandler (WC-e8c4d228).
 *
 * Verifies permission-aware filtering: open resources/templates are returned to
 * everyone; role/permission-protected ones are filtered by the caller's grants.
 * Unauthenticated callers receive only open resources (soft-auth, no exception).
 */
final class ResourcesListHandlerTest extends TestCase
{
    private const BEARER    = 'test.bearer.token';
    private const USER_ID   = 9;
    private const TENANT_ID = 4;

    private ResourceDeriver $resourceDeriver;
    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    private ResourcesListHandler $handler;

    protected function setUp(): void
    {
        $router = new Router('');

        // Static: no path params → static resources
        $router->registerUnversioned('GET', '/api/open',   fn () => null, null,    null, null,         ['summary' => 'Open resource']);
        $router->registerUnversioned('GET', '/api/perm',   fn () => null, null,    null, 'items:read', ['summary' => 'Permission resource']);
        $router->registerUnversioned('GET', '/api/role',   fn () => null, 'admin', null, null,         ['summary' => 'Role resource']);
        // Template: has path params → resource templates
        $router->registerUnversioned('GET', '/api/open/{id:\d+}',  fn () => null, null,    null, null,         ['summary' => 'Open template']);
        $router->registerUnversioned('GET', '/api/perm/{id:\d+}',  fn () => null, null,    null, 'items:read', ['summary' => 'Permission template']);

        $this->resourceDeriver = new ResourceDeriver([], $router);
        $this->roleChecker     = $this->createMock(RoleChecker::class);
        $this->tokenValidator  = $this->createMock(TokenValidator::class);

        $principal = new McpPrincipal(self::USER_ID, self::TENANT_ID, 'user', ['resources:list'], 'jti-res');
        $this->tokenValidator->method('validateMcpToken')->willReturn($principal);

        $this->handler = new ResourcesListHandler(
            $this->resourceDeriver,
            $this->roleChecker,
            $this->tokenValidator,
        );

        TenantContext::setTenantId(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── Response structure ────────────────────────────────────────────────────

    public function testInvoke_returnsResourcesAndResourceTemplatesKeys(): void
    {
        $result = ($this->handler)(null, self::BEARER);

        self::assertIsArray($result);
        self::assertArrayHasKey('resources', $result);
        self::assertArrayHasKey('resourceTemplates', $result);
    }

    // ── Open static resources ─────────────────────────────────────────────────

    public function testInvoke_includesOpenResource_forAuthenticatedCaller(): void
    {
        $result = ($this->handler)(null, self::BEARER);

        $uris = array_column($result['resources'], 'uri');
        self::assertContains('whity-api:///api/open', $uris);
    }

    public function testInvoke_includesOpenResource_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $uris = array_column($result['resources'], 'uri');
        self::assertContains('whity-api:///api/open', $uris);
    }

    public function testInvoke_includesOpenResource_whenBearerTokenInvalid(): void
    {
        $this->tokenValidator = $this->createMock(TokenValidator::class);
        $this->tokenValidator->method('validateMcpToken')->willReturn(null);
        $this->handler = new ResourcesListHandler($this->resourceDeriver, $this->roleChecker, $this->tokenValidator);

        $result = ($this->handler)(null, 'invalid-token');

        $uris = array_column($result['resources'], 'uri');
        self::assertContains('whity-api:///api/open', $uris);
    }

    // ── Permission-protected static resources ─────────────────────────────────

    public function testInvoke_excludesPermissionProtectedResource_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $uris = array_column($result['resources'], 'uri');
        self::assertNotContains('whity-api:///api/perm', $uris);
    }

    public function testInvoke_excludesPermissionProtectedResource_whenPermissionNotGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(false);

        $result = ($this->handler)(null, self::BEARER);

        $uris = array_column($result['resources'], 'uri');
        self::assertNotContains('whity-api:///api/perm', $uris);
    }

    public function testInvoke_includesPermissionProtectedResource_whenPermissionGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        $result = ($this->handler)(null, self::BEARER);

        $uris = array_column($result['resources'], 'uri');
        self::assertContains('whity-api:///api/perm', $uris);
    }

    // ── Role-protected static resources ──────────────────────────────────────

    public function testInvoke_excludesRoleProtectedResource_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $uris = array_column($result['resources'], 'uri');
        self::assertNotContains('whity-api:///api/role', $uris);
    }

    public function testInvoke_excludesRoleProtectedResource_whenRoleNotGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(false);

        $result = ($this->handler)(null, self::BEARER);

        $uris = array_column($result['resources'], 'uri');
        self::assertNotContains('whity-api:///api/role', $uris);
    }

    public function testInvoke_includesRoleProtectedResource_whenRoleGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(true);

        $result = ($this->handler)(null, self::BEARER);

        $uris = array_column($result['resources'], 'uri');
        self::assertContains('whity-api:///api/role', $uris);
    }

    // ── Resource templates ────────────────────────────────────────────────────

    public function testInvoke_includesOpenTemplate_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $uris = array_column($result['resourceTemplates'], 'uriTemplate');
        self::assertContains('whity-api:///api/open/{id}', $uris);
    }

    public function testInvoke_excludesPermissionProtectedTemplate_forUnauthenticatedCaller(): void
    {
        $result = ($this->handler)(null, null);

        $uris = array_column($result['resourceTemplates'], 'uriTemplate');
        self::assertNotContains('whity-api:///api/perm/{id}', $uris);
    }

    public function testInvoke_includesPermissionProtectedTemplate_whenPermissionGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        $result = ($this->handler)(null, self::BEARER);

        $uris = array_column($result['resourceTemplates'], 'uriTemplate');
        self::assertContains('whity-api:///api/perm/{id}', $uris);
    }

    // ── Mixed open + protected ────────────────────────────────────────────────

    public function testInvoke_returnsOnlyOpenResources_forUnauthenticatedCaller_whenMixed(): void
    {
        $result = ($this->handler)(null, null);

        $uris = array_column($result['resources'], 'uri');
        self::assertContains('whity-api:///api/open', $uris);
        self::assertNotContains('whity-api:///api/perm', $uris);
        self::assertNotContains('whity-api:///api/role', $uris);
    }

    // ── Static declaration filtering ──────────────────────────────────────────

    public function testInvoke_filtersStaticDeclarations_byPermission(): void
    {
        $deriver = new ResourceDeriver([
            ['method' => 'GET', 'path' => '/api/public', 'schema' => ['summary' => 'Public'], 'requiredRole' => null, 'requiredPermission' => null],
            ['method' => 'GET', 'path' => '/api/private', 'schema' => ['summary' => 'Private'], 'requiredRole' => null, 'requiredPermission' => 'private:read'],
        ]);
        $handler = new ResourcesListHandler($deriver, $this->roleChecker, $this->tokenValidator);
        $this->roleChecker->method('hasPermission')->willReturn(false);

        $result = ($handler)(null, self::BEARER);

        $uris = array_column($result['resources'], 'uri');
        self::assertContains('whity-api:///api/public', $uris);
        self::assertNotContains('whity-api:///api/private', $uris);
    }
}
