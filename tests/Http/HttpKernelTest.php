<?php

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\HttpKernel;
use Whity\Http\RbacMiddleware;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;

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
        $this->assertArrayHasKey('X-Custom-Header', $headers);
        $this->assertSame('custom-value', $headers['X-Custom-Header']);
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
}
