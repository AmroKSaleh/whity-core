<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

/**
 * A payout was asked to do something its current state does not allow.
 *
 * Distinct from a validation failure: the request was well formed and the
 * refusal is about WHEN it arrived — a payout already paid cannot be paid again
 * or discarded, because the money has moved and the row is the only record of
 * which transfer settled it.
 *
 * ── Why it carries a `reason` and not just a message ───────────────────────
 *
 * A handler that put `getMessage()` into a response would be refused by
 * {@see \Tests\Api\ExceptionLeakageTest}, and rightly: this class is not the
 * only thing that can reach a catch block, and the next one along carries a
 * SQLSTATE and a fragment of SQL. So the CALLER decides what the customer-facing
 * sentence is, and this carries a stable slug it can branch on.
 *
 * The message is still written, for logs and for whoever is reading a stack
 * trace. It is simply never the thing that reaches a client.
 */
final class PayoutStateException extends \RuntimeException
{
    /** No payout with that id. */
    public const NOT_FOUND = 'not_found';

    /** Already settled; re-marking would overwrite a real bank reference. */
    public const ALREADY_PAID = 'already_paid';

    /** Settled, so its commissions must not go back on the balance. */
    public const PAID_CANNOT_BE_DISCARDED = 'paid_cannot_be_discarded';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'No such payout.');
    }

    public static function alreadyPaid(): self
    {
        return new self(self::ALREADY_PAID, 'This payout is already marked paid.');
    }

    public static function paidCannotBeDiscarded(): self
    {
        return new self(
            self::PAID_CANNOT_BE_DISCARDED,
            'A payout that has been paid cannot be discarded.'
        );
    }
}
