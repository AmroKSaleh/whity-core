<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\Plan\PlanValidationException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * What a plan COSTS — the operator surface over `plan_prices`.
 *
 *   GET    /api/plans/{id}/prices        → list()
 *   POST   /api/plans/{id}/prices        → create()
 *   DELETE /api/plans/{id}/prices/{pid}  → retire()
 *
 * SAME GATE AS THE PLAN CATALOGUE, and for the same reason: `plans:manage` is
 * necessary but not sufficient, because a price is a platform-wide commercial
 * fact rather than a tenant's own setting. A regular tenant admin holds the
 * permission through the global admin role, so without the system-tenant check
 * they could reprice the product for everybody.
 *
 * DELETE RETIRES RATHER THAN DESTROYS. The row is what a past charge was made
 * against, and the partial unique index frees its slot the moment it stops being
 * active — so the replacement can be created without the evidence being thrown
 * away. The verb is DELETE because that is what a REST client expects for
 * "remove this from the list I can sell"; the docblock and the response say what
 * actually happened.
 *
 * AMOUNTS ARE MINOR UNITS ON THE WIRE, exactly as they are in the database. No
 * decimal conversion happens here: a boundary that accepted "49.00" would have
 * to decide how many decimal places the currency has, and that decision belongs
 * with the currency rather than with an HTTP handler.
 */
final class PlanPricesApiHandler
{
    public function __construct(
        private readonly PlanPriceRepository $prices,
        private readonly PlanRepository $plans,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    /** @param array<string, string> $params */
    public function list(Request $request, array $params): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        $planId = (int) ($params['id'] ?? 0);
        if ($this->plans->findById($planId) === null) {
            return Response::error('Plan not found', 404);
        }

        // Retired prices are INCLUDED. A screen showing only live ones cannot
        // explain a charge somebody is querying, and "what was this tenant
        // paying in March" is the question such a screen is opened to answer.
        return Response::json(['data' => $this->prices->listForPlan($planId)]);
    }

    /** @param array<string, string> $params */
    public function create(Request $request, array $params): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        $planId = (int) ($params['id'] ?? 0);
        if ($this->plans->findById($planId) === null) {
            return Response::error('Plan not found', 404);
        }

        $body = JsonBody::parsed($request);

        // `unit_amount` must be an INTEGER of minor units. A float arriving here
        // — 49.9 for "49.90" — would silently truncate to 49, which is a
        // hundredfold error that looks like a plausible price.
        $amount = $body['unit_amount'] ?? null;
        if (!is_int($amount)) {
            return Response::error(
                'unit_amount must be an integer number of minor units (4900 for 49.00), not a decimal',
                422
            );
        }

        try {
            $id = $this->prices->create(
                $planId,
                (string) ($body['currency'] ?? ''),
                $amount,
                (string) ($body['billing_period'] ?? ''),
                ($body['is_per_seat'] ?? false) === true,
            );
        } catch (PlanValidationException $e) {
            // The exception's STRUCTURED fields, never its message. A handler
            // interpolating getMessage() is refused by ExceptionLeakageTest, and
            // rightly: you cannot tell a safe message from one carrying a
            // SQLSTATE by looking, and the next exception type to arrive here
            // will be the unsafe one. Mirrors PlansApiHandler.
            return Response::error('Validation failed', 422, [$e->field() => $e->reason()]);
            // @phpstan-ignore catch.neverThrown
        } catch (\PDOException) {
            // NOT DEAD, whatever the analyser thinks: the partial unique index
            // on `plan_prices` raises this, and two tests prove it — the
            // repository's `testTwoLivePricesOnTheSameTermsAreRefused` and this
            // handler's own 409 case. PHPStan does not model PDO as throwing, so
            // it cannot see a path that the suite exercises on every run.
            //
            // Reported as a CONFLICT with the reason, rather than as a 500 about
            // an index name nobody outside this file can act on.
            return Response::error(
                'This plan already has a live price for that currency, period and seat basis. '
                . 'Retire the existing one first.',
                409
            );
        }

        return Response::json(['data' => $this->prices->findById($id)], 201);
    }

    /** @param array<string, string> $params */
    public function retire(Request $request, array $params): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        $priceId = (int) ($params['priceId'] ?? 0);
        $price = $this->prices->findById($priceId);
        if ($price === null || $price['plan_id'] !== (int) ($params['id'] ?? 0)) {
            return Response::error('Price not found', 404);
        }

        $this->prices->deactivate($priceId);

        // The retired row comes back rather than a 204, so a client can show
        // what happened without a second request — and can see that the row
        // still exists, which is the point of retiring instead of deleting.
        return Response::json(['data' => $this->prices->findById($priceId)]);
    }

    /**
     * `plans:manage` AND the system tenant, mirroring {@see PlansApiHandler}.
     *
     * @return true|Response
     */
    private function authorize(Request $request): bool|Response
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

        if ($tenantId !== PlanService::systemTenantId()) {
            return Response::error('Plan prices are managed by the system tenant only', 403);
        }

        return true;
    }
}
