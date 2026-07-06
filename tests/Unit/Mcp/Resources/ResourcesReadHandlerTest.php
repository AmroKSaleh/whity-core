<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Resources;

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
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Resources\ResourcesReadHandler;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * TDD tests for ResourcesReadHandler (WC-30513809).
 *
 * Verifies that resources/read parses the whity-api:// URI, matches via Router,
 * enforces RBAC through RoleChecker, synthesizes the HTTP Request with JWT claims,
 * invokes the route handler, and wraps the response in MCP contents format.
 */
final class ResourcesReadHandlerTest extends TestCase
{
    private const BEARER    = 'test.bearer.token';
    private const USER_ID   = 7;
    private const TENANT_ID = 3;

    private Router $router;
    /** @var MockObject&RoleChecker */
    private RoleChecker $roleChecker;
    /** @var MockObject&TokenValidator */
    private TokenValidator $tokenValidator;
    private ResourcesReadHandler $handler;
    private ?Request $lastRequest = null;

    protected function setUp(): void
    {
        $this->router = new Router(''); // no version prefix in unit tests

        $this->lastRequest = null;
        $capture = function (Request $req, array $params): Response {
            $this->lastRequest = $req;
            return Response::json(['ok' => true, 'params' => $params]);
        };
        $capturePermission = function (Request $req, array $params): Response {
            $this->lastRequest = $req;
            return Response::json(['secure' => true]);
        };

        $this->router->registerUnversioned('GET', '/api/things', $capture);
        $this->router->registerUnversioned('GET', '/api/things/{id:\d+}', $capture, null, null, 'things:read');
        $this->router->registerUnversioned('GET', '/api/admin-only', $capturePermission, 'admin');
        $this->router->registerUnversioned('GET', '/api/perm-only', $capturePermission, null, null, 'stuff:read');
        $this->router->registerUnversioned('GET', '/api/bad', static function (): Response {
            return Response::error('Something failed', 500);
        });

        $this->roleChecker    = $this->createMock(RoleChecker::class);
        $this->tokenValidator = $this->createMock(TokenValidator::class);

        $principal = new McpPrincipal(self::USER_ID, self::USER_ID, self::TENANT_ID, 'user', ['resources:read'], 'jti-xyz');
        $this->tokenValidator->method('validateMcpToken')->willReturn($principal);

        $this->handler = new ResourcesReadHandler(
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

    public function testInvoke_throwsInvalidParams_whenUriKeyMissing(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)([], self::BEARER);
    }

    public function testInvoke_throwsInvalidParams_whenUriIsEmpty(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['uri' => ''], self::BEARER);
    }

    public function testInvoke_throwsInvalidParams_whenUriHasWrongScheme(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::INVALID_PARAMS);

