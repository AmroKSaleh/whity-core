<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Plan\PlanService;
use Whity\Core\Plan\PlanValidationException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * OPERATOR subscription-plan admin API (WC-plans, ADR 0010).
 *
 * Catalog CRUD + entitlement bundles + applying a plan to a target tenant:
 *   GET    /api/plans                       → list()
 *   POST   /api/plans                       → create()
 *   GET    /api/plans/{id}                  → show()
 *   PATCH  /api/plans/{id}                  → update()
 *   DELETE /api/plans/{id}                  → destroy()
 *   PUT    /api/plans/{id}/entitlements     → setEntitlements()
 *   POST   /api/tenants/{id}/plan           → applyToTenant()
 *   GET    /api/tenants/{id}/plan           → getTenantPlan()
 *
 * Plans are a PLATFORM catalog. `plans:manage` is necessary but NOT sufficient:
 * authorize() additionally requires the caller to be acting in the SYSTEM tenant
 * (id 0), so a regular tenant admin (who also holds the permission via the global
 * admin role) can never touch the plan catalog or another tenant's plan — the
 * cross-tenant escalation guard, mirroring TenantEntitlementsApiHandler.
 *
 * Applying a plan materialises its entitlement bundle into the target tenant
 * (PlanService::applyToTenant). Holds no request state — safe for a worker.
 */
final class PlansApiHandler
{
    private PlanService $plans;
    private RoleChecker $roleChecker;
    private PDO $db;

    public function __construct(PlanService $plans, RoleChecker $roleChecker, PDO $db)
    {
        $this->plans = $plans;
        $this->roleChecker = $roleChecker;
        $this->db = $db;
    }

