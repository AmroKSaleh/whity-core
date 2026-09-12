<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * One subscription a payer holds, as the billing service describes it.
 *
 * WHY THIS EXISTS SEPARATELY FROM {@see AccessSnapshot}: access is one answer
 * about a whole customer — may they use paid features — and it is all the wall
 * needs. A customer can hold SEVERAL subscriptions, though: a tier and an
 * add-on, and the add-on is the one whose quantity has to follow the licensed
 * device count. Telling them apart needs the individual lines.
 *
 * Still not a decision-making object. `grantsAccess` is carried because the
 * service computes it and it costs nothing, but the wall reads `has_access` on
 * the customer, not this. Anything that started deriving access by looping over
 * these would be rebuilding a status table it does not own.
 */
final class SubscriptionLine
{
    public function __construct(
        public readonly string $id,
        /**
         * WHICH THING IS BEING PAID FOR, in the billing service's vocabulary.
         *
         * This — not the plan code — is how a line is matched back to a local
         * plan, because it is the exact string we sent to open the checkout and
         * the exact string `plan_prices.external_ref` holds. Matching on a name
         * instead would couple two catalogues edited by different people, and
         * would fail silently the first time one of them renamed something.
         */
        public readonly ?string $priceRef,
        public readonly string $status,
        public readonly bool $grantsAccess,
        /**
         * How many units this subscription is billed for.
         *
         * The number that must match the device count for a per-device add-on.
         * Null when the service did not say — treated as unknown rather than as
         * one, because assuming one would make every sync look like a change and
         * re-bill a customer for a quantity that never moved.
         */
        public readonly ?int $quantity,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $price = $payload['price'] ?? null;
        $priceRef = is_array($price) && is_string($price['id'] ?? null) && $price['id'] !== ''
            ? $price['id']
            : null;

        return new self(
            is_string($payload['id'] ?? null) ? $payload['id'] : '',
            $priceRef,
            is_string($payload['status'] ?? null) ? $payload['status'] : 'unknown',
            ($payload['grants_access'] ?? false) === true,
            is_int($payload['quantity'] ?? null) ? $payload['quantity'] : null,
        );
    }

    /**
     * Whether this subscription is still worth changing.
     *
     * A cancelled or lapsed line is not resized — putting a new quantity on a
     * subscription that has ended would be asking the billing service to bill
     * for units under an agreement the payer already left.
     */
    public function isLive(): bool
    {
        return $this->grantsAccess;
    }
}
