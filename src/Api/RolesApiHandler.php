<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Hooks\HookVetoException;
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
 * Name uniqueness is PER TENANT (#712, migration 093)
 * ---------------------------------------------------
 * `roles.name` used to be UNIQUE platform-wide, which meant the first tenant to
 * name a role "Supervisor" denied that word to every other tenant on the install.
 * Migration 093 replaced that with two partial unique indexes — one over
 * `(tenant_id, name)` for owned roles, one over `(name)` for GLOBAL (NULL-tenant)
 * roles — so the two namespaces are independent and neither leaks into the other.
 *
 * The 409 this handler raises is the same rule read from the CALLER's side: a
 * name is taken when it is already used by a role the caller can SEE in the
 * namespace it is writing into — its own tenant's roles PLUS the global base
 * roles, whose names every tenant inherits and so cannot shadow. Another
 * tenant's role never enters the comparison, and is never named in the response.
 * The scope is the OWNING tenant of the row being written (the acting tenant on
 * create), not the acting tenant, so a SYSTEM-tenant edit of tenant 5's role is
 * checked against tenant 5's namespace rather than the system tenant's.
 *
 * Who holds a role
 * ----------------
 * {@see self::assignments()} answers "how many users have this role, and who
 * most recently" from `memberships` — the authoritative assignment table —
 * rather than leaving a client to fetch every user and count. See that method
 * for why the count is the pagination total and what it deliberately cannot
 * show.
 *
 * Additive permission changes
 * ---------------------------
 * {@see self::grantPermissions()} / {@see self::revokePermissions()} add and
 * remove individual grants without sending the whole set, so two admins editing
 * one role no longer clobber each other through the read-modify-write that
 * `PATCH {permissions: [...]}` requires. Both are idempotent and emit the same
 * `role.updating`/`role.updated` hooks — and therefore the same `role.updated`
 * audit record — as the full replace.
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
     * GET /api/roles - List roles visible to the current tenant (paginated).
     *
     * @param Request $request The incoming request.
     * @return Response JSON list of roles with pagination envelope.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $p = PaginationParams::fromPath($request->getPath());

            // SYSTEM tenant (id 0) sees every role across all tenants.
            if ($tenantId === 0) {
                // @tenant-guard-ignore: system-tenant (id 0) lists roles across all tenants; scoped else-branch binds (r.tenant_id = ? OR r.tenant_id IS NULL)
                $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM roles r');
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                // @tenant-guard-ignore: system-tenant (id 0) lists all roles; scoped else-branch binds (r.tenant_id = :tenant_id OR r.tenant_id IS NULL)
                $stmt = $this->db->prepare('
                    SELECT r.id, r.name, r.description, r.parent_id, r.created_at, r.tenant_id,
                           COUNT(rp.id) AS permission_count
                    FROM roles r
                    LEFT JOIN role_permissions rp ON r.id = rp.role_id
                    GROUP BY r.id, r.tenant_id
                    ORDER BY r.created_at DESC
                    LIMIT :limit OFFSET :offset
                ');
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                // A role is visible to a tenant when it is OWNED by that tenant
                // (roles.tenant_id = currentTenant) OR is a GLOBAL/system role
                // (roles.tenant_id IS NULL, e.g. the seeded base roles).
                $countStmt = $this->db->prepare(
                    'SELECT COUNT(*) AS cnt FROM roles r WHERE (r.tenant_id = :tenant_id OR r.tenant_id IS NULL)'
                );
                $countStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                $stmt = $this->db->prepare('
                    SELECT r.id, r.name, r.description, r.parent_id, r.created_at, r.tenant_id,
                           COUNT(rp.id) AS permission_count
                    FROM roles r
                    LEFT JOIN role_permissions rp ON r.id = rp.role_id
                    WHERE (r.tenant_id = :tenant_id OR r.tenant_id IS NULL)
                    GROUP BY r.id, r.tenant_id
                    ORDER BY r.created_at DESC
                    LIMIT :limit OFFSET :offset
                ');
                $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
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

            return Response::json(['data' => $roles, 'pagination' => $p->meta($total)], 200);
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
     * Carries the same authoritative `manageable` flag the LIST rows carry
     * (#882). The list has surfaced it since WC-222 so the admin UI can gate
     * Edit/Delete without first firing a write that would 404 on a global base
     * role — but a record page reached by URL never sees a list row, so without
     * it here the page would have to choose between rendering an editable form
     * that 403/404s on save and re-fetching the whole list to find one boolean.
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
            // Resolved through the SAME helper the write guards call rather than
            // re-derived from a tenant_id added to the SELECT: two copies of an
            // authorization rule are two rules, and the second one drifts.
            $role['manageable'] = $this->roleManageableByTenant((int)$id, $tenantId);

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

            // Bound the free-text fields before any DB write: name is VARCHAR(255),
            // description is an otherwise-unbounded TEXT column.
            if ($tooLong = InputLimits::firstViolation([
                'name' => [$name, InputLimits::NAME_MAX],
                'description' => [$description, InputLimits::TEXT_MAX],
            ])) {
                return $tooLong;
            }

            /** @var array<int, string|int> $permissions */
            $permissions = $this->extractPermissionList($body);

            // Role names are unique PER TENANT (migration 093). The new role will
            // be owned by the acting tenant, so that is the namespace it must be
            // unique within — plus the global base roles it inherits. Another
            // tenant's identically-named role is irrelevant and invisible here.
            if ($this->roleNameTaken($name, $tenantId)) {
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

            // Bound the free-text fields present in the body before the write.
            if ($tooLong = InputLimits::firstViolation([
                'name' => [isset($body['name']) ? (string) $body['name'] : null, InputLimits::NAME_MAX],
                'description' => [isset($body['description']) ? (string) $body['description'] : null, InputLimits::TEXT_MAX],
            ])) {
                return $tooLong;
            }

            if (isset($body['name']) && $body['name'] !== $role['name']) {
                // Per-tenant uniqueness (migration 093), checked in the namespace
                // of the role's OWNING tenant — which is not necessarily the
                // acting one: the SYSTEM tenant may rename another tenant's role,
                // and that rename must be unique THERE, not under tenant 0.
                $ownerTenantId = isset($role['tenant_id']) ? (int)$role['tenant_id'] : null;
                if ($this->roleNameTaken((string)$body['name'], $ownerTenantId, (int)$id)) {
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

            // WC-713: the `role.deleting` hook, both DELETEs, and the
            // `role.deleted` hook run inside ONE transaction — see the same
            // comment on OusApiHandler::delete(). Here it also makes the two
            // statements below atomic with each other, which they previously
            // were not: a failure between them left the role's permission grants
            // deleted but the role itself intact.
            $ownTransaction = !$this->db->inTransaction();
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            try {
                // Filter hook BEFORE the delete — the role and its grants are
                // still readable here; also the veto point.
                $this->hookManager->dispatch('role.deleting', [
                    'id' => (int)$id,
                    'tenant_id' => $tenantId,
                ]);

                // Remove permission grants, then the role itself.
                // WC-190: every one of these mutating statements carries its own
                // tenant predicate (scoped via the owning role for the junction
                // tables and on roles itself), so a cross-tenant id can never delete
                // another tenant's rows even if the guard SELECT above were bypassed
                // (defense in depth / TOCTOU).
                $this->deleteRolePermissionsScoped((int)$id, $tenantId);
                $this->deleteRoleScoped((int)$id, $tenantId);

                // Synchronous cleanup hook, still INSIDE the transaction: a
                // listener that throws takes the delete down with it.
                $this->hookManager->dispatch('role.deleted', [
                    'id' => (int)$id,
                    'tenant_id' => $tenantId,
                ]);

                if ($ownTransaction) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            // Asynchronous post-delete hook, only AFTER the delete has COMMITTED.
            $this->hookManager->dispatchAsync('role.deleted.async', [
                'id' => (int)$id,
                'tenant_id' => $tenantId,
            ]);

            // Removing a role alters the hierarchy and effective permission sets;
            // invalidate the worker-level cache so checks reflect the deletion.
            // Only reached on a COMMITTED delete — a rolled-back one leaves the
            // cached sets correct, so clearing them there would be pure churn.
            RoleChecker::clearCache();

            $this->log('info', 'Role deleted', [
                'event' => 'roles.delete',
                'tenant_id' => $tenantId,
                'role_id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Role deleted']], 200);
        } catch (HookVetoException $e) {
            // A plugin refused the deletion (or its cleanup failed); the
            // transaction rolled back, so the role still exists. 409 matches the
            // active-assignments guard above. `reason()` is the plugin's own
            // client-safe text — the raw exception message never leaks (WC-186).
            $this->log('info', 'Role deletion vetoed by a hook listener', [
                'event' => 'roles.delete_vetoed',
                'tenant_id' => TenantContext::getTenantId(),
                'role_id' => (int)($params['id'] ?? 0),
                'hook_event' => $e->eventName(),
            ]);
            return Response::error(
                'Cannot delete role: blocked by an installed plugin',
                409,
                ['reason' => $e->reason()]
            );
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
     * GET /api/roles/{id}/assignments - who holds this role, newest first (#882).
     *
     * The record page's "12 users hold this role, most recently user3 on the
     * 14th" — one request answering both halves, because the count IS the
     * pagination total of the same query that returns the page.
     *
     * WHY THIS EXISTS AT ALL. Before it, the only per-role headcount anywhere
     * was the `usersPerRole` aggregate {@see \Whity\Api\AdminApiHandler} builds
     * for the stats dashboard — every role at once, keyed by NAME, and useless
     * for one role by id. `GET /api/users` has no `role` filter, so a client
     * wanting "who has this role" had exactly one option: fetch every user and
     * count client-side. That is wrong at any real tenant size, it walks pages
     * privately (the defect #870 just removed from the block renderer's fetch
     * hook), and it makes the answer depend on how far the client got.
     *
     * ORDERED BY WHEN THE ROLE WAS GRANTED, newest first — `memberships.created_at`
     * is the moment this person was given this role in this tenant, so page one
     * of this endpoint IS the recent-assignment history without a second
     * endpoint, a second index, or an audit trail that only knows about events
     * since the day it was switched on.
     *
     * Note what it therefore cannot show: a REVOCATION. Removing a membership
     * deletes the row, and `user.membership.added`/`user.membership.removed` are
     * dispatched but not audited (see {@see \Whity\Core\Audit\AuditLogger::subscribe()},
     * whose map covers role/user/tenant/ou CRUD and OU role changes only). So
     * this is "who holds it and since when", truthfully, rather than "every
     * grant and revoke", falsely.
     *
     * AUTHORIZATION. Registered on the SAME `admin` role gate as every other
     * `/api/roles/*` route — deliberately not a new permission. A new slug ships
     * with a grant migration that can only reach the seeded `admin` role, so
     * every operator running a custom administrative role loses the capability
     * on upgrade and finds out as a 403 (#834). Visibility is the ordinary
     * {@see self::roleVisibleToTenant()} check, so a role a tenant cannot see is
     * 404 here exactly as it is on GET /api/roles/{id} — this cannot become a
     * way to enumerate another tenant's roles by id.
     *
     * TENANT SCOPING. Memberships are tenant-owned: a regular tenant counts only
     * ITS OWN members, so a GLOBAL base role (visible to everyone) reports the
     * holders inside the caller's tenant and never leaks another tenant's
     * headcount, let alone its people. The SYSTEM tenant (id 0) counts across
     * all tenants, matching how it reads everything else, and each row names its
     * tenant so the spanning list is readable.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON `{ data: [...], pagination: {...} }` (200) or an error.
     */
    public function assignments(Request $request, array $params): Response
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

            $p = PaginationParams::fromPath($request->getPath());
            $roleId = (int)$id;

            if ($tenantId === 0) {
                // @tenant-guard-ignore: system-tenant (id 0) counts holders across all tenants; scoped else-branch binds m.tenant_id = :tenant_id
                $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM memberships m WHERE m.role_id = :role_id');
                $countStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
                $countStmt->execute();

                // @tenant-guard-ignore: system-tenant (id 0) lists holders across all tenants; scoped else-branch binds m.tenant_id = :tenant_id
                $listStmt = $this->db->prepare('
                    SELECT m.id, m.profile_id, m.tenant_id, m.ou_id, m.is_primary, m.status, m.created_at,
                           p.display_name, pe.email
                    FROM memberships m
                    JOIN profiles p ON p.id = m.profile_id
                    LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                    WHERE m.role_id = :role_id
                    ORDER BY m.created_at DESC, m.id DESC
                    LIMIT :limit OFFSET :offset
                ');
                $listStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
            } else {
                $countStmt = $this->db->prepare(
                    'SELECT COUNT(*) AS cnt FROM memberships m WHERE m.role_id = :role_id AND m.tenant_id = :tenant_id'
                );
                $countStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
                $countStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $countStmt->execute();

                $listStmt = $this->db->prepare('
                    SELECT m.id, m.profile_id, m.tenant_id, m.ou_id, m.is_primary, m.status, m.created_at,
                           p.display_name, pe.email
                    FROM memberships m
                    JOIN profiles p ON p.id = m.profile_id
                    LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                    WHERE m.role_id = :role_id AND m.tenant_id = :tenant_id
                    ORDER BY m.created_at DESC, m.id DESC
                    LIMIT :limit OFFSET :offset
                ');
                $listStmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
                $listStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            }

            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

            $listStmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
            $listStmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
            $listStmt->execute();

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

            $data = array_map(static function (array $row): array {
                return [
                    'membershipId' => (int)($row['id'] ?? 0),
                    'profileId' => (int)($row['profile_id'] ?? 0),
                    'tenantId' => (int)($row['tenant_id'] ?? 0),
                    'displayName' => (string)($row['display_name'] ?? ''),
                    // NULL when the profile carries no primary email row; the
                    // LEFT JOIN is deliberate — a person with no primary email
                    // still holds the role and must still be counted and shown.
                    'email' => isset($row['email']) && $row['email'] !== null ? (string)$row['email'] : null,
                    'ouId' => isset($row['ou_id']) && $row['ou_id'] !== null ? (int)$row['ou_id'] : null,
                    'isPrimary' => (bool)($row['is_primary'] ?? false),
                    'status' => (string)($row['status'] ?? ''),
                    'assignedAt' => isset($row['created_at']) ? (string)$row['created_at'] : null,
                ];
            }, $rows);

            return Response::json(['data' => $data, 'pagination' => $p->meta($total)], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch role assignments', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch role assignments', 500);
        }
    }

    /**
     * POST /api/roles/{id}/permissions - ADD permissions to a role (#712).
     *
     * The additive half of the pair that removes the read-modify-write race
     * `PATCH {permissions: [...]}` forces on callers: to add one grant through
     * the full replace, a client must first read the role's current set and send
     * it back with the addition, so two admins doing that concurrently each
     * write a set computed before the other's change — and the loser's edit
     * disappears with no error. Sending only the DELTA has nothing to lose.
     *
     * Accepts `{permissions: [...]}` in the same mixed id/name notation the
     * create and update endpoints take. IDEMPOTENT: granting a permission the
     * role already holds is a success, not a 409 — the point of the endpoint is
     * that a caller can assert "this role has X" without first knowing whether
     * it does. Unknown ids/names are dropped, never fabricated, exactly as in
     * create/update; `granted` reports what actually changed.
     *
     * Gated and scoped identically to PATCH /api/roles/{id}: only the OWNING
     * tenant (or SYSTEM) may write, and a global base role is 404 for a tenant.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON grant summary under the `data` key (200) or an error.
     */
    public function grantPermissions(Request $request, array $params): Response
    {
        return $this->changePermissions($request, $params, grant: true);
    }

    /**
     * DELETE /api/roles/{id}/permissions - REMOVE permissions from a role (#712).
     *
     * The subtractive counterpart of {@see self::grantPermissions()}, with the
     * same body, gate and tenant scoping. IDEMPOTENT: revoking a permission the
     * role does not hold is a success — the caller asked for an end state, and
     * that end state already holds.
     *
     * The permission list travels in the request BODY rather than the query
     * string because it is a list of arbitrary length whose entries may be
     * `resource:action` strings; DELETE-with-a-body is well-defined for a
     * same-origin JSON API and keeps the grant/revoke contract symmetrical.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON revocation summary under the `data` key (200) or an error.
     */
    public function revokePermissions(Request $request, array $params): Response
    {
        return $this->changePermissions($request, $params, grant: false);
    }

    /**
     * Shared body of the additive/subtractive permission endpoints.
     *
     * Grant and revoke differ only in the junction-table statement they run:
     * everything around it — id validation, the write-manageability gate, body
     * parsing, reference resolution, hooks, cache invalidation, logging — is the
     * same contract and is written once so the two cannot drift apart.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @param bool                 $grant   True to add grants, false to remove them.
     * @return Response The JSON response.
     */
    private function changePermissions(Request $request, array $params, bool $grant): Response
    {
        $action = $grant ? 'grant' : 'revoke';

        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Role ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Same write boundary as update/delete: a tenant may only touch its
            // OWN roles, a global (NULL-tenant) base role is 404 for it, and only
            // the SYSTEM tenant may manage global roles (WC-110).
            if (!$this->roleManageableByTenant((int)$id, $tenantId)) {
                return Response::error('Role not found', 404);
            }

            $body = JsonBody::parsed($request);

            if (!array_key_exists('permissions', $body) || !is_array($body['permissions'])) {
                return Response::error('permissions must be an array of permission ids or names', 400);
            }

            /** @var array<int, string|int> $refs */
            $refs = $this->normalizePermissionRefs($body['permissions']);
            $permissionIds = $this->resolvePermissionIds($refs);

            // Filter hook before the write, mirroring update() so a plugin
            // observing role changes sees this one too.
            $this->hookManager->dispatch('role.updating', [
                'id' => (int)$id,
                'changes' => ['permissions' => $refs, 'permissionsChange' => $action],
                'tenant_id' => $tenantId,
            ]);

            $changed = $grant
                ? $this->grantRolePermissions((int)$id, $permissionIds)
                : $this->revokeRolePermissionsScoped((int)$id, $permissionIds, $tenantId);

            // Post-write hook. Deliberately `role.updated` rather than a new
            // event name: AuditLogger maps that event to the `role.updated`
            // audit action, so an additive change lands in audit_log looking
            // exactly like the full replace it substitutes for, and no audit
            // consumer needs to learn a second event to keep seeing role edits.
            $this->hookManager->dispatch('role.updated', [
                'id' => (int)$id,
                'changes' => ['permissions' => $refs, 'permissionsChange' => $action],
                'tenant_id' => $tenantId,
            ]);

            // The role's effective permission set has changed; invalidate the
            // worker-level cache so RBAC checks are not stale.
            RoleChecker::clearCache();

            $this->log('info', $grant ? 'Role permissions granted' : 'Role permissions revoked', [
                'event' => 'roles.update',
                'tenant_id' => $tenantId,
                'role_id' => (int)$id,
                'permissions_change' => $action,
                'permission_count' => $changed,
            ]);

            return Response::json([
                'data' => [
                    'id' => (int)$id,
                    'message' => $grant ? 'Permissions granted' : 'Permissions revoked',
                    // What this call actually changed (already-held grants and
                    // not-held revocations count as 0 — that is the idempotency).
                    ($grant ? 'granted' : 'revoked') => $changed,
                    // The role's resulting grants, so a caller that needs the new
                    // state does not have to follow up with a GET.
                    'permissions' => $this->fetchRolePermissions((int)$id),
                ],
            ], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to change role permissions', [
                'event' => 'roles.error',
                'tenant_id' => TenantContext::getTenantId(),
                'permissions_change' => $action,
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to change role permissions', 500);
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
     * Whether a role name is already taken in the namespace a write would land in.
     *
     * The namespace is the OWNING tenant of the row being written — the acting
     * tenant on create, the role's own `tenant_id` on rename — because that is
     * what migration 093's partial unique indexes are keyed on. Two cases:
     *
     *  - Owned role (`$ownerTenantId` non-null): taken when that tenant already
     *    has the name, OR a GLOBAL (NULL-tenant) base role does. Globals are
     *    inherited by every tenant, so allowing a tenant role to shadow one would
     *    put two same-named roles in that tenant's own list. The DB does not (and
     *    with plain unique indexes cannot) forbid it; this is where that rule lives.
     *  - Global role (`$ownerTenantId` null — only reachable for the SYSTEM tenant
     *    renaming a base role): taken only within the global namespace. A tenant's
     *    private role name must NOT block the operator from naming a base role.
     *
     * A tenant's roles are never compared against another tenant's, which is the
     * whole point of #712 — and also means a 409 can never reveal that another
     * tenant exists, let alone what it called something.
     *
     * @param string   $name          The candidate role name.
     * @param int|null $ownerTenantId Owning tenant of the row being written (null = global).
     * @param int|null $excludeRoleId Role id to ignore (the row being renamed).
     * @return bool True when the name is already in use in that namespace.
     */
    private function roleNameTaken(string $name, ?int $ownerTenantId, ?int $excludeRoleId = null): bool
    {
        if ($ownerTenantId === null) {
            // GLOBAL namespace only.
            $sql = 'SELECT 1 FROM roles WHERE name = ? AND tenant_id IS NULL';
            $params = [$name];
        } else {
            // The tenant's own roles PLUS the global base roles it inherits.
            $sql = 'SELECT 1 FROM roles WHERE name = ? AND (tenant_id = ? OR tenant_id IS NULL)';
            $params = [$name, $ownerTenantId];
        }

        if ($excludeRoleId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeRoleId;
        }

        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetch() !== false;
    }

    /**
     * Link permission ids to a role, skipping any it already holds.
     *
     * Idempotency is enforced TWICE, deliberately. The pre-filter makes the
     * common case cheap and makes the reported count an accurate description of
     * what changed; `ON CONFLICT DO NOTHING` then covers the window between that
     * read and the write, where a concurrent grant of the same permission would
     * otherwise violate `role_permissions`' `UNIQUE(role_id, permission_id)` and
     * turn a successful concurrent edit into a 500. Two admins granting the same
     * permission at the same instant is precisely the scenario this endpoint
     * exists for, so the race is not hypothetical.
     *
     * Like {@see self::assignPermissions()} the INSERT carries no tenant
     * predicate of its own: `role_permissions` has no `tenant_id`, an INSERT
     * names columns as VALUES rather than as a row filter, and the row it writes
     * is bound to a `role_id` the caller's manageability gate has already
     * confirmed. The predicates in {@see self::deleteRolePermissionsScoped()} and
     * {@see self::revokeRolePermissionsScoped()} exist because a DELETE without
     * one can reach rows the caller never named; an INSERT cannot.
     *
     * @param int             $roleId        The role id.
     * @param array<int, int> $permissionIds Resolved, validated permission ids.
     * @return int The number of grants actually added.
     */
    private function grantRolePermissions(int $roleId, array $permissionIds): int
    {
        if ($permissionIds === []) {
            return 0;
        }

        $existing = $this->linkedPermissionIds($roleId);
        $missing = array_values(array_filter(
            $permissionIds,
            static fn (int $permissionId): bool => !isset($existing[$permissionId])
        ));

        if ($missing === []) {
            return 0;
        }

        $added = 0;
        foreach (array_chunk($missing, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, NOW())'));

            $params = [];
            foreach ($chunk as $permissionId) {
                $params[] = $roleId;
                $params[] = $permissionId;
            }

            $stmt = $this->db->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, created_at)
                 VALUES ' . $placeholders . '
                 ON CONFLICT DO NOTHING'
            );
            $stmt->execute($params);
            $added += $stmt->rowCount();
        }

        return $added;
    }

    /**
     * Unlink a SUBSET of a role's permission grants (WC-190 scoped).
     *
     * Unlike {@see self::deleteRolePermissionsScoped()}, which clears the role's
     * whole set for the full-replace path, this removes only the ids named — and
     * silently removes nothing for ids the role never held, which is what makes
     * revoke idempotent.
     *
     * @param int             $roleId        The role id.
     * @param array<int, int> $permissionIds Resolved permission ids to remove.
     * @param int|null        $tenantId      The acting tenant (0 = SYSTEM).
     * @return int The number of grants actually removed.
     */
    private function revokeRolePermissionsScoped(int $roleId, array $permissionIds, ?int $tenantId): int
    {
        if ($permissionIds === [] || $tenantId === null) {
            return 0;
        }

        $removed = 0;
        foreach (array_chunk($permissionIds, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            if ($tenantId === 0) {
                $stmt = $this->db->prepare(
                    'DELETE FROM role_permissions
                     WHERE role_id = ? AND permission_id IN (' . $placeholders . ')'
                );
                $stmt->execute(array_merge([$roleId], $chunk));
            } else {
                $stmt = $this->db->prepare(
                    'DELETE FROM role_permissions
                     WHERE role_id = ?
                       AND permission_id IN (' . $placeholders . ')
                       AND EXISTS (
                           SELECT 1 FROM roles r
                           WHERE r.id = role_permissions.role_id AND r.tenant_id = ?
                       )'
                );
                $stmt->execute(array_merge([$roleId], $chunk, [$tenantId]));
            }

            $removed += $stmt->rowCount();
        }

        return $removed;
    }

    /**
     * The permission ids a role currently holds, as a set for O(1) lookup.
     *
     * @param int $roleId The role id.
     * @return array<int, true> Set keyed by permission id.
     */
    private function linkedPermissionIds(int $roleId): array
    {
        $stmt = $this->db->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
        $stmt->execute([$roleId]);

        $set = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $permissionId) {
            $set[(int)$permissionId] = true;
        }

        return $set;
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
     * Whether the role still has members assigned to it.
     *
     * Counts `memberships.role_id` — the sole authoritative role-assignment
     * signal (ADR 0005 §3) now that the legacy `users` table has been retired by
     * the identity hard cutover (migration 042). Scoped to the tenant; the SYSTEM
     * tenant (id 0) counts globally.
     *
     * Note: `user_roles` is no longer queried — that junction table was dropped
     * by migration 039; `users` was dropped by migration 042.
     *
     * @param int      $roleId   The role id.
     * @param int|null $tenantId The resolved tenant id.
     * @return bool True if at least one member is assigned the role.
     */
    private function roleHasActiveUsers(int $roleId, ?int $tenantId): bool
    {
        if ($tenantId === 0 || $tenantId === null) {
            // ROLE data: memberships.role_id is the authoritative role assignment
            // (ADR 0005 §3).
            // @tenant-guard-ignore: system-tenant (id 0) counts references across all tenants; scoped else-branch binds tenant_id
            $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM memberships WHERE role_id = ?');
            $stmt->execute([$roleId]);
        } else {
            $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM memberships WHERE role_id = ? AND tenant_id = ?');
            $stmt->execute([$roleId, $tenantId]);
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
