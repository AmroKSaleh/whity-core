<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Roles API Handler
 *
 * Handles CRUD operations for roles with permission management.
 */
class RolesApiHandler
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/roles - List all roles
     */
    public function list(Request $request): Response
    {
        try {
            $stmt = $this->db->prepare('
                SELECT r.id, r.name, r.description, r.created_at,
                       COUNT(rp.id) as permission_count
                FROM roles r
                LEFT JOIN role_permissions rp ON r.id = rp.role_id
                GROUP BY r.id
                ORDER BY r.created_at DESC
            ');
            $stmt->execute();
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalize keys to camelCase
            $roles = array_map(function ($role) {
                $role['permissionCount'] = (int)($role['permission_count'] ?? 0);
                unset($role['permission_count']);
                return $role;
            }, $roles);

            return Response::json(['data' => $roles], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch roles: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/roles/{id} - Get a single role with permissions
     */
    public function get(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            // Get role details
            $stmt = $this->db->prepare('
                SELECT id, name, description, created_at
                FROM roles
                WHERE id = ?
            ');
            $stmt->execute([$id]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                return Response::error('Role not found', 404);
            }

            // Get role permissions
            $permStmt = $this->db->prepare('
                SELECT p.id, p.name, p.description
                FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ?
                ORDER BY p.name
            ');
            $permStmt->execute([$id]);
            $permissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);

            $role['permissions'] = $permissions;

            return Response::json(['data' => $role], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/roles - Create a new role
     */
    public function create(Request $request): Response
    {
        try {
            $body = json_decode($request->getBody(), true);

            if (empty($body['name'])) {
                return Response::error('Role name is required', 400);
            }

            $name = $body['name'];
            $description = $body['description'] ?? '';
            $permissions = $body['permissions'] ?? [];

            // Check if role already exists
            $checkStmt = $this->db->prepare('SELECT id FROM roles WHERE name = ?');
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                return Response::error('Role already exists', 409);
            }

            // Dispatch filter hook before creating role
            $hookManager = app(HookManager::class);
            $roleData = $hookManager->dispatch('role.creating', [
                'name' => $name,
                'description' => $description,
                'permissions' => $permissions,
            ]);

            // Extract potentially modified data from hook response
            $name = $roleData['name'];
            $description = $roleData['description'];
            $permissions = $roleData['permissions'];

            // Insert role
            $stmt = $this->db->prepare('
                INSERT INTO roles (name, description, created_at)
                VALUES (?, ?, NOW())
            ');
            $stmt->execute([$name, $description]);
            $roleId = $this->db->lastInsertId();

            // Insert permissions if provided
            if (!empty($permissions)) {
                $permStmt = $this->db->prepare('
                    INSERT INTO role_permissions (role_id, permission_id, created_at)
                    VALUES (?, ?, NOW())
                ');
                foreach ($permissions as $permissionId) {
                    $permStmt->execute([$roleId, $permissionId]);
                }
            }

            // Dispatch synchronous hook after role is created
            $hookManager->dispatch('role.created', [
                'id' => (int)$roleId,
                'name' => $name,
                'description' => $description,
                'permissions' => $permissions,
            ]);

            // Dispatch asynchronous hook for background tasks
            $hookManager->dispatchAsync('role.created.async', [
                'id' => (int)$roleId,
                'name' => $name,
            ]);

            return Response::json([
                'data' => [
                    'id' => (int)$roleId,
                    'name' => $name,
                    'description' => $description,
                    'permissionCount' => count($permissions)
                ]
            ], 201);
        } catch (\Exception $e) {
            return Response::error('Failed to create role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/roles/{id} - Update a role
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $body = json_decode($request->getBody(), true);

            // Get current role
            $stmt = $this->db->prepare('SELECT * FROM roles WHERE id = ?');
            $stmt->execute([$id]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                return Response::error('Role not found', 404);
            }

            // Dispatch filter hook before updating role
            $hookManager = app(HookManager::class);
            $hookManager->dispatch('role.updating', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            // Update role fields
            $updates = [];
            $params_array = [];

            if (isset($body['name']) && $body['name'] !== $role['name']) {
                $checkStmt = $this->db->prepare('SELECT id FROM roles WHERE name = ? AND id != ?');
                $checkStmt->execute([$body['name'], $id]);
                if ($checkStmt->fetch()) {
                    return Response::error('Role name already exists', 409);
                }
                $updates[] = 'name = ?';
                $params_array[] = $body['name'];
            }

            if (isset($body['description'])) {
                $updates[] = 'description = ?';
                $params_array[] = $body['description'];
            }

            if (!empty($updates)) {
                $params_array[] = $id;
                $sql = 'UPDATE roles SET ' . implode(', ', $updates) . ' WHERE id = ?';
                $updateStmt = $this->db->prepare($sql);
                $updateStmt->execute($params_array);
            }

            // Update permissions if provided
            if (isset($body['permissions'])) {
                // Delete existing permissions
                $delStmt = $this->db->prepare('DELETE FROM role_permissions WHERE role_id = ?');
                $delStmt->execute([$id]);

                // Insert new permissions
                if (!empty($body['permissions'])) {
                    $permStmt = $this->db->prepare('
                        INSERT INTO role_permissions (role_id, permission_id, created_at)
                        VALUES (?, ?, NOW())
                    ');
                    foreach ($body['permissions'] as $permissionId) {
                        $permStmt->execute([$id, $permissionId]);
                    }
                }
            }

            // Dispatch synchronous hook after role is updated
            $hookManager->dispatch('role.updated', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Role updated']], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to update role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/roles/{id} - Delete a role
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $stmt = $this->db->prepare('SELECT id FROM roles WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return Response::error('Role not found', 404);
            }

            // Check if role is in use
            $checkStmt = $this->db->prepare('SELECT COUNT(*) as count FROM users WHERE role_id = ?');
            $checkStmt->execute([$id]);
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($result['count'] > 0) {
                return Response::error('Cannot delete role in use by ' . $result['count'] . ' user(s)', 409);
            }

            // Dispatch filter hook before deleting role
            $hookManager = app(HookManager::class);
            $hookManager->dispatch('role.deleting', [
                'id' => (int)$id,
            ]);

            // Delete permissions first
            $permStmt = $this->db->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $permStmt->execute([$id]);

            // Delete role
            $deleteStmt = $this->db->prepare('DELETE FROM roles WHERE id = ?');
            $deleteStmt->execute([$id]);

            // Dispatch synchronous hook after role is deleted
            $hookManager->dispatch('role.deleted', [
                'id' => (int)$id,
            ]);

            // Dispatch asynchronous hook for background tasks
            $hookManager->dispatchAsync('role.deleted.async', [
                'id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Role deleted']], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to delete role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/roles/{id}/permissions - Get permissions for a role
     */
    public function getPermissions(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $stmt = $this->db->prepare('
                SELECT p.id, p.name, p.description
                FROM permissions p
                JOIN role_permissions rp ON p.id = rp.permission_id
                WHERE rp.role_id = ?
                ORDER BY p.name
            ');
            $stmt->execute([$id]);
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['data' => $permissions], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch role permissions: ' . $e->getMessage(), 500);
        }
    }
}
