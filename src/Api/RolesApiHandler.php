<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Http\JsonBody;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Roles API Handler
 *
 * Full CRUD for roles with permission assignment, scoped to the current tenant
 * (WC-16, issue #9).
 *
 * Request contract
 * ----------------
 * Create and update accept the assigned permission list under the canonical key
 * `permissions`. Each entry may be EITHER a numeric `permissions.id` (the form
 * the web UI sends — its checkboxes are populated from `GET /api/permissions`
 * which returns `{id, name, ...}`) OR a `resource:action` COLON-notation name
 * string (e.g. `posts:read`, the Edit-role contract); mixed arrays are accepted.
 * Ids are validated against the catalogue and names resolved to ids before being
 * linked through the `role_permissions` junction table (which references
 * permissions by id). Unknown ids/names are dropped, never fabricated (WC-110).
 *
 * Tenant scoping (NULL tenant_id = GLOBAL/system role)
 * -----------------------------------------------------
 * The `roles` table carries a nullable `tenant_id` column (migration 018) whose
 * value defines ownership and visibility:
 *
 *  - `tenant_id IS NULL` is a GLOBAL/system role visible to ALL tenants. The
 *    seeded base roles (`admin` id 1, `user` id 2) are global: every tenant
 *    needs them, so they belong to everyone, not to any single tenant.
 *  - A non-NULL `tenant_id` is a tenant-OWNED custom role, isolated to the
 *    tenant that created it.
 *
 * Read (list, get, fetch permissions, visibility): a non-system tenant sees its
 * OWN roles (`tenant_id = currentTenant`) PLUS global roles (`tenant_id IS
 * NULL`) — i.e. `WHERE (r.tenant_id = ? OR r.tenant_id IS NULL)`. The SYSTEM
 * tenant (id 0) sees every role across all tenants.
 *
 * Write (update, delete): a non-system tenant may modify/delete only its OWN
 * roles (`tenant_id = currentTenant`); it must NOT mutate a global (NULL) base
 * role — that is treated as not-visible-for-write and returns 404, consistent
 * with cross-tenant writes. Only the SYSTEM tenant (id 0) may modify/delete
 * global roles. Tenant isolation still holds: tenant A can neither see nor
 * modify tenant B's owned roles.
 *
 * Create: new roles are stamped with the current tenant id (owned) so they stay
 * isolated; a SYSTEM-tenant create stamps tenant_id 0. Newly created roles are
 * stamped via the resolved {@see TenantContext::getTenantId()}. TenantContext is
 * never bypassed. (Before WC-110 a role's tenant was inferred from a `user_roles`
 * seed row for the acting user, which made API-created roles undeletable because
 * the deletion guard counted that very seed assignment; that hack has been
 * removed in favour of the explicit owning column.)
 *
 * Cache coherence
 * ---------------
 * Mutating writes (create/update/delete) invalidate the worker-level
 * effective-permission cache via {@see RoleChecker::clearCache()} (WC-15) so RBAC
 * checks never go stale after a role or its permissions change.
 */
class RolesApiHandler
{
    private PDO $db;
    private HookManager $hookManager;

    /**
     * Optional PSR-3 logger for structured audit/diagnostic logging. When null,
     * no log output is emitted (keeps tests output-clean).
     */
    private ?LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param PDO                  $db          Database connection.
     * @param HookManager          $hookManager Hook dispatcher for role lifecycle events.
     * @param LoggerInterface|null $logger      Optional PSR-3 logger for structured logs.
     */
    public function __construct(PDO $db, HookManager $hookManager, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
        $this->logger = $logger;
    }

    /**
     * GET /api/roles - List roles visible to the current tenant.
     *
     * @param Request $request The incoming request.
     * @return Response JSON list of roles under the `data` key.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();

            // SYSTEM tenant (id 0) sees every role across all tenants.
            if ($tenantId === 0) {
                // @tenant-guard-ignore: system-tenant (id 0) lists roles across all tenants; scoped else-branch binds (r.tenant_id = ? OR r.tenant_id IS NULL)
                $stmt = $this->db->prepare('
                    SELECT r.id, r.name, r.description, r.parent_id, r.created_at, r.tenant_id,
                           COUNT(rp.id) AS permission_count
                    FROM roles r
                    LEFT JOIN role_permissions rp ON r.id = rp.role_id
                    GROUP BY r.id, r.tenant_id
                    ORDER BY r.created_at DESC
                ');
                $stmt->execute();
            } else {
                // A role is visible to a tenant when it is OWNED by that tenant
                // (roles.tenant_id = currentTenant) OR is a GLOBAL/system role
                // (roles.tenant_id IS NULL, e.g. the seeded base roles), which
                // belongs to every tenant. Tenant-owned roles stay isolated to
                // their owner (WC-110).
                $stmt = $this->db->prepare('
                    SELECT r.id, r.name, r.description, r.parent_id, r.created_at, r.tenant_id,
                           COUNT(rp.id) AS permission_count
                    FROM roles r
                    LEFT JOIN role_permissions rp ON r.id = rp.role_id
                    WHERE (r.tenant_id = ? OR r.tenant_id IS NULL)
                    GROUP BY r.id, r.tenant_id
                    ORDER BY r.created_at DESC
                ');
                $stmt->execute([$tenantId]);
            }

            /** @var array<int, array<string, mixed>> $roles */
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalize permission_count to camelCase integer and surface an
            // AUTHORITATIVE per-row `manageable` flag so the admin UI can gate
            // Edit/Delete without first issuing a write that would 404 on a
            // global base role. The flag mirrors roleManageableByTenant()
            // (WC-110): SYSTEM tenant (id 0) may manage every role; a regular
            // tenant may manage ONLY its own roles (a global NULL-tenant role is
            // not manageable); a null tenant context manages nothing. The raw
            // owning tenant_id is dropped from the payload — `manageable` is the
            // clean contract the UI consumes.
            $roles = array_map(static function (array $role) use ($tenantId): array {
                $role['permissionCount'] = (int)($role['permission_count'] ?? 0);
                unset($role['permission_count']);

                $roleTenantId = isset($role['tenant_id']) ? (int)$role['tenant_id'] : null;
                if ($tenantId === 0) {
                    $role['manageable'] = true;
                } elseif ($tenantId === null) {
                    $role['manageable'] = false;
                } else {
                    $role['manageable'] = ($roleTenantId === $tenantId);
                }
                unset($role['tenant_id']);

                return $role;
            }, $roles);

            return Response::json(['data' => $roles], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch roles', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch roles', 500);
        }
    }

    /**
     * GET /api/roles/{id} - Get a single role visible to the current tenant.
     *
     * Visible means owned by the current tenant OR global (NULL tenant_id); the
     * SYSTEM tenant sees all roles (WC-110).
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON role with `permissions` under the `data` key.
     */
    public function get(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            if (!$this->roleVisibleToTenant((int)$id, $tenantId)) {
                return Response::error('Role not found', 404);
            }

            // @tenant-guard-ignore: role visibility already enforced by roleVisibleToTenant($id,$tenantId) guard above
            $stmt = $this->db->prepare('
                SELECT id, name, description, parent_id, created_at
                FROM roles
                WHERE id = ?
            ');
            $stmt->execute([$id]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                return Response::error('Role not found', 404);
            }

            $role['permissions'] = $this->fetchRolePermissions((int)$id);

            return Response::json(['data' => $role], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch role', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch role', 500);
        }
    }

    /**
     * POST /api/roles - Create a new role scoped to the current tenant.
     *
     * Accepts `{name, description?, permissions?}` where `permissions` is a list
     * of numeric permission ids and/or `resource:action` name strings. The new
     * role is stamped with the current tenant id (`roles.tenant_id`) so it is
     * immediately visible only to that tenant.
     *
     * @param Request $request The incoming request.
     * @return Response JSON created role under the `data` key (201) or an error.
     */
    public function create(Request $request): Response
    {
        try {
            $body = JsonBody::parsed($request);

            if (empty($body['name'])) {
                return Response::error('Role name is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $name = (string)$body['name'];
            $description = isset($body['description']) ? (string)$body['description'] : '';
            /** @var array<int, string|int> $permissions */
            $permissions = $this->extractPermissionList($body);

            // Role names are globally unique at the database layer.
            // @tenant-guard-ignore: role-name uniqueness check is intentionally platform-global (role names are globally unique)
            $checkStmt = $this->db->prepare('SELECT id FROM roles WHERE name = ?');
            $checkStmt->execute([$name]);
            if ($checkStmt->fetch()) {
                return Response::error('Role already exists', 409);
            }

            // Filter hook: allow plugins to adjust the role payload before write.
            $roleData = $this->hookManager->dispatch('role.creating', [
                'name' => $name,
                'description' => $description,
                'permissions' => $permissions,
                'tenant_id' => $tenantId,
            ]);

            $name = (string)$roleData['name'];
            $description = (string)$roleData['description'];
            /** @var array<int, string|int> $permissions */
            $permissions = $this->normalizePermissionRefs((array)$roleData['permissions']);

            // Insert the role, stamping it with the owning tenant so it is visible
            // to — and only to — this tenant (WC-110). The SYSTEM tenant (id 0) is
            // a real, scopeable owner here; it also sees every role on read.
            $stmt = $this->db->prepare('
                INSERT INTO roles (name, description, tenant_id, created_at)
                VALUES (?, ?, ?, NOW())
            ');
            $stmt->execute([$name, $description, $tenantId]);
            $roleId = (int)$this->db->lastInsertId();

            // Resolve permission ids/names and link them.
            $linkedCount = $this->assignPermissions($roleId, $permissions);

            // Synchronous post-create hook.
            $this->hookManager->dispatch('role.created', [
                'id' => $roleId,
                'name' => $name,
                'description' => $description,
                'permissions' => $permissions,
                'tenant_id' => $tenantId,
            ]);

            // Asynchronous post-create hook for background tasks.
            $this->hookManager->dispatchAsync('role.created.async', [
                'id' => $roleId,
                'name' => $name,
                'tenant_id' => $tenantId,
            ]);

            // A new role with permissions changes the effective permission set;
            // invalidate the worker-level hierarchy cache so checks are fresh.
            RoleChecker::clearCache();

            $this->log('info', 'Role created', [
                'event' => 'roles.create',
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'permission_count' => $linkedCount,
            ]);

            return Response::json([
                'data' => [
                    'id' => $roleId,
                    'name' => $name,
                    'description' => $description,
                    'permissionCount' => $linkedCount,
                ],
            ], 201);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create role', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to create role', 500);
        }
    }

    /**
     * PATCH /api/roles/{id} - Update a tenant-scoped role.
     *
     * Accepts any of `{name?, description?, permissions?}`. When `permissions` is
     * present its entries (numeric ids and/or `resource:action` names) fully
     * replace the role's existing permission grants. A non-system tenant may
     * update only its OWN roles; global (NULL-tenant) base roles return 404 for a
     * tenant and are manageable only by the SYSTEM tenant (WC-110).
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON confirmation under the `data` key (200) or an error.
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Write: only the OWNING tenant (or SYSTEM) may update a role; a
            // global (NULL) base role is not mutable by a tenant (WC-110).
            if (!$this->roleManageableByTenant((int)$id, $tenantId)) {
                return Response::error('Role not found', 404);
            }

            $body = JsonBody::parsed($request);

            // @tenant-guard-ignore: role manageability already enforced by roleManageableByTenant($id,$tenantId) guard above
            $stmt = $this->db->prepare('SELECT * FROM roles WHERE id = ?');
            $stmt->execute([$id]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                return Response::error('Role not found', 404);
            }

            // Filter hook before update.
            $this->hookManager->dispatch('role.updating', [
                'id' => (int)$id,
                'changes' => $body,
                'tenant_id' => $tenantId,
            ]);

            $updates = [];
            $updateParams = [];

            if (isset($body['name']) && $body['name'] !== $role['name']) {
                // @tenant-guard-ignore: role-name uniqueness check is intentionally platform-global (role names are globally unique)
                $checkStmt = $this->db->prepare('SELECT id FROM roles WHERE name = ? AND id != ?');
                $checkStmt->execute([$body['name'], $id]);
                if ($checkStmt->fetch()) {
                    return Response::error('Role name already exists', 409);
                }
                $updates[] = 'name = ?';
                $updateParams[] = (string)$body['name'];
            }

            if (isset($body['description'])) {
                $updates[] = 'description = ?';
                $updateParams[] = (string)$body['description'];
            }

            if ($updates !== []) {
                // WC-190: the UPDATE itself carries the tenant predicate, not just
                // the prior guard SELECT, so a cross-tenant id can never mutate
                // another tenant's role even if the guard were bypassed (TOCTOU).
                $this->updateRoleScoped((int)$id, $updates, $updateParams, $tenantId);
            }

            // Replace permissions when the canonical `permissions` key is present.
            if (array_key_exists('permissions', $body) && is_array($body['permissions'])) {
                // WC-190: scope the junction DELETE to grants whose OWNING role is
                // manageable by this tenant (role_permissions has no tenant_id of
                // its own; the boundary is the parent role's tenant_id).
                $this->deleteRolePermissionsScoped((int)$id, $tenantId);

                /** @var array<int, string|int> $permissions */
                $permissions = $this->normalizePermissionRefs($body['permissions']);
                $this->assignPermissions((int)$id, $permissions);
            }

            // Synchronous post-update hook.
            $this->hookManager->dispatch('role.updated', [
                'id' => (int)$id,
                'changes' => $body,
                'tenant_id' => $tenantId,
            ]);

            // Permission/hierarchy assignments may have changed; invalidate the
            // worker-level effective-permission cache so checks are not stale.
            RoleChecker::clearCache();

            $this->log('info', 'Role updated', [
                'event' => 'roles.update',
                'tenant_id' => $tenantId,
                'role_id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Role updated']], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to update role', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to update role', 500);
        }
    }

    /**
     * DELETE /api/roles/{id} - Delete a tenant-scoped role.
     *
     * A role with active user assignments cannot be deleted: the endpoint returns
     * 409 `{error: 'Cannot delete role with active user assignments'}`. A
     * non-system tenant may delete only its OWN roles; global (NULL-tenant) base
     * roles return 404 for a tenant and are deletable only by the SYSTEM tenant
     * (WC-110).
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON confirmation (200) or an error.
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Write: only the OWNING tenant (or SYSTEM) may delete a role; a
            // global (NULL) base role is not deletable by a tenant (WC-110).
            if (!$this->roleManageableByTenant((int)$id, $tenantId)) {
                return Response::error('Role not found', 404);
            }

            // Reject deletion while users are still assigned this role. Scope the
            // assignment count to the tenant (SYSTEM tenant counts across all).
            if ($this->roleHasActiveUsers((int)$id, $tenantId)) {
                return Response::error('Cannot delete role with active user assignments', 409);
            }

            // Filter hook before delete.
            $this->hookManager->dispatch('role.deleting', [
                'id' => (int)$id,
                'tenant_id' => $tenantId,
            ]);

            // Remove permission grants, tenant assignments, then the role itself.
            // WC-190: every one of these mutating statements carries its own
            // tenant predicate (scoped via the owning role for the junction
            // tables, directly for user_roles, and on roles itself), so a
            // cross-tenant id can never delete another tenant's rows even if the
            // guard SELECT above were bypassed (defense in depth / TOCTOU).
            $this->deleteRolePermissionsScoped((int)$id, $tenantId);
            $this->deleteUserRolesScoped((int)$id, $tenantId);
            $this->deleteRoleScoped((int)$id, $tenantId);

            // Synchronous post-delete hook.
            $this->hookManager->dispatch('role.deleted', [
                'id' => (int)$id,
                'tenant_id' => $tenantId,
            ]);

            // Asynchronous post-delete hook for background tasks.
            $this->hookManager->dispatchAsync('role.deleted.async', [
                'id' => (int)$id,
                'tenant_id' => $tenantId,
            ]);

            // Removing a role alters the hierarchy and effective permission sets;
            // invalidate the worker-level cache so checks reflect the deletion.
            RoleChecker::clearCache();

            $this->log('info', 'Role deleted', [
                'event' => 'roles.delete',
                'tenant_id' => $tenantId,
                'role_id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Role deleted']], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to delete role', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to delete role', 500);
        }
    }

    /**
     * GET /api/roles/{id}/permissions - Get the permissions assigned to a role.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON permission list under the `data` key.
     */
    public function getPermissions(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            if (!$this->roleVisibleToTenant((int)$id, $tenantId)) {
                return Response::error('Role not found', 404);
            }

            return Response::json(['data' => $this->fetchRolePermissions((int)$id)], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch role permissions', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch role permissions', 500);
        }
    }

    /**
     * Whether a role is READ-visible to the given tenant.
     *
     * The SYSTEM tenant (id 0) sees every role. For any other tenant, a role is
     * visible when it is OWNED by that tenant (`roles.tenant_id = currentTenant`)
     * OR is a GLOBAL/system role (`roles.tenant_id IS NULL`), which belongs to
     * every tenant (WC-110). Used by read endpoints (get, getPermissions).
     *
     * @param int      $roleId   The role id.
     * @param int|null $tenantId The resolved tenant id (null when unresolved).
     * @return bool True if the role is read-visible to the tenant.
     */
    private function roleVisibleToTenant(int $roleId, ?int $tenantId): bool
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('SELECT id FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            return $stmt->fetch() !== false;
        }

        if ($tenantId === null) {
            return false;
        }

        $stmt = $this->db->prepare('
            SELECT 1
            FROM roles r
            WHERE r.id = ? AND (r.tenant_id = ? OR r.tenant_id IS NULL)
            LIMIT 1
        ');
        $stmt->execute([$roleId, $tenantId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Whether a role is WRITE-manageable (update/delete) by the given tenant.
     *
     * Stricter than {@see self::roleVisibleToTenant()}: a non-system tenant may
     * mutate ONLY its OWN roles (`roles.tenant_id = currentTenant`). It must NOT
     * be able to modify or delete a GLOBAL (NULL-tenant) base role — only the
     * SYSTEM tenant (id 0) may manage global roles (WC-110). A non-manageable
     * role is reported as 404 by callers, consistent with cross-tenant writes.
     *
     * @param int      $roleId   The role id.
     * @param int|null $tenantId The resolved tenant id (null when unresolved).
     * @return bool True if the tenant may update/delete the role.
     */
    private function roleManageableByTenant(int $roleId, ?int $tenantId): bool
    {
        if ($tenantId === 0) {
            // SYSTEM tenant may manage any role, including global (NULL) roles.
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('SELECT id FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            return $stmt->fetch() !== false;
        }

        if ($tenantId === null) {
            return false;
        }

        // Strict ownership: global (NULL) roles are NOT manageable by a tenant.
        $stmt = $this->db->prepare('
            SELECT 1
            FROM roles r
            WHERE r.id = ? AND r.tenant_id = ?
            LIMIT 1
        ');
        $stmt->execute([$roleId, $tenantId]);
        return $stmt->fetch() !== false;
    }

    /**
     * Apply a scoped `UPDATE roles` whose WHERE clause itself carries the tenant
     * boundary (WC-190), not merely a preceding guard SELECT.
     *
     * Convention: the SYSTEM tenant (id 0) is unscoped and may update any role
     * (including global NULL-tenant roles); any other tenant is scoped with
     * `AND tenant_id = ?`, which — because a global role's `tenant_id` is NULL —
     * also correctly excludes global roles from a tenant write, matching
     * {@see self::roleManageableByTenant()}. A null/unresolved tenant updates
     * nothing.
     *
     * @param int                $roleId   The role id to update.
     * @param array<int, string> $sets     SQL `column = ?` assignment fragments.
     * @param array<int, mixed>  $values   Bound values for the assignment fragments.
     * @param int|null           $tenantId The acting tenant (0 = SYSTEM).
     * @return void
     */
    protected function updateRoleScoped(int $roleId, array $sets, array $values, ?int $tenantId): void
    {
        if ($sets === []) {
            return;
        }

        $assignments = implode(', ', $sets);

        if ($tenantId === 0) {
            $sql = 'UPDATE roles SET ' . $assignments . ' WHERE id = ?';
            $params = array_merge($values, [$roleId]);
        } elseif ($tenantId === null) {
            // No resolvable tenant: never mutate (use an impossible predicate).
            $sql = 'UPDATE roles SET ' . $assignments . ' WHERE id = ? AND 1 = 0';
            $params = array_merge($values, [$roleId]);
        } else {
            $sql = 'UPDATE roles SET ' . $assignments . ' WHERE id = ? AND tenant_id = ?';
            $params = array_merge($values, [$roleId, $tenantId]);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Apply a scoped `DELETE FROM roles` whose WHERE clause itself carries the
     * tenant boundary (WC-190). SYSTEM tenant (0) is unscoped; any other tenant
     * is scoped with `AND tenant_id = ?` (a global NULL-tenant role is therefore
     * never deletable by a tenant); a null tenant deletes nothing.
     *
     * @param int      $roleId   The role id to delete.
     * @param int|null $tenantId The acting tenant (0 = SYSTEM).
     * @return void
     */
    protected function deleteRoleScoped(int $roleId, ?int $tenantId): void
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('DELETE FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            return;
        }

        if ($tenantId === null) {
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM roles WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$roleId, $tenantId]);
    }

    /**
     * Scoped `DELETE FROM role_permissions` for a role's grants (WC-190).
     *
     * `role_permissions` has NO `tenant_id` column of its own — a grant inherits
     * its tenant transitively from the owning role — so the predicate scopes the
     * delete to grants whose parent role is manageable by the acting tenant via a
     * correlated EXISTS on `roles`. SYSTEM tenant (0) is unscoped; any other
     * tenant requires the parent role's `tenant_id` to equal it (excluding global
     * NULL-tenant roles); a null tenant deletes nothing.
     *
     * @param int      $roleId   The owning role id.
     * @param int|null $tenantId The acting tenant (0 = SYSTEM).
     * @return void
     */
    protected function deleteRolePermissionsScoped(int $roleId, ?int $tenantId): void
    {
        if ($tenantId === 0) {
            $stmt = $this->db->prepare('DELETE FROM role_permissions WHERE role_id = ?');
            $stmt->execute([$roleId]);
            return;
        }

        if ($tenantId === null) {
            return;
        }

        $stmt = $this->db->prepare(
            'DELETE FROM role_permissions
             WHERE role_id = ?
               AND EXISTS (
                   SELECT 1 FROM roles r
                   WHERE r.id = role_permissions.role_id AND r.tenant_id = ?
               )'
        );
        $stmt->execute([$roleId, $tenantId]);
    }

    /**
     * Scoped `DELETE FROM user_roles` for a role's assignments (WC-190).
     *
     * `user_roles` DOES carry a `tenant_id` column (migration 012), so the
     * predicate scopes directly on it. SYSTEM tenant (0) is unscoped; any other
     * tenant is scoped with `AND tenant_id = ?`; a null tenant deletes nothing.
     *
     * @param int      $roleId   The role id whose assignments are removed.
     * @param int|null $tenantId The acting tenant (0 = SYSTEM).
     * @return void
     */
    protected function deleteUserRolesScoped(int $roleId, ?int $tenantId): void
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('DELETE FROM user_roles WHERE role_id = ?');
            $stmt->execute([$roleId]);
            return;
        }

        if ($tenantId === null) {
            return;
        }

        $stmt = $this->db->prepare('DELETE FROM user_roles WHERE role_id = ? AND tenant_id = ?');
        $stmt->execute([$roleId, $tenantId]);
    }

    /**
     * Whether the role still has users assigned to it.
     *
     * Counts both the legacy single-role column (`users.role_id`) and the
     * many-to-many `user_roles` junction so a role in use through either path is
     * protected. Scoped to the tenant; the SYSTEM tenant (id 0) counts globally.
     *
     * @param int      $roleId   The role id.
     * @param int|null $tenantId The resolved tenant id.
     * @return bool True if at least one user is assigned the role.
     */
    private function roleHasActiveUsers(int $roleId, ?int $tenantId): bool
    {
        if ($tenantId === 0 || $tenantId === null) {
            // @tenant-guard-ignore: system-tenant (id 0) counts references across all tenants; scoped else-branch binds tenant_id in both subqueries
            $stmt = $this->db->prepare('
                SELECT (
                    (SELECT COUNT(*) FROM users WHERE role_id = ?)
                    + (SELECT COUNT(*) FROM user_roles WHERE role_id = ?)
                ) AS cnt
            ');
            $stmt->execute([$roleId, $roleId]);
        } else {
            $stmt = $this->db->prepare('
                SELECT (
                    (SELECT COUNT(*) FROM users WHERE role_id = ? AND tenant_id = ?)
                    + (SELECT COUNT(*) FROM user_roles WHERE role_id = ? AND tenant_id = ?)
                ) AS cnt
            ');
            $stmt->execute([$roleId, $tenantId, $roleId, $tenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false && (int)($row['cnt'] ?? 0) > 0;
    }

    /**
     * Fetch the permissions assigned to a role.
     *
     * @param int $roleId The role id.
     * @return array<int, array<string, mixed>> Permission rows (id, name, description).
     */
    private function fetchRolePermissions(int $roleId): array
    {
        $stmt = $this->db->prepare('
            SELECT p.id, p.name, p.description
            FROM permissions p
            INNER JOIN role_permissions rp ON p.id = rp.permission_id
            WHERE rp.role_id = ?
            ORDER BY p.name
        ');
        $stmt->execute([$roleId]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Resolve permission references (numeric ids and/or `resource:action` name
     * strings) to ids and link them to a role via the `role_permissions` junction
     * table.
     *
     * Unknown ids/names (not present in the `permissions` catalogue) are skipped
     * rather than fabricated, so a role can never reference a permission the system
     * does not enforce. Returns the number of grants actually linked.
     *
     * @param int                    $roleId      The role id.
     * @param array<int, string|int> $permissions Permission ids and/or names.
     * @return int The number of permission grants linked.
     */
    private function assignPermissions(int $roleId, array $permissions): int
    {
        if ($permissions === []) {
            return 0;
        }

        $ids = $this->resolvePermissionIds($permissions);
        if ($ids === []) {
            return 0;
        }

        $chunks = array_chunk($ids, 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, NOW())'));
            $sql = 'INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES ' . $placeholders;

            $params = [];
            foreach ($chunk as $permissionId) {
                $params[] = $roleId;
                $params[] = $permissionId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        return count($ids);
    }

    /**
     * Resolve a mixed list of permission references to their `permissions.id`s.
     *
     * The web UI populates its checkboxes from `GET /api/permissions` (which
     * returns `{id, name, ...}`) and therefore submits numeric permission ids,
     * while the Edit-role contract submits `resource:action` name strings. To
     * support both — including arrays that mix the two — each entry is classified:
     *
     *  - An integer or numeric string is treated as a permission id and kept ONLY
     *    when that id actually exists in the `permissions` catalogue.
     *  - Anything else is treated as a `resource:action` name and resolved through
     *    the catalogue by `permissions.name`.
     *
     * Unknown ids and unknown names are dropped (never fabricated), so a role can
     * never reference a permission the system does not enforce. The result is
     * de-duplicated to respect the `(role_id, permission_id)` uniqueness
     * constraint while preserving first-seen order.
     *
     * @param array<int, string|int> $permissions Permission ids and/or names.
     * @return array<int, int> Resolved, validated, de-duplicated permission ids.
     */
    private function resolvePermissionIds(array $permissions): array
    {
        $candidateIds = [];
        $names = [];
        // Preserve the caller's first-seen ordering across both id and name paths.
        $order = [];

        foreach ($permissions as $entry) {
            if (is_int($entry) || (is_string($entry) && $this->isNumericId($entry))) {
                $id = (int)$entry;
                $candidateIds[$id] = true;
                $order[] = ['id', $id];
                continue;
            }

            $name = (string)$entry;
            $names[$name] = true;
            $order[] = ['name', $name];
        }

        // Validate numeric ids against the catalogue so only real ids survive.
        $validIds = $this->existingPermissionIds(array_keys($candidateIds));
        // Resolve names to ids via the catalogue.
        $nameToId = $this->permissionIdsByName(array_keys($names));

        $resolved = [];
        foreach ($order as [$kind, $value]) {
            if ($kind === 'id') {
                if (isset($validIds[$value])) {
                    $resolved[$value] = true;
                }
                continue;
            }

            if (isset($nameToId[$value])) {
                $resolved[$nameToId[$value]] = true;
            }
        }

        return array_map('intval', array_keys($resolved));
    }

    /**
     * Whether a string represents a non-negative integer permission id.
     *
     * Accepts only plain digit strings (e.g. "42"); rejects floats, signed values
     * and `resource:action` names so colon-notation strings are never mistaken
     * for ids.
     *
     * @param string $value The raw candidate value.
     * @return bool True when the value is a plain unsigned integer literal.
     */
    private function isNumericId(string $value): bool
    {
        return $value !== '' && ctype_digit($value);
    }

    /**
     * Filter a list of candidate permission ids down to those that exist.
     *
     * @param array<int, int> $ids Candidate permission ids.
     * @return array<int, true> Set of existing ids, keyed by id for O(1) lookup.
     */
    private function existingPermissionIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            'SELECT id FROM permissions WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($ids));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $existing = [];
        foreach ($rows as $row) {
            if (isset($row['id'])) {
                $existing[(int)$row['id']] = true;
            }
        }

        return $existing;
    }

    /**
     * Resolve a list of permission names to their ids.
     *
     * @param array<int, string> $names Permission names (colon notation).
     * @return array<string, int> Map of name => id for names that exist.
     */
    private function permissionIdsByName(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $stmt = $this->db->prepare(
            'SELECT id, name FROM permissions WHERE name IN (' . $placeholders . ')'
        );
        $stmt->execute(array_values($names));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            if (isset($row['id'], $row['name'])) {
                $map[(string)$row['name']] = (int)$row['id'];
            }
        }

        return $map;
    }

    /**
     * Extract the canonical `permissions` list from a request body.
     *
     * `permissions` is the sole accepted key (the create/edit-role UI contract).
     * Entries may be int permission ids or string `resource:action` names (WC-110)
     * and are normalised to scalars so {@see self::resolvePermissionIds()} can
     * accept either form; non-scalar entries are discarded.
     *
     * @param array<string, mixed> $body The decoded request body.
     * @return array<int, string|int> The permission references (ids and/or names).
     */
    private function extractPermissionList(array $body): array
    {
        if (!array_key_exists('permissions', $body) || !is_array($body['permissions'])) {
            return [];
        }

        return $this->normalizePermissionRefs($body['permissions']);
    }

    /**
     * Normalise raw permission references into a clean list of ints and strings.
     *
     * Integers are kept as ids; strings are kept as-is (numeric strings are
     * treated as ids downstream); other scalars are coerced to string; non-scalar
     * entries (arrays/objects) are dropped.
     *
     * @param array<int|string, mixed> $raw Raw permission entries from the request.
     * @return array<int, string|int> The normalised references.
     */
    private function normalizePermissionRefs(array $raw): array
    {
        $refs = [];
        foreach ($raw as $entry) {
            if (is_int($entry) || is_string($entry)) {
                $refs[] = $entry;
            } elseif (is_scalar($entry)) {
                $refs[] = (string)$entry;
            }
        }

        return $refs;
    }

    /**
     * Emit a structured log record when a logger is configured.
     *
     * @param string               $level   PSR-3 log level method (e.g. `info`).
     * @param string               $message The human-readable message.
     * @param array<string, mixed> $context Structured context (includes tenant_id).
     * @return void
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($level, $message, $context);
    }
}
