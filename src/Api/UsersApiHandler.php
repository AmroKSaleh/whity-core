<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\PasswordPolicy;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Users API Handler
 *
 * Handles CRUD operations for the `/api/users` admin endpoint.
 *
 * Identity source (WC-f3660e68 — ADR 0005 hard cutover, step F-a)
 * ---------------------------------------------------------------
 * This handler is the LAST /api/users CRUD consumer of the legacy per-tenant
 * `users` table; WC-f3660e68 migrates it to the global-identity model. Identity
 * (email, password, 2FA) now lives on the GLOBAL `profiles` + `profile_emails`
 * tables; role/OU/status live on the per-tenant `memberships` row. There is no
 * longer a `users` row in the read/write path here.
 *
 *  - The `id` in every list row, GET/{id}, and returned payload is the
 *    canonical `profile_id` (`profiles.id`). GET/{id}, PATCH/{id} and
 *    DELETE/{id} take a profile_id and operate on that profile's membership IN
 *    THE CURRENT TENANT.
 *  - A "user" in a tenant IS an ACTIVE membership. `list()` (and its count) is
 *    the set of profiles with an `active` membership in the tenant, so the
 *    headline total reconciles EXACTLY with {@see AdminApiHandler::stats()},
 *    which counts `memberships WHERE tenant_id = :tid AND status = 'active'`
 *    (system tenant 0: `memberships WHERE status = 'active'` across all tenants).
 *
 * Create (POST /api/users)
 * ------------------------
 * "Add a user to this tenant" = find-or-create a PROFILE by email (create
 * profile + verified primary profile_email + password_hash when the email is
 * new; REUSE the existing profile when the email already maps to one, since
 * profile_emails.email is globally unique), then INSERT an ACTIVE membership
 * (profile_id, tenant_id, role_id, status='active'). An active membership that
 * already exists for that profile in this tenant is rejected (409). The role is
 * resolved the same way as update via {@see self::resolveVisibleRoleId()} (a
 * role NAME as the Create form sends it, or a numeric role_id; absent role
 * defaults to the global `user` role; an unresolvable/foreign role is 404).
 *
 * Update (PATCH /api/users/{id})
 * ------------------------------
 * Updates the membership's `role_id` / `ou_id` for the tenant; email/password
 * changes update `profile_emails` / `profiles`. `name` is derived/read-only and
 * `tenantId` is out of scope (both ignored if sent). The membership carries the
 * tenant predicate on the write itself (defense in depth). A role/OU change
 * invalidates the worker-level effective-permission cache via
 * {@see RoleChecker::clearCache()} (WC-15), mirroring {@see RolesApiHandler}.
 *
 * Delete (DELETE /api/users/{id})
 * -------------------------------
 * Removes the caller-tenant MEMBERSHIP (ends the tenant occupancy), NOT the
 * global profile — the profile is global and may belong to other tenants.
 *
 * Tenant scoping
 * --------------
 * Every membership statement carries a parameterised `tenant_id` predicate
 * (qualified with the alias on joins). The SYSTEM tenant (id 0) acts with
 * cross-tenant authority: it lists/reads across ALL tenants (unscoped, with a
 * `@tenant-guard-ignore:` annotation) and may target any tenant's membership on
 * write, per the pre-cutover contract.
 */
class UsersApiHandler
{
    /** The reserved identifier for the system (cross-tenant authority) tenant. */
    private const SYSTEM_TENANT_ID = 0;

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
     * GET /api/users - List the users (ACTIVE memberships) of the current tenant.
     *
     * A row is a profile with an ACTIVE membership in the tenant (system tenant 0
     * spans all tenants). Only `status = 'active'` memberships are listed and
     * counted, so the headline `pagination.total` reconciles EXACTLY with
     * {@see AdminApiHandler::stats()} (active-membership count). Each row carries
     * the canonical `profile_id` as `id`, the profile's PRIMARY email, the
     * membership role name / ou_id / tenant_id / created_at.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $p = PaginationParams::fromPath($request->getPath());

