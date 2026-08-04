<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\TwoFactorRecoveryService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Pending 2FA-recovery-request review API (WC-password-reset-2fa-recovery):
 *  - GET  /api/v1/2fa-recovery/pending           — list pending requests
 *  - POST /api/v1/2fa-recovery/{id}/approve       — clear the TARGET's 2FA + send a reset link
 *  - POST /api/v1/2fa-recovery/{id}/reject        — leave the target untouched
 *  - POST /api/v1/2fa-recovery/force-reset        — secondary fallback: force the
 *    same primitive on a named profile with NO prior request (for a user who
 *    cannot even receive email and reaches an admin out-of-band)
 *
 * TENANT-SCOPED to the TARGET profile's OWN tenant (mirrors
 * {@see PasswordResetApprovalsApiHandler} — NOT system-tenant-restricted).
 * Gated on the genuinely account-takeover-adjacent
 * {@see CorePermissions::TWO_FACTOR_RECOVERY_APPROVE} permission, distinct from
 * `password_resets:approve` — approving here clears a second factor, a
 * materially higher-stakes action than approving a staged password.
 */
final class TwoFactorRecoveryApprovalsApiHandler
{
    public function __construct(
        private readonly TwoFactorRecoveryService $service,
        private readonly RoleChecker $roleChecker,
        private readonly AuditLogger $audit,
        private readonly ?PasswordResetMailer $resetMailer = null,
    ) {}

    /**
     * GET /api/v1/2fa-recovery/pending — list pending requests for profiles
     * that hold an active membership in the caller's own tenant.
     */
    public function listPending(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        try {
            $items = $this->service->listPendingForTenant($ctx['tenantId']);

            return Response::json(['data' => $items], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] list failed: ' . $e->getMessage());

            return Response::error('Failed to list pending 2FA-recovery requests', 500);
        }
    }

    /**
     * POST /api/v1/2fa-recovery/{id}/approve — clear the TARGET profile's 2FA
     * and issue+send a fresh password-reset link for the same profile.
     *
     * @param array<string, mixed> $params
     */
    public function approve(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $requestId = (int) ($params['id'] ?? 0);
        if ($requestId <= 0) {
            return Response::error('A valid request id is required', 422);
        }

        try {
            $result = $this->service->approveForTenant($requestId, $ctx['tenantId']);
            if ($result === null) {
                return Response::error('No pending 2FA-recovery request found for that id', 404);
            }

            $this->auditApproval($ctx['userId'], $result['profile_id']);
            $this->sendResetLink($result['email'], $result['reset_token']);

            return Response::json(['data' => ['id' => $requestId, 'status' => 'approved']], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] approve failed: ' . $e->getMessage());

            return Response::error('Failed to approve the 2FA-recovery request', 500);
        }
    }

    /**
     * POST /api/v1/2fa-recovery/{id}/reject — the target profile is left
     * completely untouched.
     *
     * @param array<string, mixed> $params
     */
    public function reject(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $requestId = (int) ($params['id'] ?? 0);
        if ($requestId <= 0) {
            return Response::error('A valid request id is required', 422);
        }

        try {
            $result = $this->service->rejectForTenant($requestId, $ctx['tenantId']);
            if ($result === null) {
                return Response::error('No pending 2FA-recovery request found for that id', 404);
            }

            $this->audit->record('auth.2fa_recovery.rejected', [
                'actor_user_id' => $ctx['userId'],
                'target_type'   => 'profile',
                'target_id'     => $result['profile_id'],
            ]);

            return Response::json(['data' => ['id' => $requestId, 'status' => 'rejected']], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] reject failed: ' . $e->getMessage());

            return Response::error('Failed to reject the 2FA-recovery request', 500);
        }
    }

    /**
     * POST /api/v1/2fa-recovery/force-reset — secondary fallback: an admin
     * directly forces the clear-2FA-and-trigger-reset primitive onto a named
     * profile in their own tenant, with NO prior self-service request. Reuses
     * the SAME permission and the SAME underlying primitive as approve()
     * above, and is audited distinctly so a forced action is never
     * indistinguishable from a queue approval.
     *
     * Body: `{ "profile_id": <int> }`.
     */
    public function forceReset(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $body = JsonBody::parsed($request);
        $targetProfileId = isset($body['profile_id']) && is_numeric($body['profile_id'])
            ? (int) $body['profile_id']
            : 0;
        if ($targetProfileId <= 0) {
            return Response::error('A valid profile_id is required', 422);
        }

        try {
            $result = $this->service->forceResetForTenant($targetProfileId, $ctx['tenantId']);
            if ($result === null) {
                return Response::error('No such profile in your tenant', 404);
            }

            $this->audit->record('auth.2fa_recovery.forced', [
                'actor_user_id' => $ctx['userId'],
                'target_type'   => 'profile',
                'target_id'     => $result['profile_id'],
            ]);
            $this->sendResetLink($result['email'], $result['reset_token']);

            return Response::json(['data' => ['profile_id' => $targetProfileId, 'status' => 'forced']], 200);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] force-reset failed: ' . $e->getMessage());

            return Response::error('Failed to force the 2FA reset', 500);
        }
    }

    private function auditApproval(int $actorUserId, int $targetProfileId): void
    {
        // Two distinct, separately-auditable steps per the brief: the 2FA was
        // actually cleared, and a follow-up reset was issued+sent — never
        // collapsed into one vague "approved" entry.
        $this->audit->record('auth.2fa_recovery.approved', [
            'actor_user_id' => $actorUserId,
            'target_type'   => 'profile',
            'target_id'     => $targetProfileId,
        ]);
        $this->audit->record('auth.2fa_recovery.two_factor_cleared', [
            'actor_user_id' => $actorUserId,
            'target_type'   => 'profile',
            'target_id'     => $targetProfileId,
        ]);
        $this->audit->record('auth.2fa_recovery.reset_link_sent', [
            'actor_user_id' => $actorUserId,
            'target_type'   => 'profile',
            'target_id'     => $targetProfileId,
        ]);
    }

    private function sendResetLink(string $email, string $rawToken): void
    {
        if ($this->resetMailer === null || $email === '') {
            return;
        }
        try {
            $this->resetMailer->sendResetLink($email, $rawToken);
        } catch (\Throwable $e) {
            error_log('[2fa-recovery-approvals] reset-link dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * two_factor_recovery:approve gate, scoped to the caller's OWN tenant.
     *
     * @return array{tenantId:int,userId:int}|Response
     */
    private function authorize(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::TWO_FACTOR_RECOVERY_APPROVE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::TWO_FACTOR_RECOVERY_APPROVE]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
