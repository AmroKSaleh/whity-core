<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Unit tests for the EnforceTenantIsolation middleware.
 *
 * The middleware delegates token -> tenant resolution to
 * {@see TenantContext::resolve()} and enforces cross-tenant access control at
 * the HTTP layer: a caller scoped to tenant N may not address a resource that
 * declares a different tenant. System callers (tenant_id = 0) bypass the check
 * with structured audit logging.
 */
class EnforceTenantIsolationTest extends TestCase
{
    private EnforceTenantIsolation $middleware;
    private JwtParser $mockJwtParser;

    protected function setUp(): void
    {
        $this->mockJwtParser = $this->createMock(JwtParser::class);
        $this->middleware = new EnforceTenantIsolation($this->mockJwtParser);
        TenantContext::reset();
    }

    /**
     * Reset shared TenantContext state after each test.
     */
    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * A collecting PSR-3 logger used to assert on emitted audit records.
     */
    private function collectingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            /**
             * @param mixed                $level
             * @param string|Stringable    $message
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    /**
     * Test that a valid JWT with tenant_id resolves and locks TenantContext.
     */
    public function testSetsTenantContextFromValidJwt(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'tenant_id' => 42,
            'email' => 'user@example.com',
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
     * Test that a missing Authorization header returns 401.
     */
    public function testReturns401OnMissingAuthHeader(): void
    {
        $request = new Request('GET', '/api/resource');
        $next = fn(Request $req) => new Response(200, 'Success');

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Authentication required', $responseData['error']);
    }

    /**
     * Test that an invalid JWT token returns 401.
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
        $this->assertSame('Authentication required', $responseData['error']);
    }

    /**
     * Test that a token without a tenant_id claim returns 401.
     */
    public function testReturns401OnMissingTenantIdInPayload(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'email' => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $next = fn(Request $req) => new Response(200, 'Success');

        $this->mockJwtParser->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Authentication required', $responseData['error']);
    }

    /**
     * Test that TenantContext locks after the middleware runs.
     */
    public function testContextLocksAfterMiddleware(): void
    {
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'tenant_id' => 42,
            'email' => 'user@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$validToken}"]);
        $callbackExecuted = false;
        $nextException = null;

        $next = function (Request $req) use (&$callbackExecuted, &$nextException) {
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

    /**
     * AC: same-tenant access passes. A tenant-1 caller addressing a tenant-1
     * resource (declared via query string) reaches the next handler.
     */
    public function testSameTenantResourceAccessPasses(): void
    {
        $payload = ['user_id' => 5, 'tenant_id' => 1, 'email' => 'a@t1'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource?tenant_id=1', ['Authorization' => 'Bearer t1.token']);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($reached, 'Same-tenant request must reach the handler');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, TenantContext::getTenantId());
    }

    /**
     * AC1: cross-tenant access is blocked with 403 BEFORE any handler/DB work.
     * A tenant-1 caller addressing a tenant-2 resource is refused.
     */
    public function testCrossTenantResourceAccessBlockedWith403(): void
    {
        $payload = ['user_id' => 5, 'tenant_id' => 1, 'email' => 'a@t1'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource?tenant_id=2', ['Authorization' => 'Bearer t1.token']);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertFalse($reached, 'Cross-tenant request must be blocked before the handler runs');
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Access to the requested tenant is forbidden', $data['error']);
    }

    /**
     * AC1: cross-tenant access is blocked when the resource tenant is encoded in
     * the path (/api/tenants/{id}).
     */
    public function testCrossTenantPathResourceBlockedWith403(): void
    {
        $payload = ['user_id' => 50, 'tenant_id' => 3, 'email' => 'user@tenant3'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('DELETE', '/api/tenants/7', ['Authorization' => 'Bearer t3.token']);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertFalse($reached);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * A tenant accessing its own /api/tenants/{id} record passes.
     */
    public function testOwnTenantPathResourcePasses(): void
    {
        $payload = ['user_id' => 50, 'tenant_id' => 3, 'email' => 'user@tenant3'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('PATCH', '/api/tenants/3', ['Authorization' => 'Bearer t3.token']);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * AC2: a system user (tenant_id = 0) bypasses cross-tenant enforcement and
     * the bypass is recorded as a structured audit log entry.
     */
    public function testSystemUserBypassesCrossTenantWithAudit(): void
    {
        $logger = $this->collectingLogger();
        $middleware = new EnforceTenantIsolation($this->mockJwtParser, $logger);

        $payload = ['user_id' => 1, 'tenant_id' => 0, 'email' => 'root@system'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('DELETE', '/api/tenants/7', ['Authorization' => 'Bearer sys.token']);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($reached, 'System user must reach the handler across tenants');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, TenantContext::getTenantId());

        $bypassRecords = array_values(array_filter(
            $logger->records,
            static fn(array $r): bool =>
                ($r['context']['event'] ?? null) === 'tenant_isolation.cross_tenant_bypass'
        ));
        $this->assertCount(1, $bypassRecords, 'Exactly one cross-tenant bypass audit record expected');
        $record = $bypassRecords[0];
        $this->assertSame(0, $record['context']['tenant_id']);
        $this->assertSame(7, $record['context']['resource_tenant_id']);
        $this->assertSame(1, $record['context']['user_id']);
        $this->assertArrayHasKey('path', $record['context']);
    }

    /**
     * AC2: when explicit system mode is active (set by trusted non-request code),
     * cross-tenant access is permitted and audited even for a non-zero tenant.
     */
    public function testSystemModeBypassesCrossTenantWithAudit(): void
    {
        $logger = $this->collectingLogger();
        $middleware = new EnforceTenantIsolation($this->mockJwtParser, $logger);
        TenantContext::setSystemMode(true, 'integration-test');

        $payload = ['user_id' => 9, 'tenant_id' => 1, 'email' => 'svc@t1'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource?tenant_id=2', ['Authorization' => 'Bearer svc.token']);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());

        $bypassRecords = array_values(array_filter(
            $logger->records,
            static fn(array $r): bool =>
                ($r['context']['event'] ?? null) === 'tenant_isolation.cross_tenant_bypass'
        ));
        $this->assertCount(1, $bypassRecords);
        $this->assertTrue($bypassRecords[0]['context']['system_mode']);
    }

    /**
     * A non-tenant-scoped request (no resource tenant declared) passes through
     * so per-resource handlers / query scoping can apply finer-grained rules.
     */
    public function testRequestWithoutResourceTenantPassesThrough(): void
    {
        $payload = ['user_id' => 5, 'tenant_id' => 1, 'email' => 'a@t1'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer t1.token']);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Public routes skip authentication and isolation entirely.
     */
    public function testPublicRouteSkipsIsolation(): void
    {
        $request = new Request('POST', '/api/login');
        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull(TenantContext::getTenantId());
    }
}
