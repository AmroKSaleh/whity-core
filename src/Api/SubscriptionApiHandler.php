<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Plan\PlanService;
use Whity\Core\Plan\PlanValidationException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Subscription\SubscriptionException;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Subscription (billing-state) API (WC-billing).
 *
 * OPERATOR routes (system-tenant gated, `subscriptions:manage`) — the point where
 * an out-of-band payment is reflected in-app, and where the system admin sets
 * their OWN tenant's tier in a sovereign deployment:
 *   GET /api/tenants/{id}/subscription → getForTenant()
 *   PUT /api/tenants/{id}/subscription → setForTenant()
 *
 * TENANT-SELF route (`settings:read`, wall-exempt so a lapsed tenant can still
 * reach it) — read-only view of the caller's own subscription:
 *   GET /api/subscription → getSelf()
 *
 * Setting a plan on the tenant materialises that plan's entitlements (the tier's
 * feature flags) via PlanService; the billing columns record the payment state
 * the wall enforces. The two together are "feature flags according to payments".
 */
final class SubscriptionApiHandler
{
    private SubscriptionService $subscriptions;
    private PlanService $plans;
    private RoleChecker $roleChecker;
    private PDO $db;

    public function __construct(
        SubscriptionService $subscriptions,
        PlanService $plans,
        RoleChecker $roleChecker,
        PDO $db,
    ) {
        $this->subscriptions = $subscriptions;
        $this->plans = $plans;
        $this->roleChecker = $roleChecker;
        $this->db = $db;
    }

    /**
     * @param array<string, string> $params
     */
    public function getForTenant(Request $request, array $params): Response
    {
        if (($r = $this->authorizeOperator($request)) instanceof Response) {
            return $r;
        }
        $targetTenant = (int) ($params['id'] ?? 0);
        if (!$this->tenantExists($targetTenant)) {
            return Response::error('Tenant not found', 404);
        }

        return Response::json(['data' => $this->viewFor($targetTenant, operator: true)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function setForTenant(Request $request, array $params): Response
    {
        $ctx = $this->authorizeOperator($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        ['userId' => $userId] = $ctx;

        $targetTenant = (int) ($params['id'] ?? 0);
        if (!$this->tenantExists($targetTenant)) {
            return Response::error('Tenant not found', 404);
        }
        if ($targetTenant === SubscriptionService::SYSTEM_TENANT_ID) {
            return Response::error('The system tenant is never subscribed', 409);
        }

        $body = JsonBody::parsed($request);

        try {
            // Applying a plan materialises its entitlement bundle (the tier's
            // features) into the tenant — done first so a failure aborts before
            // the billing state is touched.
            if (array_key_exists('plan_id', $body) && $body['plan_id'] !== null) {
                $this->plans->applyToTenant((int) $body['plan_id'], $targetTenant, $userId);
            }

            $changes = [];
            foreach (['status', 'current_period_end', 'grace_until', 'enforcement_mode', 'external_ref'] as $field) {
                if (array_key_exists($field, $body)) {
                    $changes[$field] = $body[$field] === null ? null : (string) $body[$field];
                }
            }
            if ($changes !== []) {
                $this->subscriptions->setSubscription($targetTenant, $changes);
            }
        } catch (PlanValidationException | SubscriptionException $e) {
            return Response::error('Validation failed', 422, ['reason' => $e->getMessage()]);
        }

        return Response::json(['data' => $this->viewFor($targetTenant, operator: true)]);
    }

    /**
     * GET /api/subscription — the caller's OWN tenant subscription (read-only).
     */
    public function getSelf(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        return Response::json(['data' => $this->viewFor($tenantId, operator: false)]);
    }

    /**
     * Build the subscription view. Operator view exposes the enforcement policy +
     * provider ref; the self view omits them.
     *
     * @return array<string, mixed>
     */
    private function viewFor(int $tenantId, bool $operator): array
    {
        $sub = $this->subscriptions->getSubscription($tenantId) ?? [];

        $planId = $sub['plan_id'] ?? null;
        $planSummary = null;
        if (is_int($planId)) {
            $plan = $this->plans->getPlanWithEntitlements($planId);
            if ($plan !== null) {
                $planSummary = ['id' => $plan['id'], 'plan_key' => $plan['plan_key'], 'name' => $plan['name']];
            }
        }

        $view = [
            'tenant_id'                  => $tenantId,
            'status'                     => $sub['status'] ?? null,
            'plan'                       => $planSummary,
            'current_period_end'         => $sub['current_period_end'] ?? null,
            'effective_enforcement_mode' => $this->subscriptions->effectiveEnforcementMode($tenantId),
        ];

        if ($operator) {
            $view['enforcement_mode'] = $sub['enforcement_mode'] ?? null;
            $view['grace_until']      = $sub['grace_until'] ?? null;
            $view['external_ref']     = $sub['external_ref'] ?? null;
        }

        return $view;
    }

    /**
     * `subscriptions:manage` AND the system-tenant gate. Returns the acting
     * profile id (for the audit column) or a 403 Response.
     *
     * @return array{userId: int}|Response
     */
    private function authorizeOperator(Request $request): array|Response
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
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::SUBSCRIPTIONS_MANAGE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::SUBSCRIPTIONS_MANAGE]);
        }

        if ($tenantId !== SubscriptionService::SYSTEM_TENANT_ID) {
            return Response::error('Subscriptions are managed by the system tenant only', 403);
        }

        return ['userId' => $userId];
    }

    private function tenantExists(int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }
}
