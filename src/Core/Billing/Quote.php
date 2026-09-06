<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use Whity\Core\Money\Money;

/**
 * What a tenant would pay, and how that number was arrived at.
 *
 * CARRIES THE WORKING, NOT JUST THE TOTAL. A quote that reported only "4,410
 * SAR" cannot be checked by the person paying it, cannot be shown as a
 * breakdown, and cannot be reconciled against a charge afterwards. Every line
 * below is something a customer or an operator will at some point need to see
 * spelled out: the list price, how many seats, what came off, and why.
 *
 * The DISCOUNT is what was actually taken off — not what the promotion offered.
 * A fixed 50 against a subtotal of 30 discounts 30, because you cannot take more
 * off a thing than it costs, and recording the offer instead would put a number
 * in the ledger that never moved.
 */
final class Quote
{
    public function __construct(
        /** The price per unit before anything is multiplied or taken off. */
        public readonly Money $unitPrice,
        /** Seats being bought. 1 for a flat price. */
        public readonly int $quantity,
        /** `unitPrice` × `quantity`, before any discount. */
        public readonly Money $subtotal,
        /** What was actually taken off — never more than the subtotal. */
        public readonly Money $discount,
        /** What is owed. Never negative. */
        public readonly Money $total,
        /** The promotion applied, or null. */
        public readonly ?int $promotionId = null,
        /** Its name, so a breakdown can say WHY the discount is there. */
        public readonly ?string $promotionName = null,
    ) {
    }

    public function hasDiscount(): bool
    {
        return !$this->discount->isZero();
    }

    /**
     * The quote as plain data, for an API response or a stored record.
     *
     * Amounts are minor units with their currency beside them, never formatted:
     * how to render 4410 SAR depends on a locale this layer does not know, and a
     * pre-formatted string is one a caller has to parse back to do arithmetic on.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->total->currency,
            'unit_price' => $this->unitPrice->amount,
            'quantity' => $this->quantity,
            'subtotal' => $this->subtotal->amount,
            'discount' => $this->discount->amount,
            'total' => $this->total->amount,
            'promotion_id' => $this->promotionId,
            'promotion_name' => $this->promotionName,
        ];
    }
}
