<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use Whity\Core\Money\Money;
use Whity\Core\Money\MoneyException;
use Whity\Core\Promotion\PromotionEligibility;
use Whity\Core\Promotion\PromotionService;
use Whity\Core\Seat\SeatService;

/**
 * What would this tenant pay for this plan, on these terms, with this promotion?
 *
 * The arithmetic half of the commercial work. Eligibility — may this promotion
 * be used at all — belongs to {@see PromotionService} and is asked FIRST; this
 * only computes, and refuses to compute with a promotion that was not judged
 * eligible.
 *
 * THE ORDER OF OPERATIONS IS FIXED AND WORTH STATING:
 *
 *   1. subtotal = unit price × quantity
 *   2. discount = the promotion applied to the SUBTOTAL
 *   3. total    = subtotal − discount, never below zero
 *
 * Applying the discount to the UNIT price instead would give a different answer
 * once rounding entered: 33% off a unit of 5, ten seats, is 17 off the subtotal
 * of 50 — but 2 off each unit is 20. Both are defensible; only one can be the
 * rule, so it is written down and tested rather than left to whichever call site
 * was written first.
 *
 * SEATS COME FROM THE SEAT SERVICE, not from a caller's parameter, when a price
 * is per-seat. A quote priced on a number somebody passed in is a quote for a
 * subscription that may not exist — and the number that matters is the one the
 * seat limit is enforced against.
 */
final class QuoteService
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly SeatService $seats,
    ) {
    }

    /**
     * Price a plan for a tenant.
     *
     * @param array<string, mixed>      $price     A `plan_prices` row.
     * @param array<string, mixed>|null $promotion An ELIGIBLE promotion row, or
     *        null. Eligibility is not re-checked here beyond the currency
     *        invariant: the caller asks {@see PromotionService::checkCode()}
     *        first, because the reason a promotion cannot be used is an answer
     *        for a person and not something to discover halfway through
     *        arithmetic.
     *
     * @throws MoneyException When the promotion's currency does not match the
     *         price's. Structural rather than a validation message, because
     *         {@see PromotionService} already refuses that pairing with a
     *         reason — reaching here with it means a caller skipped the check.
     *
     *         Also when `$price` is PER-DEVICE: its quantity depends on a
     *         billing period a quote does not carry, so there is no number to
     *         return that is not a guess. Same reasoning as the currency case —
     *         a caller reached here that should not have.
     */
    public function quote(int $tenantId, array $price, ?array $promotion = null): Quote
    {
        $currency = (string) $price['currency'];
        $unit = Money::of((int) $price['unit_amount'], $currency);

        // A PER-DEVICE PRICE IS REFUSED RATHER THAN QUOTED AS ONE UNIT. Falling
        // through to the flat branch would quote 1 × unit for a price the
        // billing run invoices at N × unit, and the customer would agree to one
        // number and be charged another — the single worst outcome available
        // here, and one that looks entirely plausible on screen.
        //
        // It is not merely unimplemented. Quoting it needs a PERIOD that a
        // pre-purchase quote does not have: `licensing.billing_basis` may be
        // `active_in_period`, which counts units seen BETWEEN two dates, and
        // there is no honest default window for a tenant who has not bought
        // anything yet. Whoever wires checkout to per-device plans has to decide
        // what a quote means on that basis; this makes that a decision rather
        // than an accident.
        if (($price['is_per_device'] ?? false) === true) {
            throw new MoneyException(
                'A per-device price cannot be quoted: the quantity depends on a billing period '
                . 'the quote does not have. Price it through the billing run, or decide what a '
                . 'pre-purchase device count means first.'
            );
        }

        // Per-seat prices multiply by the seats the tenant actually holds. A
        // flat price is one of itself — expressed as quantity 1 rather than as a
        // separate branch, so the subtotal is computed one way.
        $quantity = ($price['is_per_seat'] ?? false) === true
            ? max(1, $this->seats->used($tenantId))
            : 1;

        $subtotal = $unit->multipliedBy($quantity);

        if ($promotion === null) {
            return new Quote($unit, $quantity, $subtotal, Money::zero($currency), $subtotal);
        }

        $discount = $this->discountFor($promotion, $subtotal);

        return new Quote(
            $unit,
            $quantity,
            $subtotal,
            $discount,
            // `clampedToZero()` is UNREACHABLE while `discountFor()` clamps the
            // discount to the subtotal, and mutation testing confirms it: taking
            // it out fails nothing. It stays as the invariant's last line — the
            // two clamps guard different things, and only one of them is
            // reachable at a time.
            //
            //   discountFor()  keeps the RECORDED discount honest: what actually
            //                  came off, never the larger offer.
            //   clampedToZero() keeps the TOTAL honest if that ever stops being
            //                  true — a discount bigger than the bill must not
            //                  turn into money owed the other way.
            $subtotal->minus($discount)->clampedToZero(),
            (int) $promotion['id'],
            (string) $promotion['name'],
        );
    }

    /**
     * What the promotion actually takes off this subtotal.
     *
     * NEVER MORE THAN THE SUBTOTAL. A fixed 50 against a price of 30 discounts
     * 30, not 50: you cannot take more off a thing than it costs, and recording
     * the offer instead would put a number in the ledger that never moved and
     * make the totals stop adding up.
     *
     * @param array<string, mixed> $promotion
     * @throws MoneyException On a currency mismatch — see the note on quote().
     */
    private function discountFor(array $promotion, Money $subtotal): Money
    {
        if ($promotion['percent_off'] !== null) {
            // A percentage has no currency and so applies to any price. Rounded
            // half up, in integers — see Money::percentage().
            return $subtotal->percentage((int) $promotion['percent_off']);
        }

        $offered = Money::of((int) $promotion['amount_off'], (string) $promotion['currency']);

        // Throws when the currencies differ, which is the invariant this method
        // relies on rather than re-checking: comparing them any other way would
        // let a mismatched pair through as a number.
        return $offered->isGreaterThan($subtotal) ? $subtotal : $offered;
    }

    /**
     * Price a plan using a promotion CODE, judging it first.
     *
     * The convenience a checkout wants: one call that either prices with the
     * code applied or says why it could not. An ineligible code produces the
     * undiscounted quote AND the reason, rather than an exception — a customer
     * mistyping a code should still see what they would pay.
     *
     * @param array<string, mixed> $price
     * @return array{quote: Quote, eligibility: PromotionEligibility|null}
     */
    public function quoteWithCode(int $tenantId, array $price, ?string $code): array
    {
        if ($code === null || trim($code) === '') {
            return ['quote' => $this->quote($tenantId, $price), 'eligibility' => null];
        }

        $eligibility = $this->promotions->checkCode(
            $code,
            $tenantId,
            (int) $price['plan_id'],
            (string) $price['currency'],
        );

        return [
            'quote' => $this->quote($tenantId, $price, $eligibility->eligible ? $eligibility->promotion : null),
            'eligibility' => $eligibility,
        ];
    }
}
