<?php

namespace Whity\Http\Middleware;

use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Tenant isolation enforcement middleware
 *
 * Extracts tenant_id from JWT payload and sets it globally via TenantContext.
 * This middleware runs early in the request pipeline, before route handlers,
 * ensuring strict tenant isolation throughout the request lifecycle.
 */
class EnforceTenantIsolation
{
    private JwtParser $jwtParser;

    /**
     * Constructor
     *
     * @param JwtParser $jwtParser JWT token parser
     */
    public function __construct(JwtParser $jwtParser)
    {
        $this->jwtParser = $jwtParser;
    }

    /**
     * Handle request with tenant isolation enforcement
     *
     * Extracts tenant_id from JWT payload, validates it exists, and sets the
     * global TenantContext which becomes locked for the request lifetime.
     *
     * @param Request $request The incoming HTTP request
     * @param callable $next The next middleware/handler in the pipeline
     * @return Response HTTP response
     */
    public function handle(Request $request, callable $next): Response
    {
        // Skip tenant isolation for public endpoints (no JWT required)
        if ($this->isPublicRoute($request->getPath())) {
            return $next($request);
        }

        // Extract Authorization header
        $authHeader = $request->getHeader('Authorization');

        // Check if Authorization header is present and properly formatted
        if ($authHeader === null || !$this->isValidBearerFormat($authHeader)) {
            return Response::error('Missing or invalid Authorization header', 401);
        }

        // Extract token from "Bearer <token>" format
        $token = $this->extractToken($authHeader);

        // Parse and validate JWT
        $payload = $this->jwtParser->parse($token);
        if ($payload === null) {
            return Response::error('Invalid or expired token', 401);
        }

        // Validate required fields in payload
        if (!isset($payload['user_id']) || !isset($payload['tenant_id'])) {
            return Response::error('Missing tenant_id in token payload', 401);
        }

        // Set tenant context (this locks it for the request lifetime)
        TenantContext::setTenantId($payload['tenant_id']);

        // Attach user data to request
        $request->user = (object) $payload;

        // Call next handler
        return $next($request);
    }

    /**
     * Check if Authorization header has valid Bearer format
     *
     * @param string $authHeader The Authorization header value
     * @return bool True if format is "Bearer <token>", false otherwise
     */
    private function isValidBearerFormat(string $authHeader): bool
    {
        return preg_match('/^Bearer\s+\S+$/', $authHeader) === 1;
    }

    /**
     * Extract token from "Bearer <token>" format
     *
     * @param string $authHeader The Authorization header value
     * @return string The extracted token
     */
    private function extractToken(string $authHeader): string
    {
        // Remove "Bearer " prefix and return the token
        return substr($authHeader, 7);
    }

    /**
     * Check if a route is public (doesn't require tenant context)
     *
     * @param string $path The request path
     * @return bool True if the route is public
     */
    private function isPublicRoute(string $path): bool
    {
        // Public routes that don't require JWT/tenant context
        $publicRoutes = [
            '/api/login',
        ];

        return in_array($path, $publicRoutes, true);
    }
}
