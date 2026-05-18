<?php

namespace Whity\Http;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * HTTP Kernel for handling request dispatching
 *
 * The main entry point for HTTP request processing. Matches routes, applies middleware,
 * and dispatches requests to appropriate handlers. Supports a pipeline of middleware
 * that are executed in order before routing and authorization checks.
 *
 * Typical middleware order:
 * 1. EnforceTenantIsolation - Sets tenant context from JWT
 * 2. RbacMiddleware - Validates JWT and enforces role-based access control
 * 3. Route handlers - Application logic
 */
class HttpKernel
{
    private Router $router;
    private RbacMiddleware $rbacMiddleware;
    /** @var array<object> */
    private array $middleware = [];

    /**
     * Constructor
     *
     * @param Router $router Route matcher and manager
     * @param RbacMiddleware $rbacMiddleware RBAC middleware for authorization
     */
    public function __construct(Router $router, RbacMiddleware $rbacMiddleware)
    {
        $this->router = $router;
        $this->rbacMiddleware = $rbacMiddleware;
    }

    /**
     * Register middleware in the request pipeline
     *
     * Middleware is executed in the order it is registered.
     * Recommended order:
     * 1. EnforceTenantIsolation - Sets tenant context from JWT
     * 2. RbacMiddleware - Validates JWT and enforces authorization
     *
     * @param object $middleware Middleware instance with handle(Request, callable): Response
     * @return self For method chaining
     */
    public function use(object $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Handle incoming HTTP request
     *
     * Executes the complete request pipeline:
     * 1. Applies all registered middleware in order
     * 2. Matches request to route
     * 3. Applies RBAC middleware if route requires a role
     * 4. Calls route handler
     * 5. Cleans up TenantContext
     *
     * Wraps the entire request lifecycle in a try-finally block to ensure
     * TenantContext is cleaned up even if an exception occurs, preventing
     * tenant data from bleeding across requests in persistent workers.
     *
     * @param Request $request The incoming HTTP request
     * @return Response HTTP response
     */
    public function handle(Request $request): Response
    {
        try {
            // Build the middleware pipeline
            // Start with the core request handler that matches routes and applies RBAC
            $pipeline = $this->buildCorePipeline();

            // Apply all registered middleware (in order, wrapping the core pipeline)
            foreach (array_reverse($this->middleware) as $middleware) {
                $pipeline = $this->wrapWithMiddleware($middleware, $pipeline);
            }

            // Execute the complete pipeline
            return $pipeline($request);
        } finally {
            // Clean up tenant context after request completes
            // This ensures tenant data doesn't bleed across requests in persistent workers
            TenantContext::reset();
        }
    }

    /**
     * Build the core request handler (routes + RBAC)
     *
     * This is the base of the middleware pipeline.
     *
     * @return callable Handler that takes Request and returns Response
     */
    private function buildCorePipeline(): callable
    {
        return function(Request $request): Response {
            // Attempt to match the request to a registered route
            $matchedRoute = $this->router->match($request);

            // Return 404 if no route matches
            if ($matchedRoute === null) {
                return Response::error('Not Found', 404);
            }

            // Extract handler, params, and requiredRole from match result
            $handler = $matchedRoute['handler'];
            $params = $matchedRoute['params'] ?? [];
            $requiredRole = $matchedRoute['requiredRole'];

            // If a role is required, apply RBAC middleware
            if ($requiredRole !== null) {
                // Create a closure that wraps the handler, passing params
                $next = fn(Request $req) => $handler($req, $params);

                // Pass through RBAC middleware
                return $this->rbacMiddleware->handle($request, $next, $requiredRole);
            }

            // Otherwise, call handler directly with params
            return $handler($request, $params);
        };
    }

    /**
     * Wrap a pipeline with middleware
     *
     * Creates a new pipeline that executes the middleware before the wrapped pipeline.
     *
     * @param object $middleware Middleware instance
     * @param callable $next The next handler in the pipeline
     * @return callable The wrapped pipeline
     */
    private function wrapWithMiddleware(object $middleware, callable $next): callable
    {
        return function(Request $request) use ($middleware, $next): Response {
            return $middleware->handle($request, $next);
        };
    }
}
