<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Affiliate\AffiliateRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The people who send us customers — the operator surface.
 *
 *   GET    /api/affiliates        → list()
 *   POST   /api/affiliates        → create()
 *   PATCH  /api/affiliates/{id}   → update()
 *
 * ── Why this exists at all ─────────────────────────────────────────────────
 *
 * Everything else in the affiliate programme — the codes, the attribution, the
 * ledger, the accrual sweep — was unreachable without it. There was no way to
 * create a row in `affiliates`, so no code existed, so no referral could be
 * attributed and no commission could be earned. The whole feature looked
 * finished and produced nothing, which is the failure mode it shares with a
 * sweep nobody schedules.
 *
 * ── The same gate as the plan catalogue ────────────────────────────────────
 *
 * `plans:manage` AND the system tenant. An affiliate rate is an instruction to
 * pay somebody real money out of the platform's own revenue, so it belongs with
 * pricing and not with anything a tenant administrator can reach: one holding
 * `plans:manage` through a global admin role could otherwise mint themselves a
 * hundred-per-cent code and refer their own workspaces.
 *
 * ── There is no delete ─────────────────────────────────────────────────────
 *
 * Deactivating stops future earning; nothing removes an affiliate. Their
 * commissions point at the row, and money already owed is owed whatever happens
 * to the arrangement — a deleted affiliate would take the evidence of their own
 * payouts with them. This mirrors how a retired tier is kept rather than
 * dropped.
 */
final class AffiliatesApiHandler
{
    /**
     * The most anybody can be paid for a referral.
     *
     * NOT 100%. A rate at or near the full price is not a commission, it is a
     * mistake being typed — and one that would be discovered after the money
     * left. Fifty per cent is generous for a referral programme and still
     * leaves the refusal explainable to whoever hit it.
     */
    private const MAX_COMMISSION_BP = 5000;

    /** A window has to be at least a month and cannot outlive the deal. */
    private const MAX_WINDOW_MONTHS = 60;

    public function __construct(
        private readonly AffiliateRepository $affiliates,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    public function list(Request $request): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        return Response::json(['data' => $this->affiliates->listAll()]);
    }

    public function create(Request $request): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        $body = JsonBody::parsed($request);

        $code = self::text($body['code'] ?? null);
        $name = self::text($body['name'] ?? null);

        $errors = [];
        if ($code === null) {
            $errors['code'] = 'A code is required — it is what goes in the link.';
        } elseif (mb_strlen($code) > 64) {
            $errors['code'] = 'A code must be 64 characters or fewer.';
        } elseif (preg_match('/^[A-Za-z0-9._-]+$/', $code) !== 1) {
            // A CODE TRAVELS IN A URL AND IS TYPED BY HAND. Anything needing
            // escaping produces a link that breaks in somebody's email client,
            // and the affiliate is the last to find out.
            $errors['code'] = 'A code may use letters, digits, dots, dashes and underscores only.';
        }

        if ($name === null) {
            $errors['name'] = 'A name is required — it is who the payout is for.';
        }

        $rate = $body['commission_bp'] ?? null;
        if (!is_int($rate) || $rate < 1 || $rate > self::MAX_COMMISSION_BP) {
            // BASIS POINTS, refused rather than coerced. A caller sending 20
            // meaning "20%" would create a 0.2% affiliate and nobody would
            // notice until the first statement, so the units are stated in the
            // refusal rather than guessed at.
            $errors['commission_bp'] = sprintf(
                'A rate is basis points, 1 to %d (2000 = 20%%).',
                self::MAX_COMMISSION_BP
            );
        }

        $window = $body['window_months'] ?? 12;
        if (!is_int($window) || $window < 1 || $window > self::MAX_WINDOW_MONTHS) {
            $errors['window_months'] = sprintf('A window is 1 to %d months.', self::MAX_WINDOW_MONTHS);
        }

        if ($errors !== []) {
            return Response::error('Validation failed', 422, $errors);
        }

        /** @var string $code */
        /** @var string $name */
        /** @var int $rate */
        /** @var int $window */

