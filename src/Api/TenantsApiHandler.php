<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Api\Exception\SystemTenantProtectedException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
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
     * GET /api/tenants - List tenants visible to the current user (paginated).
     */
    public function list(Request $request): Response
    {
        try {
            $currentTenantId = TenantContext::getTenantId();

            $isSystemUser = $currentTenantId === 0;
            $p = PaginationParams::fromPath($request->getPath());

            if ($isSystemUser) {
                // System user: all tenants except the system tenant itself.
                // @tenant-guard-ignore: system-tenant (isSystemUser) lists all tenants; scoped else-branch binds t.id = :tenant_id
                $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM tenants t WHERE t.id != 0');
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                // ROLE/TENANT data: memberships are the authoritative per-tenant member count
                // (ADR 0005 §3 — memberships replace users.tenant_id). Only active memberships
                // are counted (invited/suspended do not represent active accounts).
                // @tenant-guard-ignore: system-tenant (isSystemUser) lists all real tenants; memberships LEFT JOIN is unscoped by design — each row is a distinct tenant
                $stmt = $this->db->prepare('
                    SELECT t.id, t.name, t.slug, t.created_at,
                           COUNT(m.id) as userCount
                    FROM tenants t
                    LEFT JOIN memberships m ON t.id = m.tenant_id AND m.status = \'active\'
                    WHERE t.id != 0
                    GROUP BY t.id
                    ORDER BY t.created_at DESC
                    LIMIT :limit OFFSET :offset
                ');
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                // Regular user: only their own tenant (at most 1 row).
                // @tenant-guard-ignore: caller's own tenant; WHERE t.id = :tenant_id on tenants constrains the memberships LEFT JOIN to one tenant's rows
                $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM tenants t WHERE t.id = :tenant_id AND t.id != 0');
                $countStmt->bindValue(':tenant_id', $currentTenantId, PDO::PARAM_INT);
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                // ROLE/TENANT data: memberships are the authoritative per-tenant member count
                // (ADR 0005 §3). WHERE t.id = :tenant_id pins the memberships JOIN to the
                // caller's own tenant so no cross-tenant data leaks.
                // @tenant-guard-ignore: caller's own tenant; WHERE t.id = :tenant_id on tenants constrains the memberships LEFT JOIN to one tenant's rows
                $stmt = $this->db->prepare('
                    SELECT t.id, t.name, t.slug, t.created_at,
                           COUNT(m.id) as userCount
                    FROM tenants t
                    LEFT JOIN memberships m ON t.id = m.tenant_id AND m.status = \'active\'
                    WHERE t.id = :tenant_id
                    GROUP BY t.id
                    LIMIT :limit OFFSET :offset
                ');
                $stmt->bindValue(':tenant_id', $currentTenantId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows) && !$isSystemUser) {
                return Response::error('Tenant not found', 404);
            }

            // Shape each row into the public contract so the payload always carries
            // the camelCase keys the frontend `Tenant` type binds (WC-122). The
            // unquoted `userCount` SQL alias is folded to lowercase (`usercount`) in
            // the result set by the database (PostgreSQL/MySQL both lowercase
            // unquoted identifiers), so the delete-tenant dialog — which reads
            // `userCount` — never saw the count; mapping here pins the casing
            // regardless of the engine, mirroring {@see UsersApiHandler::toPublicUser()}.
            $tenants = array_map(fn (array $row): array => $this->toPublicTenant($row), $rows);

            return Response::json(['data' => $tenants, 'pagination' => $p->meta($total)], 200);
        } catch (\Exception $e) {
            error_log('[TenantsApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch tenant', 500);
        }
    }

    /**
     * Map a raw tenants row to the public API contract consumed by the web UI.
     *
     * Snake_case / engine-folded columns are normalised to the camelCase keys the
     * frontend `Tenant` type binds: the user-count aggregate is exposed as
     * `userCount` (the unquoted `userCount` SQL alias comes back lowercased as
     * `usercount` from the database) and `created_at` as `createdAt`. This
     * guarantees the delete-tenant dialog receives the associated-user count under
     * the key it reads (WC-122) and keeps the casing consistent with the users
     * payload (WC-100/WC-113).
     *
     * @param array<string, mixed> $row Raw row from the tenants SELECT.
     * @return array{id: int, name: string, slug: string|null, userCount: int, createdAt: string|null}
     */
    private function toPublicTenant(array $row): array
    {
        // The user-count aggregate is aliased `userCount` in SQL but the database
        // folds the unquoted result-set column name to lowercase, so accept either
        // casing (and the explicit `userCount` from create()/SQLite tests).
        $userCount = $row['userCount'] ?? $row['usercount'] ?? 0;

        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => (string)($row['name'] ?? ''),
            'slug' => isset($row['slug']) ? (string)$row['slug'] : null,
            'userCount' => (int)$userCount,
            'createdAt' => isset($row['created_at']) ? (string)$row['created_at'] : null,
        ];
    }

    /**
     * POST /api/tenants - Create a new tenant
     *
     * Tenant creation is a platform-level operation: it provisions a brand-new
     * tenant boundary rather than acting within an existing one. It is therefore
     * restricted to system users (tenant_id=0). A regular tenant's admin — even
     * with the global `admin` role the route requires — must not be able to
     * mint additional tenants, as that would be a platform-level privilege
     * escalation (WC-49). The strict system-authority check is used here rather
     * than {@see canManageTenant()}, which only governs writes to an *existing*
     * tenant.
     */
    public function create(Request $request): Response
    {
        // Platform-level guard: only system users may create tenants. This runs
        // before any work so a non-system caller can never provision a tenant.
        if (!$this->isSystemUser()) {
            error_log(sprintf(
                '[tenants] denied create: tenant_id=%s',
                var_export(TenantContext::getTenantId(), true)
            ));
            return Response::error('Only system administrators may create tenants', 403);
        }

        try {
            $body = JsonBody::parsed($request);

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
                'data' => $this->toPublicTenant([
                    'id' => (int)$tenantId,
                    'name' => $name,
                    'slug' => $slug,
                    'userCount' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ])
            ], 201);
        } catch (\Exception $e) {
            error_log('[TenantsApiHandler] create failed: ' . $e->getMessage());
            return Response::error('Failed to create tenant', 500);
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

            $body = JsonBody::parsed($request);

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
            error_log('[TenantsApiHandler] update failed: ' . $e->getMessage());
            return Response::error('Failed to update tenant', 500);
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

            // Check if tenant has active members (ROLE/TENANT data: memberships are
            // the authoritative tenant-scoped membership table, ADR 0005 §3).
            $checkStmt = $this->db->prepare('SELECT COUNT(*) as count FROM memberships WHERE tenant_id = ?');
            $checkStmt->execute([$id]);
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($result['count'] > 0) {
                return Response::error('Cannot delete tenant with ' . $result['count'] . ' member(s)', 409);
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
                '[tenants] blocked system tenant deletion: tenant_id=%s, detail=%s',
                var_export(TenantContext::getTenantId(), true),
                $e->getMessage()
            ));
            // Safe, explicit domain message — never the raw exception text. This is
            // a deliberate 400 for a known guard (system tenant id=0 is protected),
            // not a generic failure, so the client gets actionable but leak-free text.
            // The literal mirrors SystemTenantProtectedException::forAction('delete')
            // so the client contract is unchanged while no $e->getMessage() reaches it.
            return Response::error('Cannot delete system tenant', 400);
        } catch (\Exception $e) {
            error_log('[TenantsApiHandler] delete failed: ' . $e->getMessage());
            return Response::error('Failed to delete tenant', 500);
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
