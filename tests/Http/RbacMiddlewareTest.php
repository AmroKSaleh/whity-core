<?php

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\RbacMiddleware;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Tests for RbacMiddleware class.
 *
 * WC-idcut-E: post-cutover — all JWT payloads carry profile_id (not user_id).
 * The middleware exclusively uses profile_id for identity and calls the
 * membership-aware RoleChecker methods (hasRoleForProfile / hasPermissionForProfile).
 */
class RbacMiddlewareTest extends TestCase
{
    /**
     * Tenant id locked into the context for the authorization-decision tests.
     */
    private const TENANT = 1;

    private RbacMiddleware $middleware;
    private JwtParser $mockJwtParser;
    private RoleChecker $mockRoleChecker;
    private string $secret = 'test-secret-key-padded-for-hs256-min-32-byte-key';

    protected function setUp(): void
    {
        $this->mockJwtParser = $this->createMock(JwtParser::class);
        $this->mockRoleChecker = $this->createMock(RoleChecker::class);
        $this->middleware = new RbacMiddleware($this->mockJwtParser, $this->mockRoleChecker);

        // Mirror the production pipeline: the tenant is resolved/locked before RBAC.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $request = new Request('GET', '/api/resource');
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Missing or invalid Authorization header', $responseData['error']);
    }

    public function testInvalidAuthHeaderFormatReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'InvalidFormat']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Missing or invalid Authorization header', $responseData['error']);
    }

    public function testBearerWithoutTokenReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'Bearer']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testInvalidTokenReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'Bearer invalid.token.here']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with('invalid.token.here')
            ->willReturn(null);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid or expired token', $responseData['error']);
    }

    /**
     * Post-cutover: token carries profile_id; fail-open (no role/permission required).
     */
    public function testValidTokenWithoutRoleRequirementPasses(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
    }

    /**
     * Post-cutover: missing profile_id returns 401 (not user_id fallback).
     */
    public function testMissingProfileIdInTokenReturns401(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'email' => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid token payload', $responseData['error']);
    }

    /**
     * Post-cutover: only user_id (no profile_id) is also rejected.
     */
    public function testLegacyUserIdOnlyTokenReturns401(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id'   => 123,
            'tenant_id' => self::TENANT,
            'email'     => 'legacy@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid token payload', $responseData['error']);
    }

    public function testInvalidProfileIdTypeReturns401(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 'not-an-integer',
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid token payload', $responseData['error']);
    }

    /**
     * Post-cutover: role check uses hasRoleForProfile (membership-aware).
     */
    public function testValidTokenWithSufficientRoleAllows(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/admin', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Admin Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasRoleForProfile')
            ->with(123, 'admin', self::TENANT)
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Admin Success', $response->getBody());
        $this->assertNotNull($request->user);
        $this->assertSame(123, $request->user->profile_id);
    }

    /**
     * Post-cutover: role check uses hasRoleForProfile (membership-aware).
     */
    public function testValidTokenWithInsufficientRoleDenies(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/admin', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Admin Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasRoleForProfile')
            ->with(123, 'admin', self::TENANT)
            ->willReturn(false);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $responseData['error']);
    }

    public function testCaseInsensitiveAuthorizationHeader(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        // Test with lowercase header name
        $request = new Request('GET', '/api/resource', ['authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testExpiredTokenReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'Bearer expired.token.here']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with('expired.token.here')
            ->willReturn(null);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid or expired token', $responseData['error']);
    }

    /**
     * User object on request carries the full payload including profile_id.
     */
    public function testUserObjectSetOnRequest(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 456,
            'active_tenant_id' => self::TENANT,
            'email'            => 'admin@example.com',
            'role'             => 'admin',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = function (Request $req) {
            $this->assertNotNull($req->user);
            $this->assertSame(456, $req->user->profile_id);
            $this->assertSame('admin@example.com', $req->user->email);
            $this->assertSame('admin', $req->user->role);
            return new Response(200, 'Success');
        };

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasRoleForProfile')
            ->with(456, 'admin', self::TENANT)
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A forged role claim in the JWT must not bypass the authoritative check (issue #54).
     */
    public function testTokenRoleClaimDoesNotBypassPermissionCheck(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 99,
            'active_tenant_id' => self::TENANT,
            'email'            => 'attacker@example.com',
            'role'             => 'admin', // forged/elevated claim must be ignored
        ];

        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$validToken}"]);
        $handlerCalled = false;
        $next = function (Request $req) use (&$handlerCalled) {
            $handlerCalled = true;
            return new Response(200, 'Success');
        };

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        // Authoritative store: profile does NOT have the permission.
        $this->mockRoleChecker->method('hasPermissionForProfile')
            ->with(99, 'users:read', self::TENANT)
            ->willReturn(false);

        $response = $this->middleware->handle($request, $next, null, 'users:read');

        $this->assertFalse($handlerCalled, 'Handler must not run when the authoritative check fails');
        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $responseData['error']);
        $this->assertSame('users:read', $responseData['required']);
    }

    public function testPermissionDeniedIncludesRequiredField(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 7,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')->willReturn($payload);
        $this->mockRoleChecker->method('hasPermissionForProfile')
            ->with(7, 'users:read', self::TENANT)
            ->willReturn(false);

        $response = $this->middleware->handle($request, $next, null, 'users:read');

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame(
            ['error' => 'Insufficient permissions', 'required' => 'users:read'],
            $responseData
        );
    }

    public function testRoleDeniedDoesNotIncludeRequiredField(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 8,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/admin', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')->willReturn($payload);
        $this->mockRoleChecker->method('hasRoleForProfile')
            ->with(8, 'admin', self::TENANT)
            ->willReturn(false);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $responseData['error']);
        $this->assertArrayNotHasKey('required', $responseData);
    }

    public function testHandleWithValidPermissionAllowsRequest(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasPermissionForProfile')
            ->with(123, 'edit:users', self::TENANT)
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, null, 'edit:users');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
        $this->assertNotNull($request->user);
        $this->assertSame(123, $request->user->profile_id);
    }

    public function testHandleWithInvalidPermissionReturnsForbidden(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasPermissionForProfile')
            ->with(123, 'delete:users', self::TENANT)
            ->willReturn(false);

        $response = $this->middleware->handle($request, $next, null, 'delete:users');

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $responseData['error']);
        $this->assertSame('delete:users', $responseData['required']);
    }

    public function testHandleWithBothRoleAndPermissionRequiresBoth(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/admin', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasRoleForProfile')
            ->with(123, 'admin', self::TENANT)
            ->willReturn(true);

        $this->mockRoleChecker->method('hasPermissionForProfile')
            ->with(123, 'manage:permissions', self::TENANT)
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, 'admin', 'manage:permissions');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
    }

    public function testHandleWithOnlyPermissionCheckIgnoresRole(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'profile_id'       => 123,
            'active_tenant_id' => self::TENANT,
            'email'            => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        // Only hasPermissionForProfile should be called, not hasRoleForProfile
        $this->mockRoleChecker->expects($this->never())
            ->method('hasRoleForProfile');

        $this->mockRoleChecker->method('hasPermissionForProfile')
            ->with(123, 'read:reports', self::TENANT)
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, null, 'read:reports');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
    }
}
