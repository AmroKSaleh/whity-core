<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\InvitationMailer;
use Whity\Core\Identity\InvitationService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The tenant administrator's invitation surface (WHIT-417 / #797 item 3):
 *   GET    /api/v1/invitations              — who is still pending
 *   POST   /api/v1/invitations              — invite an address
 *   POST   /api/v1/invitations/{id}/resend  — new token, new expiry, old link dead
 *   DELETE /api/v1/invitations/{id}         — withdraw
 *
 * PERMISSION: `users:write` (and `users:read` to list), NOT a new
 * `invitations:manage`. Inviting somebody is user administration — it is the
 * same decision as POST /api/users, reached without typing a password — and
 * every role that can already add users can already do this by other means.
 * A dedicated permission would have to be granted by a migration, which reaches
 * the `admin` role and no other: a deployment that had built a custom
 * "User manager" role would find invitations silently missing from it, and
 * would have no way to tell that from the feature not existing.
 *
 * WHAT THIS ENDPOINT REFUSES TO REVEAL. A tenant administrator may type any
 * address into the invite form, including one belonging to a person who has
 * never heard of this tenant. So neither the response to POST nor any row in
 * GET says whether that address already has an account anywhere on the
 * platform: the same 201 comes back either way, no profile is created at invite
 * time (so there is no bcrypt cost to time the two paths apart), and
 * {@see InvitationService::listForTenant()} carries no profile id. The one
 * refusal that IS specific — "already a member here" — reveals nothing, because
 * the caller can already list their own tenant's users.
 */
