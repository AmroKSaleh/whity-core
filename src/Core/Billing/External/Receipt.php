<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * Proof that a tenant paid, for the tenant to look at.
 *
 * FOR DISPLAY, AND NEVER FOR A DECISION. Access is answered by
 * {@see AccessSnapshot} and by nothing else — reconstructing "may they use it"
 * from what they have paid would mean owning a copy of a status table this side
 * does not maintain, and would be wrong the first time the other side tuned a
 * grace period. This exists because a customer who has paid deserves to see the
 * receipt, which is a different question with a different answer.
 *
 * A TENANT BILLED EXTERNALLY HAS NO LOCAL INVOICE, by design: the local billing
 * run stands down for them, precisely so nobody is charged twice. Which left the
 * billing screen showing an empty invoice table to somebody who had just paid —
 * technically accurate about OUR records and a lie about their money.
 *
 * Deliberately thin. A number to quote in an email, whether it is settled, what
 * it cost, and when. No line items, no tax breakdown, no payment method: those
 * belong to whoever issued the receipt, and copying them here would create a
 * second version of a document we did not write.
 */
final class Receipt
{
    public function __construct(
        public readonly string $number,
        public readonly string $status,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly ?string $paidAt,
        public readonly ?string $issuedAt,
        /**
         * The invoice before any discount, and what came off it.
         *
         * CARRIED FOR COMMISSION, not for display. An affiliate earns on what
         * the customer actually paid for the product — after discounts, and not
         * on tax, which is not income and is not ours to share. `totalMinor`
         * alone cannot express that: it is the amount charged, discount already
         * applied and tax already added.
         *
         * The billing service's invoice carries `subtotal` and `discount` and no
         * tax field at all, so `subtotal - discount` is the whole answer on that
         * side; a locally raised invoice has `tax_minor` to leave out as well.
         */
        public readonly int $subtotalMinor = 0,
        public readonly int $discountMinor = 0,
        /**
         * What is still settled against this invoice after any refunds.
         *
         * A FULL refund sets the status to `refunded`; a PARTIAL one leaves the
         * status alone and reduces this. Kept because it is what distinguishes
         * how MUCH came back, now that {@see $refundedAt} says whether any did.
         */
        public readonly int $amountPaidMinor = 0,
        /**
         * When money was given back, or null if none ever was.
         *
         * SET ON EVERY REFUND, partial ones included — which is what makes it
         * the signal to read rather than an amount comparison.
         *
         * It exists because the billing service clears `paid_at` on a full
         * reversal (`amount_paid` is then zero and the two must agree), and an
         * ABSENCE is a terrible thing to infer a refund from: read as "never
         * paid", every reversal becomes invisible and commission keeps being
         * paid on money that went back. That reading was ours, and they added
         * this field rather than leave us guessing at a hole.
         */
        public readonly ?string $refundedAt = null,
    ) {
    }

    /** Fully reversed — the billing service says so outright. */
    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    /**
     * Some of the money has gone back, but not all of it.
     *
     * READ FROM `refunded_at`, NOT FROM THE ARITHMETIC. Comparing what is still
     * settled against the invoice total cannot tell a partial REFUND from a
     * partial PAYMENT — both leave less settled than the invoice is for — so the
     * old inference would eventually have called an underpaid invoice a refund
     * and reported a clawback nobody made. A date that is only ever set when
     * money moves outward says exactly one thing.
     *
     * Reported rather than acted on: the commission ledger holds one entry per
     * payment and reverses it whole, so it cannot express "give back a third".
     * Silently ignoring it would quietly overpay; silently reversing it whole
     * would underpay. Naming it lets a person settle the difference until the
     * ledger learns to.
     */
    public function isPartiallyRefunded(): bool
    {
        return !$this->isRefunded() && $this->refundedAt !== null;
    }

    /**
     * What an affiliate commission is calculated on.
     *
     * Falls back to the total when the service sent no breakdown — a receipt
     * from an older payload shape earns on its face value rather than on zero,
     * because an accrual of nothing is a silent underpayment and somebody would
     * find it in their own statement before we did.
     */
    public function commissionBaseMinor(): int
    {
        if ($this->subtotalMinor === 0) {
            return $this->totalMinor;
        }

        return max(0, $this->subtotalMinor - $this->discountMinor);
    }

    /**
     * @param array<string, mixed> $payload One invoice from the billing service.
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            self::str($payload['number'] ?? null) ?? '',
            self::str($payload['status'] ?? null) ?? 'unknown',
            // MINOR UNITS, uncast and undivided. The dinar has three decimal
            // places, so anything that "helpfully" converts here is wrong by a
            // factor of ten; the screen formats from the integer and the code.
            is_int($payload['total'] ?? null) ? $payload['total'] : (int) ($payload['total'] ?? 0),
            self::str($payload['currency'] ?? null) ?? '',
            self::str($payload['paid_at'] ?? null),
            self::str($payload['due_at'] ?? null),
            self::intOrZero($payload['subtotal'] ?? null),
            self::intOrZero($payload['discount'] ?? null),
            self::intOrZero($payload['amount_paid'] ?? null),
            self::str($payload['refunded_at'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'status' => $this->status,
            'total_minor' => $this->totalMinor,
            'currency' => $this->currency,
            'paid_at' => $this->paidAt,
            'issued_at' => $this->issuedAt,
        ];
    }

    private static function intOrZero(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
