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

        // Post-cutover: system token uses profile_id + active_tenant_id (0 = system tenant).
        $payload = ['profile_id' => 1, 'active_tenant_id' => 0, 'email' => 'root@system'];
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
        // Post-cutover: userIdFromPayload returns profile_id (1) as the audit actor.
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
        $request = new Request('POST', '/api/v1/login');
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

    // ===================================================================
    // WC-193: X-Tenant-Id / tenant_id-query trust boundary
    //
    // The JWT-derived TenantContext is the ONLY source of truth for the
    // caller's tenant. The path /api/tenants/{id}, the tenant_id query
    // parameter and the X-Tenant-Id header are attacker-suppliable
    // *declared targets* that feed ONLY this cross-tenant gate; they can
    // never widen a non-system caller's reach. These tests lock that in.
    // ===================================================================

    /**
     * WC-193: a non-system caller declaring ANOTHER tenant via the
     * X-Tenant-Id header is refused with 403 before any handler runs — the
     * header can never escalate across tenants.
     */
    public function testCrossTenantViaHeaderBlockedWith403(): void
    {
        $payload = ['user_id' => 5, 'tenant_id' => 1, 'email' => 'a@t1'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer t1.token',
            'X-Tenant-Id' => '2',
        ]);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertFalse($reached, 'X-Tenant-Id of another tenant must not reach the handler');
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Access to the requested tenant is forbidden', $data['error']);
    }

    /**
     * WC-193: a non-system caller declaring its OWN tenant via X-Tenant-Id is
     * allowed — the header matches the JWT tenant, so it can only ever match
     * or 403, never escalate.
     */
    public function testOwnTenantViaHeaderPasses(): void
    {
        $payload = ['user_id' => 5, 'tenant_id' => 7, 'email' => 'a@t7'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer t7.token',
            'X-Tenant-Id' => '7',
        ]);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($reached, 'A header matching the caller tenant must reach the handler');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(7, TenantContext::getTenantId());
    }

    /**
     * WC-193: a non-system caller declaring ANOTHER tenant via the tenant_id
     * query parameter is refused with 403 — the query selector can never
     * escalate across tenants.
     */
    public function testCrossTenantViaQueryParamBlockedWith403(): void
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

        $this->assertFalse($reached, 'tenant_id query of another tenant must not reach the handler');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * WC-193: a refused cross-tenant attempt is audited. An attacker probing
     * other tenants via X-Tenant-Id/tenant_id must leave a structured trail —
     * the 403 path is no longer silent.
     */
    public function testCrossTenantDenialIsAudited(): void
    {
        $logger = $this->collectingLogger();
        $middleware = new EnforceTenantIsolation($this->mockJwtParser, $logger);

        // Post-cutover: use profile_id + active_tenant_id claims.
        $payload = ['profile_id' => 5, 'active_tenant_id' => 1, 'email' => 'a@t1'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer t1.token',
            'X-Tenant-Id' => '2',
        ]);

        $next = fn(Request $req): Response => new Response(200, 'ok');

        $response = $middleware->handle($request, $next);
        $this->assertSame(403, $response->getStatusCode());

        $denials = array_values(array_filter(
            $logger->records,
            static fn(array $r): bool =>
                ($r['context']['event'] ?? null) === 'tenant_isolation.cross_tenant_denied'
        ));
        $this->assertCount(1, $denials, 'A refused cross-tenant attempt must be audited');
        $this->assertSame(1, $denials[0]['context']['tenant_id'], 'caller tenant');
        $this->assertSame(2, $denials[0]['context']['resource_tenant_id'], 'declared target');
        // Post-cutover: userIdFromPayload returns profile_id (5) as the audit actor.
        $this->assertSame(5, $denials[0]['context']['user_id']);
        $this->assertArrayHasKey('path', $denials[0]['context']);
    }

    /**
     * WC-193: the system tenant (id 0) crossing tenants via X-Tenant-Id is
     * permitted and emits the bypass (not denial) audit record — the
     * privileged path stays audited and distinct from a denial.
     */
    public function testSystemTenantCrossTenantViaHeaderAuditedAsBypass(): void
    {
        $logger = $this->collectingLogger();
        $middleware = new EnforceTenantIsolation($this->mockJwtParser, $logger);

        $payload = ['user_id' => 1, 'tenant_id' => 0, 'email' => 'root@system'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer sys.token',
            'X-Tenant-Id' => '2',
        ]);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $middleware->handle($request, $next);

        $this->assertTrue($reached, 'System tenant must cross tenants via the audited bypass');
        $this->assertSame(200, $response->getStatusCode());

        $bypasses = array_values(array_filter(
            $logger->records,
            static fn(array $r): bool =>
                ($r['context']['event'] ?? null) === 'tenant_isolation.cross_tenant_bypass'
        ));
        $denials = array_values(array_filter(
            $logger->records,
            static fn(array $r): bool =>
                ($r['context']['event'] ?? null) === 'tenant_isolation.cross_tenant_denied'
        ));
        $this->assertCount(1, $bypasses, 'A permitted system bypass must be audited as a bypass');
        $this->assertCount(0, $denials, 'A permitted bypass must NOT be logged as a denial');
        $this->assertSame(2, $bypasses[0]['context']['resource_tenant_id']);
    }

    /**
     * WC-193: a malformed X-Tenant-Id (non-digit, signed, whitespace, decimal)
     * is treated as no-declared-target — it can neither escalate nor coincide
     * with a numeric tenant id. The request defers to the handler's
     * JWT-derived scoping.
     *
     * @dataProvider malformedTenantSelectorProvider
     */
    public function testMalformedHeaderIsIgnoredSafely(string $headerValue): void
    {
        $payload = ['user_id' => 5, 'tenant_id' => 1, 'email' => 'a@t1'];
        $this->mockJwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer t1.token',
            'X-Tenant-Id' => $headerValue,
        ]);

        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($reached, 'A malformed header must be ignored, deferring to handler scoping');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, TenantContext::getTenantId());
    }

    /**
     * Malformed tenant selectors that must never resolve to a numeric target.
     *
     * @return array<string, array{0:string}>
     */
    public static function malformedTenantSelectorProvider(): array
    {
        return [
            'negative'      => ['-1'],
            'signed plus'   => ['+2'],
            'decimal'       => ['2.0'],
            'whitespace'    => [' 2 '],
            'hex'           => ['0x2'],
            'non-numeric'   => ['two'],
            'empty'         => [''],
            'trailing junk' => ['2; DROP'],
        ];
    }

    /**
     * The whity-plugin-store READ surface (catalogue browse + registry index +
     * self-authenticating package download) must pass through WITHOUT a host
     * session — a consuming host has no session on the store. All are GET.
     *
     * @dataProvider pluginStorePublicReadProvider
     */
    public function testPluginStorePublicReadBypassesTenantIsolation(string $path): void
    {
        // No Authorization header at all — a public/consumer request.
        $request = new Request('GET', $path);
        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertTrue($reached, "GET {$path} must reach the handler without a session");
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function pluginStorePublicReadProvider(): array
    {
        return [
            'list'            => ['/api/v1/plugin-store/plugins'],
            'show'            => ['/api/v1/plugin-store/plugins/quote-of-day'],
            'download'        => ['/api/v1/plugin-store/plugins/quote-of-day/versions/1.0.0/download'],
            'registry index'  => ['/api/v1/plugin-store/index.json'],
        ];
    }

    /**
     * The exemption is READ-ONLY (GET). Operator writes on the SAME base path —
     * publishing a version — must still require a session (401 without one), so a
     * cross-site or anonymous caller can never publish.
     */
    public function testPluginStorePublishStillRequiresSession(): void
    {
        $request = new Request('POST', '/api/v1/plugin-store/plugins/quote-of-day/versions');
        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertFalse($reached, 'POST publish must NOT bypass tenant isolation');
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * The token-management routes are a different PATH (never matched by the
     * read exemption) and must stay session-gated — even the GET list, which
     * shares the method but not the path.
     *
     * @dataProvider pluginStoreTokenRouteProvider
     */
    public function testPluginStoreTokenRoutesStillRequireSession(string $method, string $path): void
    {
        $request = new Request($method, $path);
        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertFalse($reached, "{$method} {$path} must NOT bypass tenant isolation");
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public static function pluginStoreTokenRouteProvider(): array
    {
        return [
            'list tokens (GET, different path)' => ['GET', '/api/v1/plugin-store/tokens'],
            'mint token'                        => ['POST', '/api/v1/plugin-store/tokens'],
            'revoke token'                      => ['DELETE', '/api/v1/plugin-store/tokens/1'],
        ];
    }

    /**
     * The exemption is anchored to EXACT route shapes, not an open `/plugins/`
     * prefix. A GET to a DEEPER or unknown path under the store namespace must
     * NOT be auto-exempted — it stays session-gated (401), so a future store
     * route can never become unauthenticated without an explicit change here.
     *
     * @dataProvider pluginStoreNonExemptGetProvider
     */
    public function testDeeperOrUnknownStoreGetPathsAreNotExempt(string $path): void
    {
        $request = new Request('GET', $path);
        $reached = false;
        $next = function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };

        $response = $this->middleware->handle($request, $next);

        $this->assertFalse($reached, "GET {$path} must NOT be treated as a public store route");
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function pluginStoreNonExemptGetProvider(): array
    {
        return [
            'deeper under slug'          => ['/api/v1/plugin-store/plugins/quote-of-day/stats'],
            'versions listing (no dl)'   => ['/api/v1/plugin-store/plugins/quote-of-day/versions/1.0.0'],
            'trailing segment past dl'   => ['/api/v1/plugin-store/plugins/x/versions/1.0.0/download/extra'],
            'traversal to tokens'        => ['/api/v1/plugin-store/plugins/../tokens'],
            'index prefix but not exact' => ['/api/v1/plugin-store/index.jsonx'],
        ];
    }
}
