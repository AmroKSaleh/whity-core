<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use PDO;

/**
 * Users API Handler
 *
 * Handles CRUD operations for users with full validation,
 * password hashing, and error handling.
 */
class UsersApiHandler
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/users - List all users
     */
    public function list(Request $request): Response
    {
        try {
            $stmt = $this->db->prepare('
                SELECT u.id, u.email, u.password, u.created_at, u.tenant_id, r.name as role
                FROM users u
                JOIN roles r ON u.role_id = r.id
                ORDER BY u.created_at DESC
            ');
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Remove password from response
            $users = array_map(function ($user) {
                unset($user['password']);
                return $user;
            }, $users);

            return Response::json(['data' => $users], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch users: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/users - Create a new user
     */
    public function create(Request $request): Response
    {
        try {
            $body = json_decode($request->getBody(), true);

            // Validation
            if (empty($body['email']) || empty($body['password'])) {
                return Response::error('Email and password are required', 400);
            }

            if (strlen($body['password']) < 6) {
                return Response::error('Password must be at least 6 characters', 400);
            }

            if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::error('Invalid email format', 400);
            }

            $name = $body['name'] ?? '';
            $email = $body['email'];
            $password = password_hash($body['password'], PASSWORD_BCRYPT);
            $roleId = $body['role_id'] ?? 2; // Default to 'user' role
            $tenantId = $body['tenant_id'] ?? 1; // Default to tenant 1

            // Check if email already exists
            $checkStmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND tenant_id = ?');
            $checkStmt->execute([$email, $tenantId]);
            if ($checkStmt->fetch()) {
                return Response::error('Email already exists for this tenant', 409);
            }

            // Insert new user
            $stmt = $this->db->prepare('
                INSERT INTO users (email, password, role_id, tenant_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$email, $password, $roleId, $tenantId]);

            $userId = $this->db->lastInsertId();

            return Response::json([
                'data' => [
                    'id' => (int)$userId,
                    'email' => $email,
                    'role_id' => (int)$roleId,
                    'tenant_id' => (int)$tenantId
                ]
            ], 201);
        } catch (\Exception $e) {
            return Response::error('Failed to create user: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/users/{id} - Update a user
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('User ID is required', 400);
            }

            $body = json_decode($request->getBody(), true);

            // Get current user
            $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // Prepare update fields
            $updates = [];
            $params_array = [];

            if (isset($body['email']) && $body['email'] !== $user['email']) {
                // Check if new email already exists
                $checkStmt = $this->db->prepare(
                    'SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id != ?'
                );
                $checkStmt->execute([$body['email'], $user['tenant_id'], $id]);
                if ($checkStmt->fetch()) {
                    return Response::error('Email already exists for this tenant', 409);
                }
                $updates[] = 'email = ?';
                $params_array[] = $body['email'];
            }

            if (isset($body['password']) && !empty($body['password'])) {
                if (strlen($body['password']) < 6) {
                    return Response::error('Password must be at least 6 characters', 400);
                }
                $updates[] = 'password = ?';
                $params_array[] = password_hash($body['password'], PASSWORD_BCRYPT);
            }

            if (isset($body['role_id'])) {
                $updates[] = 'role_id = ?';
                $params_array[] = $body['role_id'];
            }

            if (empty($updates)) {
                return Response::json(['data' => ['id' => (int)$id, 'message' => 'No updates provided']], 200);
            }

            $params_array[] = $id;
            $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params_array);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'User updated']], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to update user: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/users/{id} - Delete a user
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('User ID is required', 400);
            }

            $stmt = $this->db->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return Response::error('User not found', 404);
            }

            $deleteStmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
            $deleteStmt->execute([$id]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'User deleted']], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to delete user: ' . $e->getMessage(), 500);
        }
    }
}
