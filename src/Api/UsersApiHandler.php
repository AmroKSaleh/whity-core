<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\PasswordPolicy;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Identity\ProfileProvisioner;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Core\Tenant\TenantContext;
use PDO;
use Whity\Core\Db\DbBool;

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
 * defaults to the global `user` role; an unresolvable/foreign role is 404). An
 * optional `ou_id` places the new membership in an organizational unit in the
 * same one call — validated by the SAME gate update() uses
 * ({@see self::resolveOuIdForTenant()}), so a foreign OU is a 403; omitting it
 * leaves the membership's `ou_id` NULL exactly as before.
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
 *
 * That authority is IMPLICIT everywhere above — the target tenant is inferred
 * from an existing membership — which is why none of it could attach a profile
 * to a tenant it was not already in (#797 §2). {@see self::addMembership()} is
 * the one place it becomes EXPLICIT: a `tenant_id` in the body, honoured for a
 * tenant-0 caller and refused for anyone else. The write still stamps the TARGET
 * tenant; acting across tenants never means writing tenant 0.
 */
class UsersApiHandler
{
    /** The reserved identifier for the system (cross-tenant authority) tenant. */
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Most memberships enumerated into a single audit payload (#889).
     *
     * Removing a user from a tenant deletes every membership they hold there,
     * and the audit row lists them so the trail can say what was lost. The list
     * is bounded because its length is not something an operator controls: it
     * is whatever the data happens to hold, and an unbounded blob in a metadata
     * column is a way for one request to write an arbitrarily large row. The
     * true count travels beside it, so exceeding the cap is legible rather than
     * silent.
     */
    private const AUDIT_MEMBERSHIP_LIST_CAP = 25;

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
                    "SELECT COUNT(*) AS cnt FROM memberships m WHERE m.is_primary AND m.status = 'active'"
                );
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                // @tenant-guard-ignore: system-tenant (id 0) lists active memberships across all tenants; scoped else-branch binds m.tenant_id = :tenant_id
                $stmt = $this->db->prepare("
                    SELECT m.profile_id AS id, pe.email, r.name AS role,
                           m.tenant_id, m.ou_id, m.created_at, m.status,
                           p.status AS account_status
                    FROM memberships m
                    JOIN roles r ON m.role_id = r.id
                    JOIN profiles p ON p.id = m.profile_id
                    LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                    WHERE m.is_primary AND m.status = 'active'
                    ORDER BY m.tenant_id, m.created_at DESC, m.profile_id ASC
                    LIMIT :limit OFFSET :offset
                ");
                $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $countStmt = $this->db->prepare(
                    "SELECT COUNT(*) AS cnt FROM memberships m WHERE m.is_primary AND m.tenant_id = :tenant_id AND m.status = 'active'"
                );
                $countStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
                $countStmt->execute();
                $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
                $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

                $stmt = $this->db->prepare("
                    SELECT m.profile_id AS id, pe.email, r.name AS role,
                           m.tenant_id, m.ou_id, m.created_at, m.status,
                           p.status AS account_status
                    FROM memberships m
                    JOIN roles r ON m.role_id = r.id
                    JOIN profiles p ON p.id = m.profile_id
                    LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                    WHERE m.is_primary AND m.tenant_id = :tenant_id AND m.status = 'active'
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
     * @param array<string, mixed> $row Raw row (id = profile_id, email, role, tenant_id, ou_id, created_at, status, account_status).
     * @return array{id: int, name: string, email: string, role: string, tenantId: int, ou_id: int|null, createdAt: string|null, status: string, accountStatus: string}
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
            // The GLOBAL account-level active/inactive switch (WC-user-status,
            // profiles.status — ADR 0005 §1). Deliberately distinct from
            // `status` above (the PER-TENANT membership lifecycle): deactivating
            // a profile blocks login everywhere it holds a membership, not just
            // in this tenant.
            'accountStatus' => (string)($row['account_status'] ?? 'active'),
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
     * unresolvable/foreign role is a 404). An optional `ou_id` places the
     * membership in an organizational unit of the SAME tenant (403 otherwise) so
     * provisioning needs no follow-up PATCH; omitted, the `ou_id` stays NULL.
     * The SYSTEM tenant (id 0) creates in the caller's TenantContext per the
     * existing contract.
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

            // Optional OU placement, so provisioning is ONE atomic call instead of
            // POST + a follow-up PATCH. Validated by the same gate update() uses
            // ({@see self::resolveOuIdForTenant()}), against the tenant the
            // membership is about to be created in — a foreign OU is refused, not
            // silently accepted. An absent/null `ou_id` resolves to null, i.e. the
            // pre-existing behaviour, byte for byte.
            $ouId = null;
            if (array_key_exists('ou_id', $body)) {
                $resolvedOu = $this->resolveOuIdForTenant($body['ou_id'], $tenantId);
                if ($resolvedOu instanceof Response) {
                    return $resolvedOu;
                }
                $ouId = $resolvedOu;
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

            // Whether this act revived an existing invited/suspended membership
            // rather than creating one. Initialised out here because the audit
            // payload below is built after the transaction block (#889).
            $promoted = false;

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

                // Which shape this act took, for the audit payload below (#889).
                $promoted = $existing !== null;

                if ($existing !== null) {
                    // A non-active membership (invited/suspended) exists: promote it
                    // to active with the resolved role. The predicate is on
                    // (profile_id, tenant_id). `ou_id` is only touched when the
                    // request actually carried one — an omitted `ou_id` must leave
                    // the OU the membership already had alone, not blank it.
                    $params = [
                        ':role_id' => $roleId,
                        ':profile_id' => $profileId,
                        ':tenant_id' => $tenantId,
                    ];
                    $ouAssignment = '';
                    if ($ouId !== null) {
                        $ouAssignment = ', ou_id = :ou_id';
                        $params[':ou_id'] = $ouId;
                    }
                    $upd = $this->db->prepare(
                        "UPDATE memberships SET status = 'active', role_id = :role_id{$ouAssignment}
                         WHERE profile_id = :profile_id AND tenant_id = :tenant_id"
                    );
                    $upd->execute($params);
                } else {
                    $ins = $this->db->prepare(
                        "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
                         VALUES (:profile_id, :tenant_id, :role_id, :ou_id, 'active', NOW())"
                    );
                    $ins->execute([
                        ':profile_id' => $profileId,
                        ':tenant_id' => $tenantId,
                        ':role_id' => $roleId,
                        ':ou_id' => $ouId,
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

            // Resolve the workspace name for listeners (drives the invitation
            // email). Best-effort — a lookup failure must not fail user creation.
            $tenantName = '';
            try {
                $tnStmt = $this->db->prepare('SELECT name FROM tenants WHERE id = :id');
                $tnStmt->execute([':id' => (int) $tenantId]);
                $tenantName = (string) ($tnStmt->fetchColumn() ?: '');
            } catch (\Throwable) {
                $tenantName = '';
            }

            // Dispatch synchronous hook after the user is created. `id` is the
            // canonical profile_id (ADR 0005 hard cutover).
            //
            // ONE audit row for one administrative act (#889). This path creates
            // a membership, and it deliberately does NOT also dispatch
            // `user.membership.added`: `user.created` is already audited and
            // already targets the user, so a second event would put two rows in
            // an append-only trail for a single click — and creating a user is
            // by far the most common membership write, so doubling it is exactly
            // the flood that drowns the signal. It carries the membership detail
            // instead. `promoted` says which shape it was: a fresh membership,
            // or an `invited`/`suspended` row revived to active with this role,
            // which is a REINSTATEMENT and reads very differently in a timeline.
            $this->hookManager->dispatch('user.created', [
                'id' => $profileId,
                'email' => $email,
                'role_id' => $roleId,
                'role_name' => $this->roleNameVisibleToTenant($roleId, (int)$tenantId),
                'ou_id' => $ouId,
                'promoted' => $promoted,
                'tenant_id' => (int)$tenantId,
                'tenant_name' => $tenantName,
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
     * Persists the editable fields: `email`, `password` and `accountStatus` on
     * the PROFILE (profile_emails / profiles); `role` and `ou_id` on the tenant
     * MEMBERSHIP. `accountStatus` (WC-user-status) is the admin deactivate/
     * reactivate control — 'active' | 'inactive' — gated on the SAME
     * CorePermissions::USERS_WRITE as every other field here (no dedicated
     * permission). It is deliberately GLOBAL (unlike the membership-lifecycle
     * `status` in the response, which stays per-tenant): toggling it blocks/
     * restores login for the profile everywhere it holds a membership, not just
     * in the caller's tenant. `name` is derived/read-only and `tenantId` is out
     * of scope; both are ignored if sent. Tenant-scoped + ownership-checked: a
     * non-system tenant may edit ONLY a profile with a membership in its own
     * tenant (else 404), the
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
            $accountStatusChanged = false;
            $newRoleId = null;
            $newOuId = null;
            $ouSetNull = false;
            $newEmail = null;
            $newPasswordHash = null;
            $newAccountStatus = null;

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
            // array_key_exists (not isset): an explicit `null` — "clear the OU" —
            // must be honoured; isset() is false for a null value and would
            // otherwise silently drop the change (the same class of bug
            // OusApiHandler::update() documents for parent_id).
            if (array_key_exists('ou_id', $body)) {
                $resolvedOu = $this->resolveOuIdForTenant($body['ou_id'], $ownerTenantId);
                if ($resolvedOu instanceof Response) {
                    return $resolvedOu;
                }
                if ($resolvedOu === null) {
                    $ouSetNull = true;
                } else {
                    $newOuId = $resolvedOu;
                }
                $ouChanged = true;
            }

            // Account-level status (profiles.status) — the WC-user-status
            // admin deactivate/reactivate control (CorePermissions::USERS_WRITE,
            // same gate as every other field on this endpoint; no dedicated
            // permission was introduced). Deliberately GLOBAL, unlike the
            // membership `status` above: this affects the profile everywhere it
            // holds a membership, not just the caller's tenant.
            if (isset($body['accountStatus']) && $body['accountStatus'] !== '') {
                $requestedAccountStatus = (string)$body['accountStatus'];
                if (!in_array($requestedAccountStatus, ['active', 'inactive'], true)) {
                    return Response::error('Invalid account status', 400);
                }
                $currentAccountStatus = (string)($membership['account_status'] ?? 'active');
                if ($requestedAccountStatus !== $currentAccountStatus) {
                    $newAccountStatus = $requestedAccountStatus;
                    $accountStatusChanged = true;
                }
            }

            // A true no-op (nothing genuinely changed) still returns a sensible 200.
            if (!$roleChanged && !$ouChanged && !$emailChanged && !$passwordChanged && !$accountStatusChanged) {
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
                    // token_epoch is bumped WITH the hash, never separately.
                    //
                    // A password change is a credential change and must
                    // invalidate every existing session — which is the whole
                    // point when an administrator resets an account they
                    // believe is compromised. Without the bump the attacker's
                    // session survives the reset, and the administrator is left
                    // believing they closed a door that is still open.
                    //
                    // PasswordResetService and AuthHandler::handleUpdateMe()
                    // have always done this; this path did not, so it was the
                    // one credential change that left sessions alive.
                    //
                    // Deliberately does NOT touch any two_factor_* column: an
                    // administrator resetting a password must not silently
                    // strip 2FA from an account that still has an authenticator
                    // enrolled. Clearing 2FA is a separate, explicit action.
                    //
                    // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                    $this->db->prepare(
                        'UPDATE profiles
                            SET password_hash = ?, token_epoch = token_epoch + 1, updated_at = NOW()
                          WHERE id = ?'
                    )->execute([$newPasswordHash, $profileId]);
                }
                if ($accountStatusChanged && $newAccountStatus !== null) {
                    // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
                    $this->db->prepare(
                        'UPDATE profiles SET status = ?, updated_at = NOW() WHERE id = ?'
                    )->execute([$newAccountStatus, $profileId]);
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
                'account_status_changed' => $accountStatusChanged,
            ]);

            // Notify listeners (e.g. the audit trail, WC-34) after a successful
            // update. The owning tenant scopes the record.
            //
            // The from/to ids are the point (#889). This endpoint — not the
            // memberships endpoints — is where a person's PRIMARY role is
            // changed, so it is where most authority on this platform actually
            // moves. It was reporting `role_changed: true` and nothing else,
            // which records that authority changed while making it impossible to
            // say what it changed FROM or TO. Reconstructing "who held manager
            // on the 14th" from a column of booleans cannot be done, and an
            // append-only trail gets no second chance to write the ids down.
            //
            // A role reassignment is deliberately ONE row rather than a
            // synthesised removed+added pair: it is one act, it is not a
            // revocation (access is replaced, not withdrawn), and two rows would
            // report two events that never happened separately.
            $payload = [
                'id' => $profileId,
                'tenant_id' => $ownerTenantId,
                'role_changed' => $roleChanged,
                'ou_changed' => $ouChanged,
                'account_status_changed' => $accountStatusChanged,
            ];
            if ($roleChanged && $newRoleId !== null) {
                $previousRoleId = (int) $membership['role_id'];
                $payload['previous_role_id'] = $previousRoleId;
                $payload['previous_role_name'] = isset($membership['role']) ? (string) $membership['role'] : null;
                $payload['role_id'] = $newRoleId;
                $payload['role_name'] = $this->roleNameVisibleToTenant($newRoleId, $ownerTenantId);
            }
            if ($ouChanged) {
                $payload['previous_ou_id'] = $membership['ou_id'] !== null ? (int) $membership['ou_id'] : null;
                $payload['ou_id'] = $ouSetNull ? null : $newOuId;
            }
            if ($accountStatusChanged && $newAccountStatus !== null) {
                $payload['previous_account_status'] = (string) ($membership['account_status'] ?? 'active');
                $payload['account_status'] = $newAccountStatus;
            }

            $this->hookManager->dispatch('user.updated', $payload);

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

            // Read every row about to go, BEFORE it goes (#889).
            //
            // This DELETE is predicated on (profile_id, tenant_id), so it takes
            // the primary membership AND every extra role the person holds here
            // — a three-role person loses three rows. `user.deleted` was the
            // only signal, and it named none of them, so the platform could say
            // somebody was removed from a tenant and never what they had been
            // able to do in it.
            //
            // Enumerated into ONE row rather than dispatched per membership:
            // this is one administrative act, and one act that emits N audit
            // rows is how a provisioning run floods a trail. The list is capped
            // for the same reason, with the true count kept alongside it so a
            // truncated list is visibly truncated rather than quietly wrong.
            $lost = $this->membershipsHeldBy($profileId, $ownerTenantId);

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

            // The authoritative number of memberships this act ended — taken
            // from the DELETE itself, not from the capped list above, so a
            // truncated list is visibly truncated instead of quietly reporting
            // its own length as the total.
            $removedCount = $deleteStmt->rowCount();

            // A role/membership removal alters the profile's effective access;
            // invalidate the worker-level cache.
            RoleChecker::clearCache();

            // Resolve the removed user's email + workspace name for the farewell /
            // termination email, and the optional removal reason. Best-effort — a
            // lookup failure must not fail the removal. The profile + email are
            // GLOBAL (survive the membership delete), and the tenant row survives.
            $recipientEmail = '';
            $tenantName = '';
            try {
                // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL table (ADR 0005 §2)
                $peStmt = $this->db->prepare(
                    'SELECT email FROM profile_emails WHERE profile_id = :pid AND is_primary = true'
                );
                $peStmt->execute([':pid' => $profileId]);
                $recipientEmail = (string) ($peStmt->fetchColumn() ?: '');

                $tnStmt = $this->db->prepare('SELECT name FROM tenants WHERE id = :id');
                $tnStmt->execute([':id' => $ownerTenantId]);
                $tenantName = (string) ($tnStmt->fetchColumn() ?: '');
            } catch (\Throwable) {
                // leave blank; the subscriber no-ops without a recipient.
            }
            $reason = strtolower(trim((string) (JsonBody::parsed($request)['reason'] ?? '')));

            // Notify listeners (e.g. the audit trail, WC-34) after removal.
            $this->hookManager->dispatch('user.deleted', [
                'id' => $profileId,
                'tenant_id' => $ownerTenantId,
                'email' => $recipientEmail,
                'tenant_name' => $tenantName,
                // '' (friendly farewell) or 'terms_violation' (ToS termination).
                'reason' => $reason,
                // What this removal actually took away (#889).
                'memberships_removed' => $removedCount,
                'roles_lost' => $lost,
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
     * GET /api/users/{id}/memberships — every role this profile holds.
     *
     * The user LIST deliberately shows one row per person carrying their PRIMARY
     * role (#780), so a second role is invisible there by design. This is where
     * it becomes visible.
     *
     * SCOPE (#797 §2): a tenant sees its OWN memberships and nothing else. The
     * SYSTEM tenant (0) sees EVERY tenant the profile belongs to — the "which
     * tenants is this person in?" question, which had no API at all and was
     * answered by reading the table by hand. Each row therefore names its tenant;
     * for a tenant caller that is a constant, for tenant 0 it is the point.
     *
     * @param array<string, mixed> $params  Route params (expects `id` = profile_id).
     */
    public function listMemberships(Request $request, array $params): Response
    {
        try {
            $profileId = (int) ($params['id'] ?? 0);
            if ($profileId <= 0) {
                return Response::error('User ID is required', 400);
            }

            $currentTenantId = TenantContext::getTenantId();
            if ($currentTenantId === null) {
                return Response::error('Tenant context required', 400);
            }

            // The two statements are written out in full rather than sharing a
            // SELECT fragment: the tenant predicate is the security boundary and
            // the CI guard reads these literals, so a query assembled from parts
            // hides the very thing both a reviewer and the guard are looking for.
            if ($currentTenantId === self::SYSTEM_TENANT_ID) {
                // A profile with no membership anywhere is an empty list, not a
                // 404: "this person is in no tenant" is an answer the operator
                // needs, and it is exactly the state a repair leaves behind.
                if (!$this->profileExists($profileId)) {
                    return Response::error('User not found', 404);
                }

                // @tenant-guard-ignore: system-tenant (id 0) lists a profile's memberships across all tenants; scoped else-branch binds m.tenant_id = ?
                $stmt = $this->db->prepare(
                    'SELECT m.id, m.tenant_id, t.name AS tenant_name, m.role_id, r.name AS role,
                            m.ou_id, m.is_primary, m.status
                       FROM memberships m
                       JOIN roles r ON r.id = m.role_id
                       LEFT JOIN tenants t ON t.id = m.tenant_id
                      WHERE m.profile_id = ?
                      ORDER BY m.tenant_id ASC, m.is_primary DESC, m.id ASC'
                );
                $stmt->execute([$profileId]);
            } else {
                $membership = $this->fetchMembershipRow($profileId, $currentTenantId);
                if ($membership === null) {
                    return Response::error('User not found', 404);
                }
                $ownerTenantId = (int) $membership['tenant_id'];

                $stmt = $this->db->prepare(
                    'SELECT m.id, m.tenant_id, t.name AS tenant_name, m.role_id, r.name AS role,
                            m.ou_id, m.is_primary, m.status
                       FROM memberships m
                       JOIN roles r ON r.id = m.role_id
                       LEFT JOIN tenants t ON t.id = m.tenant_id
                      WHERE m.profile_id = ? AND m.tenant_id = ?
                      ORDER BY m.is_primary DESC, m.id ASC'
                );
                $stmt->execute([$profileId, $ownerTenantId]);
            }

            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[] = [
                    'id'         => (int) $row['id'],
                    'tenantId'   => (int) $row['tenant_id'],
                    'tenantName' => (string) ($row['tenant_name'] ?? ''),
                    'roleId'     => (int) $row['role_id'],
                    'role'       => (string) $row['role'],
                    'ou_id'      => $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
                    'isPrimary'  => DbBool::of($row['is_primary']),
                    'status'     => (string) $row['status'],
                ];
            }

            return Response::json(['data' => $rows]);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to fetch memberships', [
                'event' => 'users.memberships.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch memberships', 500);
        }
    }

    /**
     * POST /api/users/{id}/memberships — grant a role in a tenant.
     *
     * The doctor attending in Emergency who also part-times in Family Medicine:
     * a second membership, its own role, its own OU. Such a row is written
     * `is_primary = false`, because the primary row is what answers "what is this
     * person here?" for display and defaults, and there must stay exactly one of
     * those — migration 094's partial unique index enforces it.
     *
     * Idempotent: granting a role the profile already holds with the same OU
     * returns the existing membership instead of creating a duplicate. There is
     * no unique constraint on (profile, tenant, role) — a duplicate secondary row
     * would be meaningless rather than illegal — so this is enforced here.
     *
     * CROSS-TENANT (#797 §2)
     * ----------------------
     * An optional `tenant_id` names the tenant to grant IN, honoured only for a
     * SYSTEM-tenant (0) caller. It is what closes the reported gap: until now
     * every write path derived the tenant from the caller's own context, so
     * putting one profile in two tenants meant an INSERT by hand. When the
     * profile has no membership in the target yet, THAT row is the target
     * tenant's primary — it is the answer to "what is this person here".
     *
     * Widening this endpoint rather than POST /api/users is deliberate: that
     * endpoint's contract is "create a person HERE", and a second, cross-tenant
     * mode bolted onto it is how an endpoint acquires two meanings. Memberships
     * are already the thing being manipulated.
     *
     * An explicit `tenant_id` from a non-system caller is REFUSED, not ignored: a
     * field that is accepted and silently discarded teaches the caller it worked.
     *
     * @param array<string, mixed> $params  Route params (expects `id` = profile_id).
     */
    public function addMembership(Request $request, array $params): Response
    {
        try {
            $profileId = (int) ($params['id'] ?? 0);
            if ($profileId <= 0) {
                return Response::error('User ID is required', 400);
            }

            $currentTenantId = TenantContext::getTenantId();
            if ($currentTenantId === null) {
                return Response::error('Tenant context required', 400);
            }

            $body = json_decode($request->getBody(), true);
            // Read the target BEFORE the body is validated as a whole, so the two
            // branches below can keep their own error ordering. A malformed body
            // carries no `tenant_id` by definition and therefore takes the
            // unchanged branch, which still answers 404-before-400 as it always
            // did.
            $requestedTenantId = is_array($body) ? ($body['tenant_id'] ?? null) : null;

            if ($requestedTenantId === null || $requestedTenantId === '') {
                // Unchanged: the tenant is the caller's, and the profile must
                // already be in it.
                $membership = $this->fetchMembershipRow($profileId, $currentTenantId);
                if ($membership === null) {
                    return Response::error('User not found', 404);
                }
                $ownerTenantId = (int) $membership['tenant_id'];
                $isCrossTenant = false;
            } else {
                if ($currentTenantId !== self::SYSTEM_TENANT_ID) {
                    return Response::error('Only the system tenant may name a target tenant', 403);
                }

                // Rejecting a non-integer HERE keeps `{"tenant_id": "sales"}` a
                // clean 400 rather than an "invalid input syntax for integer"
                // driver error surfacing as an opaque 500 on PostgreSQL.
                if (!is_int($requestedTenantId)
                    && !(is_string($requestedTenantId) && ctype_digit($requestedTenantId))
                ) {
                    return Response::error('Invalid tenant_id', 400);
                }

                $ownerTenantId = (int) $requestedTenantId;
                if (!$this->tenantExists($ownerTenantId)) {
                    return Response::error('Tenant not found', 404);
                }

                // The profile need NOT be in the target tenant — that is the
                // whole point — so its existence is checked against the global
                // identity table instead of a membership.
                if (!$this->profileExists($profileId)) {
                    return Response::error('User not found', 404);
                }

                $isCrossTenant = true;
            }

            if (!is_array($body)) {
                return Response::error('Invalid request body', 400);
            }

            $roleRef = $body['role_id'] ?? $body['role'] ?? null;
            if ($roleRef === null || $roleRef === '') {
                return Response::error('role_id is required', 400);
            }

            // Same visibility rule as create/update: a tenant may grant only its
            // own roles or a global one, so this cannot become a way to attach
            // another tenant's role.
            //
            // The cross-tenant branch resolves against the TARGET tenant rather
            // than through the system tenant's usual unscoped lookup. The system
            // tenant assigning any role is harmless while it is acting AS the
            // owning tenant; here the caller names both, so an unscoped lookup
            // would let tenant A's private permission set take effect inside
            // tenant B — a cross-tenant leak written one role id at a time.
            $roleId = $isCrossTenant
                ? $this->resolveRoleIdVisibleToTenant($roleRef, $ownerTenantId)
                : $this->resolveVisibleRoleId($roleRef, $currentTenantId, $ownerTenantId);
            if ($roleId === null) {
                return Response::error('Role not found', 404);
            }

            // Reuses the create/update gate, so a foreign OU is a 403 here too.
            $ouId = $this->resolveOuIdForTenant($body['ou_id'] ?? null, $ownerTenantId);
            if ($ouId instanceof Response) {
                return $ouId;
            }

            // Null-safe comparison written longhand: SQLite has no
            // IS NOT DISTINCT FROM, and this suite runs on both engines.
            //
            // The CASTs are load-bearing on PostgreSQL. A bare placeholder in
            // `? IS NULL` gives the planner nothing to infer a type from and it
            // refuses the statement outright (42P18, "could not determine data
            // type of parameter") — so without them every grant answered 500 on
            // PostgreSQL while passing on SQLite, which infers happily.
            $existing = $this->db->prepare(
                'SELECT id, is_primary FROM memberships
                  WHERE profile_id = ? AND tenant_id = ? AND role_id = ?
                    AND ((ou_id IS NULL AND CAST(? AS INTEGER) IS NULL)
                          OR ou_id = CAST(? AS INTEGER))
                  LIMIT 1'
            );
            $existing->execute([$profileId, $ownerTenantId, $roleId, $ouId, $ouId]);
            $found = $existing->fetch(PDO::FETCH_ASSOC);

            if ($found !== false) {
                return Response::json([
                    'data' => [
                        'id'        => (int) $found['id'],
                        'tenantId'  => $ownerTenantId,
                        'roleId'    => $roleId,
                        'ou_id'     => $ouId,
                        'isPrimary' => DbBool::of($found['is_primary']),
                        'created'   => false,
                    ],
                ]);
            }

            // The FIRST membership in a tenant is that tenant's primary row —
            // without one, every single-row read ("what is this person here?")
            // has no answer. Only reachable cross-tenant: the unchanged branch
            // requires an existing membership, so it is always an extra role.
            $isPrimary = $isCrossTenant
                && $this->fetchMembershipInTenant($profileId, $ownerTenantId) === null;

            // A literal, not a placeholder: PostgreSQL rejects the integer 1/0 a
            // bound PHP bool becomes for a BOOLEAN column, which SQLite accepts
            // happily — the class of divergence that only shows up in CI's
            // PostgreSQL shard.
            $primaryLiteral = $isPrimary ? 'true' : 'false';
            $insert = $this->db->prepare(
                "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
                 VALUES (?, ?, ?, ?, {$primaryLiteral}, 'active', NOW())"
            );
            $insert->execute([$profileId, $ownerTenantId, $roleId, $ouId]);
            $newId = (int) $this->db->lastInsertId();

            // A new role changes effective access, so the worker-level permission
            // cache must not keep serving the old answer (#701/#727: a stale grant
            // on seven of eight workers reads as test flakiness).
            //
            // It is also the grant half of the audit trail (#889), and it carries
            // the SAME fields the revocation does. A trail whose grants and
            // revocations describe an authority differently cannot be read as a
            // sequence — matching one against the other becomes a judgement call
            // rather than a join on `role_id`.
            $this->hookManager->dispatch('user.membership.added', [
                'profile_id'    => $profileId,
                'tenant_id'     => $ownerTenantId,
                'membership_id' => $newId,
                'role_id'       => $roleId,
                'role_name'     => $this->roleNameVisibleToTenant($roleId, $ownerTenantId),
                'ou_id'         => $ouId,
                'is_primary'    => $isPrimary,
            ]);

            return Response::json([
                'data' => [
                    'id'        => $newId,
                    'tenantId'  => $ownerTenantId,
                    'roleId'    => $roleId,
                    'ou_id'     => $ouId,
                    'isPrimary' => $isPrimary,
                    'created'   => true,
                ],
            ], 201);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to add membership', [
                'event' => 'users.memberships.add.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to add membership', 500);
        }
    }

    /**
     * DELETE /api/users/{id}/memberships/{membershipId} — revoke an extra role.
     *
     * Refuses to remove the PRIMARY membership. That row is the person's presence
     * in the tenant: removing it here would leave them holding secondary roles
     * with no primary, which every single-row read interprets as "no answer to
     * what is this person here". Removing someone from a tenant is
     * DELETE /api/users/{id}, which takes every row.
     *
     * The SYSTEM tenant (0) resolves the tenant from the MEMBERSHIP ROW rather
     * than from its own context, so a grant it just made in another tenant (#797
     * §2) is revocable in-product instead of by hand. The row's id already names
     * exactly one tenant, so nothing is being guessed — and every statement below
     * still binds THAT tenant_id, never 0. Previously this branch resolved some
     * arbitrary tenant of the profile's, which could only delete by luck.
     *
     * @param array<string, mixed> $params  Route params (`id` = profile_id, `membershipId`).
     */
    public function removeMembership(Request $request, array $params): Response
    {
        try {
            $profileId    = (int) ($params['id'] ?? 0);
            $membershipId = (int) ($params['membershipId'] ?? 0);
            if ($profileId <= 0 || $membershipId <= 0) {
                return Response::error('User ID and membership ID are required', 400);
            }

            $currentTenantId = TenantContext::getTenantId();
            if ($currentTenantId === null) {
                return Response::error('Tenant context required', 400);
            }

            if ($currentTenantId === self::SYSTEM_TENANT_ID) {
                $ownerTenantId = $this->tenantOfMembership($membershipId, $profileId);
                if ($ownerTenantId === null) {
                    return Response::error('Membership not found', 404);
                }
            } else {
                $membership = $this->fetchMembershipRow($profileId, $currentTenantId);
                if ($membership === null) {
                    return Response::error('User not found', 404);
                }
                $ownerTenantId = (int) $membership['tenant_id'];
            }

            // Reads everything the row HOLDS, not merely what this method needs
            // in order to decide (#889). Once the DELETE below runs, the audit
            // row is the only place any of it still exists — so a revocation
            // that recorded just "membership removed" would answer "who lost
            // access" and never "access to WHAT", which is the half an incident
            // actually turns on.
            //
            // `roles` is joined for the NAME as well as the id. A bare role_id
            // dangles the moment that role is deleted, and a trail that has to
            // be read against a table that no longer contains the row is not an
            // append-only record of what happened. The join is on the role id
            // the membership itself carries, inside a statement already pinned
            // to one tenant, so it reads back a role this tenant demonstrably
            // held — it is not a lookup by any caller-supplied id and cannot
            // become a way to enumerate roles.
            $stmt = $this->db->prepare(
                'SELECT m.id, m.is_primary, m.role_id, m.ou_id, m.status, m.created_at,
                        r.name AS role_name
                   FROM memberships m
                   LEFT JOIN roles r ON r.id = m.role_id
                  WHERE m.id = ? AND m.profile_id = ? AND m.tenant_id = ? LIMIT 1'
            );
            $stmt->execute([$membershipId, $profileId, $ownerTenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return Response::error('Membership not found', 404);
            }

            if (DbBool::of($row['is_primary'])) {
                return Response::error(
                    'Cannot remove the primary membership; use DELETE /api/users/{id} to remove the user from this tenant',
                    409
                );
            }

            $del = $this->db->prepare('DELETE FROM memberships WHERE id = ? AND profile_id = ? AND tenant_id = ?');
            $del->execute([$membershipId, $profileId, $ownerTenantId]);

            // The payload IS the surviving record of the deleted row (#889).
            // {@see \Whity\Core\Audit\AuditLogger::subscribe()} maps this event
            // to an audit row targeting the USER, with everything below except
            // profile_id/tenant_id landing in metadata verbatim. `granted_at` is
            // the membership's own created_at, so the pair of rows answers not
            // just "who took this away and when" but "how long did they hold
            // it" — which is the question a compromised-account timeline is
            // built out of.
            $this->hookManager->dispatch('user.membership.removed', [
                'profile_id'    => $profileId,
                'tenant_id'     => $ownerTenantId,
                'membership_id' => $membershipId,
                'role_id'       => isset($row['role_id']) ? (int) $row['role_id'] : null,
                'role_name'     => isset($row['role_name']) ? (string) $row['role_name'] : null,
                'ou_id'         => isset($row['ou_id']) ? (int) $row['ou_id'] : null,
                'status'        => isset($row['status']) ? (string) $row['status'] : null,
                'granted_at'    => isset($row['created_at']) ? (string) $row['created_at'] : null,
            ]);

            return Response::json(['data' => ['id' => $membershipId, 'removed' => true]]);
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to remove membership', [
                'event' => 'users.memberships.remove.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to remove membership', 500);
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
                       m.tenant_id, m.ou_id, m.created_at, m.status, m.role_id,
                       p.status AS account_status
                FROM memberships m
                JOIN roles r ON m.role_id = r.id
                JOIN profiles p ON p.id = m.profile_id
                LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                WHERE m.profile_id = ?
                ORDER BY m.created_at DESC, m.tenant_id ASC
                LIMIT 1
            ");
            $stmt->execute([$profileId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT m.profile_id AS id, pe.email, r.name AS role,
                       m.tenant_id, m.ou_id, m.created_at, m.status, m.role_id,
                       p.status AS account_status
                FROM memberships m
                JOIN roles r ON m.role_id = r.id
                JOIN profiles p ON p.id = m.profile_id
                LEFT JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                WHERE m.profile_id = ? AND m.tenant_id = ?
                ORDER BY m.is_primary DESC, m.id ASC
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
             ORDER BY m.is_primary DESC, m.id ASC
             LIMIT 1"
        );
        $stmt->execute([$profileId, $tenantId]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Every membership a profile holds in one tenant, in audit-payload shape (#889).
     *
     * Read immediately before a bulk removal so the resulting audit row can say
     * what was lost, since afterwards there is nothing left to read.
     *
     * CAPPED, and the count is reported separately. An unbounded list would put
     * an arbitrarily large blob into a metadata column on a path an operator
     * does not control — and a person with hundreds of memberships in one tenant
     * is a data anomaly, not a case worth serialising in full. Reporting the
     * true `memberships_removed` count beside a capped list means a truncated
     * row is visibly truncated: `count > len(roles_lost)` says so plainly,
     * whereas a silently short list would read as a complete one.
     *
     * Best-effort: the removal must not fail because the trail could not be
     * prepared. A read failure yields an empty list, and the count of 0 beside
     * it is then honest about knowing nothing rather than asserting nothing was
     * lost — the caller pairs it with the removal's own outcome.
     *
     * @return list<array<string, mixed>>
     */
    private function membershipsHeldBy(int $profileId, int $tenantId): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT m.id, m.role_id, m.ou_id, m.status, m.is_primary, m.created_at,
                        r.name AS role_name
                   FROM memberships m
                   LEFT JOIN roles r ON r.id = m.role_id
                  WHERE m.profile_id = ? AND m.tenant_id = ?
                  ORDER BY m.is_primary DESC, m.id ASC
                  LIMIT ' . self::AUDIT_MEMBERSHIP_LIST_CAP
            );
            $stmt->execute([$profileId, $tenantId]);

            $out = [];
            /** @var array<string, mixed> $row */
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[] = [
                    'membership_id' => (int) $row['id'],
                    'role_id'       => isset($row['role_id']) ? (int) $row['role_id'] : null,
                    'role_name'     => isset($row['role_name']) ? (string) $row['role_name'] : null,
                    'ou_id'         => isset($row['ou_id']) ? (int) $row['ou_id'] : null,
                    'status'        => isset($row['status']) ? (string) $row['status'] : null,
                    'is_primary'    => DbBool::of($row['is_primary']),
                    'granted_at'    => isset($row['created_at']) ? (string) $row['created_at'] : null,
                ];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * The NAME of a role visible to a tenant — its own, or a global one (#889).
     *
     * Recorded into the audit payload beside `role_id` so a grant row stays
     * legible after the role itself is gone. `memberships.role_id` is
     * `ON DELETE CASCADE`, so deleting a role silently removes every membership
     * holding it with no per-row event; from that moment an id alone points
     * into a table that no longer has the row.
     *
     * Best-effort by design: a null name degrades the audit row's readability
     * and must never fail the grant that produced it.
     */
    private function roleNameVisibleToTenant(int $roleId, int $tenantId): ?string
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT name FROM roles WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1'
            );
            $stmt->execute([$roleId, $tenantId]);
            $name = $stmt->fetchColumn();

            return $name === false || $name === null ? null : (string) $name;
        } catch (\Throwable) {
            return null;
        }
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
            'accountStatus' => 'active',
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
        // Shared with tenant provisioning (#779), which needs exactly this to
        // give a new tenant its first administrator. Kept as one implementation
        // rather than two: an identity written two slightly different ways is an
        // identity that eventually diverges.
        return (new ProfileProvisioner($this->db))->findOrCreate($email, $passwordHash);
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

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false || !isset($row['id'])) {
                return null;
            }

            return (int)$row['id'];
        }

        return $this->resolveRoleIdVisibleToTenant($roleRef, $scopeTenantId);
    }

    /**
     * Resolve a role reference to a role id VISIBLE to one named tenant: owned by
     * it (`roles.tenant_id = ?`) or GLOBAL (`roles.tenant_id IS NULL`).
     *
     * Split out of {@see self::resolveVisibleRoleId()} because the cross-tenant
     * membership grant (#797 §2) names its target tenant explicitly and must be
     * scoped to THAT tenant, not to the acting one — a system caller granting in
     * tenant B must not be able to reach tenant A's private roles.
     *
     * @param mixed    $roleRef  Role name string or numeric role id.
     * @param int|null $tenantId The tenant whose visibility applies. Null (no
     *                           resolvable acting tenant) matches GLOBAL roles
     *                           only, which is what the predicate already did.
     * @return int|null The resolved, visible role id, or null when not found.
     */
    private function resolveRoleIdVisibleToTenant(mixed $roleRef, ?int $tenantId): ?int
    {
        $byId = is_int($roleRef) || (is_string($roleRef) && $roleRef !== '' && ctype_digit($roleRef));
        $column = $byId ? 'id' : 'name';
        $value = $byId ? (int) $roleRef : (string) $roleRef;

        $stmt = $this->db->prepare(
            "SELECT id FROM roles WHERE {$column} = ? AND (tenant_id = ? OR tenant_id IS NULL) LIMIT 1"
        );
        $stmt->execute([$value, $tenantId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !isset($row['id'])) {
            return null;
        }

        return (int)$row['id'];
    }

    /**
     * Whether the global profile exists at all.
     *
     * The cross-tenant grant (#797 §2) cannot ask "is this profile a member
     * here?" — the answer is no by construction, that being the request — so
     * existence is checked against the identity table itself.
     */
    private function profileExists(int $profileId): bool
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT 1 FROM profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$profileId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Whether the named tenant exists.
     *
     * Checked before the INSERT so a mistyped `tenant_id` is a 404 rather than a
     * foreign-key violation surfacing as an opaque 500.
     */
    private function tenantExists(int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE id = ? LIMIT 1');
        $stmt->execute([$tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The tenant a membership row belongs to, for the SYSTEM-tenant delete path.
     *
     * `memberships.id` is a primary key, so this identifies exactly one tenant —
     * the caller is not choosing it, the row is. Pairing it with the profile id
     * from the route keeps a membership id belonging to somebody else from being
     * deleted through another profile's URL.
     *
     * @return int|null The owning tenant id, or null when there is no such row.
     */
    private function tenantOfMembership(int $membershipId, int $profileId): ?int
    {
        // @tenant-guard-ignore: system-tenant (id 0) resolves a membership's OWN tenant from its primary key; every statement that follows binds that tenant_id
        $stmt = $this->db->prepare(
            'SELECT tenant_id FROM memberships WHERE id = ? AND profile_id = ? LIMIT 1'
        );
        $stmt->execute([$membershipId, $profileId]);
        $tenantId = $stmt->fetchColumn();

        return $tenantId === false ? null : (int) $tenantId;
    }

    /**
     * Resolve a submitted `ou_id` to the value to persist on a membership whose
     * owning tenant is `$ownerTenantId`.
     *
     * The SINGLE OU-assignment gate shared by {@see self::create()} and
     * {@see self::update()} — both endpoints write `memberships.ou_id`, so both
     * must clear the same bar. SECURITY: an OU is only assignable when it belongs
     * to the membership's OWNING tenant; a foreign OU would otherwise plant a
     * membership across the tenant boundary (the system tenant is NOT exempt —
     * the OU still has to live in the tenant the membership does).
     *
     * `null`, `0`/`"0"` and `''` all mean "no OU" and resolve to null, so an explicit
     * `{"ou_id": null}` clears the assignment on update and creates an unassigned
     * membership on create (identical to omitting the field).
     *
     * @param  mixed $ouRef         The raw submitted value.
     * @param  int   $ownerTenantId The tenant that owns the target membership.
     * @return int|Response|null    The OU id to persist, null for "no OU", or a
     *                              ready-to-return error Response (400/403).
     */
    private function resolveOuIdForTenant(mixed $ouRef, int $ownerTenantId): int|Response|null
    {
        if ($ouRef === null || $ouRef === '') {
            return null;
        }

        // Only an integer-ish reference can be an OU id. Rejecting anything else
        // HERE keeps a non-numeric body (e.g. `{"ou_id": "eng"}`) a clean 400
        // instead of an "invalid input syntax for integer" driver error surfacing
        // as an opaque 500 on PostgreSQL.
        if (!is_int($ouRef) && !(is_string($ouRef) && ctype_digit($ouRef))) {
            return Response::error('Invalid ou_id', 400);
        }

        $ouId = (int)$ouRef;

        // 0 (and its string form) is the "no OU" sentinel the Edit form submits
        // for an empty select — never a real organizational_units.id.
        if ($ouId === 0) {
            return null;
        }

        $stmtCheckOu = $this->db->prepare(
            'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
        );
        $stmtCheckOu->execute([$ouId, $ownerTenantId]);
        if (!$stmtCheckOu->fetch()) {
            return Response::error('OU does not belong to current tenant', 403);
        }

        return $ouId;
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
