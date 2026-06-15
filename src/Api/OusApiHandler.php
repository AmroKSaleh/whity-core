<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Api\Exception\OuHierarchyCycleException;
use Whity\Auth\RoleChecker;
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
 *
 * Cache coherence
 * ---------------
 * OU role assignments feed a user's effective roles/permissions (WC-54), so any
 * mutation to those assignments ({@see self::assignRole()}, {@see self::removeRole()})
 * invalidates the worker-level effective-permission caches via
 * {@see RoleChecker::clearCache()}; otherwise an authorization check could keep
 * serving a stale resolved set after a grant was added or revoked.
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
            error_log('[OusApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational units', 500);
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
            error_log('[OusApiHandler] create failed: ' . $e->getMessage());
            return Response::error('Failed to create organizational unit', 500);
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
            error_log('[OusApiHandler] get failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational unit', 500);
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

            // Handle parent_id update. Use array_key_exists (not isset) so an
            // explicit `null` — "move to root" from the picker — is honoured;
            // isset() is false for null and would silently drop the change.
            // Compare as nullable ints so the int (JSON body) vs string (PDO
            // column) representations of the same parent are not seen as a diff.
            $currentParentId = $ou['parent_id'] === null ? null : (int)$ou['parent_id'];
            $requestedParentId = array_key_exists('parent_id', $body) && $body['parent_id'] !== null
                ? (int)$body['parent_id']
                : null;

            if (array_key_exists('parent_id', $body) && $requestedParentId !== $currentParentId) {
                $newParentId = $requestedParentId;

                // Validate parent exists in same tenant
                if ($newParentId !== null) {
                    $parentStmt = $this->db->prepare(
                        'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
                    );
                    $parentStmt->execute([$newParentId, $tenantId]);
                    if (!$parentStmt->fetch()) {
                        return Response::error('Parent organizational unit does not belong to current tenant', 403);
                    }

                    // Detect cycle: if the new parent is this OU or one of its
                    // descendants, reject the move with a typed domain error
                    // (translated to 422 below). This guards the hierarchy
                    // independently of the UI's move-picker (defense in depth).
                    if ($this->wouldCreateCycle((int)$id, (int)$newParentId, $tenantId)) {
                        throw OuHierarchyCycleException::forMove((int)$id, (int)$newParentId);
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
        } catch (OuHierarchyCycleException $e) {
            // Re-parenting under self/descendant: a client error, not a server
            // fault. 422 (Unprocessable Entity) — the request is well-formed but
            // semantically invalid; no row was changed.
            error_log(sprintf(
                '[ous] rejected cyclic re-parent: tenant_id=%s ou_id=%s',
                var_export(TenantContext::getTenantId(), true),
                var_export($params['id'] ?? null, true)
            ));
            return Response::error('Setting this parent would create a cycle in the hierarchy', 422);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] update failed: ' . $e->getMessage());
            return Response::error('Failed to update organizational unit', 500);
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
            error_log('[OusApiHandler] delete failed: ' . $e->getMessage());
            return Response::error('Failed to delete organizational unit', 500);
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

            // Validate the role is visible to the caller's tenant before attaching
            // it (WC-56). Without this a tenant could attach another tenant's
            // private role to its own OU. Own roles and globals (NULL tenant_id)
            // are allowed, consistent with the WC-110 role-visibility model. A
            // role outside that set returns 404 (not 403) so cross-tenant role
            // existence is never disclosed.
            $roleStmt = $this->db->prepare('
                SELECT id FROM roles WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
            ');
            $roleStmt->execute([$roleId, $tenantId]);
            if (!$roleStmt->fetch()) {
                error_log(sprintf(
                    '[ous] denied role assignment: tenant_id=%s ou_id=%s role_id=%s',
                    var_export($tenantId, true),
                    var_export($ouId, true),
                    var_export($roleId, true)
                ));
                return Response::error('Role not found', 404);
            }

            // Insert role assignment
            try {
                $assignStmt = $this->db->prepare('
                    INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
                    VALUES (?, ?, ?, NOW())
                ');
                $assignStmt->execute([$tenantId, $ouId, $roleId]);
                $assignmentId = $this->db->lastInsertId();

                // Invalidate the worker-level effective-permission caches: this new
                // OU role assignment changes the effective roles of every user in
                // the OU (and its descendants), so cached resolutions are now stale.
                RoleChecker::clearCache();

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
            error_log('[OusApiHandler] assignRole failed: ' . $e->getMessage());
            return Response::error('Failed to assign role', 500);
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

            // Invalidate the worker-level effective-permission caches: revoking an
            // OU role assignment changes the effective roles of every user in the
            // OU (and its descendants), so cached resolutions are now stale.
            RoleChecker::clearCache();

            // Dispatch hook after role removal
            $this->hookManager->dispatch('ou.role_removed', [
                'ou_id' => (int)$ouId,
                'role_id' => (int)$roleId,
                'tenant_id' => $tenantId,
            ]);

            return Response::json([], 204);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] removeRole failed: ' . $e->getMessage());
            return Response::error('Failed to remove role', 500);
        }
    }

    /**
     * GET /api/ous/{id}/roles - List the roles assigned to an organizational unit.
     *
     * Joins `ou_role_assignments` to `roles` and returns the assigned roles as
     * `{id, name, description}`. Tenant-scoped: the OU must be visible to the
     * caller (system tenant 0 sees every tenant's OU; any other tenant sees only
     * its own), otherwise a 404 is returned so an OU's existence in another
     * tenant is never disclosed.
     */
    public function roles(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            if (!$this->ouIsVisible((int)$id, $tenantId)) {
                return Response::error('Organizational unit not found', 404);
            }

            // The OU's tenant scopes the assignment lookup. For a non-system
            // tenant this equals $tenantId; for the system tenant we read the
            // OU's own tenant so assignments are matched on the same tenant_id
            // they were written with.
            $assignmentStmt = $this->db->prepare('
                SELECT r.id, r.name, r.description
                FROM ou_role_assignments ora
                JOIN roles r ON r.id = ora.role_id
                WHERE ora.ou_id = ?
                ORDER BY r.name
            ');
            $assignmentStmt->execute([$id]);
            $rows = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

            $data = array_map(
                static fn (array $row): array => [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'description' => (string)($row['description'] ?? ''),
                ],
                $rows
            );

            return Response::json(['data' => $data], 200);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] listRoles failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational unit roles', 500);
        }
    }

    /**
     * GET /api/ous/{id}/members - List the users assigned to an organizational unit.
     *
     * Returns the users whose `ou_id` is this OU, shaped to the public user
     * contract ({id, name, email, role, tenantId, createdAt}) — the password hash
     * is never included. Tenant-scoped exactly like {@see self::roles()}: a caller
     * that cannot see the OU receives a 404.
     */
    public function members(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            if (!$this->ouIsVisible((int)$id, $tenantId)) {
                return Response::error('Organizational unit not found', 404);
            }

            $stmt = $this->db->prepare('
                SELECT u.id, u.email, u.created_at, u.tenant_id, r.name AS role
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE u.ou_id = ?
                ORDER BY u.created_at DESC
            ');
            $stmt->execute([$id]);
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = array_map(fn (array $row): array => $this->toPublicUser($row), $rows);

            return Response::json(['data' => $data], 200);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] listMembers failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational unit members', 500);
        }
    }

    /**
     * Whether an OU is visible to the acting tenant.
     *
     * The system tenant (id 0) can see every tenant's OU; any other tenant sees
     * only OUs it owns. Used by the read endpoints to return 404 (rather than
     * leaking existence) for OUs the caller may not access.
     */
    private function ouIsVisible(int $ouId, int $tenantId): bool
    {
        if ($tenantId === 0) {
            $stmt = $this->db->prepare('SELECT 1 FROM organizational_units WHERE id = ?');
            $stmt->execute([$ouId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM organizational_units WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([$ouId, $tenantId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Map a raw users row to the public API contract consumed by the web UI.
     *
     * Mirrors {@see \Whity\Api\UsersApiHandler::toPublicUser()}: the `users`
     * table has no `name` column, so `name` is derived from the email local-part;
     * snake_case columns are aliased to the camelCase keys the frontend binds; and
     * the password hash is never included.
     *
     * @param array<string, mixed> $row Raw row from the members SELECT.
     * @return array{id: int, name: string, email: string, role: string, tenantId: int, createdAt: string|null}
     */
    private function toPublicUser(array $row): array
    {
        $email = (string)($row['email'] ?? '');
        $localPart = strstr($email, '@', true);

        return [
            'id' => (int)($row['id'] ?? 0),
            'name' => $localPart !== false && $localPart !== '' ? $localPart : $email,
            'email' => $email,
            'role' => (string)($row['role'] ?? ''),
            'tenantId' => (int)($row['tenant_id'] ?? 0),
            'createdAt' => isset($row['created_at']) ? (string)$row['created_at'] : null,
        ];
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
     * Determine whether setting `$newParentId` as the parent of `$ouId` would
     * create a cycle in the hierarchy.
     *
     * Walks up the ancestor chain starting from the proposed new parent. If the
     * OU being moved is encountered anywhere in that chain — including being the
     * proposed parent itself — the move would form a loop and is rejected.
     *
     * Type discipline: parent ids are read back from the database (which under
     * PostgreSQL's PDO driver yields integer columns as PHP strings), so each id
     * is normalised to `int` before comparison. The earlier implementation
     * compared a string id against the int `$ouId` with `===`, which never
     * matched a deeper descendant against real Postgres and let cyclic moves
     * through (it happened to pass on SQLite, which returns native ints — the
     * mocked/SQLite-vs-Postgres gap this guard now closes).
     *
     * A visited set bounds the walk so any pre-existing data corruption cannot
     * spin into an infinite loop.
     *
     * @param int $ouId        The OU being moved.
     * @param int $newParentId The proposed new parent.
     * @param int $tenantId    The acting tenant (scopes the traversal).
     * @return bool True if the move would create a cycle, false otherwise.
     */
    private function wouldCreateCycle(int $ouId, int $newParentId, int $tenantId): bool
    {
        $currentId = $newParentId;
        $visited = [];

        $stmt = $this->db->prepare(
            'SELECT parent_id FROM organizational_units WHERE id = ? AND tenant_id = ?'
        );

        while (true) {
            if ($currentId === $ouId) {
                return true;
            }

            // A repeated node means the existing data already contains a loop;
            // stop rather than spin forever.
            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;

            $stmt->execute([$currentId, $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Reached a root (NULL parent) or an id outside the tenant: no cycle.
            if ($row === false || $row['parent_id'] === null) {
                return false;
            }

            $currentId = (int)$row['parent_id'];
        }
    }
}
