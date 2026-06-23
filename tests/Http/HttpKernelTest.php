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
        $this->router = new Router('');

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
     * WC-160: a registered path requested with the wrong method returns 405
     * (Method Not Allowed) with an Allow header — not a misleading 404.
     */
    public function testReturns405WithAllowHeaderOnMethodMismatch(): void
    {
        $this->router->register('GET', '/hello', static fn(Request $req) => Response::json([]));
        $this->router->register('POST', '/hello', static fn(Request $req) => Response::json([]));

        $response = $this->kernel->handle(new Request('DELETE', '/hello'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('Method Not Allowed', $response->getBody());
        $headers = $response->getHeaders();
        $allow = $headers['Allow'] ?? $headers['allow'] ?? null;
        $this->assertNotNull($allow, 'A 405 response must carry an Allow header');
        $this->assertStringContainsString('GET', $allow);
        $this->assertStringContainsString('POST', $allow);
    }

    /**
     * WC-160: a path violating a route's {id:\d+} constraint is a 404 (the
     * resource does not exist), not a 405.
     */
    public function testConstraintViolationIs404NotMethodMismatch(): void
    {
        $this->router->register('GET', '/users/{id:\d+}', static fn(Request $req) => Response::json([]));

        $response = $this->kernel->handle(new Request('POST', '/users/abc'));

        $this->assertSame(404, $response->getStatusCode());
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
     * Test that a route declaring only a requiredPermission (no role) still passes
     * through RBAC middleware, and the permission is forwarded as the 4th argument.
     */
    public function testForwardsRequiredPermissionToRbacMiddleware(): void
    {
        $rbacMiddlewareMock = $this->createMock(RbacMiddleware::class);
        $kernel = new HttpKernel($this->router, $rbacMiddlewareMock);

        $handler = static function (Request $request): Response {
            return Response::json(['message' => 'permission-protected']);
        };

        // requiredRole = null (4th arg), namespacePrefix = null (5th arg),
        // requiredPermission = 'plugins:read' (6th arg).
        $this->router->register('GET', '/api/plugins', $handler, null, null, 'plugins:read');

        $rbacMiddlewareMock
            ->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (
                Request $req,
                callable $next,
                ?string $role,
                ?string $permission
            ) {
                // The kernel must forward the route's permission as the 4th arg,
                // with no role required.
                $this->assertNull($role);
                $this->assertSame('plugins:read', $permission);
                return $next($req);
            });

        $request = new Request('GET', '/api/plugins');
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('permission-protected', $response->getBody());
    }

    /**
     * Test that a route declaring both a role and a permission forwards both to
     * the RBAC middleware.
     */
    public function testForwardsRoleAndPermissionToRbacMiddleware(): void
    {
        $rbacMiddlewareMock = $this->createMock(RbacMiddleware::class);
        $kernel = new HttpKernel($this->router, $rbacMiddlewareMock);

        $handler = static fn (Request $request): Response => Response::json(['ok' => true]);

        $this->router->register('POST', '/api/widgets', $handler, 'admin', null, 'widgets:write');

        $rbacMiddlewareMock
            ->expects($this->once())
            ->method('handle')
            ->willReturnCallback(function (
                Request $req,
                callable $next,
                ?string $role,
                ?string $permission
            ) {
                $this->assertSame('admin', $role);
                $this->assertSame('widgets:write', $permission);
                return $next($req);
            });

        $response = $kernel->handle(new Request('POST', '/api/widgets'));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test that a route with neither a role nor a permission bypasses RBAC
     * entirely (the middleware's handle() is never invoked).
     */
    public function testRouteWithoutRoleOrPermissionBypassesRbac(): void
    {
        $rbacMiddlewareMock = $this->createMock(RbacMiddleware::class);
        $kernel = new HttpKernel($this->router, $rbacMiddlewareMock);

        $rbacMiddlewareMock->expects($this->never())->method('handle');

        $handler = static fn (Request $request): Response => Response::json(['public' => true]);
        $this->router->register('GET', '/api/health', $handler);

        $response = $kernel->handle(new Request('GET', '/api/health'));
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

    /**
     * Test memory limit is not exceeded when limit is set high
     */
    public function testMemoryLimitNotExceeded(): void
    {
        $oldLimit = $_ENV['WORKER_MEMORY_LIMIT_MB'] ?? null;
        $_ENV['WORKER_MEMORY_LIMIT_MB'] = '10000'; // 10 GB
        
        try {
            $handler = static function(Request $request): Response {
                return Response::json(['message' => 'success']);
            };
            $this->router->register('GET', '/memory-ok', $handler);
            $request = new Request('GET', '/memory-ok');
            
            $response = $this->kernel->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertFalse($this->kernel->hasExceededMemoryLimit());
        } finally {
            if ($oldLimit !== null) {
                $_ENV['WORKER_MEMORY_LIMIT_MB'] = $oldLimit;
            } else {
                unset($_ENV['WORKER_MEMORY_LIMIT_MB']);
            }
        }
    }

    /**
     * Test memory limit is exceeded when limit is set very low
     */
    public function testMemoryLimitExceeded(): void
    {
        $oldLimit = $_ENV['WORKER_MEMORY_LIMIT_MB'] ?? null;
        $_ENV['WORKER_MEMORY_LIMIT_MB'] = '1'; // 1 MB
        
        try {
            $handler = static function(Request $request): Response {
                return Response::json(['message' => 'success']);
            };
            $this->router->register('GET', '/memory-limit', $handler);
            $request = new Request('GET', '/memory-limit');
            
            $response = $this->kernel->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertTrue($this->kernel->hasExceededMemoryLimit());
        } finally {
            if ($oldLimit !== null) {
                $_ENV['WORKER_MEMORY_LIMIT_MB'] = $oldLimit;
            } else {
                unset($_ENV['WORKER_MEMORY_LIMIT_MB']);
            }
        }
    }

    // ── WC-317 RFC 8594 deprecation headers ─────────────────────────────────

    public function testDeprecatedRouteEmitsDeprecationHeader(): void
    {
        $this->router->register(
            'GET', '/api/v1/legacy', static fn(Request $req) => Response::json(['ok' => true]),
            null, null, null, ['deprecated' => true]
        );

        $response = $this->kernel->handle(new Request('GET', '/api/v1/legacy'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('true', $response->getHeaders()['deprecation'] ?? null);
    }

    public function testDeprecatedRouteWithSunsetEmitsBothHeaders(): void
    {
        $this->router->register(
            'GET', '/api/v1/old', static fn(Request $req) => Response::json(['ok' => true]),
            null, null, null, ['deprecated' => true, 'sunset' => 'Sat, 31 Dec 2025 00:00:00 GMT']
        );

        $response = $this->kernel->handle(new Request('GET', '/api/v1/old'));
        $headers = $response->getHeaders();

        $this->assertSame('true', $headers['deprecation'] ?? null);
        $this->assertSame('Sat, 31 Dec 2025 00:00:00 GMT', $headers['sunset'] ?? null);
    }

    public function testNonDeprecatedRouteHasNoDeprecationHeader(): void
    {
        $this->router->register(
            'GET', '/api/v1/current', static fn(Request $req) => Response::json(['ok' => true]),
            null, null, null, ['summary' => 'Active endpoint']
        );

        $response = $this->kernel->handle(new Request('GET', '/api/v1/current'));

        $this->assertArrayNotHasKey('deprecation', $response->getHeaders());
    }

    public function testRouteWithNoSchemaHasNoDeprecationHeader(): void
    {
        $this->router->register('GET', '/api/v1/bare', static fn(Request $req) => Response::json([]));

        $response = $this->kernel->handle(new Request('GET', '/api/v1/bare'));

        $this->assertArrayNotHasKey('deprecation', $response->getHeaders());
    }

    public function testDeprecatedRbacProtectedRouteEmitsDeprecationHeader(): void
    {
        $rbacMock = $this->createMock(RbacMiddleware::class);
        $rbacMock->method('handle')->willReturnCallback(
            static fn(Request $req, callable $next) => $next($req)
        );
        $kernel = new HttpKernel($this->router, $rbacMock);

        $this->router->register(
            'GET', '/api/v1/admin-legacy',
            static fn(Request $req) => Response::json(['protected' => true]),
            'admin', null, null, ['deprecated' => true]
        );

        $response = $kernel->handle(new Request('GET', '/api/v1/admin-legacy'));

        $this->assertSame('true', $response->getHeaders()['deprecation'] ?? null);
    }

    /**
     * Stress test validating memory stability under heavy requests
     */
    public function testMemoryLimitStressTest(): void
    {
        $oldLimit = $_ENV['WORKER_MEMORY_LIMIT_MB'] ?? null;
        $_ENV['WORKER_MEMORY_LIMIT_MB'] = '512'; // 512 MB, safe limit
        
        try {
            $handler = static function(Request $request): Response {
                // Perform some memory allocation and free it
                $data = array_fill(0, 10000, 'some test string data');
                unset($data);
                return Response::json(['message' => 'success']);
            };
            $this->router->register('GET', '/stress', $handler);
            
            $initialMemory = memory_get_usage();
            
            for ($i = 0; $i < 100; $i++) {
                $request = new Request('GET', '/stress');
                $response = $this->kernel->handle($request);
                $this->assertSame(200, $response->getStatusCode());
                $this->assertFalse($this->kernel->hasExceededMemoryLimit());
            }
            
            gc_collect_cycles();
            $finalMemory = memory_get_usage();
            
            // Check that memory has not grown excessively (e.g. less than 1MB growth after GC)
            $growth = $finalMemory - $initialMemory;
            $this->assertLessThan(1024 * 1024, $growth, "Memory leaked: " . ($growth / 1024) . " KB");
        } finally {
            if ($oldLimit !== null) {
                $_ENV['WORKER_MEMORY_LIMIT_MB'] = $oldLimit;
            } else {
                unset($_ENV['WORKER_MEMORY_LIMIT_MB']);
            }
        }
    }
}
