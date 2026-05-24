<?php

namespace Whity\Http;

use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * RBAC Middleware for enforcing role-based access control
 *
 * Validates JWT tokens from the Authorization header, parses their payload,
 * and checks user roles if required. Sets the user object on the request
 * for use by downstream handlers.
 */
class RbacMiddleware
{
    private JwtParser $jwtParser;
    private RoleChecker $roleChecker;

    /**
     * Constructor
     *
     * @param JwtParser $jwtParser JWT token parser
     * @param RoleChecker $roleChecker Role verification service
     */
    public function __construct(JwtParser $jwtParser, RoleChecker $roleChecker)
    {
        $this->jwtParser = $jwtParser;
        $this->roleChecker = $roleChecker;
    }

    /**
     * Handle request with RBAC validation
     *
     * Extracts and validates JWT token from Authorization header, checks user role and/or permission if required,
     * and attaches user data to the request object before passing to next handler.
     *
     * @param Request $request The incoming HTTP request
     * @param callable $next The next middleware/handler in the pipeline
     * @param ?string $requiredRole Optional role name to enforce authorization
     * @param ?string $requiredPermission Optional permission string to enforce authorization
     * @return Response HTTP response
     */
    public function handle(Request $request, callable $next, ?string $requiredRole = null, ?string $requiredPermission = null): Response
    {
        // If no role or permission required, skip RBAC validation
        if ($requiredRole === null && $requiredPermission === null) {
            return $next($request);
        }

        // Extract token from Authorization header or access_token cookie
        $token = null;
        $authHeader = $request->getHeader('Authorization');

        if ($authHeader !== null && $this->isValidBearerFormat($authHeader)) {
            // Extract token from "Bearer <token>" format
            $token = $this->extractToken($authHeader);
        } else {
            // Try to get token from access_token cookie via Cookie header
            $cookieHeader = $request->getHeader('Cookie');
            if ($cookieHeader !== null) {
                // Parse cookie header: "name1=value1; name2=value2"
                $cookies = [];
                foreach (explode(';', $cookieHeader) as $cookie) {
                    $parts = explode('=', trim($cookie), 2);
                    if (count($parts) === 2) {
                        $cookies[$parts[0]] = $parts[1];
                    }
                }
                $token = $cookies['access_token'] ?? null;
            }
        }

        // Check if token is present
        if ($token === null) {
            return Response::error('Missing or invalid Authorization header', 401);
        }

        // Parse and validate JWT
        $payload = $this->jwtParser->parse($token);
        if ($payload === null) {
            return Response::error('Invalid or expired token', 401);
        }

        // Validate payload structure
        if (!isset($payload['user_id'])) {
            return Response::error('Invalid token payload', 401);
        }

        // Check role or permission if required
        if ($requiredRole !== null || $requiredPermission !== null) {
            $userId = $payload['user_id'];

            // Ensure user_id is an integer
            if (!is_int($userId)) {
                return Response::error('Invalid token payload', 401);
            }

            // Check if token includes a role (for system/CLI tokens)
            if (isset($payload['role'])) {
                $tokenRole = $payload['role'];
                if ($requiredRole !== null && $tokenRole !== $requiredRole) {
                    return Response::error('Insufficient permissions', 403);
                }
                // For token-based roles, skip permission check (CLI admin has all permissions)
                if ($requiredPermission !== null && $tokenRole !== 'admin') {
                    return Response::error('Insufficient permissions', 403);
                }
            } else {
                // Check if user has required role
                if ($requiredRole !== null) {
                    if (!$this->roleChecker->hasRole($userId, $requiredRole)) {
                        return Response::error('Insufficient permissions', 403);
                    }
                }

                // Check if user has required permission
                if ($requiredPermission !== null) {
                    if (!$this->roleChecker->hasPermission($userId, $requiredPermission)) {
                        return Response::error('Insufficient permissions', 403);
                    }
                }
            }
        }

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
}
