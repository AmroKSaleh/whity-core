<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Api\Exception\SystemTenantProtectedException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Tenants API Handler
 *
 * Handles CRUD operations for tenants with slug management.
 *
 * Authorization model:
 * - System users (tenant_id=0) have administrative authority over the whole
 *   multi-tenant platform and may update or delete any tenant.
 * - Regular tenant users may only manage their own tenant.
 * - The system tenant itself (id=0) is protected and can never be deleted.
 */
class TenantsApiHandler
{
    /**
     * The reserved identifier for the system tenant.
     *
     * The system tenant anchors platform-wide infrastructure and must never be
     * deleted; system users (tenant_id=0) act with cross-tenant authority.
     */
    private const SYSTEM_TENANT_ID = 0;

    private PDO $db;
    private HookManager $hookManager;

    public function __construct(PDO $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * Determine whether the current request is made by a system user.
     *
     * System users belong to the system tenant (tenant_id=0) and are granted
     * cross-tenant administrative authority.
     *
     * @return bool True when the current tenant context is the system tenant.
     */
    private function isSystemUser(): bool
    {
        return TenantContext::getTenantId() === self::SYSTEM_TENANT_ID;
    }

    /**
     * Authorize a write (update/delete) on the given tenant for the caller.
     *
     * System users may act on any tenant. Regular users may only act on their
     * own tenant.
     *
     * @param int $targetTenantId The tenant being modified.
     * @return bool True when the caller is authorized.
     */
    private function canManageTenant(int $targetTenantId): bool
    {
        if ($this->isSystemUser()) {
            return true;
        }

        return $targetTenantId === TenantContext::getTenantId();
    }

    /**
     * GET /api/tenants - List current tenant or all tenants if system user
     */
    public function list(Request $request): Response
    {
        try {
            $currentTenantId = TenantContext::getTenantId();

            // System users (tenant_id=0) can see all tenants
            $isSystemUser = $currentTenantId === 0;

            if ($isSystemUser) {
                // System user: return all tenants except system tenant itself
                $stmt = $this->db->prepare('
                    SELECT t.id, t.name, t.slug, t.created_at,
                           COUNT(u.id) as userCount
                    FROM tenants t
                    LEFT JOIN users u ON t.id = u.tenant_id
                    WHERE t.id != 0
                    GROUP BY t.id
                    ORDER BY t.created_at DESC
                ');
                $stmt->execute();
            } else {
                // Regular user: return only their tenant
                $stmt = $this->db->prepare('
                    SELECT t.id, t.name, t.slug, t.created_at,
                           COUNT(u.id) as userCount
                    FROM tenants t
                    LEFT JOIN users u ON t.id = u.tenant_id
                    WHERE t.id = ?
                    GROUP BY t.id
                ');
                $stmt->execute([$currentTenantId]);
            }

            $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tenants) && !$isSystemUser) {
                return Response::error('Tenant not found', 404);
            }

            return Response::json(['data' => $tenants], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/tenants - Create a new tenant
     */
    public function create(Request $request): Response
    {
        try {
            $body = json_decode($request->getBody(), true);

            if (empty($body['name'])) {
                return Response::error('Tenant name is required', 400);
            }

            $name = $body['name'];
            $slug = $body['slug'] ?? $this->generateSlug($name);

            // Validate slug format
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                return Response::error('Slug must contain only lowercase letters, numbers, and hyphens', 400);
            }

            // Check if name already exists
            $checkStmt = $this->db->prepare('SELECT id FROM tenants WHERE name = ?');
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                return Response::error('Tenant name already exists', 409);
            }

            // Check if slug already exists
            $checkSlugStmt = $this->db->prepare('SELECT id FROM tenants WHERE slug = ?');
            $checkSlugStmt->execute([$slug]);
            if ($checkSlugStmt->fetch()) {
                return Response::error('Slug already exists', 409);
            }

            // Dispatch filter hook before creating tenant
            $tenantData = $this->hookManager->dispatch('tenant.creating', [
                'name' => $name,
                'slug' => $slug,
            ]);

            // Extract potentially modified data from hook response
            $name = $tenantData['name'];
            $slug = $tenantData['slug'];

            // Insert tenant
            $stmt = $this->db->prepare('
                INSERT INTO tenants (name, slug, created_at)
                VALUES (?, ?, NOW())
            ');
            $stmt->execute([$name, $slug]);
            $tenantId = $this->db->lastInsertId();

            // Dispatch synchronous hook after tenant is created
            $this->hookManager->dispatch('tenant.created', [
                'id' => (int)$tenantId,
                'name' => $name,
                'slug' => $slug,
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('tenant.created.async', [
                'id' => (int)$tenantId,
                'name' => $name,
            ]);

            return Response::json([
                'data' => [
                    'id' => (int)$tenantId,
                    'name' => $name,
                    'slug' => $slug,
                    'userCount' => 0,
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ], 201);
        } catch (\Exception $e) {
            return Response::error('Failed to create tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * PATCH /api/tenants/{id} - Update a tenant
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if ($id === null || $id === '') {
                return Response::error('Tenant ID is required', 400);
            }
            $id = (int)$id;

            // System users may manage any tenant; regular users only their own.
            if (!$this->canManageTenant($id)) {
                error_log(sprintf(
                    '[tenants] denied update: tenant_id=%s target_tenant_id=%d',
                    var_export(TenantContext::getTenantId(), true),
                    $id
                ));
                return Response::error('Unauthorized: Cannot update other tenants', 403);
            }

            $body = json_decode($request->getBody(), true);

            // Get target tenant
            $stmt = $this->db->prepare('SELECT * FROM tenants WHERE id = ?');
            $stmt->execute([$id]);
            $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tenant) {
                return Response::error('Tenant not found', 404);
            }

            // Dispatch filter hook before updating tenant
            $this->hookManager->dispatch('tenant.updating', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            $updates = [];
            $params_array = [];

            // Update name
            if (isset($body['name']) && $body['name'] !== $tenant['name']) {
                $checkStmt = $this->db->prepare('SELECT id FROM tenants WHERE name = ? AND id != ?');
                $checkStmt->execute([$body['name'], $id]);
                if ($checkStmt->fetch()) {
                    return Response::error('Tenant name already exists', 409);
                }
                $updates[] = 'name = ?';
                $params_array[] = $body['name'];
            }

            // Update slug
            if (isset($body['slug']) && $body['slug'] !== $tenant['slug']) {
                if (!preg_match('/^[a-z0-9-]+$/', $body['slug'])) {
                    return Response::error('Slug must contain only lowercase letters, numbers, and hyphens', 400);
                }
                $checkSlugStmt = $this->db->prepare('SELECT id FROM tenants WHERE slug = ? AND id != ?');
                $checkSlugStmt->execute([$body['slug'], $id]);
                if ($checkSlugStmt->fetch()) {
                    return Response::error('Slug already exists', 409);
                }
                $updates[] = 'slug = ?';
                $params_array[] = $body['slug'];
            }

            if (!empty($updates)) {
                $params_array[] = $id;
                $sql = 'UPDATE tenants SET ' . implode(', ', $updates) . ' WHERE id = ?';
                $updateStmt = $this->db->prepare($sql);
                $updateStmt->execute($params_array);
            }

            // Dispatch synchronous hook after tenant is updated
            $this->hookManager->dispatch('tenant.updated', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Tenant updated']], 200);
        } catch (\Exception $e) {
            return Response::error('Failed to update tenant: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/tenants/{id} - Delete a tenant
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if ($id === null || $id === '') {
                return Response::error('Tenant ID is required', 400);
            }
            $id = (int)$id;

            // Protect the system tenant: it anchors the platform and must never
            // be deleted, regardless of who is asking. This guard runs before
            // authorization so that even a system user cannot remove tenant 0.
            if ($id === self::SYSTEM_TENANT_ID) {
                throw SystemTenantProtectedException::forAction('delete');
            }

            // System users may delete any tenant; regular users only their own.
            if (!$this->canManageTenant($id)) {
                error_log(sprintf(
                    '[tenants] denied delete: tenant_id=%s target_tenant_id=%d',
                    var_export(TenantContext::getTenantId(), true),
                    $id
                ));
                return Response::error('Unauthorized: Cannot delete other tenants', 403);
            }

            $stmt = $this->db->prepare('SELECT id FROM tenants WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetch()) {
                return Response::error('Tenant not found', 404);
            }

            // Check if tenant has users
            $checkStmt = $this->db->prepare('SELECT COUNT(*) as count FROM users WHERE tenant_id = ?');
            $checkStmt->execute([$id]);
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($result['count'] > 0) {
                return Response::error('Cannot delete tenant with ' . $result['count'] . ' user(s)', 409);
            }

            // Dispatch filter hook before deleting tenant
            $this->hookManager->dispatch('tenant.deleting', [
                'id' => (int)$id,
            ]);

            // Delete tenant
            $deleteStmt = $this->db->prepare('DELETE FROM tenants WHERE id = ?');
            $deleteStmt->execute([$id]);

            // Dispatch synchronous hook after tenant is deleted
            $this->hookManager->dispatch('tenant.deleted', [
                'id' => (int)$id,
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('tenant.deleted.async', [
                'id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Tenant deleted']], 200);
        } catch (SystemTenantProtectedException $e) {
            error_log(sprintf(
                '[tenants] blocked system tenant deletion: tenant_id=%s',
                var_export(TenantContext::getTenantId(), true)
            ));
            return Response::error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return Response::error('Failed to delete tenant: ' . $e->getMessage(), 500);
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
}