    public function list(Request $request): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }

        return Response::json(['data' => $this->plans->listPlans()]);
    }

    public function create(Request $request): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }

        $body = JsonBody::parsed($request);
        try {
            $id = $this->plans->createPlan(
                (string) ($body['plan_key'] ?? ''),
                (string) ($body['name'] ?? ''),
                isset($body['description']) ? (string) $body['description'] : null,
                !isset($body['is_active']) || (bool) $body['is_active'],
                isset($body['sort_order']) ? (int) $body['sort_order'] : 0,
            );
        } catch (PlanValidationException $e) {
            return Response::error('Validation failed', 422, [$e->field() => $e->reason()]);
        }

        return Response::json(['data' => $this->plans->getPlanWithEntitlements($id)], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }

        $plan = $this->plans->getPlanWithEntitlements((int) ($params['id'] ?? 0));
        if ($plan === null) {
            return Response::error('Plan not found', 404);
        }

        return Response::json(['data' => $plan]);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }
        $id = (int) ($params['id'] ?? 0);

        $body = JsonBody::parsed($request);
        $fields = [];
        foreach (['name', 'description', 'is_active', 'sort_order'] as $f) {
            if (array_key_exists($f, $body)) {
                $fields[$f] = match ($f) {
                    'is_active'  => (bool) $body[$f],
                    'sort_order' => (int) $body[$f],
                    'description' => $body[$f] === null ? null : (string) $body[$f],
                    default      => (string) $body[$f],
                };
            }
        }
        if ($fields === []) {
            return Response::error('No updatable fields supplied', 422);
        }

        try {
            /** @var array{name?: string, description?: ?string, is_active?: bool, sort_order?: int} $fields */
            $updated = $this->plans->updatePlan($id, $fields);
        } catch (PlanValidationException $e) {
            return Response::error('Validation failed', 422, [$e->field() => $e->reason()]);
        }
        if (!$updated) {
            return Response::error('Plan not found', 404);
        }

        return Response::json(['data' => $this->plans->getPlanWithEntitlements($id)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }

        if (!$this->plans->deletePlan((int) ($params['id'] ?? 0))) {
            return Response::error('Plan not found', 404);
        }

        return Response::json([], 204);
    }

    /**
     * Replace a plan's entitlement bundle. Body: `{ "entitlements": { "<key>":
     * <value|null> } }`. Null removes the key from the bundle. Whole payload is
     * validated first (no partial write).
     *
     * @param array<string, string> $params
     */
    public function setEntitlements(Request $request, array $params): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }
        $id = (int) ($params['id'] ?? 0);
        if ($this->plans->getPlanWithEntitlements($id) === null) {
            return Response::error('Plan not found', 404);
        }

        $body = JsonBody::parsed($request);
        $entitlements = $body['entitlements'] ?? null;
        if (!is_array($entitlements) || $entitlements === [] || array_is_list($entitlements)) {
            return Response::error('Request body must include a non-empty "entitlements" object', 400);
        }

        // Validate the whole payload up front, mirroring EntitlementRegistry.
        $normalised = [];
        $details = [];
        foreach ($entitlements as $key => $value) {
            if (!is_string($key)) {
                $details['_'] = 'Entitlement keys must be strings.';
                continue;
            }
            if (!EntitlementRegistry::isKnown($key)) {
                $details[$key] = "Unknown entitlement key: {$key}";
                continue;
            }
            if ($value === null) {
                $normalised[$key] = null;
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (!is_scalar($value)) {
                $details[$key] = 'Value must be a scalar (or null to remove).';
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                $normalised[$key] = null;
                continue;
            }
            $reason = EntitlementRegistry::validate($key, $stringValue);
            if ($reason !== null) {
                $details[$key] = $reason;
                continue;
            }
            $normalised[$key] = $stringValue;
        }
        if ($details !== []) {
            return Response::error('Validation failed', 422, $details);
        }

        try {
            foreach ($normalised as $key => $value) {
                if ($value === null) {
                    $this->plans->removePlanEntitlement($id, $key);
                } else {
                    $this->plans->setPlanEntitlement($id, $key, $value);
                }
            }
        } catch (PlanValidationException $e) {
            return Response::error('Validation failed', 422, [$e->field() => $e->reason()]);
        }

        return Response::json(['data' => $this->plans->getPlanWithEntitlements($id)]);
    }

    /**
     * Apply a plan to a target tenant. Body: `{ "plan_id": <int> }`. The target
     * tenant is the path param.
     *
     * @param array<string, string> $params
     */
    public function applyToTenant(Request $request, array $params): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        ['userId' => $userId] = $ctx;

        $targetTenant = (int) ($params['id'] ?? 0);
        if (!$this->tenantExists($targetTenant)) {
            return Response::error('Tenant not found', 404);
        }

        $body = JsonBody::parsed($request);
        $planId = isset($body['plan_id']) ? (int) $body['plan_id'] : 0;
        if ($planId <= 0) {
            return Response::error('plan_id is required', 422);
        }

        try {
            $this->plans->applyToTenant($planId, $targetTenant, $userId);
        } catch (PlanValidationException $e) {
            return Response::error('Validation failed', 422, [$e->field() => $e->reason()]);
        }

        return Response::json(['data' => $this->plans->getTenantPlan($targetTenant)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function getTenantPlan(Request $request, array $params): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }
        $targetTenant = (int) ($params['id'] ?? 0);
        if (!$this->tenantExists($targetTenant)) {
            return Response::error('Tenant not found', 404);
        }

        return Response::json(['data' => $this->plans->getTenantPlan($targetTenant)]);
    }

    /**
     * Enforce `plans:manage` AND the system-tenant gate. Returns the acting
     * profile id (for the audit column) or a 403 Response.
     *
     * @return array{userId: int}|Response
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
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::PLANS_MANAGE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::PLANS_MANAGE]);
        }

        // Plans are a platform catalog — a system-tenant operation. The permission
        // is necessary but not sufficient (WC-235 pattern).
        if ($tenantId !== PlanService::systemTenantId()) {
            return Response::error('Plans are managed by the system tenant only', 403);
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
