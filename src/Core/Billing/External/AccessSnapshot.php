<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * What the billing service says about one tenant's access, at one moment.
 *
 * THE WHOLE ANSWER, AND NOTHING ELSE. whity-core asks the billing service two
 * questions; this is the second one's reply. It carries no amount, no currency,
 * no payment method, no transaction and no provider, because whity-core cannot
 * act on any of those and holding them would invite someone to try.
 *
 * `$hasAccess` IS NOT DERIVED HERE, and that is the single most important
 * property of this class. The billing service computes it from a status table
 * that is its business and that changes without us — `past_due` grants access
 * on purpose, because a card that expired over a weekend should not lock a
 * paying customer out mid-sentence while dunning still has days of retries
 * left. Re-deriving it from `$status` would mean owning a copy of that table,
 * and the copy would be wrong the first time they tuned it.
 *
 * So: read `$hasAccess`. `$status` exists to explain the answer to a human — a
 * banner that says "your payment did not go through" — never to compute it.
 */
final class AccessSnapshot
{
    /**
     * @param string      $subjectRef  The id the billing service knows this payer by.
     * @param bool        $hasAccess   The answer. Not computed here.
     * @param string|null $planCode    The plan's code on the billing side, matched
     *                                 against `plans.plan_key` to decide entitlements.
     * @param string|null $status      For display and diagnosis only.
     * @param string|null $accessUntil ISO-8601 instant access runs out, if known.
     * @param bool        $cancelAtPeriodEnd Ends at `$accessUntil` rather than renewing.
     *                                 NOT a reason to lock now — access is live until then.
     * @param string|null $subscriptionRef Opaque handle, stored so a later question
     *                                 can name the subscription. Never parsed.
     */
    public function __construct(
        public readonly string $subjectRef,
        public readonly bool $hasAccess,
        public readonly ?string $planCode = null,
        public readonly ?string $status = null,
        public readonly ?string $accessUntil = null,
        public readonly bool $cancelAtPeriodEnd = false,
        public readonly ?string $subscriptionRef = null,
    ) {
    }

    /**
     * The answer for a tenant the billing service has never heard of.
     *
     * A tenant who has never paid is not an error and not an outage — it is the
     * ordinary state of every tenant before their first purchase, and of every
     * tenant on a deployment that does not bill at all. It has to be
     * constructible without a round trip so that "no subscription" and "the
     * service said no" are the same code path.
     */
    public static function none(string $subjectRef): self
    {
        return new self($subjectRef, false);
    }

    /**
     * Parse one `/access` reply.
     *
     * PERMISSIVE ON EVERY FIELD EXCEPT THE ANSWER. Anything the billing service
     * adds later must not break this, and anything it omits must not throw —
     * but `has_access` is read strictly as a boolean, because a missing or
     * unparseable answer is not "false", it is "no answer", and quietly
     * treating it as false would revoke a paying customer's access on the
     * strength of a typo in someone else's serialiser.
     *
     * @param array<string, mixed> $payload
     *
     * @throws BillingPortalException When `has_access` is absent or not a boolean.
     */
    public static function fromPayload(string $subjectRef, array $payload): self
    {
        if (!array_key_exists('has_access', $payload) || !is_bool($payload['has_access'])) {
            throw BillingPortalException::unreadable(
                'the access reply carried no boolean has_access'
            );
        }

        return new self(
            $subjectRef,
            $payload['has_access'],
            self::stringOrNull($payload['plan'] ?? null),
            self::stringOrNull($payload['status'] ?? null),
            self::stringOrNull($payload['access_until'] ?? null),
            // Documented to arrive as null on the event emitted when a
            // subscription is first created. Null is not "cancelling".
            ($payload['cancel_at_period_end'] ?? false) === true,
            self::stringOrNull($payload['subscription_id'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
