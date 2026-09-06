<?php

declare(strict_types=1);

namespace Whity\Core\Promotion;

/**
 * May this tenant use this promotion, for this plan, in this currency, now?
 *
 * ONE PROMOTION AT A TIME. There is deliberately no stacking: a caller asks
 * about one promotion and gets one answer. Stacking is where discount systems go
 * wrong — two 60% promotions are not 120% off, "percentage then fixed" and
 * "fixed then percentage" give different totals, and every rule invented to
 * resolve that is a rule somebody has to remember when writing the next one. If
 * combining is ever wanted it should arrive as an explicit, tested composition,
 * not as an accident of a caller applying two.
 *
 * THE CLOCK IS INJECTED so the boundary cases can be tested at all. A promotion
 * that expires "now" is exactly the case worth pinning, and a service reading
 * the wall clock can only be tested by waiting.
 *
 * BOUNDARIES ARE INCLUSIVE AT THE START AND EXCLUSIVE AT THE END. A promotion
 * running 1 June to 1 July is usable for the whole of June and not on 1 July,
 * which is what an operator entering those dates means. The alternative makes
 * the last day ambiguous and, worse, makes two adjacent campaigns overlap for an
 * instant.
 */
final class PromotionService
{
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private readonly PromotionRepository $repo,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Look up a CODE and judge it.
     *
     * `null` currency means the caller is not yet pricing anything — checking a
     * code before a plan is chosen — so the currency test is skipped rather than
     * failed. A fixed-amount promotion still has to match once a price exists,
     * and {@see self::judge()} is where that happens either way.
     */
    public function checkCode(
        string $code,
        int $tenantId,
        ?int $planId = null,
        ?string $currency = null,
    ): PromotionEligibility {
        $normalized = PromotionRepository::normalizeCode($code);
        if ($normalized === null) {
            return PromotionEligibility::no(PromotionEligibility::NOT_FOUND);
        }

        // Looked up WITHOUT the active filter so "switched off" can be told from
        // "never existed". Both are hidden from a customer — see
        // PromotionEligibility::isDisclosableToCustomer() — but an operator
        // screen needs them apart, and a distinction thrown away here cannot be
        // recovered later.
        $promotion = $this->repo->findAnyByCode($normalized);
        if ($promotion === null) {
            return PromotionEligibility::no(PromotionEligibility::NOT_FOUND);
        }

        return $this->judge($promotion, $tenantId, $planId, $currency);
    }

    /**
     * The automatic promotions this tenant qualifies for right now — the early
     * birds and offers, which carry no code.
     *
     * Returns every eligible one rather than picking, because choosing between
     * two live offers is a commercial decision (best for the customer? most
     * recently launched? the one the campaign is about?) and inventing a rule
     * here would apply it silently to every caller. The caller that knows what
     * it is doing picks.
     *
     * @return list<array<string, mixed>>
     */
    public function automaticFor(int $tenantId, ?int $planId = null, ?string $currency = null): array
    {
        $out = [];
        foreach ($this->repo->listAutomatic() as $promotion) {
            if ($this->judge($promotion, $tenantId, $planId, $currency)->eligible) {
                $out[] = $promotion;
            }
        }

        return $out;
    }

    /**
     * The rules, in the order that gives the most useful reason.
     *
     * Ordering matters because only the FIRST failure is reported, and some
     * reasons are more actionable than others. "This does not apply to the plan
     * you chose" is worth hearing even about a promotion that is also nearly
     * exhausted, so structural mismatches come before availability.
     *
     * @param array<string, mixed> $promotion
     */
    public function judge(
        array $promotion,
        int $tenantId,
        ?int $planId = null,
        ?string $currency = null,
    ): PromotionEligibility {
        if ($promotion['is_active'] !== true) {
            return PromotionEligibility::no(PromotionEligibility::INACTIVE);
        }

        $now = ($this->clock)();

        if ($promotion['starts_at'] !== null && $now < strtotime((string) $promotion['starts_at'])) {
            return PromotionEligibility::no(PromotionEligibility::NOT_STARTED);
        }
        // Exclusive: a promotion ending at midnight is not usable at midnight.
        if ($promotion['ends_at'] !== null && $now >= strtotime((string) $promotion['ends_at'])) {
            return PromotionEligibility::no(PromotionEligibility::EXPIRED);
        }

        // Structural mismatches BEFORE availability — see the note above.
        if ($planId !== null) {
            $planIds = $this->repo->planIdsFor((int) $promotion['id']);
            // Empty means every plan; a blanket promotion stores no rows rather
            // than one per plan, so a new tier is covered automatically.
            if ($planIds !== [] && !in_array($planId, $planIds, true)) {
                return PromotionEligibility::no(PromotionEligibility::PLAN_NOT_ELIGIBLE);
            }
        }

        // A fixed amount is an amount OF A CURRENCY. Refused rather than
        // converted: a conversion needs a rate nobody stored and that has since
        // moved, and a discount that quietly became a different amount of money
        // is worse than one that did not apply. A percentage has no currency and
        // so never mismatches.
        if ($currency !== null && $promotion['amount_off'] !== null) {
            if (strtoupper($currency) !== strtoupper((string) $promotion['currency'])) {
                return PromotionEligibility::no(PromotionEligibility::CURRENCY_MISMATCH);
            }
        }

        $id = (int) $promotion['id'];

        if ($promotion['max_redemptions'] !== null
            && $this->repo->redemptionCount($id) >= (int) $promotion['max_redemptions']
        ) {
            return PromotionEligibility::no(PromotionEligibility::FULLY_REDEEMED);
        }

        if ($this->repo->redemptionCountForTenant($id, $tenantId) >= (int) $promotion['max_redemptions_per_tenant']) {
            return PromotionEligibility::no(PromotionEligibility::ALREADY_REDEEMED);
        }

        return PromotionEligibility::yes($promotion);
    }
}
