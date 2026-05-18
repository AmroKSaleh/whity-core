<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Organizational Units API Handler
 *
 * Handles CRUD operations for organizational units (OUs) with parent-child
 * hierarchies, role assignments, and strict tenant isolation.
 */
class OusApiHandler
{
    private PDO $db;
    private HookManager $hookManager;

    public function __construct(PDO $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * GET /api/ous - List all OUs for current tenant
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();

            // System users (tenant_id=0) can see all OUs from all tenants
            if ($tenantId === 0) {
                $stmt = $this->db->prepare('
                    SELECT id, tenant_id, parent_id, name, slug, description, created_at
                    FROM organizational_units
                    ORDER BY tenant_id, id
                ');
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare('
                    SELECT id, tenant_id, parent_id, name, slug, description, created_at
                    FROM organizational_units
                    WHERE tenant_id = ?
                    ORDER BY id
                ');
                $stmt->execute([$tenantId]);
            }

            $ous = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['data' => $ous], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch organizational units: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/ous - Create a new organizational unit
     */
    public function create(Request $request): Response
    {
        try {
            $body = json_decode($request->getBody(), true);
            $tenantId = TenantContext::getTenantId();

            // Validation: name is required
            if (empty($body['name'])) {
                return Response::error('Organizational unit name is required', 400);
            }

            $name = $body['name'];
            $parentId = $body['parent_id'] ?? null;
            $description = $body['description'] ?? '';
            $slug = $this->generateSlug($name);

            // Parent validation: if parent_id supplied, it must exist and belong to current tenant
            if ($parentId !== null) {
                $checkStmt = $this->db->prepare(
                    'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
                );
                $checkStmt->execute([$parentId, $tenantId]);
                if (!$checkStmt->fetch()) {
                    return Response::error('Parent organizational unit does not belong to current tenant', 403);
                }
            }

            // Check if name already exists in tenant
            $checkNameStmt = $this->db->prepare(
                'SELECT id FROM organizational_units WHERE name = ? AND tenant_id = ?'
            );
            $checkNameStmt->execute([$name, $tenantId]);
            if ($checkNameStmt->fetch()) {
                return Response::error('Organizational unit name already exists in tenant', 409);
            }

            // Check if slug already exists in tenant
            $checkSlugStmt = $this->db->prepare(
                'SELECT id FROM organizational_units WHERE slug = ? AND tenant_id = ?'
            );
            $checkSlugStmt->execute([$slug, $tenantId]);
            if ($checkSlugStmt->fetch()) {
                return Response::error('Slug already exists in tenant', 409);
            }

            // Dispatch filter hook before creating OU
            $ouData = $this->hookManager->dispatch('ou.creating', [
                'name' => $name,
                'parent_id' => $parentId,
                'description' => $description,
                'slug' => $slug,
            ]);

            // Extract potentially modified data from hook response
            $name = $ouData['name'];
            $parentId = $ouData['parent_id'] ?? $parentId;
            $description = $ouData['description'] ?? $description;
            $slug = $ouData['slug'] ?? $slug;

            // Insert OU
            $stmt = $this->db->prepare('
                INSERT INTO organizational_units (tenant_id, parent_id, name, slug, description, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$tenantId, $parentId, $name, $slug, $description]);
            $ouId = $this->db->lastInsertId();

            // Dispatch synchronous hook after OU is created
            $this->hookManager->dispatch('ou.created', [
                'id' => (int)$ouId,
                'tenant_id' => $tenantId,
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $parentId,
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('ou.created.async', [
                'id' => (int)$ouId,
                'tenant_id' => $tenantId,
                'name' => $name,
            ]);

            return Response::json([
                'data' => [
                    'id' => (int)$ouId,
                    'tenant_id' => $tenantId,
                    'parent_id' => $parentId,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ], 201);
        } catch (\Exception $e) {
            return Response::error('Failed to create organizational unit: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/ous/{id} - Get a specific organizational unit with children
     */
    public function get(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Get OU
            $stmt = $this->db->prepare('
                SELECT id, tenant_id, parent_id, name, slug, description, created_at
                FROM organizational_units
                WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$id, $tenantId]);
            $ou = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ou) {
                return Response::error('Organizational unit not found', 404);
            }

            // Get children
            $childrenStmt = $this->db->prepare('
                SELECT id
                FROM organizational_units
                WHERE parent_id = ? AND tenant_id = ?
            ');
            $childrenStmt->execute([$id, $tenantId]);
            $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);
            $ou['children'] = $children;

            return Response::json(['data' => $ou], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch organizational unit: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/ous/{id} - Update an organizational unit
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            $body = json_decode($request->getBody(), true);

            // Get OU to update
            $stmt = $this->db->prepare('
                SELECT * FROM organizational_units WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$id, $tenantId]);
            $ou = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ou) {
                return Response::error('Organizational unit not found or does not belong to current tenant', 403);
            }

            // Dispatch filter hook before updating OU
            $this->hookManager->dispatch('ou.updating', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            $updates = [];
            $params_array = [];

            // Handle name update - regenerate slug when name changes
            if (isset($body['name']) && $body['name'] !== $ou['name']) {
                $updates[] = 'name = ?';
                $params_array[] = $body['name'];

                // Regenerate slug when name changes
                $newSlug = $this->generateSlug($body['name']);
                $updates[] = 'slug = ?';
                $params_array[] = $newSlug;
            }

            // Handle description update
            if (isset($body['description']) && $body['description'] !== $ou['description']) {
                $updates[] = 'description = ?';
                $params_array[] = $body['description'];
            }

            // Handle parent_id update
            if (isset($body['parent_id']) && $body['parent_id'] !== $ou['parent_id']) {
                $newParentId = $body['parent_id'];

                // Validate parent exists in same tenant
                if ($newParentId !== null) {
                    $parentStmt = $this->db->prepare(
                        'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
                    );
                    $parentStmt->execute([$newParentId, $tenantId]);
                    if (!$parentStmt->fetch()) {
                        return Response::error('Parent organizational unit does not belong to current tenant', 403);
                    }

                    // Detect cycle: if new parent is this OU or a descendant, reject
                    if ($this->detectCycle($this->db, (int)$id, (int)$newParentId, $tenantId)) {
                        return Response::error('Setting this parent would create a cycle in the hierarchy', 400);
                    }
                }

                $updates[] = 'parent_id = ?';
                $params_array[] = $newParentId;
            }

            if (!empty($updates)) {
                $params_array[] = $id;
                $sql = 'UPDATE organizational_units SET ' . implode(', ', $updates) . ' WHERE id = ?';
                $updateStmt = $this->db->prepare($sql);
                $updateStmt->execute($params_array);
            }

            // Dispatch synchronous hook after OU is updated
            $this->hookManager->dispatch('ou.updated', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('ou.updated.async', [
                'id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Organizational unit updated']], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to update organizational unit: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/ous/{id} - Delete an organizational unit
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Get OU
            $stmt = $this->db->prepare('
                SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$id, $tenantId]);
            if (!$stmt->fetch()) {
                return Response::error('Organizational unit not found or does not belong to current tenant', 403);
            }

            // Check if OU has children
            $childrenStmt = $this->db->prepare('
                SELECT COUNT(*) FROM organizational_units WHERE parent_id = ? AND tenant_id = ?
            ');
            $childrenStmt->execute([$id, $tenantId]);
            $childCount = $childrenStmt->fetchColumn();
            if ($childCount > 0) {
                return Response::error(
                    'Cannot delete organizational unit with ' . $childCount . ' child organizational unit(s)',
                    409
                );
            }

            // Check if OU has assigned users
            $usersStmt = $this->db->prepare('
                SELECT COUNT(*) FROM users WHERE ou_id = ? AND tenant_id = ?
            ');
            $usersStmt->execute([$id, $tenantId]);
            $userCount = $usersStmt->fetchColumn();
            if ($userCount > 0) {
                return Response::error(
                    'Cannot delete organizational unit with ' . $userCount . ' assigned user(s)',
                    409
                );
            }

            // Dispatch filter hook before deleting OU
            $this->hookManager->dispatch('ou.deleting', [
                'id' => (int)$id,
            ]);

            // Delete OU
            $deleteStmt = $this->db->prepare('DELETE FROM organizational_units WHERE id = ? AND tenant_id = ?');
            $deleteStmt->execute([$id, $tenantId]);

            // Dispatch synchronous hook after OU is deleted
            $this->hookManager->dispatch('ou.deleted', [
                'id' => (int)$id,
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('ou.deleted.async', [
                'id' => (int)$id,
            ]);

            return Response::json([], 204);
        } catch (\Exception $e) {
            return Response::error('Failed to delete organizational unit: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/ous/{id}/roles - Assign a role to an organizational unit
     */
    public function assignRole(Request $request, array $params): Response
    {
        try {
            $ouId = $params['id'] ?? null;
            if (!$ouId) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            $body = json_decode($request->getBody(), true);

            // Validation: role_id is required
            if (empty($body['role_id'])) {
                return Response::error('Role ID is required', 400);
            }

            $roleId = $body['role_id'];

            // Get OU - return 404 if not found in current tenant
            $stmt = $this->db->prepare('
                SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$ouId, $tenantId]);
            if (!$stmt->fetch()) {
                return Response::error('Organizational unit not found', 404);
            }

            // Insert role assignment
            try {
                $assignStmt = $this->db->prepare('
                    INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
                    VALUES (?, ?, ?, NOW())
                ');
                $assignStmt->execute([$tenantId, $ouId, $roleId]);
                $assignmentId = $this->db->lastInsertId();

                // Dispatch hook after role assignment
                $this->hookManager->dispatch('ou.role_assigned', [
                    'id' => (int)$assignmentId,
                    'ou_id' => (int)$ouId,
                    'role_id' => (int)$roleId,
                    'tenant_id' => $tenantId,
                ]);

                return Response::json([
                    'data' => [
                        'id' => (int)$assignmentId,
                        'ou_id' => (int)$ouId,
                        'role_id' => (int)$roleId,
                        'tenant_id' => $tenantId,
                    ]
                ], 201);
            } catch (\PDOException $e) {
                // Check if it's a constraint violation (duplicate assignment or role not found)
                if (stripos($e->getMessage(), 'constraint') !== false || stripos($e->getMessage(), 'unique') !== false) {
                    return Response::error('Role assignment already exists or role does not exist', 409);
                }
                throw $e;
            }
        } catch (\Exception $e) {
            return Response::error('Failed to assign role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/ous/{ouId}/roles/{roleId} - Remove a role from an organizational unit
     */
    public function removeRole(Request $request, array $params): Response
    {
        try {
            $ouId = $params['ouId'] ?? null;
            $roleId = $params['roleId'] ?? null;

            if (!$ouId || !$roleId) {
                return Response::error('Organizational unit ID and role ID are required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Get assignment
            $stmt = $this->db->prepare('
                SELECT id FROM ou_role_assignments
                WHERE ou_id = ? AND role_id = ? AND tenant_id = ?
            ');
            $stmt->execute([$ouId, $roleId, $tenantId]);
            if (!$stmt->fetch()) {
                return Response::error('Role assignment not found', 404);
            }

            // Delete assignment
            $deleteStmt = $this->db->prepare('
                DELETE FROM ou_role_assignments
                WHERE ou_id = ? AND role_id = ? AND tenant_id = ?
            ');
            $deleteStmt->execute([$ouId, $roleId, $tenantId]);

            // Dispatch hook after role removal
            $this->hookManager->dispatch('ou.role_removed', [
                'ou_id' => (int)$ouId,
                'role_id' => (int)$roleId,
                'tenant_id' => $tenantId,
            ]);

            return Response::json([], 204);
        } catch (\Exception $e) {
            return Response::error('Failed to remove role: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Generate a URL-friendly slug from a string
     */
    private function generateSlug(string $text): string
    {
        // Convert to lowercase
        $slug = strtolower($text);
        // Replace spaces with hyphens
        $slug = str_replace(' ', '-', $slug);
        // Remove special characters
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        // Replace multiple hyphens with single hyphen
        $slug = preg_replace('/-+/', '-', $slug);
        // Trim hyphens from start and end
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Detect if setting a parent would create a cycle in the hierarchy
     *
     * Walks up the parent chain starting from newParentId. If the OU being updated
     * is encountered in the chain, returns true (cycle detected). Otherwise returns false.
     *
     * @param PDO $db Database connection
     * @param int $ouId The OU being updated
     * @param int $newParentId The proposed new parent
     * @param int $tenantId The current tenant ID
     * @return bool True if cycle would be created, false otherwise
     */
    private function detectCycle(PDO $db, int $ouId, int $newParentId, int $tenantId): bool
    {
        $currentParentId = $newParentId;
        $visited = [];

        while ($currentParentId !== null) {
            // Prevent infinite loops
            if (isset($visited[$currentParentId])) {
                return true;
            }
            $visited[$currentParentId] = true;

            // If we encounter the OU being updated in the parent chain, cycle detected
            if ($currentParentId === $ouId) {
                return true;
            }

            // Get parent of current parent
            $stmt = $db->prepare('
                SELECT parent_id FROM organizational_units
                WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$currentParentId, $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // Parent not found, stop traversal
                break;
            }

            $currentParentId = $row['parent_id'];
        }

        return false;
    }
}
