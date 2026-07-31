<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Notification\NotificationMetricsRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Admin API for a tenant's notification delivery METRICS (WC-notifications
 * #4d40cc1c):
 *
 *   GET /api/v1/notification-metrics — per-status counts, failure rate, queue
 *                                      depth, average send latency.
 *
 * Tenant-scoped (from TenantContext) and gated on `notifications:manage`. A
 * read-only observability surface: it aggregates `notification_deliveries` and
 * exposes NO per-recipient rows or message content — only counts and rates.
 */
final class NotificationMetricsApiHandler
{
    public function __construct(
        private readonly RoleChecker $roleChecker,
        private readonly NotificationMetricsRepository $repo,
    ) {}

    /**
     * GET /api/v1/notification-metrics.
     *
     * @param array<string, mixed> $params
     */
    public function show(Request $request, array $params = []): Response
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
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::NOTIFICATIONS_MANAGE, $tenantId)
        ) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::NOTIFICATIONS_MANAGE]);
        }

        return Response::json(['data' => $this->repo->forTenant($tenantId)], 200);
    }
}
