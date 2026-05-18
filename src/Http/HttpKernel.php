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
 * and dispatches requests to appropriate handlers. Handles RBAC authorization through
 * the RbacMiddleware when routes require specific roles.
 */
class HttpKernel
{
    private Router $router;
    private RbacMiddleware $rbacMiddleware;

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
     * Handle incoming HTTP request
     *
     * Attempts to match the request to a registered route. If a match is found,
     * applies RBAC middleware if the route requires a specific role, then calls
     * the route handler. Returns a 404 error response if no route matches.
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
        } finally {
            // Clean up tenant context after request completes
            // This ensures tenant data doesn't bleed across requests in persistent workers
            TenantContext::reset();
        }
    }
}
