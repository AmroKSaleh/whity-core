<?php

namespace Whity\Auth;

use Whity\Core\Request;
use Whity\Core\Response;
use PDO;
use PDOStatement;

/**
 * Authentication handler for login endpoint
 *
 * Validates user credentials and returns JWT tokens for authenticated users.
 * Handles password verification using bcrypt and tenant isolation.
 */
class AuthHandler
{
    private PDO $db;
    private JwtParser $jwtParser;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param JwtParser $jwtParser JWT parser for token creation
     */
    public function __construct(PDO $db, JwtParser $jwtParser)
    {
        $this->db = $db;
        $this->jwtParser = $jwtParser;
    }

    /**
     * Handle login request
     *
     * Processes login requests by:
     * 1. Extracting email and password from request body
     * 2. Querying users table with tenant_id = 1
     * 3. Verifying password using password_verify()
     * 4. Creating JWT token with user claims
     * 5. Returning token and user data on success or error on failure
     *
     * @param Request $request HTTP request with email and password in JSON body
     * @return Response HTTP response with token/user (200) or error (401)
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

        // Query user by email and tenant_id
        $stmt = $this->db->prepare('
            SELECT id, email, password, role_id
            FROM users
            WHERE tenant_id = ? AND email = ?
        ');
        $stmt->execute([1, $email]);
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

        // Create JWT token
        $token = $this->jwtParser->create([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'role' => $roleName
        ], 86400); // 24 hours expiration

        // Return success response
        return Response::json([
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $roleName
            ]
        ], 200);
    }
}
