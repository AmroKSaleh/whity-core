<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\PasswordPolicy;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Users API Handler
 *
 * Handles CRUD operations for users with full validation,
 * password hashing, and error handling.
 *
 * Role assignment on create (WC-121)
 * ----------------------------------
 * `POST /api/users` resolves the submitted role the SAME way the update path
 * does: the Create User form binds the chosen role as a role NAME (e.g. `admin`)
 * and the handler resolves it to a tenant-visible `roles.id` via
 * {@see self::resolveVisibleRoleId()} (a numeric `role_id` is still accepted for
 * API callers). Previously create read only `role_id` and defaulted to the `user`
 * role, silently dropping the chosen role NAME; now a user created as "admin" is
 * actually created as admin. A supplied-but-unresolvable role is rejected with
 * 404 (matching update); when no role is supplied the user defaults to the global
 * `user` role. The created user is returned via {@see self::toPublicUser()}.
 *
 * Editable fields on update (WC-113)
 * ----------------------------------
 * `PATCH /api/users/{id}` persists the genuinely editable columns of the `users`
 * table: `email`, `password` and the user's primary `role` (the Edit User form
 * binds `role` as a role NAME, which is resolved to a `roles.id` scoped to the
 * tenant before being written to `users.role_id`). Two fields the form also
 * submits are intentionally NOT persisted here:
 *
 *  - `name` is DERIVED from the email local-part — there is no `users.name`
 *    column (see {@see self::toPublicUser()}) — so it is read-only and silently
 *    ignored if sent.
 *  - `tenantId` is intentionally out of scope: moving a user between tenants is a
 *    security-sensitive operation that would breach tenant isolation, so this
 *    endpoint never re-homes a user. A `tenantId` in the body is ignored.
 *
 * Tenant scoping mirrors the other admin handlers: a non-system tenant may edit
 * ONLY its own users (a user outside the tenant is reported as 404); the SYSTEM
 * tenant (id 0) may manage users across all tenants. A resolved role must be
 * visible to the acting tenant (owned by it or a global/NULL-tenant role), so a
 * tenant can never assign one of another tenant's private roles. TenantContext is
 * never bypassed. After a role change the worker-level effective-permission cache
 * is invalidated via {@see RoleChecker::clearCache()} (WC-15), mirroring
 * {@see RolesApiHandler}, so RBAC checks never go stale on a role re-assignment.
 */