        ($this->handler)(['uri' => 'https://example.com/api/things'], self::BEARER);
    }

    // ── Resource not found ────────────────────────────────────────────────────

    public function testInvoke_throwsResourceNotFound_forUnknownPath(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::RESOURCE_NOT_FOUND);

        ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/nonexistent'], self::BEARER);
    }

    public function testInvoke_throwsResourceNotFound_forPostRouteRequestedAsGet(): void
    {
        // POST /api/create exists but GET does not — should be RESOURCE_NOT_FOUND
        $this->router->registerUnversioned('POST', '/api/create', static fn (): Response => Response::json([]));

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::RESOURCE_NOT_FOUND);

        ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/create'], self::BEARER);
    }

    // ── Permission enforcement ────────────────────────────────────────────────

    public function testInvoke_throwsForbidden_whenRequiredPermissionNotGranted(): void
    {
        $this->roleChecker->expects($this->once())
            ->method('hasPermissionForProfile')
            ->with(self::USER_ID, 'stuff:read', self::TENANT_ID)
            ->willReturn(false);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/perm-only'], self::BEARER);
    }

    public function testInvoke_throwsForbidden_whenRequiredRoleNotGranted(): void
    {
        $this->roleChecker->expects($this->once())
            ->method('hasRoleForProfile')
            ->with(self::USER_ID, 'admin', self::TENANT_ID)
            ->willReturn(false);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::FORBIDDEN);

        ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/admin-only'], self::BEARER);
    }

    public function testInvoke_doesNotCallRoleChecker_whenNoRbacRequired(): void
    {
        $this->roleChecker->expects($this->never())->method('hasPermissionForProfile');
        $this->roleChecker->expects($this->never())->method('hasRoleForProfile');

        ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/things'], self::BEARER);
    }

    // ── Successful invocation ─────────────────────────────────────────────────

    public function testInvoke_callsHandler_andWrapsInContentsFormat(): void
    {
        $uri    = ResourceDeriver::URI_SCHEME . '/api/things';
        $result = ($this->handler)(['uri' => $uri], self::BEARER);

        self::assertIsArray($result);
        self::assertArrayHasKey('contents', $result);
        self::assertCount(1, $result['contents']);
        self::assertSame($uri, $result['contents'][0]['uri']);
        self::assertSame('application/json', $result['contents'][0]['mimeType']);
        self::assertIsString($result['contents'][0]['text']);
    }

    public function testInvoke_callsHandler_whenPermissionGranted(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        $result = ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/perm-only'], self::BEARER);

        self::assertArrayHasKey('contents', $result);
    }

    public function testInvoke_callsHandler_whenRoleGranted(): void
    {
        $this->roleChecker->method('hasRoleForProfile')->willReturn(true);

        $result = ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/admin-only'], self::BEARER);

        self::assertArrayHasKey('contents', $result);
    }

    // ── Path params resolution ────────────────────────────────────────────────

    public function testInvoke_passesRouterPathParams_toHandler(): void
    {
        $this->roleChecker->method('hasPermissionForProfile')->willReturn(true);

        $uri = ResourceDeriver::URI_SCHEME . '/api/things/42';
        ($this->handler)(['uri' => $uri], self::BEARER);

        self::assertNotNull($this->lastRequest);
        self::assertStringContainsString('/42', $this->lastRequest->getPath());
    }

    // ── Request synthesis ─────────────────────────────────────────────────────

    public function testInvoke_setsJwtClaims_onSynthesizedRequest(): void
    {
        ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/things'], self::BEARER);

        self::assertNotNull($this->lastRequest);
        $claims = $this->lastRequest->getAttribute(Request::ATTR_JWT_CLAIMS);
        self::assertIsArray($claims);
        self::assertSame(self::USER_ID, $claims['user_id']);
        self::assertSame(self::TENANT_ID, $claims['tenant_id']);
        self::assertSame('mcp', $claims['type']);
    }

    public function testInvoke_setsUserObject_onSynthesizedRequest(): void
    {
        ($this->handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/things'], self::BEARER);

        self::assertNotNull($this->lastRequest);
        self::assertNotNull($this->lastRequest->user);
        $userVars = get_object_vars($this->lastRequest->user);
        self::assertSame(self::USER_ID, $userVars['user_id']);
    }

    // ── Response body forwarding ──────────────────────────────────────────────

    public function testInvoke_forwardsHandlerResponseBody_asContentsText(): void
    {
        $uri    = ResourceDeriver::URI_SCHEME . '/api/things';
        $result = ($this->handler)(['uri' => $uri], self::BEARER);

        $body = (array) json_decode($result['contents'][0]['text'], true);
        self::assertArrayHasKey('ok', $body);
    }

    public function testInvoke_returnsContentsWithErrorBody_whenHandlerReturnsErrorResponse(): void
    {
        $uri    = ResourceDeriver::URI_SCHEME . '/api/bad';
        $result = ($this->handler)(['uri' => $uri], self::BEARER);

        self::assertArrayHasKey('contents', $result);
        self::assertCount(1, $result['contents']);
        self::assertSame($uri, $result['contents'][0]['uri']);
    }

    // ── Audit logging (WC-94526f65) ───────────────────────────────────────────

    public function testSuccessfulRead_recordsAuditLog(): void
    {
        $uri = ResourceDeriver::URI_SCHEME . '/api/things';

        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->once())
            ->method('record')
            ->with(
                'mcp.resources.read',
                $this->callback(function (array $opts) use ($uri): bool {
                    return ($opts['tenant_id'] ?? null) === self::TENANT_ID
                        && ($opts['actor_user_id'] ?? null) === self::USER_ID
                        && ($opts['target_type'] ?? null) === 'resource'
                        && ($opts['metadata']['uri'] ?? null) === $uri;
                }),
            );

        $handler = new ResourcesReadHandler($this->router, $this->roleChecker, $this->tokenValidator, auditLogger: $audit);
        ($handler)(['uri' => $uri], self::BEARER);
    }

    public function testAuditContext_setsActorUserId_afterTokenValidation(): void
    {
        AuditContext::reset();

        $capturedUserId = null;
        $this->router->registerUnversioned('GET', '/api/audit-rr', function () use (&$capturedUserId): Response {
            $capturedUserId = AuditContext::getActorUserId();
            return Response::json(['ok' => true]);
        });

        $handler = new ResourcesReadHandler($this->router, $this->roleChecker, $this->tokenValidator);
        ($handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/audit-rr'], self::BEARER);

        self::assertSame(self::USER_ID, $capturedUserId);

        AuditContext::reset();
    }

    public function testHandlerThrows_stillRecordsAuditLog(): void
    {
        $this->router->registerUnversioned('GET', '/api/throws-rr', static function (): never {
            throw new \RuntimeException('Boom');
        });

        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->once())->method('record');

        $handler = new ResourcesReadHandler($this->router, $this->roleChecker, $this->tokenValidator, auditLogger: $audit);
        $result  = ($handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/throws-rr'], self::BEARER);

        self::assertArrayHasKey('contents', $result);
    }

    public function testUnknownResource_doesNotRecordAuditLog(): void
    {
        /** @var MockObject&AuditLoggerInterface $audit */
        $audit = $this->createMock(AuditLoggerInterface::class);
        $audit->expects($this->never())->method('record');

        $handler = new ResourcesReadHandler($this->router, $this->roleChecker, $this->tokenValidator, auditLogger: $audit);

        $this->expectException(McpException::class);
        $this->expectExceptionCode(ErrorCode::RESOURCE_NOT_FOUND);
        ($handler)(['uri' => ResourceDeriver::URI_SCHEME . '/api/no-such-resource'], self::BEARER);
    }
}