            if ($tenantId === self::SYSTEM_TENANT_ID) {
                // @tenant-guard-ignore: system-tenant (id 0) counts active memberships across all tenants; scoped else-branch binds m.tenant_id = :tenant_id
                $countStmt = $this->db->prepare(
                    "SELECT COUNT(*) AS cnt FROM memberships m WHERE m.status = 'active'"
                );
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                // @tenant-guard-ignore: system-tenant (id 0) lists active memberships across all tenants; scoped else-branch binds m.tenant_id = :tenant_id
                $stmt = $this->db->prepare("
                    SELECT m.profile_id AS id, pe.email, r.name AS role,
                           m.tenant_id, m.ou_id, m.created_at, m.status
                    FROM memberships m
                    JOIN roles r ON m.role_id = r.id
                    LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                    WHERE m.status = 'active'
                    ORDER BY m.tenant_id, m.created_at DESC, m.profile_id ASC
                    LIMIT :limit OFFSET :offset
                ");
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $countStmt = $this->db->prepare(
                    "SELECT COUNT(*) AS cnt FROM memberships m WHERE m.tenant_id = :tenant_id AND m.status = 'active'"
                );
                $countStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                $stmt = $this->db->prepare("
                    SELECT m.profile_id AS id, pe.email, r.name AS role,
                           m.tenant_id, m.ou_id, m.created_at, m.status
                    FROM memberships m
                    JOIN roles r ON m.role_id = r.id
                    LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                    WHERE m.tenant_id = :tenant_id AND m.status = 'active'
                    ORDER BY m.created_at DESC, m.profile_id ASC
                    LIMIT :limit OFFSET :offset
                ");
                $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $users = array_map(fn (array $row): array => $this->toPublicUser($row), $rows);

            return Response::json(['data' => $users, 'pagination' => $p->meta($total)], 200);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to fetch users', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch users', 500);
        }
    }

    /**
     * Map a membership/profile row to the public API contract consumed by the UI.
     *
     * `id` is the canonical `profile_id`. There is no `name` column in the
     * identity model, so `name` is derived from the email local-part to give the
     * Edit User form a non-empty value to pre-fill (its zod schema marks name
     * required). Snake_case columns are aliased to the camelCase keys the
     * frontend `User` type binds; the password hash is never included.
     *
     * @param array<string, mixed> $row Raw row (id = profile_id, email, role, tenant_id, ou_id, created_at, status).
     * @return array{id: int, name: string, email: string, role: string, tenantId: int, ou_id: int|null, createdAt: string|null, status: string}
     */
    private function toPublicUser(array $row): array
    {
        $email = (string)($row['email'] ?? '');
        $localPart = strstr($email, '@', true);

        return [
            // `id` is the canonical profile_id (ADR 0005 hard cutover).
            'id' => (int)($row['id'] ?? 0),
            'name' => $localPart !== false && $localPart !== '' ? $localPart : $email,
            'email' => $email,
            'role' => (string)($row['role'] ?? ''),
            'tenantId' => (int)($row['tenant_id'] ?? 0),
            'ou_id' => isset($row['ou_id']) && $row['ou_id'] !== null ? (int)$row['ou_id'] : null,
            'createdAt' => isset($row['created_at']) ? (string)$row['created_at'] : null,
            // The membership status (active|invited|suspended). The list only
            // ever returns 'active', but GET/{id} may surface others.
            'status' => (string)($row['status'] ?? ''),
        ];
    }

    /**
     * GET /api/users/{id} - Read a single user (profile membership) by profile_id.
     *
     * Tenant-scoped: a non-system tenant reads only a membership in its OWN
     * tenant (a profile without a membership here is reported as 404); the SYSTEM
     * tenant (id 0) may read a membership in any tenant.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id` = profile_id).
     * @return Response JSON user under the `data` key (200) or an error.
     */
    public function get(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('User ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context required', 400);
            }
            $row = $this->fetchMembershipRow((int)$id, $tenantId);
            if ($row === null) {
                return Response::error('User not found', 404);
            }

