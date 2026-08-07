<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Pending self-service password-reset review API
 * (WC-password-reset-2fa-recovery), the approval branch of
 * `auth.password_reset_approval_required`:
 *  - GET  /api/v1/password-resets/pending        — list pending requests
 *  - POST /api/v1/password-resets/{id}/approve    — apply the staged password
 *  - POST /api/v1/password-resets/{id}/reject     — discard the staged password
 *
 * TENANT-SCOPED to the REQUESTING USER'S OWN tenant — unlike
 * {@see RegistrationsApiHandler} (system-tenant-only, since a freshly
 * registered tenant has no admin of its own yet), the requester's account and
 * its tenant admin already exist here, so their own tenant's admin reviews it.
 * {@see PasswordResetService::listPendingForTenant()} /
 * {@see PasswordResetService::approveForTenant()} /
 * {@see PasswordResetService::rejectForTenant()} enforce this via a JOIN to
 * `memberships` — never trust a bare request id.
 */
final class PasswordResetApprovalsApiHandler
{
    public function __construct(
        private readonly PasswordResetService $service,
        private readonly RoleChecker $roleChecker,
        private readonly AuditLogger $audit,
        private readonly ?PasswordResetMailer $mailer = null,
    ) {}

    /**
     * GET /api/v1/password-resets/pending — list requests awaiting approval
     * for profiles that hold an active membership in the caller's own tenant.
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
            error_log('[password-reset-approvals] list failed: ' . $e->getMessage());

            return Response::error('Failed to list pending password-reset requests', 500);
        }
    }

    /**
     * POST /api/v1/password-resets/{id}/approve — apply the staged password.
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
                return Response::error('No pending password-reset request found for that id', 404);
            }

            $this->audit->record('auth.password_reset.approved', [
                'actor_user_id' => $ctx['userId'],
                'target_type'   => 'profile',
                'target_id'     => $result['profile_id'],
            ]);

            if ($this->mailer !== null && $result['email'] !== '') {
                try {
                    $this->mailer->sendApprovedNotice($result['email']);
                } catch (\Throwable $e) {
                    error_log('[password-reset-approvals] approved-notice dispatch failed: ' . $e->getMessage());
                }
            }

            return Response::json(['data' => ['id' => $requestId, 'status' => 'approved']], 200);
        } catch (\Throwable $e) {
            error_log('[password-reset-approvals] approve failed: ' . $e->getMessage());

            return Response::error('Failed to approve the password-reset request', 500);
        }
    }

    /**
     * POST /api/v1/password-resets/{id}/reject — discard the staged password.
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
                return Response::error('No pending password-reset request found for that id', 404);
            }

            $this->audit->record('auth.password_reset.rejected', [
                'actor_user_id' => $ctx['userId'],
                'target_type'   => 'profile',
                'target_id'     => $result['profile_id'],
            ]);

            return Response::json(['data' => ['id' => $requestId, 'status' => 'rejected']], 200);
        } catch (\Throwable $e) {
            error_log('[password-reset-approvals] reject failed: ' . $e->getMessage());

            return Response::error('Failed to reject the password-reset request', 500);
        }
    }

    /**
     * password_resets:approve gate, scoped to the caller's OWN tenant (never
     * system-tenant-restricted — unlike RegistrationsApiHandler).
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
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::PASSWORD_RESETS_APPROVE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::PASSWORD_RESETS_APPROVE]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
