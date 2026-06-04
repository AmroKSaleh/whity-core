<?php

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\HttpKernel;
use Whity\Http\RbacMiddleware;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Tenant\TenantContext;

/**
 * Tests for HttpKernel class
 */
class HttpKernelTest extends TestCase
{
    private HttpKernel $kernel;
    private Router $router;
    private RbacMiddleware $rbacMiddleware;

    protected function setUp(): void
    {
        $this->router = new Router();

        // Create mock dependencies for RbacMiddleware
        $jwtParser = $this->createMock(JwtParser::class);
        $roleChecker = $this->createMock(RoleChecker::class);
        $this->rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);

        $this->kernel = new HttpKernel($this->router, $this->rbacMiddleware);
    }

    protected function tearDown(): void
    {
        // Clean up TenantContext after each test to avoid state leakage
        TenantContext::reset();
    }

    /**
     * Test handling a request that matches a route without required role
     */
    public function testHandlesRequestWithoutRequiredRole(): void
    {
        $handler = static function(Request $request): Response {
            return Response::json(['message' => 'success']);
        };

        $this->router->register('GET', '/hello', $handler);

        $request = new Request('GET', '/hello');
        $response = $this->kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('success', $response->getBody());
    }

    /**
     * Test handling a request that does not match any route returns 404
     */
    public function testReturns404ForUnmatchedRoute(): void
    {
        $this->router->register('GET', '/hello', static fn(Request $req) => Response::json([]));

        $request = new Request('GET', '/unknown');
        $response = $this->kernel->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Not Found', $response->getBody());
    }

    /**
     * Test handling a request with path parameters
     */
    public function testHandlesRequestWithPathParameters(): void
    {
        $handler = static function(Request $request): Response {
            return Response::json(['message' => 'hello']);
        };

        $this->router->register('GET', '/users/{id}', $handler);

        $request = new Request('GET', '/users/42');
        $response = $this->kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test handling a request with required role passes through RBAC middleware
     */
    public function testHandlesRequestWithRequiredRolePassesThroughMiddleware(): void
    {
        // Create a custom mock RbacMiddleware to track if handle was called
        $rbacMiddlewareMock = $this->createMock(RbacMiddleware::class);
        $kernel = new HttpKernel($this->router, $rbacMiddlewareMock);

        $handler = static function(Request $request): Response {
            return Response::json(['message' => 'protected']);
        };

        $this->router->register('POST', '/admin/users', $handler, 'admin');

        // Mock the RBAC middleware to return a successful response
        $rbacMiddlewareMock
            ->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function(Request $req, callable $next, ?string $role) {
                $this->assertSame('admin', $role);
                return $next($req);
            });

        $request = new Request('POST', '/admin/users');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test handling multiple different routes
     */
    public function testHandlesMultipleDifferentRoutes(): void
    {
        $handler1 = static fn(Request $req) => Response::json(['route' => 'users']);
        $handler2 = static fn(Request $req) => Response::json(['route' => 'posts']);

        $this->router->register('GET', '/users', $handler1);
        $this->router->register('GET', '/posts', $handler2);

        $request1 = new Request('GET', '/users');
        $response1 = $this->kernel->handle($request1);
        $this->assertStringContainsString('users', $response1->getBody());

        $request2 = new Request('GET', '/posts');
        $response2 = $this->kernel->handle($request2);
        $this->assertStringContainsString('posts', $response2->getBody());
    }

    /**
     * Test that response headers are preserved from handler
     */
    public function testPreservesResponseHeaders(): void
    {
        $handler = static function(Request $request): Response {
            return new Response(200, 'test', ['X-Custom-Header' => 'custom-value']);
        };

        $this->router->register('GET', '/test', $handler);

        $request = new Request('GET', '/test');
        $response = $this->kernel->handle($request);

        $headers = $response->getHeaders();
        // Headers are normalized to lowercase with hyphens
        $this->assertArrayHasKey('x-custom-header', $headers);
        $this->assertSame('custom-value', $headers['x-custom-header']);
    }

    /**
     * Test handling POST requests
     */
    public function testHandlesPOSTRequests(): void
    {
        $handler = static function(Request $request): Response {
            return Response::json(['method' => 'POST']);
        };

        $this->router->register('POST', '/data', $handler);

        $request = new Request('POST', '/data', [], '{"test":"data"}');
        $response = $this->kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('POST', $response->getBody());
    }

    /**
     * Test that TenantContext is cleaned up after request completes
     */
    public function testTenantContextIsCleanedUpAfterRequest(): void
    {
        // Create a handler that sets a tenant in the context
        $handler = static function(Request $request): Response {
            // Set tenant context during request handling
            TenantContext::setTenantId(42);
            return Response::json(['message' => 'success']);
        };

        $this->router->register('GET', '/test', $handler);

        // Execute the request
        $request = new Request('GET', '/test');
        $response = $this->kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());

        // Verify tenant context is cleaned up after request
        $this->assertFalse(TenantContext::hasTenant());
        $this->assertNull(TenantContext::getTenantId());
    }

    /**
     * Test that TenantContext is cleaned up even when handler throws exception
     */
    public function testTenantContextIsCleanedUpEvenWhenExceptionThrown(): void
    {
        // Create a handler that sets tenant context and then throws exception
        $handler = static function(Request $request): Response {
            TenantContext::setTenantId(99);
            throw new \Exception('Handler error');
        };

        $this->router->register('GET', '/error', $handler);

        // Execute the request and expect exception
        $request = new Request('GET', '/error');

        $caughtException = null;
        try {
            $this->kernel->handle($request);
        } catch (\Exception $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException);
        $this->assertSame('Handler error', $caughtException->getMessage());

        // Verify tenant context is cleaned up even after exception
        $this->assertFalse(TenantContext::hasTenant());
        $this->assertNull(TenantContext::getTenantId());
    }

    /**
     * Test middleware execution order (tenant isolation before RBAC)
     */
    public function testMiddlewareExecutionOrder(): void
    {
        // Track the execution order of middleware
        $executionOrder = [];

        // Create a test middleware class
        $testMiddleware = new class($executionOrder) {
            private array $executionOrder;

            public function __construct(array &$executionOrder)
            {
                $this->executionOrder = &$executionOrder;
            }

            public function handle(Request $request, callable $next): Response
            {
                $this->executionOrder[] = 'middleware_before';
                $response = $next($request);
                $this->executionOrder[] = 'middleware_after';
                return $response;
            }
        };

        // Create a simple handler
        $handler = static function(Request $request) use (&$executionOrder): Response {
            $executionOrder[] = 'handler';
            return Response::json(['message' => 'success']);
        };

        $this->router->register('GET', '/test', $handler);

        // Create kernel with middleware
        $kernel = new HttpKernel($this->router, $this->rbacMiddleware);
        $kernel->use($testMiddleware);

        // Execute request
        $request = new Request('GET', '/test');
        $response = $kernel->handle($request);

        // Verify response is successful
        $this->assertSame(200, $response->getStatusCode());

        // Verify middleware execution order
        $this->assertSame(['middleware_before', 'handler', 'middleware_after'], $executionOrder);
    }

    /**
     * Test that EnforceTenantIsolation middleware sets TenantContext before routing
     */
    public function testEnforceTenantIsolationRunsBeforeRouting(): void
    {
        $jwtParser = $this->createMock(JwtParser::class);
        $tenantMiddleware = new EnforceTenantIsolation($jwtParser);

        // Track if tenant was set when handler was called
        $tenantIdWhenHandlerRan = null;
        $handler = static function(Request $request) use (&$tenantIdWhenHandlerRan): Response {
            $tenantIdWhenHandlerRan = TenantContext::getTenantId();
            return Response::json(['tenant' => $tenantIdWhenHandlerRan]);
        };

        $this->router->register('GET', '/test', $handler);

        $kernel = new HttpKernel($this->router, $this->rbacMiddleware);
        $kernel->use($tenantMiddleware);

        // Create request with valid JWT
        $validToken = 'valid.jwt.token';
        $payload = [
            'user_id' => 123,
            'tenant_id' => 42,
            'email' => 'user@example.com'
        ];

        // The middleware delegates tenant resolution to TenantContext::resolve()
        // (which parses the token) and re-derives the decoded payload to expose it
        // as Request::$user, so the parser may be invoked more than once.
        $jwtParser->expects($this->atLeastOnce())
            ->method('parse')
            ->with($validToken)
            ->willReturn($payload);

        $request = new Request('GET', '/test', ['Authorization' => "Bearer {$validToken}"]);
        $response = $kernel->handle($request);

        // Verify response is successful
        $this->assertSame(200, $response->getStatusCode());

        // Verify tenant was set before handler ran
        $this->assertSame(42, $tenantIdWhenHandlerRan);
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame(42, $responseData['tenant']);
    }

    /**
     * Test that tenant isolation middleware returns 401 when authorization is missing
     */
    public function testTenantIsolationReturns401OnMissingAuth(): void
    {
        $jwtParser = $this->createMock(JwtParser::class);
        $tenantMiddleware = new EnforceTenantIsolation($jwtParser);

        $handler = static fn(Request $req) => Response::json(['message' => 'success']);
        $this->router->register('GET', '/test', $handler);

        $kernel = new HttpKernel($this->router, $this->rbacMiddleware);
        $kernel->use($tenantMiddleware);

        // Request without Authorization header
        $request = new Request('GET', '/test');
        $response = $kernel->handle($request);

        // Verify 401 response. The middleware collapses every resolution failure
        // (missing/invalid token, missing/invalid tenant claim) to a single
        // client-safe message so request internals are never leaked.
        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Authentication required', $responseData['error']);
    }
}
