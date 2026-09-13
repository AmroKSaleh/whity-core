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

        try {
            if (!$this->plans->deletePlan((int) ($params['id'] ?? 0))) {
                return Response::error('Plan not found', 404);
            }
        } catch (PlanValidationException $e) {
            // 409, NOT 422. The request is perfectly well formed; it is refused
            // because of what the tier still holds. A client telling the two
            // apart can offer the remedy — move these workspaces, or retire it —
            // instead of asking somebody to correct a field that is not wrong.
            return Response::error(
                'This tier is still in use',
                409,
                [$e->field() => $e->reason()]
            );
        }

        return Response::json([], 204);
    }

    /**
     * GET /api/plans/{id}/usage — what still points at a tier.
     *
     * Asked before offering a delete, so the screen can say WHY it is not on
     * offer rather than showing a disabled button with no explanation. Cheap
     * enough to ask for every row.
     *
     * @param array<string, string> $params
     */
    public function usage(Request $request, array $params): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($this->plans->getPlanWithEntitlements($id) === null) {
            return Response::error('Plan not found', 404);
        }

        $usage = $this->plans->usageFor($id);

        return Response::json(['data' => [
            'subscribers' => $usage->subscribers,
            'invoices' => $usage->invoices,
            'prices' => $usage->prices,
            'limits' => $usage->limits,
            'promotions' => $usage->promotions,
            'deletable' => $usage->isDeletable(),
            // Separate from `deletable` because it changes what an interface
            // should OFFER: a tier with subscribers has a route to deletion
            // (move them), a tier with invoices never will.
            'permanently_undeletable' => $usage->isPermanentlyUndeletable(),
            'refusal_reason' => $usage->refusalReason(),
        ]]);
    }

    /**
     * POST /api/plans/{id}/move-subscribers — put every workspace on this tier
     * onto another one. Body: `{ "to_plan_id": <int> }`.
     *
     * The remedy for a tier that cannot be deleted because people are on it.
     * Moving them is what makes retiring it honest: the tier stops being sold
     * and nobody is left on something nobody maintains.
     *
     * @param array<string, string> $params
     */
    public function moveSubscribers(Request $request, array $params): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }

        $body = JsonBody::parsed($request);
        $to = $body['to_plan_id'] ?? null;
        if (!is_int($to) && !(is_string($to) && ctype_digit($to))) {
            return Response::error('Request body must include an integer "to_plan_id"', 400);
        }

        $from = (int) ($params['id'] ?? 0);

        try {
            $moved = $this->plans->moveSubscribers($from, (int) $to, null);
        } catch (PlanValidationException $e) {
            return Response::error('Validation failed', 422, [$e->field() => $e->reason()]);
        }

        return Response::json(['data' => [
            'moved' => $moved,
            // Returned so the caller can act on the new state without a second
            // round trip — most often to retire the tier it has just emptied.
            'usage' => [
                'subscribers' => $this->plans->usageFor($from)->subscribers,
                'deletable' => $this->plans->usageFor($from)->isDeletable(),
            ],
        ]]);
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

        // TAKING SOMETHING AWAY NEEDS TO BE MEANT. A tier's bundle is read live,
        // so an edit reaches every workspace on that tier immediately — which is
        // the point, and which makes this endpoint a place where a typo restricts
        // paying customers. The service refuses a reduction unless the caller
        // says they mean it, and the refusal names how many workspaces it would
        // affect. `confirm_reduction` is the client saying the person saw that
        // number and went ahead.
        $confirmReduction = ($body['confirm_reduction'] ?? false) === true;

        try {
            foreach ($normalised as $key => $value) {
                if ($value === null) {
                    $this->plans->removePlanEntitlement($id, $key);
                } else {
                    $this->plans->setPlanEntitlement($id, $key, $value, $confirmReduction);
                }
            }
        } catch (PlanValidationException $e) {
            // 409 rather than 422: the value is perfectly valid, and the request
            // is refused because of who it would affect. A client telling those
            // apart can offer "apply anyway" for one and not the other.
            $status = str_contains($e->reason(), 'Confirm the reduction') ? 409 : 422;

            return Response::error(
                $status === 409 ? 'This change reduces access for existing workspaces' : 'Validation failed',
                $status,
                [$e->field() => $e->reason()]
            );
        }

        return Response::json(['data' => $this->plans->getPlanWithEntitlements($id)]);
    }

    /**
     * The vocabulary of sellable limits: every key, its kind, its baseline, and
     * who declared it.
     *
     * SEPARATE FROM THE PER-TENANT ENTITLEMENTS ENDPOINT, which answers "what
     * does THIS workspace get" and needs a tenant in the path. Pricing a tier is
     * a question about the catalogue, not about any one customer, and making
     * whoever prices things pick an arbitrary tenant first to discover what can
     * be priced would be a strange thing to ask.
     *
     * It carries `period` and `owner` so the editor can render a meter ("5 per
     * day") differently from a standing cap ("500 of them"), and group a
     * plugin's limits under the plugin that sells them.
     */
    public function entitlementCatalogue(Request $request): Response
    {
        if (($r = $this->authorize($request)) instanceof Response) {
            return $r;
        }

        return Response::json(['data' => EntitlementRegistry::catalogue()]);
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
