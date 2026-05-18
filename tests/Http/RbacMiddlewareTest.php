<?php

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\RbacMiddleware;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Tests for RbacMiddleware class
 */
class RbacMiddlewareTest extends TestCase
{
    private RbacMiddleware $middleware;
    private JwtParser $mockJwtParser;
    private RoleChecker $mockRoleChecker;
    private string $secret = 'test-secret-key';

    protected function setUp(): void
    {
        $this->mockJwtParser = $this->createMock(JwtParser::class);
        $this->mockRoleChecker = $this->createMock(RoleChecker::class);
        $this->middleware = new RbacMiddleware($this->mockJwtParser, $this->mockRoleChecker);
    }

    /**
     * Test missing Authorization header returns 401
     */
    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $request = new Request('GET', '/api/resource');
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Missing or invalid Authorization header', $responseData['error']);
    }

    /**
     * Test invalid Authorization header format returns 401
     */
    public function testInvalidAuthHeaderFormatReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'InvalidFormat']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Missing or invalid Authorization header', $responseData['error']);
    }

    /**
     * Test Bearer without token returns 401
     */
    public function testBearerWithoutTokenReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'Bearer']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test invalid token returns 401
     */
    public function testInvalidTokenReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'Bearer invalid.token.here']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with('invalid.token.here')
            ->willReturn(null);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid or expired token', $responseData['error']);
    }

    /**
     * Test valid token without role requirement passes
     */
    public function testValidTokenWithoutRoleRequirementPasses(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
        $this->assertNotNull($request->user);
        $this->assertSame(123, $request->user->user_id);
        $this->assertSame('user@example.com', $request->user->email);
    }

    /**
     * Test missing user_id in token returns 401
     */
    public function testMissingUserIdInTokenReturns401(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid token payload', $responseData['error']);
    }

    /**
     * Test invalid user_id type in token returns 401
     */
    public function testInvalidUserIdTypeReturns401(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 'not-an-integer',
            'email' => 'user@example.com'
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
     * Test valid token with sufficient role allows
     */
    public function testValidTokenWithSufficientRoleAllows(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/admin', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Admin Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasRole')
            ->with(123, 'admin')
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Admin Success', $response->getBody());
        $this->assertNotNull($request->user);
        $this->assertSame(123, $request->user->user_id);
    }

    /**
     * Test valid token with insufficient role denies
     */
    public function testValidTokenWithInsufficientRoleDenies(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/admin', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Admin Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasRole')
            ->with(123, 'admin')
            ->willReturn(false);

        $response = $this->middleware->handle($request, $next, 'admin');

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $responseData['error']);
    }

    /**
     * Test case-insensitive Authorization header
     */
    public function testCaseInsensitiveAuthorizationHeader(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
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

    /**
     * Test expired token returns 401
     */
    public function testExpiredTokenReturns401(): void
    {
        $request = new Request('GET', '/api/resource', ['Authorization' => 'Bearer expired.token.here']);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with('expired.token.here')
            ->willReturn(null);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid or expired token', $responseData['error']);
    }

    /**
     * Test user object is set on request with full payload
     */
    public function testUserObjectSetOnRequest(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 456,
            'email' => 'admin@example.com',
            'role' => 'admin',
            'permissions' => ['read', 'write', 'delete']
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = function(Request $req) {
            $this->assertNotNull($req->user);
            $this->assertSame(456, $req->user->user_id);
            $this->assertSame('admin@example.com', $req->user->email);
            $this->assertSame('admin', $req->user->role);
            $this->assertSame(['read', 'write', 'delete'], $req->user->permissions);
            return new Response(200, 'Success');
        };

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test valid token with sufficient permission allows
     */
    public function testHandleWithValidPermissionAllowsRequest(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasPermission')
            ->with(123, 'edit:users')
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, null, 'edit:users');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
        $this->assertNotNull($request->user);
        $this->assertSame(123, $request->user->user_id);
    }

    /**
     * Test valid token with insufficient permission denies
     */
    public function testHandleWithInvalidPermissionReturnsForbidden(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasPermission')
            ->with(123, 'delete:users')
            ->willReturn(false);

        $response = $this->middleware->handle($request, $next, null, 'delete:users');

        $this->assertSame(403, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Insufficient permissions', $responseData['error']);
    }

    /**
     * Test both role and permission checks must pass when both are required
     */
    public function testHandleWithBothRoleAndPermissionRequiresBoth(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/admin', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $this->mockRoleChecker->method('hasRole')
            ->with(123, 'admin')
            ->willReturn(true);

        $this->mockRoleChecker->method('hasPermission')
            ->with(123, 'manage:permissions')
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, 'admin', 'manage:permissions');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
    }

    /**
     * Test permission check passes when only permission is required regardless of role
     */
    public function testHandleWithOnlyPermissionCheckIgnoresRole(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        // Only hasPermission should be called, not hasRole
        $this->mockRoleChecker->expects($this->never())
            ->method('hasRole');

        $this->mockRoleChecker->method('hasPermission')
            ->with(123, 'read:reports')
            ->willReturn(true);

        $response = $this->middleware->handle($request, $next, null, 'read:reports');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getBody());
    }
}
