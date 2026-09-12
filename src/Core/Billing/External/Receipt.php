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
    ) {
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

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
