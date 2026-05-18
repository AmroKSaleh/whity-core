<?php

namespace Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Tests for EnforceTenantIsolation middleware
 */
class EnforceTenantIsolationTest extends TestCase
{
    private EnforceTenantIsolation $middleware;
    private JwtParser $mockJwtParser;

    protected function setUp(): void
    {
        $this->mockJwtParser = $this->createMock(JwtParser::class);
        $this->middleware = new EnforceTenantIsolation($this->mockJwtParser);
    }

    /**
     * Reset TenantContext after each test
     */
    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Test that valid JWT with tenant_id sets TenantContext correctly
     */
    public function testSetsTenantContextFromValidJwt(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'tenant_id' => 42,
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
        $this->assertSame(42, TenantContext::getTenantId());
        $this->assertNotNull($request->user);
        $this->assertSame(123, $request->user->user_id);
        $this->assertSame(42, $request->user->tenant_id);
        $this->assertSame('user@example.com', $request->user->email);
    }

    /**
     * Test that missing Authorization header returns 401
     */
    public function testReturns401OnMissingAuthHeader(): void
    {
        $request = new Request('GET', '/api/resource');
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Missing or invalid Authorization header', $responseData['error']);
    }

    /**
     * Test that invalid JWT token returns 401
     */
    public function testReturns401OnInvalidToken(): void
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
     * Test that missing tenant_id in JWT payload returns 401
     */
    public function testReturns401OnMissingTenantIdInPayload(): void
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

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Missing tenant_id in token payload', $responseData['error']);
    }

    /**
     * Test that TenantContext locks after middleware runs
     */
    public function testContextLocksAfterMiddleware(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'tenant_id' => 42,
            'email' => 'user@example.com'
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $callbackExecuted = false;
        $nextException = null;

        $next = function(Request $req) use (&$callbackExecuted, &$nextException) {
            $callbackExecuted = true;
            try {
                TenantContext::setTenantId(99);
            } catch (\RuntimeException $e) {
                $nextException = $e;
            }
            return new Response(200, 'Success');
        };

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($callbackExecuted);
        $this->assertNotNull($nextException);
        $this->assertInstanceOf(\RuntimeException::class, $nextException);
        $this->assertStringContainsString('locked', $nextException->getMessage());
    }
}