        // CHECKED BEFORE INSERTING, and the database still has the last word:
        // this is the friendly message, the functional unique index is the
        // guarantee. Two operators creating the same code at once lose the race
        // at the index rather than here, which is why the insert is also
        // wrapped.
        if ($this->affiliates->codeExists($code)) {
            return Response::error(
                'Another affiliate already uses that code. Codes are matched without regard to case.',
                409
            );
        }

        try {
            $id = $this->affiliates->create(
                $code,
                $name,
                $rate,
                $window,
                self::text($body['email'] ?? null),
                is_int($body['profile_id'] ?? null) ? $body['profile_id'] : null,
                is_int($body['promotion_id'] ?? null) ? $body['promotion_id'] : null,
            );
        } catch (\PDOException) {
            return Response::error(
                'Another affiliate already uses that code. Codes are matched without regard to case.',
                409
            );
        }

        return Response::json(['data' => $this->affiliates->findById($id)], 201);
    }

    /**
     * Renegotiate the terms, or stop the arrangement.
     *
     * A RATE CHANGE IS NOT RETROACTIVE, and that is worth knowing before using
     * this: every commission already accrued copied the rate it was earned at
     * onto its own row. Changing this moves only what has not been earned yet,
     * which is what renegotiating means.
     *
     * An OPEN WINDOW DOES NOT MOVE EITHER. A referral's end date is stored when
     * its first payment arrives and frozen there, so changing `window_months`
     * applies to referrals that have not converted yet — never to one both
     * parties have already been earning under.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $denied = $this->authorize($request);
        if ($denied instanceof Response) {
            return $denied;
        }

        $id = (int) ($params['id'] ?? 0);
        $existing = $this->affiliates->findById($id);
        if ($existing === null) {
            return Response::error('Affiliate not found', 404);
        }

        $body = JsonBody::parsed($request);

        if (isset($body['is_active'])) {
            if (!is_bool($body['is_active'])) {
                return Response::error('Validation failed', 422, ['is_active' => 'must be true or false']);
            }
            $this->affiliates->setActive($id, $body['is_active']);
        }

        $termsGiven = array_key_exists('commission_bp', $body)
            || array_key_exists('window_months', $body)
            || array_key_exists('promotion_id', $body);

        if ($termsGiven) {
            $rate = $body['commission_bp'] ?? $existing['commission_bp'];
            $window = $body['window_months'] ?? $existing['window_months'];

            $errors = [];
            if (!is_int($rate) || $rate < 1 || $rate > self::MAX_COMMISSION_BP) {
                $errors['commission_bp'] = sprintf(
                    'A rate is basis points, 1 to %d (2000 = 20%%).',
                    self::MAX_COMMISSION_BP
                );
            }
            if (!is_int($window) || $window < 1 || $window > self::MAX_WINDOW_MONTHS) {
                $errors['window_months'] = sprintf('A window is 1 to %d months.', self::MAX_WINDOW_MONTHS);
            }
            if ($errors !== []) {
                return Response::error('Validation failed', 422, $errors);
            }

            // NULL IS A VALUE HERE, not an omission: sending `promotion_id: null`
            // detaches the discount the code carried, which is a thing an
            // operator does at the end of a campaign. `??` alone could not tell
            // that apart from "leave it as it is".
            $promotionId = array_key_exists('promotion_id', $body)
                ? (is_int($body['promotion_id']) ? $body['promotion_id'] : null)
                : $existing['promotion_id'];

            /** @var int $rate */
            /** @var int $window */
            /** @var int|null $promotionId */
            $this->affiliates->updateTerms($id, $rate, $window, $promotionId);
        }

        return Response::json(['data' => $this->affiliates->findById($id)]);
    }

    /** Trimmed, or null when there was nothing there. */
    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * `plans:manage` AND the system tenant, mirroring {@see PromotionsApiHandler}.
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
            return Response::error('Affiliates are managed by the system tenant only', 403);
        }

        return true;
    }
}