            return Response::json(['data' => $this->toPublicUser($row)], 200);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to fetch user', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch user', 500);
        }
    }

    /**
     * POST /api/users - Add a user to the current tenant.
     *
     * Find-or-create a PROFILE by email (create profile + verified primary
     * profile_email + password_hash when the email is new; reuse the existing
     * profile when the email already maps to one), then INSERT an ACTIVE
     * membership binding that profile to the tenant + resolved role. Rejects
     * (409) when an active membership already exists for that profile in this
     * tenant. The role is resolved via {@see self::resolveVisibleRoleId()} (name
     * or numeric id; absent defaults to the global `user` role; an
     * unresolvable/foreign role is a 404). The SYSTEM tenant (id 0) creates in
     * the caller's TenantContext per the existing contract.
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

            $email = (string)$body['email'];
            // Bound the email (VARCHAR(255)) before any DB write.
            if ($tooLong = InputLimits::firstViolation(['email' => [$email, InputLimits::NAME_MAX]])) {
                return $tooLong;
            }
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context required', 400);
            }

            // Resolve the submitted role (a NAME as the Create form sends it, or a
            // numeric role_id). Absent role defaults to the global `user` role;
            // a supplied-but-unresolvable/foreign role is 404, mirroring update.
            $roleRef = $body['role'] ?? $body['role_id'] ?? null;
            $roleId = $this->resolveVisibleRoleId($roleRef, $tenantId, $tenantId);
            if ($roleRef !== null && $roleRef !== '' && $roleId === null) {
                return Response::error('Role not found', 404);
            }
            if ($roleId === null) {
                $roleId = $this->resolveVisibleRoleId('user', $tenantId, $tenantId);
                if ($roleId === null) {
                    return Response::error('Default role not found', 500);
                }
            }

            // Dispatch filter hook before creating the user (may modify email/role).
            $userData = $this->hookManager->dispatch('user.creating', [
                'email' => $email,
                'password' => $body['password'], // Pass plaintext password to hooks
                'role_id' => $roleId,
            ]);

            $email = (string)$userData['email'];
            $roleId = (int)$userData['role_id'];
            $passwordHash = password_hash((string)$userData['password'], PASSWORD_BCRYPT);

            $ownTx = !$this->db->inTransaction();
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            try {
                // Find-or-create the global profile for this (globally-unique) email.
                $profileId = $this->findOrCreateProfile($email, $passwordHash);

                // Reject when an ACTIVE membership already exists for this profile
                // in the tenant (a profile may be re-added after being removed, but
                // never double-added while active). This MUST check the exact
                // target tenant ($tenantId) — NOT fetchMembershipRow(), whose
                // system-tenant (0) branch resolves a membership in ANY tenant and
                // would produce a spurious 409 / a promote that matches no row.
                $existing = $this->fetchMembershipInTenant($profileId, $tenantId);
                if ($existing !== null && ($existing['status'] ?? '') === 'active') {
                    if ($ownTx && $this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    return Response::error('User already exists for this tenant', 409);
                }

                if ($existing !== null) {
                    // A non-active membership (invited/suspended) exists: promote it
                    // to active with the resolved role. The predicate is on
                    // (profile_id, tenant_id).
                    $upd = $this->db->prepare(
                        "UPDATE memberships SET status = 'active', role_id = :role_id
                         WHERE profile_id = :profile_id AND tenant_id = :tenant_id"
                    );
                    $upd->execute([
                        ':role_id' => $roleId,
                        ':profile_id' => $profileId,
                        ':tenant_id' => $tenantId,
                    ]);
                } else {
                    $ins = $this->db->prepare(
                        "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
                         VALUES (:profile_id, :tenant_id, :role_id, NULL, 'active', NOW())"
                    );
                    $ins->execute([
                        ':profile_id' => $profileId,
                        ':tenant_id' => $tenantId,
                        ':role_id' => $roleId,
                    ]);
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

            // Dispatch synchronous hook after the user is created. `id` is the
            // canonical profile_id (ADR 0005 hard cutover).
            $this->hookManager->dispatch('user.created', [
                'id' => $profileId,
                'email' => $email,
                'role_id' => $roleId,
                'tenant_id' => (int)$tenantId,
            ]);

            // Dispatch asynchronous hook for background tasks.
            $this->hookManager->dispatchAsync('user.created.async', [
                'id' => $profileId,
                'email' => $email,
            ]);

            $this->log('info', 'User created', [
                'event' => 'users.create',
                'tenant_id' => $tenantId,
                'user_id' => $profileId,
                'role_id' => $roleId,
            ]);

            $row = $this->fetchMembershipRow($profileId, $tenantId);

            return Response::json(['data' => $this->publicUserOrEmpty($row, $profileId, $tenantId)], 201);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to create user', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to create user', 500);
        }
    }

    /**
     * PATCH /api/users/{id} - Update a user (profile membership) by profile_id.
     *
     * Persists the editable fields: `email` and `password` on the PROFILE
     * (profile_emails / profiles); `role` and `ou_id` on the tenant MEMBERSHIP.
     * `name` is derived/read-only and `tenantId` is out of scope; both are
     * ignored if sent. Tenant-scoped + ownership-checked: a non-system tenant may
     * edit ONLY a profile with a membership in its own tenant (else 404), the
     * SYSTEM tenant (id 0) may edit across tenants, and an assigned role must be
     * visible to the tenant. A role/OU change invalidates the effective-permission
     * cache.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id` = profile_id).
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
            if ($currentTenantId === null) {
                return Response::error('Tenant context required', 400);
            }
            $body = JsonBody::parsed($request);
            $profileId = (int)$id;

            // Tenant ownership: the membership must exist in the caller's tenant
            // (the SYSTEM tenant sees across tenants). A profile without a
            // membership here is reported as 404 so tenant existence never leaks.
            $membership = $this->fetchMembershipRow($profileId, $currentTenantId);
            if ($membership === null) {
                return Response::error('User not found', 404);
            }

            // The owning tenant of the target membership scopes role visibility and
            // email-uniqueness checks (relevant when the SYSTEM tenant edits another
            // tenant's membership: validate against THAT tenant, not tenant 0).
            $ownerTenantId = (int)$membership['tenant_id'];

            $roleChanged = false;
            $ouChanged = false;
            $emailChanged = false;
            $passwordChanged = false;
            $newRoleId = null;
            $newOuId = null;
            $ouSetNull = false;
            $newEmail = null;
            $newPasswordHash = null;

            // Email change lives on profile_emails (global identity). Enforce the
            // GLOBAL uniqueness of profile_emails.email (ADR 0005 §2).
            $currentEmail = (string)($membership['email'] ?? '');
            if (isset($body['email']) && $body['email'] !== '' && $body['email'] !== $currentEmail) {
                // Bound the email (VARCHAR(255)) before the write.
                if ($tooLong = InputLimits::firstViolation(['email' => [(string) $body['email'], InputLimits::NAME_MAX]])) {
                    return $tooLong;
                }
                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
                $checkStmt = $this->db->prepare(
                    'SELECT profile_id FROM profile_emails WHERE email = ? AND profile_id != ?'
                );
                $checkStmt->execute([$body['email'], $profileId]);
                if ($checkStmt->fetch()) {
                    return Response::error('Email already exists', 409);
                }
                $newEmail = (string)$body['email'];
                $emailChanged = true;
            }

            // Password change lives on profiles (global identity).
            if (isset($body['password']) && !empty($body['password'])) {
                try {
                    PasswordPolicy::validate($body['password']);
                } catch (\InvalidArgumentException $e) {
                    $validationError = $e->getMessage();
                    return Response::error($validationError, 400);
                }
                $newPasswordHash = password_hash((string)$body['password'], PASSWORD_BCRYPT);
                $passwordChanged = true;
            }

            // Role assignment lives on the membership. The resolved role must be
            // visible to the acting tenant (owned by it, global, or — for the
            // SYSTEM tenant — any role).
            $roleRef = $body['role'] ?? $body['role_id'] ?? null;
            if ($roleRef !== null && $roleRef !== '') {
                $resolved = $this->resolveVisibleRoleId($roleRef, $currentTenantId, $ownerTenantId);
                if ($resolved === null) {
                    return Response::error('Role not found', 404);
                }
                if ($resolved !== (int)$membership['role_id']) {
                    $newRoleId = $resolved;
                    $roleChanged = true;
                }
            }

            // OU assignment lives on the membership (scoped to the owning tenant).
            if (isset($body['ou_id'])) {
                $ouId = $body['ou_id'];
                if ($ouId !== null && $ouId !== 0 && $ouId !== '') {
                    // SECURITY: ou_id must belong to the membership's owning tenant.
                    $stmtCheckOu = $this->db->prepare(
                        'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
                    );
                    $stmtCheckOu->execute([$ouId, $ownerTenantId]);
                    if (!$stmtCheckOu->fetch()) {
                        return Response::error('OU does not belong to current tenant', 403);
                    }
                    $newOuId = (int)$ouId;
                    $ouChanged = true;
                } else {
                    $ouSetNull = true;
                    $ouChanged = true;
                }
            }

            // A true no-op (nothing genuinely changed) still returns a sensible 200.
            if (!$roleChanged && !$ouChanged && !$emailChanged && !$passwordChanged) {
                $this->log('info', 'User update was a no-op', [
                    'event' => 'users.update.noop',
                    'tenant_id' => $currentTenantId,
                    'user_id' => $profileId,
                ]);

                return Response::json(['data' => $this->toPublicUser($membership)], 200);
            }

            $ownTx = !$this->db->inTransaction();
            if ($ownTx) {
                $this->db->beginTransaction();
            }

            try {
                // Identity writes (global tables).
                if ($emailChanged && $newEmail !== null) {
                    // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); scoped to the target profile's PRIMARY email
                    $this->db->prepare(
                        'UPDATE profile_emails SET email = ? WHERE profile_id = ? AND is_primary = true'
                    )->execute([$newEmail, $profileId]);
                }
                if ($passwordChanged && $newPasswordHash !== null) {
                    // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                    $this->db->prepare(
                        'UPDATE profiles SET password_hash = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$newPasswordHash, $profileId]);
                }

                // Membership writes (tenant-owned). The UPDATE carries the tenant
                // predicate itself (defense in depth). The SYSTEM tenant (id 0)
                // edits across tenants and stays unscoped; any other tenant is
                // pinned to the membership's OWNING tenant (which the guard already
                // proved equals the acting tenant).
                $membershipUpdates = [];
                $membershipParams = [];
                if ($roleChanged && $newRoleId !== null) {
                    $membershipUpdates[] = 'role_id = ?';
                    $membershipParams[] = $newRoleId;
                }
                if ($ouChanged) {
                    if ($ouSetNull) {
                        $membershipUpdates[] = 'ou_id = NULL';
                    } else {
                        $membershipUpdates[] = 'ou_id = ?';
                        $membershipParams[] = $newOuId;
                    }
                }

                if ($membershipUpdates !== []) {
                    // Always scope the write to the SINGLE resolved membership's
                    // tenant. For the system tenant (0) $ownerTenantId is the
                    // tenant of the membership resolved by fetchMembershipRow; a
                    // bare `WHERE profile_id = ?` would overwrite the profile's
                    // role/OU in EVERY tenant it belongs to (cross-tenant
                    // corruption + a foreign OU planted across the tenant
                    // boundary). For a normal tenant $ownerTenantId === the
                    // caller tenant. Either way exactly one membership changes.
                    $membershipParams[] = $profileId;
                    $membershipParams[] = $ownerTenantId;
                    $sql = 'UPDATE memberships SET ' . implode(', ', $membershipUpdates)
                        . ' WHERE profile_id = ? AND tenant_id = ?';
                    $this->db->prepare($sql)->execute($membershipParams);
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

            // A role re-assignment OR an OU-membership change alters the profile's
            // effective role/permission set (OU roles are inherited, WC-54);
            // invalidate the worker-level cache so RBAC checks are not stale.
            if ($roleChanged || $ouChanged) {
                RoleChecker::clearCache();
            }

            $this->log('info', 'User updated', [
                'event' => 'users.update',
                'tenant_id' => $currentTenantId,
                'user_id' => $profileId,
                'role_changed' => $roleChanged,
                'ou_changed' => $ouChanged,
            ]);

            // Notify listeners (e.g. the audit trail, WC-34) after a successful
            // update. The owning tenant scopes the record.
            $this->hookManager->dispatch('user.updated', [
                'id' => $profileId,
                'tenant_id' => $ownerTenantId,
                'role_changed' => $roleChanged,
                'ou_changed' => $ouChanged,
            ]);

            $row = $this->fetchMembershipRow($profileId, $ownerTenantId);

            return Response::json(['data' => $this->publicUserOrEmpty($row, $profileId, $ownerTenantId)], 200);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to update user', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to update user', 500);
        }
    }

    /**
     * DELETE /api/users/{id} - Remove a user's membership in the current tenant.
     *
     * Ends the caller-tenant MEMBERSHIP; the GLOBAL profile survives (it may
     * belong to other tenants). Tenant-scoped: a non-system tenant removes only a
     * membership in its own tenant (a profile without one here is 404); the
     * SYSTEM tenant (id 0) may remove a membership in any tenant.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id` = profile_id).
     * @return Response JSON confirmation (200) or an error.
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('User ID is required', 400);
            }

            $currentTenantId = TenantContext::getTenantId();
            if ($currentTenantId === null) {
                return Response::error('Tenant context required', 400);
            }
            $profileId = (int)$id;

            // Guard: the membership must exist in the caller's tenant.
            $membership = $this->fetchMembershipRow($profileId, $currentTenantId);
            if ($membership === null) {
                return Response::error('User not found', 404);
            }
            $ownerTenantId = (int)$membership['tenant_id'];

            // Remove the MEMBERSHIP (not the global profile). The DELETE carries
            // the tenant predicate itself; the SYSTEM tenant edits across tenants
            // and stays unscoped.
            if ($currentTenantId === self::SYSTEM_TENANT_ID) {
                // @tenant-guard-ignore: system-tenant (id 0) removes a membership in any tenant; scoped else-branch binds tenant_id
                $deleteStmt = $this->db->prepare('DELETE FROM memberships WHERE profile_id = ? AND tenant_id = ?');
                $deleteStmt->execute([$profileId, $ownerTenantId]);
            } else {
                $deleteStmt = $this->db->prepare('DELETE FROM memberships WHERE profile_id = ? AND tenant_id = ?');
                $deleteStmt->execute([$profileId, $currentTenantId]);
            }

            // A role/membership removal alters the profile's effective access;
            // invalidate the worker-level cache.
            RoleChecker::clearCache();

            // Notify listeners (e.g. the audit trail, WC-34) after removal.
            $this->hookManager->dispatch('user.deleted', [
                'id' => $profileId,
                'tenant_id' => $ownerTenantId,
            ]);

            return Response::json(['data' => ['id' => $profileId, 'message' => 'User deleted']], 200);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to delete user', [
                'event' => 'users.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to delete user', 500);
        }
    }

    /**
     * Fetch a single membership row (joined to its profile's primary email and
     * role) for a profile in a tenant, in the public row shape used by
     * {@see self::toPublicUser()}.
     *
     * Tenant-scoped: a non-system tenant is pinned to its own tenant_id; the
     * SYSTEM tenant (id 0) resolves the profile's membership in ANY tenant
     * (it targets exactly one membership — the caller supplies a profile_id and
     * the system tenant has cross-tenant authority; when a profile has
     * memberships in multiple tenants the most-recent is returned, matching the
     * cross-tenant "any tenant's membership" contract).
     *
     * @return array<string, mixed>|null Public-shaped row, or null when absent.
     */
    private function fetchMembershipRow(int $profileId, int $tenantId): ?array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            // @tenant-guard-ignore: system-tenant (id 0) resolves a profile's membership in any tenant; scoped else-branch binds m.tenant_id = ?
            $stmt = $this->db->prepare("
                SELECT m.profile_id AS id, pe.email, r.name AS role,
                       m.tenant_id, m.ou_id, m.created_at, m.status, m.role_id
                FROM memberships m
                JOIN roles r ON m.role_id = r.id
                LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                WHERE m.profile_id = ?
                ORDER BY m.created_at DESC, m.tenant_id ASC
                LIMIT 1
            ");
            $stmt->execute([$profileId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT m.profile_id AS id, pe.email, r.name AS role,
                       m.tenant_id, m.ou_id, m.created_at, m.status, m.role_id
                FROM memberships m
                JOIN roles r ON m.role_id = r.id
                LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                WHERE m.profile_id = ? AND m.tenant_id = ?
                LIMIT 1
            ");
            $stmt->execute([$profileId, $tenantId]);
        }

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Resolve a profile's membership in EXACTLY the given tenant (no system-tenant
     * cross-tenant resolution). Used by create() to decide add-vs-promote-vs-409
     * against the precise insert target — including the system tenant (0) itself,
     * where {@see self::fetchMembershipRow()} would instead resolve a membership
     * in some OTHER tenant.
     *
     * @return array<string, mixed>|null The (profile_id, tenant_id) membership, or null.
     */
    private function fetchMembershipInTenant(int $profileId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.profile_id AS id, m.tenant_id, m.status, m.role_id
             FROM memberships m
             WHERE m.profile_id = ? AND m.tenant_id = ?
             LIMIT 1"
        );
        $stmt->execute([$profileId, $tenantId]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Shape a membership row for the response, falling back to a minimal record
     * when the row could not be re-read (should not happen after a successful
     * write, but keeps the response contract non-null).
     *
     * @param array<string, mixed>|null $row
     * @return array<string, mixed>
     */
    private function publicUserOrEmpty(?array $row, int $profileId, int $tenantId): array
    {
        if ($row !== null) {
            return $this->toPublicUser($row);
        }

        return [
            'id' => $profileId,
            'name' => '',
            'email' => '',
            'role' => '',
            'tenantId' => $tenantId,
            'ou_id' => null,
            'createdAt' => null,
            'status' => '',
        ];
    }

    /**
     * Find the profile that owns a (globally-unique) email, else create a profile
     * + verified PRIMARY profile_email carrying the given password hash.
     *
     * profile_emails.email is globally UNIQUE (ADR 0005 §2), so when the email
     * already has a profile we REUSE it (the same person added to a second
     * tenant) and never create a duplicate identity. Must run inside the caller's
     * transaction so a partial identity can never be persisted.
     *
     * @return int The profile id (existing or newly created).
     */
    private function findOrCreateProfile(string $email, string $passwordHash): int
    {
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
        $peStmt = $this->db->prepare('SELECT profile_id FROM profile_emails WHERE email = ? LIMIT 1');
        $peStmt->execute([$email]);
        $existingProfileId = $peStmt->fetchColumn();

        if ($existingProfileId !== false) {
            return (int)$existingProfileId;
        }

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $profStmt = $this->db->prepare(
            'INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, NOW(), NOW())'
        );
        $profStmt->execute([$this->localPart($email), $passwordHash]);
        $profileId = (int)$this->db->lastInsertId();

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
        $this->db->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$profileId, $email]);

        return $profileId;
    }

    /** Local-part (before @) of an email, used as the profile display name. */
    private function localPart(string $email): string
    {
        $at = strrpos($email, '@');
        return $at !== false ? substr($email, 0, $at) : $email;
    }

    /**
     * Resolve a role reference (a role NAME from the form, or a numeric
     * `roles.id`) to a role id that is VISIBLE to the acting tenant.
     *
     * Visibility mirrors {@see RolesApiHandler}: a role is visible when it is
     * OWNED by the acting tenant (`roles.tenant_id = currentTenant`) OR is a
     * GLOBAL/system role (`roles.tenant_id IS NULL`). The SYSTEM tenant (id 0)
     * may assign any role. This prevents a tenant from assigning another tenant's
     * private role.
     *
     * @param mixed    $roleRef        Role name string or numeric role id.
     * @param int|null $actingTenantId The resolved acting tenant id (0 = SYSTEM).
     * @param int      $ownerTenantId  The owning tenant of the target membership.
     * @return int|null The resolved, visible role id, or null when not found/visible.
     */
    private function resolveVisibleRoleId(mixed $roleRef, ?int $actingTenantId, int $ownerTenantId): ?int
    {
        $isSystem = $actingTenantId === self::SYSTEM_TENANT_ID;
        $scopeTenantId = $isSystem ? $ownerTenantId : $actingTenantId;

        $byId = is_int($roleRef) || (is_string($roleRef) && $roleRef !== '' && ctype_digit($roleRef));

        if ($byId) {
            $column = 'id';
            $value = (int)$roleRef;
        } else {
            $column = 'name';
            $value = (string)$roleRef;
        }

        if ($isSystem) {
            // @tenant-guard-ignore: system-tenant role resolution; scoped else-branch binds (tenant_id = ? OR tenant_id IS NULL)
            $stmt = $this->db->prepare("SELECT id FROM roles WHERE {$column} = ? LIMIT 1");
            $stmt->execute([$value]);
        } else {
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
