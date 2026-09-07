<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Plan\PlanService;
use Whity\Core\Promotion\PromotionRepository;
use Whity\Core\Promotion\PromotionValidationException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Early birds, offers and promo codes — the operator surface.
 *
 *   GET    /api/promotions        → list()
 *   POST   /api/promotions        → create()
 *   DELETE /api/promotions/{id}   → retire()
 *
 * ONE OBJECT, THREE DISCOVERIES. A promotion with a `code` must be typed by the
 * customer; one without applies automatically to whoever qualifies. That is the
 * only structural difference between a promo code and an early bird, so there is
 * one endpoint rather than three.
 *
 * SAME GATE AS THE PLAN CATALOGUE — `plans:manage` AND the system tenant. A
 * promotion is a platform-wide commercial fact: a tenant admin holding the
 * permission through the global admin role could otherwise mint themselves a
 * hundred-per-cent discount.
 *
 * THE LISTING CARRIES REDEMPTION COUNTS, because "how much of this early bird is
 * left" is the question an operator opens the screen to answer, and a list
 * without it would need one request per row to find out.
 */
final class PromotionsApiHandler
{
    public function __construct(
        private readonly PromotionRepository $promotions,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function list(Request $request): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        return Response::json(['data' => $this->promotions->listAll()]);
    }

    public function create(Request $request): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        $body = JsonBody::parsed($request);
        $name = (string) ($body['name'] ?? '');
        $code = isset($body['code']) && is_string($body['code']) && trim($body['code']) !== ''
            ? (string) $body['code']
            : null;

        $planIds = [];
        if (isset($body['plan_ids']) && is_array($body['plan_ids'])) {
            foreach ($body['plan_ids'] as $planId) {
                if (is_int($planId)) {
                    $planIds[] = $planId;
                }
            }
        }

        $starts = isset($body['starts_at']) && is_string($body['starts_at']) && $body['starts_at'] !== ''
            ? (string) $body['starts_at']
            : null;
        $ends = isset($body['ends_at']) && is_string($body['ends_at']) && $body['ends_at'] !== ''
            ? (string) $body['ends_at']
            : null;
        $maxRedemptions = isset($body['max_redemptions']) && is_int($body['max_redemptions'])
            ? $body['max_redemptions']
            : null;
        $maxPerTenant = isset($body['max_redemptions_per_tenant']) && is_int($body['max_redemptions_per_tenant'])
            ? $body['max_redemptions_per_tenant']
            : 1;

        try {
            // Percentage or fixed amount, never both — the database CHECK says so
            // too, and this decides which the caller asked for rather than
            // guessing when they sent both.
            if (isset($body['percent_off'])) {
                if (!is_int($body['percent_off'])) {
                    return Response::error('Validation failed', 422, ['percent_off' => 'must be a whole number 1-100']);
                }
                $id = $this->promotions->createPercentOff(
                    $name,
                    $body['percent_off'],
                    $code,
                    $starts,
                    $ends,
                    $maxRedemptions,
                    $maxPerTenant,
                    $planIds,
                );
            } elseif (isset($body['amount_off'])) {
                // Minor units, as everywhere else. A decimal would truncate to a
                // hundredth of the intended discount — plausible-looking and
                // wrong, so it is refused rather than rounded.
                if (!is_int($body['amount_off'])) {
                    return Response::error(
                        'Validation failed',
                        422,
                        ['amount_off' => 'must be an integer of minor units (5000 for 50.00), not a decimal']
                    );
                }
                $id = $this->promotions->createAmountOff(
                    $name,
                    $body['amount_off'],
                    (string) ($body['currency'] ?? ''),
                    $code,
                    $starts,
                    $ends,
                    $maxRedemptions,
                    $maxPerTenant,
                    $planIds,
                );
            } else {
                return Response::error(
                    'Validation failed',
                    422,
                    ['percent_off' => 'a promotion needs either percent_off or amount_off']
                );
            }
        } catch (PromotionValidationException $e) {
            // The exception's STRUCTURED fields, never its message — a handler
            // interpolating getMessage() is refused by ExceptionLeakageTest, and
            // rightly: the next exception type to reach here carries a SQLSTATE.
            return Response::error('Validation failed', 422, [$e->field => $e->reason]);
        } catch (\PDOException) {
            // The partial unique index on live codes. A conflict with its reason,
            // not a 500 naming an index.
            return Response::error(
                'Another live promotion already uses that code. Retire it first, or choose another code.',
                409
            );
        }

        return Response::json(['data' => $this->promotions->findById($id)], 201);
    }

    /** @param array<string, string> $params */
    public function retire(Request $request, array $params): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        $id = (int) ($params['id'] ?? 0);
        if ($this->promotions->findById($id) === null) {
            return Response::error('Promotion not found', 404);
        }

        $this->promotions->deactivate($id);

        // The retired row comes back rather than a 204: a redeemed promotion is
        // the evidence of why a tenant is paying what they are paying, and a
        // client should be able to see it survived.
        return Response::json(['data' => $this->promotions->findById($id)]);
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
            return Response::error('Promotions are managed by the system tenant only', 403);
        }

        return true;
    }
}
