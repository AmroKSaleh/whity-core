<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
// Core route handlers type-hint the narrower Whity\Core\Request (not the SDK
// base), so the test doubles do too — a synthesized SDK request would TypeError
// on every real handler (WC-mcp-toolcall regression guard).
use Whity\Core\Request;
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
        ToolDeriver::clearCache();

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

        $principal = new McpPrincipal(self::USER_ID, self::USER_ID, self::TENANT_ID, 'user', ['tools:call'], 'jti-abc');
        $this->tokenValidator->method('validateBearerForMcp')->willReturn($principal);

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
        ToolDeriver::clearCache();
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
            ->method('hasPermissionForProfile')
            ->with(self::USER_ID, 'things:write', self::TENANT_ID)
            ->willReturn(false);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['name' => 'patch_api_things_id', 'arguments' => ['id' => 5]], self::BEARER);
    }

    public function testInvoke_throwsForbidden_whenRequiredRoleNotGranted(): void
    {
        $this->roleChecker->expects($this->once())
            ->method('hasRoleForProfile')
            ->with(self::USER_ID, 'admin', self::TENANT_ID)
            ->willReturn(false);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['name' => 'delete_api_things_id', 'arguments' => ['id' => 5]], self::BEARER);
    }

    public function testInvoke_doesNotCallRoleChecker_whenNoPermissionRequired(): void
    {
        $this->roleChecker->expects($this->never())->method('hasPermissionForProfile');
        $this->roleChecker->expects($this->never())->method('hasRoleForProfile');

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
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        $result = ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 9],
        ], self::BEARER);

        self::assertFalse($result['isError']);
    }

    public function testInvoke_callsHandler_whenRoleGranted(): void
    {
        $this->roleChecker->method('hasRoleForProfile')->willReturn(true);

        $result = ($this->handler)([
            'name'      => 'delete_api_things_id',
            'arguments' => ['id' => 2],
        ], self::BEARER);

        self::assertFalse($result['isError']);
    }

    // ── Request synthesis ─────────────────────────────────────────────────────

    public function testInvoke_substitutesPathParam_inSynthesizedRequestPath(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

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
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

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
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 1],
        ], self::BEARER);

        self::assertNotNull($this->lastRequest);
        // The synthesized request must be the core subclass every handler expects,
        // not the SDK base (WC-mcp-toolcall).
        self::assertInstanceOf(Request::class, $this->lastRequest);
        $claims = $this->lastRequest->getAttribute(Request::ATTR_JWT_CLAIMS);
        self::assertIsArray($claims);
        // Canonical, post-identity-cutover claims handlers read the caller from.
        self::assertSame(self::USER_ID, $claims['profile_id']);
        self::assertSame(self::TENANT_ID, $claims['active_tenant_id']);
        // Legacy aliases retained for back-compat.
        self::assertSame(self::USER_ID, $claims['user_id']);
        self::assertSame(self::TENANT_ID, $claims['tenant_id']);
    }

    public function testInvoke_setsUserObject_onSynthesizedRequest(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => 1],
        ], self::BEARER);

        self::assertNotNull($this->lastRequest);
        self::assertNotNull($this->lastRequest->user);
        $userVars = get_object_vars($this->lastRequest->user);
        // Handlers read the caller off the canonical profile_id (post-cutover);
        // the legacy user_id alias is retained.
        self::assertSame(self::USER_ID, $userVars['profile_id']);
        self::assertSame(self::USER_ID, $userVars['user_id']);
    }

    // ── Argument schema validation (WC-b570dccd) ─────────────────────────────

    public function testInvoke_throwsInvalidParams_whenRequiredArgMissing(): void
    {
        // POST /api/things declares 'name' as required in its request schema
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['name' => 'post_api_things', 'arguments' => []], self::BEARER);
    }

    public function testInvoke_throwsInvalidParams_whenIntegerPathArgIsNonNumericString(): void
    {
        // PATCH /api/things/{id:\d+} declares 'id' as type integer (from path constraint)
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['name' => 'patch_api_things_id', 'arguments' => ['id' => 'not-a-number']], self::BEARER);
    }

    public function testInvoke_coercesDigitString_toIntegerForPathParam(): void
    {
        // "42" as a string should be coerced to int 42 before path substitution
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        $result = ($this->handler)([
            'name'      => 'patch_api_things_id',
            'arguments' => ['id' => '42'],
        ], self::BEARER);

        self::assertFalse($result['isError']);
        self::assertNotNull($this->lastRequest);
        self::assertStringContainsString('/42', $this->lastRequest->getPath());
    }

    public function testInvoke_throwsInvalidParams_whenArrayPassedForScalarField(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        // POST /api/things: 'name' is type string — passing an array is invalid
        ($this->handler)(['name' => 'post_api_things', 'arguments' => ['name' => ['evil']]], self::BEARER);
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

    // ── Audit logging (WC-94526f65) ───────────────────────────────────────────

    public function testSuccessfulCall_recordsAuditLog(): void
    {
        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->once())
            ->method('record')
            ->with(
                'mcp.tools.call',
                $this->callback(function (array $opts): bool {
                    return ($opts['tenant_id'] ?? null) === self::TENANT_ID
                        && ($opts['actor_user_id'] ?? null) === self::USER_ID
                        && ($opts['target_type'] ?? null) === 'tool'
                        && ($opts['metadata']['tool'] ?? null) === 'get_api_things';
                }),
            );

        $handler = new ToolsCallHandler(
            $this->toolDeriver,
            $this->router,
            $this->roleChecker,
            $this->tokenValidator,
            auditLogger: $audit,
        );

        ($handler)(['name' => 'get_api_things', 'arguments' => []], self::BEARER);
    }

    public function testAuditLog_stripsForbiddenKeys_fromArgsSummary(): void
    {
        $recorded = [];
        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->method('record')->willReturnCallback(
            function (string $action, array $opts) use (&$recorded): void {
                $recorded[] = ['action' => $action, 'opts' => $opts];
            }
        );

        $handler = new ToolsCallHandler(
            $this->toolDeriver,
            $this->router,
            $this->roleChecker,
            $this->tokenValidator,
            auditLogger: $audit,
        );

        ($handler)(['name' => 'post_api_things', 'arguments' => ['name' => 'Widget', 'password' => 'secret123']], self::BEARER);

        self::assertCount(1, $recorded);
        $args = $recorded[0]['opts']['metadata']['args'] ?? [];
        self::assertArrayNotHasKey('password', $args);
        self::assertArrayHasKey('name', $args);
    }

    public function testAuditContext_setsActorUserId_afterTokenValidation(): void
    {
        AuditContext::reset();

        $capturedUserId = null;
        $this->router->registerUnversioned('GET', '/api/audit-check', function () use (&$capturedUserId): Response {
            $capturedUserId = AuditContext::getActorUserId();
            return Response::json(['ok' => true]);
        });

        $declarations = [['method' => 'GET', 'path' => '/api/audit-check', 'schema' => ['summary' => 'Audit check']]];
        $deriver = new ToolDeriver($declarations);

        $handler = new ToolsCallHandler($deriver, $this->router, $this->roleChecker, $this->tokenValidator);
        ($handler)(['name' => 'get_api_audit_check', 'arguments' => []], self::BEARER);

        self::assertSame(self::USER_ID, $capturedUserId);

        AuditContext::reset();
    }

    public function testHandlerThrows_stillRecordsAuditLog(): void
    {
        $this->router->registerUnversioned('GET', '/api/throws', static function (): never {
            throw new \RuntimeException('Boom');
        });
        $declarations = [['method' => 'GET', 'path' => '/api/throws', 'schema' => ['summary' => 'Throws']]];
        $deriver = new ToolDeriver($declarations);

        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->once())->method('record');

        $handler = new ToolsCallHandler($deriver, $this->router, $this->roleChecker, $this->tokenValidator, auditLogger: $audit);
        $result  = ($handler)(['name' => 'get_api_throws', 'arguments' => []], self::BEARER);

        self::assertTrue($result['isError']);
    }

    public function testUnknownTool_doesNotRecordAuditLog(): void
    {
        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->never())->method('record');

        $handler = new ToolsCallHandler(
            $this->toolDeriver,
            $this->router,
            $this->roleChecker,
            $this->tokenValidator,
            auditLogger: $audit,
        );

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::METHOD_NOT_FOUND);
        ($handler)(['name' => 'nonexistent_tool', 'arguments' => []], self::BEARER);
    }

    public function testRbacDenied_stillRecordsAuditLog(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(false);

        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->once())->method('record')->with('mcp.tools.call', $this->anything());

        $handler = new ToolsCallHandler(
            $this->toolDeriver,
            $this->router,
            $this->roleChecker,
            $this->tokenValidator,
            auditLogger: $audit,
        );

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);
        ($handler)(['name' => 'patch_api_things_id', 'arguments' => ['id' => 1]], self::BEARER);
    }
}