class UsersApiHandler
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
     * @param HookManager          $hookManager Hook dispatcher for user lifecycle events.
     * @param LoggerInterface|null $logger      Optional PSR-3 logger for structured logs.
     */
    public function __construct(PDO $db, HookManager $hookManager, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
        $this->logger = $logger;
    }

    /**
     * GET /api/users - List users for the current tenant (paginated).
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $p = PaginationParams::fromPath($request->getPath());

            // DEFERRAL (WC-d88de9fa → WC-f3660e68): this listing (and its count)
            // intentionally still reads `FROM users`, NOT active memberships. The
            // users→profiles/memberships migration of this full-CRUD handler is its
            // own step (WC-f3660e68), so the count basis here (a users row count)
            // deliberately differs from AdminApiHandler::stats() (which now counts
            // active memberships). The divergence between /api/users and
            // /api/admin/stats is documented and expected until WC-f3660e68 lands;
            // do not migrate it here (mirrors DelegationsApiHandler::granteeVisible).
            if ($tenantId === 0) {
                // @tenant-guard-ignore: system-tenant (id 0) lists users across all tenants; scoped else-branch binds u.tenant_id = ?
                $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM users u');
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                // @tenant-guard-ignore: system-tenant (id 0) lists all users; scoped else-branch binds u.tenant_id = :tenant_id
                $stmt = $this->db->prepare('
                    SELECT u.id, u.email, u.password, u.created_at, u.tenant_id, u.ou_id, r.name as role,
                           pe.profile_id
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN profile_emails pe ON LOWER(TRIM(pe.email)) = LOWER(TRIM(u.email)) AND pe.is_primary = true
                    ORDER BY u.tenant_id, u.created_at DESC
                    LIMIT :limit OFFSET :offset
                ');
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM users u WHERE u.tenant_id = :tenant_id');
                $countStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                $stmt = $this->db->prepare('
                    SELECT u.id, u.email, u.password, u.created_at, u.tenant_id, u.ou_id, r.name as role,
                           pe.profile_id
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN profile_emails pe ON LOWER(TRIM(pe.email)) = LOWER(TRIM(u.email)) AND pe.is_primary = true
                    WHERE u.tenant_id = :tenant_id
                    ORDER BY u.created_at DESC
                    LIMIT :limit OFFSET :offset
                ');
                $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $users = array_map(fn (array $row): array => $this->toPublicUser($row), $rows);

            return Response::json(['data' => $users, 'pagination' => $p->meta($total)], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch users', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch users', 500);
        }
    }

    /**
     * Map a raw users row to the public API contract consumed by the web UI.
     *
     * The `users` table has no `name` column, so `name` is derived from the
     * email local-part to give the Edit User form a non-empty value to pre-fill
     * (its zod schema marks name required). Snake_case columns are aliased to
     * the camelCase keys the frontend `User` type binds, and the password hash
     * is never included.
     *
     * @param array<string, mixed> $row Raw row from the users SELECT.
     * @return array{id: int, name: string, email: string, role: string, tenantId: int, ou_id: int|null, createdAt: string|null}
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
            'ou_id' => isset($row['ou_id']) && $row['ou_id'] !== null ? (int)$row['ou_id'] : null,
            'createdAt' => isset($row['created_at']) ? (string)$row['created_at'] : null,
            // profileId is the identity anchor for this account (null when no
            // matching profile_emails record exists yet — callers must treat null
            // as "account not yet migrated to identity model").
            'profileId' => isset($row['profile_id']) && $row['profile_id'] !== null ? (int)$row['profile_id'] : null,
        ];
    }

    /**
     * POST /api/users - Create a new user.
     *
     * The Create User form binds the chosen role as a role NAME (e.g. `admin`),
     * exactly like the Edit form (WC-113); a numeric `role_id` is also accepted
     * for API callers. The submitted role is resolved to a `roles.id` VISIBLE to
     * the acting tenant via {@see self::resolveVisibleRoleId()} — the same helper
     * the update path uses — so a user created as "admin" is actually created as
     * admin (WC-121, the prior behaviour silently dropped the name and defaulted
     * to `user`). A role that is supplied but cannot be resolved (unknown name, or
     * another tenant's private role) is rejected with 404, matching update; when
     * NO role is supplied the user defaults to the global `user` role.
     *
     * The created user is returned via {@see self::toPublicUser()} so the response
     * carries the same shape (`id`/`name`/`email`/`role`/`tenantId`/`createdAt`,
     * never the password hash) as the list and update endpoints (WC-100/113).
     *
     * @param Request $request The incoming request.
     * @return Response JSON created user under the `data` key (201) or an error.
     */
    public function create(Request $request): Response
    {
        try {
            // The body envelope (size/type/well-formed JSON object) is validated
            // once in the pipeline (WC-189, RequestBodyValidator); here we read
            // the already-validated array.
            $body = JsonBody::parsed($request);

            // Validation
            if (empty($body['email']) || empty($body['password'])) {
                return Response::error('Email and password are required', 400);
            }

            try {
                PasswordPolicy::validate($body['password']);
            } catch (\InvalidArgumentException $e) {
                $validationError = $e->getMessage();
                return Response::error($validationError, 400);
            }

            if (!filter_var($body['email'], FILTER_VALIDATE_EMAIL)) {
                return Response::error('Invalid email format', 400);
            }

            $email = $body['email'];
            $tenantId = TenantContext::getTenantId();

            // Resolve the submitted role. The form sends `role` as a NAME; a
            // numeric `role_id` is also accepted. When neither is supplied the
            // user defaults to the global `user` role (matching the historical
            // default, but resolved rather than hard-coded so the id is always
            // valid). A supplied-but-unresolvable role is a 404, mirroring the
            // update path, so the dropdown can never silently downgrade an
            // unknown/foreign role to `user`.
            $roleRef = $body['role'] ?? $body['role_id'] ?? null;
            $roleId = $this->resolveVisibleRoleId($roleRef, $tenantId, $tenantId);
            if ($roleRef !== null && $roleRef !== '' && $roleId === null) {
                return Response::error('Role not found', 404);
            }
            if ($roleId === null) {
                // No role supplied: fall back to the global `user` role.
                $roleId = $this->resolveVisibleRoleId('user', $tenantId, $tenantId);
                if ($roleId === null) {
                    return Response::error('Default role not found', 500);
                }
            }

            // Check if email already exists
            $checkStmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND tenant_id = ?');
            $checkStmt->execute([$email, $tenantId]);
            if ($checkStmt->fetch()) {
                return Response::error('Email already exists for this tenant', 409);
            }

            // Dispatch filter hook before creating user
            $userData = $this->hookManager->dispatch('user.creating', [
                'email' => $email,
                'password' => $body['password'], // Pass plaintext password to hooks
                'role_id' => $roleId,
            ]);

            // Extract potentially modified data from hook response
            $email = $userData['email'];
            $roleId = (int)$userData['role_id'];
            $password = password_hash($userData['password'], PASSWORD_BCRYPT);

            // Insert new user
            $stmt = $this->db->prepare('
                INSERT INTO users (email, password, role_id, tenant_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$email, $password, $roleId, $tenantId]);

            $userId = (int)$this->db->lastInsertId();

            // Provision the PROFILE model so the new account can actually log in.
            // After the WC-c35c4ce0 login rewrite, authentication runs through
            // profile_emails → profiles → memberships (the #181 fix); a bare
            // `users` row is NOT login-capable. Every account created here must
            // therefore get a profile, a verified PRIMARY profile_email, and an
            // ACTIVE membership in this tenant — mirroring the seeder and
            // migration 035. Uses the SAME password hash so both the legacy and
            // profile login paths accept the same credential.
            $this->provisionProfileForUser($email, $password, (int) $roleId, (int) $tenantId);

            // Dispatch synchronous hook after user is created
            $this->hookManager->dispatch('user.created', [
                'id' => $userId,
                'email' => $email,
                'role_id' => $roleId,
                'tenant_id' => (int)$tenantId
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('user.created.async', [
                'id' => $userId,
                'email' => $email,
            ]);

            $this->log('info', 'User created', [
                'event' => 'users.create',
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);

            return Response::json(['data' => $this->fetchPublicUser($userId)], 201);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create user', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to create user', 500);
        }
    }

    /**
     * PATCH /api/users/{id} - Update a user.
     *
     * Persists the editable fields the Edit User form offers: `email`, `password`
     * and `role` (a role NAME resolved to a tenant-visible `roles.id`). `name` is
     * derived/read-only and `tenantId` is out of scope; both are ignored if sent
     * (see the class docblock). Tenant-scoped + ownership-checked: a non-system
     * tenant may only edit its own users (else 404), the SYSTEM tenant (id 0) may
     * edit across tenants, and an assigned role must be visible to the tenant. A
     * real change returns the updated user via {@see self::toPublicUser()}; a true
     * no-op still returns a sensible 200. A role change invalidates the
     * effective-permission cache.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON updated user under the `data` key (200) or an error.
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('User ID is required', 400);
            }

            $currentTenantId = TenantContext::getTenantId();

            $body = JsonBody::parsed($request);

            // Tenant ownership: a non-system tenant may only see/edit its OWN
            // users; the SYSTEM tenant (id 0) may manage users across all tenants.
            // A user outside the caller's tenant is reported as 404 so tenant
            // existence is never leaked, mirroring the other admin handlers.
            if ($currentTenantId === 0) {
                // @tenant-guard-ignore: system-tenant (id 0) branch; scoped else-branch binds tenant_id
                $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
                $stmt->execute([$id]);
            } else {
                $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? AND tenant_id = ?');
                $stmt->execute([$id, $currentTenantId]);
            }
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return Response::error('User not found', 404);
            }

            // The owning tenant of the target row scopes uniqueness/visibility
            // checks below (relevant when the SYSTEM tenant edits another tenant's
            // user: validate against THAT tenant, not tenant 0).
            $ownerTenantId = (int)$user['tenant_id'];

            // Prepare update fields.
            $updates = [];
            $params_array = [];
            $roleChanged = false;
            $ouChanged = false;

            if (isset($body['email']) && $body['email'] !== $user['email']) {
                // Check if new email already exists within the owning tenant.
                $checkStmt = $this->db->prepare(
                    'SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id != ?'
                );
                $checkStmt->execute([$body['email'], $ownerTenantId, $id]);
                if ($checkStmt->fetch()) {
                    return Response::error('Email already exists for this tenant', 409);
                }
                $updates[] = 'email = ?';
                $params_array[] = $body['email'];
            }

            if (isset($body['password']) && !empty($body['password'])) {
                try {
                    PasswordPolicy::validate($body['password']);
                } catch (\InvalidArgumentException $e) {
                    $validationError = $e->getMessage();
                    return Response::error($validationError, 400);
                }
                $updates[] = 'password = ?';
                $params_array[] = password_hash($body['password'], PASSWORD_BCRYPT);
            }

            // Role assignment. The form binds `role` as a role NAME; a numeric
            // `role_id` is also accepted for API callers. The resolved role must
            // be visible to the acting tenant (owned by it, a global/NULL-tenant
            // role, or — for the SYSTEM tenant — any role) so a tenant can never
            // assign another tenant's private role.
            $roleRef = $body['role'] ?? $body['role_id'] ?? null;
            if ($roleRef !== null && $roleRef !== '') {
                $newRoleId = $this->resolveVisibleRoleId($roleRef, $currentTenantId, $ownerTenantId);
                if ($newRoleId === null) {
                    return Response::error('Role not found', 404);
                }

                if ($newRoleId !== (int)$user['role_id']) {
                    $updates[] = 'role_id = ?';
                    $params_array[] = $newRoleId;
                    $roleChanged = true;
                }
            }

            // Support ou_id assignment with tenant validation (scoped to the owning
            // tenant so it stays correct under SYSTEM-tenant cross-tenant edits).
            if (isset($body['ou_id'])) {
                $ouId = $body['ou_id'];

                // NULL and 0 are valid (user in root)
                if ($ouId !== null && $ouId !== 0 && $ouId !== '') {
                    // SECURITY: ou_id must belong to the user's owning tenant.
                    $stmtCheckOu = $this->db->prepare('SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?');
                    $stmtCheckOu->execute([$ouId, $ownerTenantId]);
                    if (!$stmtCheckOu->fetch()) {
                        return Response::error('OU does not belong to current tenant', 403);
                    }
                    // Safe to update ou_id
                    $updates[] = 'ou_id = ?';
                    $params_array[] = $ouId;
                    $ouChanged = true;
                } else {
                    // Set to NULL (user in root)
                    $updates[] = 'ou_id = NULL';
                    $ouChanged = true;
                }
            }

            // A true no-op (nothing genuinely changed — e.g. only `name`/`tenantId`
            // were sent, or the role matched the current one) still returns a
            // sensible 200 carrying the unchanged record.
            if (empty($updates)) {
                $this->log('info', 'User update was a no-op', [
                    'event' => 'users.update.noop',
                    'tenant_id' => $currentTenantId,
                    'user_id' => (int)$id,
                ]);

                return Response::json(['data' => $this->fetchPublicUser((int)$id)], 200);
            }

            // WC-190: the UPDATE itself carries the tenant predicate, not just the
            // prior guard SELECT, so a cross-tenant id can never mutate another
            // tenant's user even if the guard were bypassed (TOCTOU / defense in
            // depth). The SYSTEM tenant (id 0) edits across tenants by design and
            // stays unscoped; any other tenant is pinned to the row's OWNING tenant
            // (which the guard already proved equals the acting tenant).
            $params_array[] = $id;
            if ($currentTenantId === 0) {
                $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
            } else {
                $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ? AND tenant_id = ?';
                $params_array[] = $ownerTenantId;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params_array);

            // A role re-assignment OR an OU-membership change alters the user's
            // effective role/permission set (OU roles are inherited, WC-54);
            // invalidate the worker-level cache so RBAC checks are not stale.
            if ($roleChanged || $ouChanged) {
                RoleChecker::clearCache();
            }

            $this->log('info', 'User updated', [
                'event' => 'users.update',
                'tenant_id' => $currentTenantId,
                'user_id' => (int)$id,
                'role_changed' => $roleChanged,
                'ou_changed' => $ouChanged,
            ]);

            // Notify listeners (e.g. the audit trail, WC-34) after a successful
            // user update. The owning tenant scopes the record.
            $this->hookManager->dispatch('user.updated', [
                'id' => (int)$id,
                'tenant_id' => $ownerTenantId,
                'role_changed' => $roleChanged,
                'ou_changed' => $ouChanged,
            ]);

            return Response::json(['data' => $this->fetchPublicUser((int)$id)], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to update user', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to update user', 500);
        }
    }

    /**
     * Resolve a role reference (a role NAME from the Edit form, or a numeric
     * `roles.id`) to a role id that is VISIBLE to the acting tenant.
     *
     * Visibility mirrors {@see RolesApiHandler}: a role is visible when it is
     * OWNED by the acting tenant (`roles.tenant_id = currentTenant`) OR is a
     * GLOBAL/system role (`roles.tenant_id IS NULL`, e.g. the seeded base roles).
     * The SYSTEM tenant (id 0) may assign any role. This prevents a tenant from
     * assigning another tenant's private role to a user.
     *
     * @param mixed    $roleRef        Role name string or numeric role id.
     * @param int|null $actingTenantId The resolved acting tenant id (0 = SYSTEM).
     * @param int      $ownerTenantId  The owning tenant of the target user.
     * @return int|null The resolved, visible role id, or null when not found/visible.
     */
    private function resolveVisibleRoleId(mixed $roleRef, ?int $actingTenantId, int $ownerTenantId): ?int
    {
        // For a tenant-scoped acting context, a role owned by the user's OWNING
        // tenant (relevant when the SYSTEM tenant edits another tenant's user) or a
        // global role is visible. For a regular tenant editing its own user the
        // owning tenant equals the acting tenant.
        $isSystem = $actingTenantId === 0;
        $scopeTenantId = $isSystem ? $ownerTenantId : $actingTenantId;

        // Classify the reference: a plain integer / digit string is a role id,
        // anything else is treated as a role name.
        $byId = is_int($roleRef) || (is_string($roleRef) && $roleRef !== '' && ctype_digit($roleRef));

        if ($byId) {
            $column = 'id';
            $value = (int)$roleRef;
        } else {
            $column = 'name';
            $value = (string)$roleRef;
        }

        if ($isSystem) {
            // SYSTEM tenant may assign any role regardless of ownership.
            // @tenant-guard-ignore: system-tenant role resolution; scoped else-branch binds (tenant_id = ? OR tenant_id IS NULL)
            $stmt = $this->db->prepare("SELECT id FROM roles WHERE {$column} = ? LIMIT 1");
            $stmt->execute([$value]);
        } else {
            // Owned-by-tenant OR global (NULL tenant) role only.
            $stmt = $this->db->prepare(
                "SELECT id FROM roles WHERE {$column} = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1"
            );
            $stmt->execute([$value, $scopeTenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !isset($row['id'])) {
            return null;
        }

        return (int)$row['id'];
    }

    /**
     * Re-read a user by id and shape it into the public API contract.
     *
     * Joins roles so the returned `role` reflects any role change just persisted,
     * and reuses {@see self::toPublicUser()} so the update response matches the
     * shape the list endpoint and the Edit form consume.
     *
     * @param int $id The user id.
     * @return array{id: int, name: string, email: string, role: string, tenantId: int, createdAt: string|null}
     */
    /**
     * Provision the profile-model identity for a newly-created user so the
     * account is login-capable under the profile-based auth (WC-c35c4ce0).
     *
     * Creates, idempotently:
     *  - a `profiles` row (global identity) carrying the SAME password hash, when
     *    no profile already owns this email;
     *  - a verified PRIMARY `profile_emails` row (the globally-unique login key);
     *  - an ACTIVE `memberships` row binding the profile to this tenant + role.
     *
     * profile_emails.email is globally UNIQUE, so when the email already has a
     * profile (e.g. the same person added to a second tenant) we REUSE that
     * profile and only add the tenant membership — never a duplicate identity.
     * Runs in a transaction so a partial identity can never be persisted.
     *
     * @param string $email        The account email (login key).
     * @param string $passwordHash The already-bcrypt-hashed password.
     * @param int    $roleId       The tenant role id for the membership.
     * @param int    $tenantId     The tenant the membership belongs to.
     */
    private function provisionProfileForUser(
        string $email,
        string $passwordHash,
        int $roleId,
        int $tenantId
    ): void {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            // Reuse an existing profile for this globally-unique email, else create one.
            // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
            $peStmt = $this->db->prepare('SELECT profile_id FROM profile_emails WHERE email = ? LIMIT 1');
            $peStmt->execute([$email]);
            $existingProfileId = $peStmt->fetchColumn();

            if ($existingProfileId !== false) {
                $profileId = (int) $existingProfileId;
            } else {
                // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                $profStmt = $this->db->prepare(
                    'INSERT INTO profiles
                         (display_name, password_hash, two_factor_enabled,
                          two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                     VALUES (?, ?, false, 0, 0, NOW(), NOW())'
                );
                $profStmt->execute([$this->localPart($email), $passwordHash]);
                $profileId = (int) $this->db->lastInsertId();

                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
                $this->db->prepare(
                    'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
                     VALUES (?, ?, true, true, NOW())'
                )->execute([$profileId, $email]);
            }

            // ACTIVE membership for this tenant (idempotent via UNIQUE(profile_id, tenant_id)).
            // @tenant-guard-ignore: membership provisioning during user creation (ADR 0005 §6)
            $this->db->prepare(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
                 VALUES (?, ?, ?, NULL, 'active', NOW())
                 ON CONFLICT (profile_id, tenant_id) DO NOTHING"
            )->execute([$profileId, $tenantId, $roleId]);

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** Local-part (before @) of an email, used as the profile display name. */
    private function localPart(string $email): string
    {
        $at = strrpos($email, '@');
        return $at !== false ? substr($email, 0, $at) : $email;
    }

    /**
     * @return array<string, mixed> Public-shaped user record.
     */
    private function fetchPublicUser(int $id): array
    {
        // @tenant-guard-ignore: by-PK re-read after the scoped guard+write proved this user belongs to the acting tenant
        $stmt = $this->db->prepare('
            SELECT u.id, u.email, u.password, u.created_at, u.tenant_id, u.ou_id, r.name as role
            FROM users u
            JOIN roles r ON u.role_id = r.id
            WHERE u.id = ?
        ');
        $stmt->execute([$id]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return [
                'id' => $id,
                'name' => '',
                'email' => '',
                'role' => '',
                'tenantId' => 0,
                'ou_id' => null,
                'createdAt' => null,
            ];
        }

        return $this->toPublicUser($row);
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

            $currentTenantId = TenantContext::getTenantId();
            $stmt = $this->db->prepare('SELECT id FROM users WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$id, $currentTenantId]);
            if (!$stmt->fetch()) {
                return Response::error('User not found', 404);
            }

            $deleteStmt = $this->db->prepare('DELETE FROM users WHERE id = ? AND tenant_id = ?');
            $deleteStmt->execute([$id, $currentTenantId]);

            // Notify listeners (e.g. the audit trail, WC-34) after deletion.
            $this->hookManager->dispatch('user.deleted', [
                'id' => (int)$id,
                'tenant_id' => $currentTenantId,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'User deleted']], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to delete user', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to delete user', 500);
        }
    }
}
