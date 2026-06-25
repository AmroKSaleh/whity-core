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
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * TDD tests for ToolsCallHandler (WC-2e6944d5).
 *
 * Verifies per-call execution: principal resolution, TenantContext setup,
 * RoleChecker enforcement, HTTP Request synthesis, and MCP result wrapping.
 */
final class ToolsCallHandlerTest extends TestCase
{
    private const BEARER    = 'test.bearer.token';
    private const USER_ID   = 7;
    private const TENANT_ID = 3;

    private Router $router;
    private ToolDeriver $toolDeriver;
    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    private ToolsCallHandler $handler;
    private ?Request $lastRequest = null;

    protected function setUp(): void
    {
        $this->router = new Router(''); // no version prefix in unit tests

        // Handlers capture the last synthesized request for assertion
        $this->lastRequest = null;
        $captureAndOk = function (Request $req, array $params): Response {
            $this->lastRequest = $req;
            return Response::json(['ok' => true, 'params' => $params]);
        };
        $echoBody = function (Request $req, array $params): Response {
            $this->lastRequest = $req;
            return Response::json((array) json_decode($req->getBody(), true));
        };

        $this->router->registerUnversioned('GET', '/api/things', function (): Response {
            return Response::json(['items' => []]);
        });
        $this->router->registerUnversioned('PATCH', '/api/things/{id:\d+}', $captureAndOk, null, null, 'things:write');
        $this->router->registerUnversioned('DELETE', '/api/things/{id:\d+}', $captureAndOk, 'admin');
        $this->router->registerUnversioned('POST', '/api/things', $echoBody);
        $this->router->registerUnversioned('GET', '/api/bad', function (): Response {
            return Response::error('Something went wrong', 500);
        });

        $declarations = [
            ['method' => 'GET',    'path' => '/api/things',          'schema' => ['summary' => 'List things']],
            ['method' => 'PATCH',  'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Update thing']],
            ['method' => 'DELETE', 'path' => '/api/things/{id:\d+}', 'schema' => ['summary' => 'Delete thing']],
            ['method' => 'POST',   'path' => '/api/things',          'schema' => [
                'summary' => 'Create thing',
                'request' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => ['name' => ['type' => 'string']],
                ],
            ]],
            ['method' => 'GET',    'path' => '/api/bad',             'schema' => ['summary' => 'Bad route']],
        ];

        $this->toolDeriver = new ToolDeriver($declarations);

        $this->roleChecker    = $this->createMock(RoleChecker::class);
        $this->tokenValidator = $this->createMock(TokenValidator::class);

        $principal = new McpPrincipal(self::USER_ID, self::TENANT_ID, 'user', ['tools:call'], 'jti-abc');
        $this->tokenValidator->method('validateMcpToken')->willReturn($principal);

        $this->handler = new ToolsCallHandler(
            $this->toolDeriver,
            $this->router,
            $this->roleChecker,
            $this->tokenValidator,
        );

        TenantContext::setTenantId(self::TENANT_ID);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── Param validation ──────────────────────────────────────────────────────

    public function testInvoke_throwsInvalidParams_whenParamsNull(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(null, self::BEARER);
    }

    public function testInvoke_throwsInvalidParams_whenNameKeyMissing(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['arguments' => []], self::BEARER);
    }

    public function testInvoke_throwsInvalidParams_whenNameIsEmpty(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['name' => '', 'arguments' => []], self::BEARER);
    }

    // ── Tool lookup ───────────────────────────────────────────────────────────

    public function testInvoke_throwsMethodNotFound_forUnknownToolName(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::METHOD_NOT_FOUND);

        ($this->handler)(['name' => 'nonexistent_tool', 'arguments' => []], self::BEARER);
    }

    // ── Permission enforcement ────────────────────────────────────────────────

    public function testInvoke_throwsForbidden_whenRequiredPermissionNotGranted(): void
    {
        $this->roleChecker->expects($this->once())
            ->method('hasPermission')
            ->with(self::USER_ID, 'things:write', self::TENANT_ID)
            ->willReturn(false);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['name' => 'patch_api_things_id', 'arguments' => ['id' => 5]], self::BEARER);
    }

    public function testInvoke_throwsForbidden_whenRequiredRoleNotGranted(): void
    {
        $this->roleChecker->expects($this->once())
            ->method('hasRole')
            ->with(self::USER_ID, 'admin', self::TENANT_ID)
            ->willReturn(false);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['name' => 'delete_api_things_id', 'arguments' => ['id' => 5]], self::BEARER);
    }

    public function testInvoke_doesNotCallRoleChecker_whenNoPermissionRequired(): void
    {
        $this->roleChecker->expects($this->never())->method('hasPermission');
        $this->roleChecker->expects($this->never())->method('hasRole');

        ($this->handler)(['name' => 'get_api_things', 'arguments' => []], self::BEARER);
    }

    // ── Successful invocation ─────────────────────────────────────────────────

    public function testInvoke_callsHandler_andReturnsMcpResultFormat(): void
    {
        $result = ($this->handler)(['name' => 'get_api_things', 'arguments' => []], self::BEARER);

        self::assertIsArray($result);
        self::assertFalse($result['isError']);
        self::assertCount(1, $result['content']);
        self::assertSame('text', $result['content'][0]['type']);
        self::assertIsString($result['content'][0]['text']);
    }

    public function testInvoke_callsHandler_whenPermissionGranted(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        $result = ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 9],
        ], self::BEARER);

        self::assertFalse($result['isError']);
    }

    public function testInvoke_callsHandler_whenRoleGranted(): void
    {
        $this->roleChecker->method('hasRole')->willReturn(true);

        $result = ($this->handler)([
            'name'      => 'delete_api_things_id',
            'arguments' => ['id' => 2],
        ], self::BEARER);

        self::assertFalse($result['isError']);
    }

    // ── Request synthesis ─────────────────────────────────────────────────────

    public function testInvoke_substitutesPathParam_inSynthesizedRequestPath(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 42],
        ], self::BEARER);

        self::assertNotNull($this->lastRequest);
        self::assertStringContainsString('/42', $this->lastRequest->getPath());
    }

    public function testInvoke_putsRemainingArgs_inJsonBody_forPostRequest(): void
    {
        ($this->handler)([
            'name'      => 'post_api_things',
            'arguments' => ['name' => 'Widget'],
        ], self::BEARER);

        self::assertNotNull($this->lastRequest);
        self::assertStringContainsString('"name"', $this->lastRequest->getBody());
        self::assertStringContainsString('Widget', $this->lastRequest->getBody());
    }

    public function testInvoke_doesNotIncludePathParam_inBody(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 7, 'name' => 'Updated'],
        ], self::BEARER);

        self::assertNotNull($this->lastRequest);
        // Path param 'id' must not appear in the request body
        $body = (array) json_decode($this->lastRequest->getBody(), true);
        self::assertArrayNotHasKey('id', $body);
        self::assertArrayHasKey('name', $body);
    }

    public function testInvoke_setsJwtClaims_onSynthesizedRequest(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 1],
        ], self::BEARER);

        self::assertNotNull($this->lastRequest);
        $claims = $this->lastRequest->getAttribute(Request::ATTR_JWT_CLAIMS);
        self::assertIsArray($claims);
        self::assertSame(self::USER_ID, $claims['user_id']);
        self::assertSame(self::TENANT_ID, $claims['tenant_id']);
    }

    public function testInvoke_setsUserObject_onSynthesizedRequest(): void
    {
        $this->roleChecker->method('hasPermission')->willReturn(true);

        ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 1],
        ], self::BEARER);

        self::assertNotNull($this->lastRequest);
        self::assertNotNull($this->lastRequest->user);
        self::assertSame(self::USER_ID, $this->lastRequest->user->user_id);
    }

    // ── Error result wrapping ─────────────────────────────────────────────────

    public function testInvoke_returnsIsErrorTrue_whenHandlerReturnsErrorResponse(): void
    {
        $result = ($this->handler)(['name' => 'get_api_bad', 'arguments' => []], self::BEARER);

        self::assertIsArray($result);
        self::assertTrue($result['isError']);
        self::assertCount(1, $result['content']);
    }

    public function testInvoke_returnsIsErrorFalse_forSuccessfulCall(): void
    {
        $result = ($this->handler)(['name' => 'get_api_things', 'arguments' => []], self::BEARER);

        self::assertFalse($result['isError']);
    }
}
