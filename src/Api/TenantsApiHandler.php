<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Api\Exception\SystemTenantProtectedException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Core\PasswordPolicy;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\TenantProvisioner;
use Whity\Sdk\Hooks\HookVetoException;
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
                           COUNT(DISTINCT m.profile_id) as userCount
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
                           COUNT(DISTINCT m.profile_id) as userCount
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

            // Bound the free-text fields (VARCHAR(255)) before any DB write so an
            // over-long value is a clean 422, not a Postgres 22001 → 500.
            if ($tooLong = InputLimits::firstViolation([
                'name' => [(string) $name, InputLimits::NAME_MAX],
                'slug' => [(string) $slug, InputLimits::NAME_MAX],
            ])) {
                return $tooLong;
            }

            // Validate slug format
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                return Response::error('Slug must contain only lowercase letters, numbers, and hyphens', 400);
            }

            // The optional initial administrator is validated HERE, before any
            // write, so a rejected administrator cannot leave a tenant behind
            // (#779). The transaction below is the backstop for engine-level
            // failures; this is the cheaper guarantee, and it also means the
            // caller gets the real reason rather than a generic 500.
            $admin = null;
            if (array_key_exists('admin', $body) && $body['admin'] !== null) {
                $admin = $this->validateInitialAdmin($body['admin']);
                if ($admin instanceof Response) {
                    return $admin;
                }
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

            // The tenant and its first administrator are ONE unit of work. A
            // tenant that could not get an administrator must not be left
            // behind: it would be invisible to every API path that requires a
            // membership, and the only way to finish or remove it would be the
            // direct SQL this endpoint exists to eliminate.
            // The first administrator's membership grant, for the audit dispatch
            // after the transaction commits (#889).
            $adminGrant = null;

            $ownTx = !$this->db->inTransaction();
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            try {
                // Insert the tenant, announce it, and give it the core
                // provisioning every tenant gets — one call, because this used to
                // be an INSERT plus a dispatch here and an INSERT with no
                // dispatch in the seeder, and the difference between the two is
                // the whole of #1012. The announcement still happens BEFORE the
                // administrator is provisioned, so a listener that seeds
                // tenant-scoped roles has done so by the time the initial role is
                // resolved.
                $tenantId = TenantProvisioner::withCoreSteps($this->db, $this->hookManager)
                    ->create($name, $slug);

                $adminSummary = null;
                if ($admin !== null) {
                    $adminSummary = $this->provisionInitialAdmin((int)$tenantId, $admin, $adminGrant);
                    if ($adminSummary instanceof Response) {
                        if ($ownTx && $this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        return $adminSummary;
                    }
                }

                if ($ownTx) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTx && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            // Post-commit, unlike `tenant.created` above — that one runs inside
            // the transaction on purpose, so a listener can seed tenant roles
            // before the initial role is resolved. This one has the opposite
            // requirement: it is an assertion for an append-only trail, so it
            // must not be made until the write it describes is durable.
            if ($adminGrant !== null) {
                $this->hookManager->dispatch('user.membership.added', $adminGrant);
            }

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('tenant.created.async', [
                'id' => (int)$tenantId,
                'name' => $name,
            ]);

            $payload = $this->toPublicTenant([
                'id' => (int)$tenantId,
                'name' => $name,
                'slug' => $slug,
                // A provisioned administrator is the tenant's first member, so
                // the count the list endpoint would report is already 1.
                'userCount' => $adminSummary === null ? 0 : 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            if ($adminSummary !== null) {
                $payload['admin'] = $adminSummary;
            }

            return Response::json(['data' => $payload], 201);
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

            // Bound the free-text fields (VARCHAR(255)) before the write; only the
            // fields actually present in the body are checked (null = skipped).
            if ($tooLong = InputLimits::firstViolation([
                'name' => [isset($body['name']) ? (string) $body['name'] : null, InputLimits::NAME_MAX],
                'slug' => [isset($body['slug']) ? (string) $body['slug'] : null, InputLimits::NAME_MAX],
            ])) {
                return $tooLong;
            }

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

            // Check if tenant has ACTIVE members (ROLE/TENANT data: memberships are
            // the authoritative tenant-scoped membership table, ADR 0005 §3). Only
            // active memberships block deletion — a tenant whose only memberships are
            // invited/suspended has no active occupants and can be deleted.
            $checkStmt = $this->db->prepare(
                "SELECT COUNT(DISTINCT profile_id) as count FROM memberships WHERE tenant_id = ? AND status = 'active'"
            );
            $checkStmt->execute([$id]);
            $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($result['count'] > 0) {
                return Response::error('Cannot delete tenant with ' . $result['count'] . ' member(s)', 409);
            }

            // WC-713: the `tenant.deleting` hook, the DELETE, and the
            // `tenant.deleted` hook run inside ONE transaction — see the same
            // comment on OusApiHandler::delete(). Deleting a tenant cascades
            // across 32 core FK constraints; a plugin's own tenant-scoped tables
            // carry NO such FK, so the synchronous hook is their only cleanup
            // mechanism and it must be atomic with the tenant row itself.
            $ownTransaction = !$this->db->inTransaction();
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            try {
                // Filter hook BEFORE the delete — the row (and everything that
                // cascades off it) is still readable here, so this is where a
                // listener reads what it needs, and where it may veto.
                $this->hookManager->dispatch('tenant.deleting', [
                    'id' => (int)$id,
                ]);

                // Delete tenant
                $deleteStmt = $this->db->prepare('DELETE FROM tenants WHERE id = ?');
                $deleteStmt->execute([$id]);

                // Synchronous cleanup hook, still INSIDE the transaction: a
                // listener that throws takes the delete down with it.
                $this->hookManager->dispatch('tenant.deleted', [
                    'id' => (int)$id,
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

            // Durable/async notification only AFTER the delete has COMMITTED.
            $this->hookManager->dispatchAsync('tenant.deleted.async', [
                'id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Tenant deleted']], 200);
        } catch (HookVetoException $e) {
            // A plugin refused the deletion (or its cleanup failed); the
            // transaction rolled back, so the tenant still exists. 409 matches
            // the active-members guard above. `reason()` is the plugin's own
            // client-safe text — the raw exception message never leaks (WC-186).
            error_log(sprintf(
                '[tenants] delete vetoed by a hook listener: tenant_id=%s target_tenant_id=%s event=%s',
                var_export(TenantContext::getTenantId(), true),
                var_export($params['id'] ?? null, true),
                $e->eventName()
            ));
            return Response::error(
                'Cannot delete tenant: blocked by an installed plugin',
                409,
                ['reason' => $e->reason()]
            );
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
     * Validate the optional `admin` block of a create request.
     *
     * Runs before any write. Everything checkable without touching the database
     * is checked here so that a bad administrator is a clean 4xx describing the
     * actual problem, rather than a rollback reported as a generic failure.
     *
     * @param mixed $raw The submitted `admin` value, of unknown shape.
     * @return array{email: string, password: string, role: string}|Response
     *         The normalised block, or the error response to return.
     */
    private function validateInitialAdmin(mixed $raw): array|Response
    {
        if (!is_array($raw)) {
            return Response::error('admin must be an object with email and password', 400);
        }

        $email = trim((string) ($raw['email'] ?? ''));
        $password = (string) ($raw['password'] ?? '');

        if ($email === '' || $password === '') {
            return Response::error('admin.email and admin.password are required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::error('admin.email is not a valid email address', 400);
        }

        // Bound before any DB write so an over-long value is a clean 422 rather
        // than a Postgres 22001 surfacing as a 500.
        if ($tooLong = InputLimits::firstViolation(['admin.email' => [$email, InputLimits::NAME_MAX]])) {
            return $tooLong;
        }

        // The same policy every other credential clears. A bootstrap account is
        // the LAST one that should get a weaker rule: it is the most privileged
        // account in the tenant and the one most likely to be created quickly
        // and then forgotten.
        try {
            PasswordPolicy::validate($password);
        } catch (\InvalidArgumentException $e) {
            // Bound to a named local first, as the same PasswordPolicy call in
            // UsersApiHandler::create() does. The message is authored BY the
            // policy FOR the person typing the password ("must be at least N
            // characters"), so surfacing it is the point — telling someone their
            // password was rejected without saying why is a dead end. The naming
            // is what distinguishes that from forwarding an engine error, which
            // ExceptionLeakageTest exists to stop.
            $policyViolation = $e->getMessage();

            return Response::error($policyViolation, 400);
        }

        $role = trim((string) ($raw['role'] ?? 'admin'));

        return ['email' => $email, 'password' => $password, 'role' => $role === '' ? 'admin' : $role];
    }

    /**
     * Create the tenant's first administrator, inside the caller's transaction.
     *
     * @param int $tenantId The tenant just inserted.
     * @param array{email: string, password: string, role: string} $admin
     * @param array<string, mixed>|null $grant OUT: the `user.membership.added`
     *        payload for the membership this creates (#889). An out-parameter
     *        rather than an extra key on the return value because that value is
     *        the API RESPONSE shape — widening it to carry an audit detail
     *        would put an internal concern on a published contract. The caller
     *        dispatches it after the transaction commits: this method runs
     *        inside one, and a tenant whose creation rolls back must not leave
     *        an audit row claiming its first administrator was appointed.
     * @return array{id: int, email: string, role: string}|Response
     *         A summary for the response, or the error response to return.
     */
    private function provisionInitialAdmin(int $tenantId, array $admin, ?array &$grant = null): array|Response
    {
        // A brand-new tenant has no roles of its own unless a `tenant.created`
        // listener just seeded some, so both scopes are consulted — tenant-owned
        // first, so a seeded role wins over the global one of the same name.
        $roleId = $this->resolveRoleForTenant($admin['role'], $tenantId);
        if ($roleId === null) {
            return Response::error(sprintf('Role "%s" not found', $admin['role']), 404);
        }

        $profileId = (new ProfileProvisioner($this->db))->findOrCreate(
            $admin['email'],
            password_hash($admin['password'], PASSWORD_BCRYPT)
        );

        // is_primary: this is the tenant's first membership for this profile and
        // the row that answers "what is this person here?" (migration 094).
        // Written as a boolean literal, not 1 — PostgreSQL rejects the integer.
        $this->db->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, is_primary, status, created_at)
             VALUES (?, ?, ?, true, 'active', NOW())"
        )->execute([$profileId, $tenantId, $roleId]);

        // The highest-authority grant this platform makes — a brand-new tenant's
        // first administrator — and until #889 it was made in complete silence.
        // The role NAME is free here: it is what the request named, so unlike
        // every other emitter this one needs no lookup to record it.
        $grant = [
            'profile_id'    => $profileId,
            'tenant_id'     => $tenantId,
            'membership_id' => (int) $this->db->lastInsertId(),
            'role_id'       => $roleId,
            'role_name'     => $admin['role'],
            'ou_id'         => null,
            'status'        => 'active',
            'is_primary'    => true,
            'via'           => 'tenant_bootstrap',
        ];

        return ['id' => $profileId, 'email' => $admin['email'], 'role' => $admin['role']];
    }

    /**
     * A role id visible to `$tenantId`, preferring one the tenant owns.
     *
     * Two queries rather than one ordered query: expressing "tenant-owned first,
     * then global" in a single statement needs NULL ordering that SQLite and
     * PostgreSQL spell differently, and this path runs on both.
     */
    private function resolveRoleForTenant(string $roleName, int $tenantId): ?int
    {
        $owned = $this->db->prepare('SELECT id FROM roles WHERE name = ? AND tenant_id = ? LIMIT 1');
        $owned->execute([$roleName, $tenantId]);
        $id = $owned->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        // @tenant-guard-ignore: global roles are tenant-independent by definition (tenant_id IS NULL)
        $global = $this->db->prepare('SELECT id FROM roles WHERE name = ? AND tenant_id IS NULL LIMIT 1');
        $global->execute([$roleName]);
        $id = $global->fetchColumn();

        return $id === false ? null : (int) $id;
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
