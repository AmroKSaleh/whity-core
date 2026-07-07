<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Pending-registration review API (WC-235 admin-approval activation).
 *
 * When {@see \Whity\Core\Identity\AccountActivationPolicy} is enforced,
 * self-service registration provisions the workspace owner's membership as
 * 'invited' (pending) rather than 'active', so the owner cannot log in until a
 * system-tenant admin approves it. This handler is that review surface:
 *  - GET  /api/v1/registrations/pending        — list pending registrations
 *  - POST /api/v1/registrations/{id}/approve    — invited → active
 *  - POST /api/v1/registrations/{id}/reject     — invited → suspended
 *
 * SYSTEM-TENANT ONLY. A freshly-registered tenant's only member is the pending
 * owner, so no in-tenant admin can approve it; the authority is the platform
 * operator (system tenant, id 0). registrations:approve is necessary but not
 * sufficient — the caller must also be acting in tenant 0 (a regular tenant's
 * admin holds the permission within its own tenant, but must never approve
 * another tenant's owner). This mirrors the WC-235 global-settings gate.
 *
 * A "pending registration" is specifically an 'invited' membership in a tenant
 * that has NO 'active' membership — i.e. the whole workspace is still awaiting
 * activation. That predicate distinguishes it from an ordinary tenant-level
 * INVITATION (also status 'invited'), which lives in a tenant that already has
 * active members; those never appear here and can never be approved/rejected
 * through this endpoint.
 */
final class RegistrationsApiHandler
{
    private const SYSTEM_TENANT_ID = 0;

    private PDO $db;
    private RoleChecker $roleChecker;

    public function __construct(PDO $db, RoleChecker $roleChecker)
    {
        $this->db = $db;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/v1/registrations/pending — list pending self-service
     * registrations awaiting approval (system tenant only).
     */
    public function listPending(Request $request): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        try {
            // Cross-tenant by design: the platform operator reviews pending
            // registrations across the whole install. The predicate below scopes
            // the result to whole-workspace-pending rows only (an 'invited'
            // membership in a tenant with no active member), never ordinary
            // tenant invitations.
            // @tenant-guard-ignore: system-tenant platform operation — lists pending registrations across all tenants (WC-235)
            $stmt = $this->db->query(
                "SELECT m.id AS membership_id, m.tenant_id, m.created_at,
                        t.name AS tenant_name, t.slug AS tenant_slug,
                        p.id AS profile_id, p.display_name,
                        pe.email AS owner_email
                 FROM memberships m
                 JOIN tenants t ON t.id = m.tenant_id
                 JOIN profiles p ON p.id = m.profile_id
                 LEFT JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = TRUE
                 WHERE m.status = 'invited'
                   AND NOT EXISTS (
                       SELECT 1 FROM memberships am
                       WHERE am.tenant_id = m.tenant_id AND am.status = 'active'
                   )
                 ORDER BY m.created_at ASC"
            );

            $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'membership_id' => (int) $row['membership_id'],
                    'tenant_id'     => (int) $row['tenant_id'],
                    'tenant_name'   => (string) ($row['tenant_name'] ?? ''),
                    'tenant_slug'   => (string) ($row['tenant_slug'] ?? ''),
                    'profile_id'    => (int) $row['profile_id'],
                    'display_name'  => (string) ($row['display_name'] ?? ''),
                    'owner_email'   => (string) ($row['owner_email'] ?? ''),
                    'created_at'    => (string) ($row['created_at'] ?? ''),
                ];
            }

            return Response::json(['data' => $items], 200);
        } catch (\Throwable $e) {
            error_log('[registrations] list failed: ' . $e->getMessage());
            return Response::error('Failed to list pending registrations', 500);
        }
    }

    /**
     * POST /api/v1/registrations/{id}/approve — activate a pending registration
     * (invited → active). {id} is the owner membership id.
     *
     * @param array<string, mixed> $params
     */
    public function approve(Request $request, array $params = []): Response
    {
        return $this->transition($request, $params, MembershipRepository::STATUS_ACTIVE, 'approved');
    }

    /**
     * POST /api/v1/registrations/{id}/reject — reject a pending registration
     * (invited → suspended). Reversible: a suspended registration can be
     * re-approved later. {id} is the owner membership id.
     *
     * @param array<string, mixed> $params
     */
    public function reject(Request $request, array $params = []): Response
    {
        return $this->transition($request, $params, MembershipRepository::STATUS_SUSPENDED, 'rejected');
    }

    /**
     * Shared approve/reject path: transition exactly one PENDING-REGISTRATION
     * membership ('invited', in a tenant with no active member) to $newStatus.
     * The conditional UPDATE is atomic and idempotent-safe under concurrency,
     * and its guard means this endpoint can never mutate an ordinary tenant
     * invitation.
     *
     * @param array<string, mixed> $params
     */
    private function transition(Request $request, array $params, string $newStatus, string $verb): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $membershipId = (int) ($params['id'] ?? 0);
        if ($membershipId <= 0) {
            return Response::error('A valid registration id is required', 422);
        }

        try {
            // Cross-tenant by design (platform operation): a pending registration
            // lives in the newly-created tenant, not the acting system tenant.
            // The guard restricts the write to a whole-workspace-pending row, so
            // an ordinary tenant invitation can never be transitioned here.
            // @tenant-guard-ignore: system-tenant platform operation — approves/rejects a pending registration in another tenant (WC-235)
            $stmt = $this->db->prepare(
                "UPDATE memberships
                    SET status = :new_status
                  WHERE id = :id
                    AND status = 'invited'
                    AND NOT EXISTS (
                        SELECT 1 FROM memberships am
                        WHERE am.tenant_id = memberships.tenant_id AND am.status = 'active'
                    )"
            );
            $stmt->execute([':new_status' => $newStatus, ':id' => $membershipId]);

            if ($stmt->rowCount() === 0) {
                // Not found, already handled, or not a pending registration.
                return Response::error('No pending registration found for that id', 404);
            }

            return Response::json(['data' => ['membership_id' => $membershipId, 'status' => $newStatus]], 200);
        } catch (\Throwable $e) {
            error_log('[registrations] ' . $verb . ' failed: ' . $e->getMessage());
            return Response::error('Failed to update the registration', 500);
        }
    }

    /**
     * System-tenant + registrations:approve gate. The route-level RBAC already
     * enforces the permission; this adds the system-tenant requirement and gives
     * defense-in-depth if the route is ever misconfigured.
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
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::REGISTRATIONS_APPROVE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::REGISTRATIONS_APPROVE]);
        }

        // Approving a registration activates another tenant's owner — a PLATFORM
        // operation. The permission is necessary but not sufficient: the caller
        // must be acting in the system tenant (id 0), otherwise any tenant admin
        // (who holds registrations:approve in its own tenant) could approve or
        // reject another workspace's owner (cross-tenant escalation, WC-235).
        if ($tenantId !== self::SYSTEM_TENANT_ID) {
            return Response::error('Registrations are approved by the system tenant only', 403);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
