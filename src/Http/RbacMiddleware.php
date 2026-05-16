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
     * Extracts and validates JWT token from Authorization header, checks user role if required,
     * and attaches user data to the request object before passing to next handler.
     *
     * @param Request $request The incoming HTTP request
     * @param callable $next The next middleware/handler in the pipeline
     * @param ?string $requiredRole Optional role name to enforce authorization
     * @return Response HTTP response
     */
    public function handle(Request $request, callable $next, ?string $requiredRole = null): Response
    {
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

        // Validate payload structure
        if (!isset($payload['user_id'])) {
            return Response::error('Invalid token payload', 401);
        }

        // Check role if required
        if ($requiredRole !== null) {
            $userId = $payload['user_id'];

            // Ensure user_id is an integer
            if (!is_int($userId)) {
                return Response::error('Invalid token payload', 401);
            }

            // Check if user has required role
            if (!$this->roleChecker->hasRole($userId, $requiredRole)) {
                return Response::error('Insufficient permissions', 403);
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