final class InvitationsApiHandler
{
    /** Throttle: fixed window plus per-tenant and per-actor ceilings. */
    private const WINDOW_SECONDS = 3600;
    private const TENANT_MAX = 100;
    private const ACTOR_MAX = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly InvitationService $service,
        private readonly RoleChecker $roleChecker,
        private readonly AuditLogger $audit,
        private readonly SharedStoreInterface $store,
        private readonly SettingsService $settings,
        private readonly ?InvitationMailer $mailer = null,
    ) {}

    /**
     * GET /api/v1/invitations — the pending (and recently closed) invitations
     * for the caller's own tenant.
     */
    public function list(Request $request): Response
    {
        $ctx = $this->authorize($request, CorePermissions::USERS_READ);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        try {
            return Response::json(['data' => $this->service->listForTenant($ctx['tenantId'])], 200);
        } catch (\Throwable $e) {
            error_log('[invitations] list failed: ' . $e->getMessage());

            return Response::error('Failed to list invitations', 500);
        }
    }

    /**
     * POST /api/v1/invitations — invite an address into the caller's tenant.
     *
     * 201 whether or not the address already has an account; 409 only when it
     * already holds an ACTIVE membership HERE, which the caller can see anyway.
     */
    public function create(Request $request): Response
    {
        $ctx = $this->authorize($request, CorePermissions::USERS_WRITE);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $body = JsonBody::parsed($request);
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            return Response::error('A valid email address is required', 422);
        }

        $roleId = $this->resolveRoleId($body['role_id'] ?? $body['role'] ?? null, $ctx['tenantId']);
        if ($roleId === null) {
            return Response::error('That role does not exist in this tenant', 422);
        }

        $ouId = $this->resolveOuId($body['ou_id'] ?? null, $ctx['tenantId']);
        if ($ouId === false) {
            return Response::error('That organizational unit does not exist in this tenant', 422);
        }

        $throttled = $this->throttle($ctx['tenantId'], $ctx['userId']);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        $ttlDays = $this->ttlDays($ctx['tenantId']);

        try {
            $result = $this->service->invite($ctx['tenantId'], $email, $roleId, $ouId, $ctx['userId'], $ttlDays);
        } catch (\Throwable $e) {
            error_log('[invitations] create failed: ' . $e->getMessage());

            return Response::error('Failed to create the invitation', 500);
        }

        if ($result['result'] === InvitationService::INVITE_ALREADY_MEMBER) {
            return Response::error('That address is already an active member of this tenant', 409);
        }

        $this->audit->record('tenant.invitation.created', [
            'tenant_id' => $ctx['tenantId'],
            'actor_user_id' => $ctx['userId'],
            'target_type' => 'invitation',
            'target_id' => $result['id'],
        ]);

        $this->dispatchMail($email, $ctx['tenantId'], $result['token'], $ttlDays);

        return Response::json(['data' => $this->rowFor($result['id'], $ctx['tenantId'])], 201);
    }

    /**
     * POST /api/v1/invitations/{id}/resend — mint a fresh token and mail it.
     *
     * @param array<string, mixed> $params
     */
    public function resend(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request, CorePermissions::USERS_WRITE);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A valid invitation id is required', 422);
        }

        $throttled = $this->throttle($ctx['tenantId'], $ctx['userId']);
        if ($throttled instanceof Response) {
            return $throttled;
        }

        $ttlDays = $this->ttlDays($ctx['tenantId']);

        try {
            $result = $this->service->resend($id, $ctx['tenantId'], $ttlDays);
        } catch (\Throwable $e) {
            error_log('[invitations] resend failed: ' . $e->getMessage());

            return Response::error('Failed to resend the invitation', 500);
        }

        if ($result === null) {
            return Response::error('No pending invitation found for that id', 404);
        }

        $this->audit->record('tenant.invitation.resent', [
            'tenant_id' => $ctx['tenantId'],
            'actor_user_id' => $ctx['userId'],
            'target_type' => 'invitation',
            'target_id' => $id,
        ]);

        $this->dispatchMail($result['email'], $ctx['tenantId'], $result['token'], $ttlDays);

        return Response::json(['data' => $this->rowFor($id, $ctx['tenantId'])], 200);
    }

    /**
     * DELETE /api/v1/invitations/{id} — withdraw an outstanding invitation.
     *
     * @param array<string, mixed> $params
     */
    public function revoke(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request, CorePermissions::USERS_WRITE);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A valid invitation id is required', 422);
        }

        try {
            $revoked = $this->service->revoke($id, $ctx['tenantId']);
        } catch (\Throwable $e) {
            error_log('[invitations] revoke failed: ' . $e->getMessage());

            return Response::error('Failed to revoke the invitation', 500);
        }

        if (!$revoked) {
            return Response::error('No pending invitation found for that id', 404);
        }

        $this->audit->record('tenant.invitation.revoked', [
            'tenant_id' => $ctx['tenantId'],
            'actor_user_id' => $ctx['userId'],
            'target_type' => 'invitation',
            'target_id' => $id,
        ]);

        return Response::json(['data' => ['id' => $id, 'status' => InvitationService::STATUS_REVOKED]], 200);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * `users:*` gate, scoped to the caller's own tenant. Mirrors
     * {@see PasswordResetApprovalsApiHandler::authorize()} — the route already
     * declares the permission; re-checking here is the defence in depth the
     * platform applies everywhere.
     *
     * @return array{tenantId: int, userId: int}|Response
     */
    private function authorize(Request $request, string $permission): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }

    /**
     * Per-tenant and per-actor ceilings on issuing invitations.
     *
     * The threat is not a stranger — the caller is already an authenticated
     * administrator — it is that a stolen administrator session turns this
     * endpoint into a mail cannon sending from the deployment's own domain.
     * Counted BEFORE any work, exactly like the public throttles, so the
     * ceiling does not depend on the outcome.
     */
    private function throttle(int $tenantId, int $actorId): ?Response
    {
        $tenantKey = 'invite:issue:tenant:' . $tenantId;
        $actorKey = 'invite:issue:actor:' . $actorId;

        if ($this->store->count($tenantKey) >= self::TENANT_MAX
            || $this->store->count($actorKey) >= self::ACTOR_MAX
        ) {
            $retryAfter = max($this->store->ttl($tenantKey), $this->store->ttl($actorKey), 1);

            return Response::error('Too many invitations sent. Please try again later.', 429)
                ->withHeaders(['Retry-After' => (string) $retryAfter]);
        }

        $this->store->increment($tenantKey, self::WINDOW_SECONDS);
        $this->store->increment($actorKey, self::WINDOW_SECONDS);

        return null;
    }

    /**
     * The tenant's invitation lifetime, falling back to the global default and
     * then to the service's own — the per-tenant ?? global ?? registry-default
     * chain the platform uses for every tunable.
     */
    private function ttlDays(int $tenantId): int
    {
        try {
            $effective = $this->settings->effective($tenantId);
            $raw = (string) ($effective[SettingsRegistry::INVITATION_TTL_DAYS] ?? '');
        } catch (\Throwable) {
            $raw = '';
        }

        return preg_match('/^\d+$/', $raw) === 1
            ? (int) $raw
            : InvitationService::DEFAULT_TTL_DAYS;
    }

    /**
     * Resolve a role reference (numeric id or name) to a role this tenant may
     * actually assign: its own, or a platform-global one.
     *
     * Absent means the global `user` role, matching POST /api/users.
     */
    private function resolveRoleId(mixed $roleRef, int $tenantId): ?int
    {
        if ($roleRef === null || $roleRef === '') {
            $roleRef = 'user';
        }

        if (is_int($roleRef) || (is_string($roleRef) && preg_match('/^\d+$/', $roleRef) === 1)) {
            $stmt = $this->db->prepare(
                'SELECT id FROM roles
                  WHERE id = :id AND (tenant_id IS NULL OR tenant_id = :tenant_id)
                  LIMIT 1'
            );
            $stmt->execute([':id' => (int) $roleRef, ':tenant_id' => $tenantId]);
        } elseif (is_string($roleRef)) {
            $stmt = $this->db->prepare(
                'SELECT id FROM roles
                  WHERE name = :name AND (tenant_id IS NULL OR tenant_id = :tenant_id)
                  ORDER BY tenant_id DESC
                  LIMIT 1'
            );
            $stmt->execute([':name' => $roleRef, ':tenant_id' => $tenantId]);
        } else {
            return null;
        }

        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Resolve an optional OU reference within this tenant.
     *
     * @return int|null|false null when none was asked for, false when the OU is
     *         unknown or belongs to another tenant.
     */
    private function resolveOuId(mixed $ouRef, int $tenantId): int|null|false
    {
        if ($ouRef === null || $ouRef === '') {
            return null;
        }
        if (!is_int($ouRef) && !(is_string($ouRef) && preg_match('/^\d+$/', $ouRef) === 1)) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM organizational_units WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => (int) $ouRef, ':tenant_id' => $tenantId]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : false;
    }

    /**
     * The public shape of one invitation, re-read through the tenant-scoped
     * list so a create/resend response can never describe a row the caller
     * would not be allowed to see.
     *
     * @return array<string, mixed>
     */
    private function rowFor(int $id, int $tenantId): array
    {
        foreach ($this->service->listForTenant($tenantId) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return ['id' => $id];
    }

    /** Delivery failures never change the response shape. */
    private function dispatchMail(string $email, int $tenantId, string $token, int $ttlDays): void
    {
        if ($this->mailer === null || $token === '') {
            return;
        }

        try {
            $this->mailer->sendInvitation($email, $this->tenantName($tenantId), $token, $ttlDays);
        } catch (\Throwable $e) {
            error_log('[invitations] mail dispatch failed: ' . $e->getMessage());
        }
    }

    private function tenantName(int $tenantId): string
    {
        try {
            $stmt = $this->db->prepare('SELECT name FROM tenants WHERE id = :id');
            $stmt->execute([':id' => $tenantId]);

            return (string) ($stmt->fetchColumn() ?: '');
        } catch (\Throwable) {
            return '';
        }
    }
}
