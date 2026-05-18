<?php

namespace Whity\Auth;

use Whity\Core\Request;
use Whity\Core\Response;
use PDO;
use PDOStatement;

/**
 * Authentication handler for login and token endpoints
 *
 * Handles login, token refresh, logout, and session validation endpoints.
 * Uses HTTP-only cookies for token storage and implements token revocation
 * for secure logout.
 */
class AuthHandler
{
    private PDO $db;
    private JwtParser $jwtParser;
    private TokenValidator $tokenValidator;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param JwtParser $jwtParser JWT parser for token creation
     * @param TokenValidator $tokenValidator Token validator for token validation (optional)
     */
    public function __construct(PDO $db, JwtParser $jwtParser, ?TokenValidator $tokenValidator = null)
    {
        $this->db = $db;
        $this->jwtParser = $jwtParser;
        $this->tokenValidator = $tokenValidator ?? new TokenValidator($jwtParser, $db);
    }

    /**
     * Handle login request (POST /api/login)
     *
     * Processes login requests by:
     * 1. Extracting email and password from request body
     * 2. Querying users table by email (globally unique)
     * 3. Verifying password using password_verify()
     * 4. Creating access and refresh JWT tokens
     * 5. Setting tokens in HTTP-only cookies
     * 6. Returning only user data (no token in JSON body)
     *
     * @param Request $request HTTP request with email and password in JSON body
     * @return Response HTTP response with user data (200) or error (401)
     */
    public function handle(Request $request, array $params = []): Response
    {
        // Parse request body
        $body = json_decode($request->getBody(), true);

        // Validate request has email and password
        if (!isset($body['email']) || !isset($body['password'])) {
            return Response::error('Email and password are required', 401);
        }

        $email = $body['email'];
        $password = $body['password'];

        // Query user by email (globally unique)
        $stmt = $this->db->prepare('
            SELECT id, email, password, role_id, tenant_id
            FROM users
            WHERE email = ?
        ');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // User not found
        if (!$user) {
            return Response::error('Invalid credentials', 401);
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            return Response::error('Invalid credentials', 401);
        }

        // Get role name
        $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = ?');
        $roleStmt->execute([$user['role_id']]);
        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$roleData) {
            return Response::error('Role not found', 500);
        }

        $roleName = $roleData['name'];

        // Create access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'email' => $user['email'],
            'role' => $roleName
        ], 900, 'access'); // 15 minutes

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'email' => $user['email'],
            'role' => $roleName
        ], 604800, 'refresh'); // 7 days

        // Set cookies
        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);

        // Return success response with user data only (no token in body)
        return Response::json([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $roleName
            ]
        ], 200);
    }

    /**
     * Handle GET /api/me - Get current user session
     *
     * Returns the current authenticated user's data by validating the
     * access token from cookies.
     *
     * @param Request $request HTTP request
     * @return Response User data on success (200) or 401 on auth failure
     */
    public function handleMe(Request $request, array $params = []): Response
    {
        // Validate access token
        $claims = $this->tokenValidator->validateAccessToken();

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Return user data from token claims
        return Response::json([
            'user' => [
                'id' => $claims['user_id'],
                'email' => $claims['email'],
                'role' => $claims['role']
            ]
        ], 200);
    }

    /**
     * Handle POST /api/auth/refresh - Refresh access token
     *
     * Issues a new access token when the refresh token is valid and not revoked.
     * Validates the refresh token from cookies and creates a new access token.
     *
     * @param Request $request HTTP request
     * @return Response Success response with new access token set in cookie (200) or 401 on failure
     */
    public function handleRefresh(Request $request, array $params = []): Response
    {
        // Validate refresh token
        $claims = $this->tokenValidator->validateRefreshToken();

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Create new access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $claims['user_id'],
            'tenant_id' => $claims['tenant_id'],
            'email' => $claims['email'],
            'role' => $claims['role']
        ], 900, 'access'); // 15 minutes

        // Set new access token cookie
        CookieManager::setAccessToken($accessToken, 900);

        // Return success response
        return Response::json([
            'status' => 'success'
        ], 200);
    }

    /**
     * Handle POST /api/auth/logout - Logout and revoke tokens
     *
     * Revokes the refresh token by adding its JTI to the revoked_tokens table,
     * and clears both access and refresh token cookies.
     * This endpoint is idempotent - returns 200 even if no refresh token is present.
     *
     * @param Request $request HTTP request
     * @return Response Logout confirmation (200) on success, even if no token
     */
    public function handleLogout(Request $request, array $params = []): Response
    {
        // Get refresh token from cookie (optional - logout is idempotent)
        $refreshToken = CookieManager::getRefreshToken();

        if ($refreshToken !== null) {
            // Parse the refresh token to get claims
            $claims = $this->jwtParser->parse($refreshToken);

            if ($claims !== null) {
                // Get the jti and exp from token claims
                $jti = $claims['jti'] ?? null;
                $exp = $claims['exp'] ?? null;

                if ($jti !== null && $exp !== null) {
                    // Insert into revoked_tokens table
                    try {
                        $stmt = $this->db->prepare('
                            INSERT INTO revoked_tokens (jti, expires_at)
                            VALUES (?, to_timestamp(?))
                        ');
                        $stmt->execute([$jti, $exp]);
                    } catch (\Exception) {
                        // Silently fail if revocation fails, still clear cookies
                    }
                }
            }
        }

        // Clear both cookies
        CookieManager::clearAccessToken();
        CookieManager::clearRefreshToken();

        // Return success response
        return Response::json([
            'status' => 'logged out'
        ], 200);
    }
}
