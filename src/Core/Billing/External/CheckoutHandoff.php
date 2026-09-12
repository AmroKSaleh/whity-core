<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * Somewhere to send this tenant to pay.
 *
 * The answer to whity-core's first question, and the end of its involvement in
 * taking money. What is behind `$url` — a card page, transfer instructions, a
 * method that does not exist yet — is not knowable here and must not become
 * knowable: the day a new payment method is added, nothing in whity-core should
 * need to change.
 *
 * `$reference` comes back so the return handler can name the session it is
 * asking about. It is also a capability token — unguessable, and scoped to this
 * deployment's credentials — so it is not written to a log or rendered in a
 * page.
 */
final class CheckoutHandoff
{
    public function __construct(
        public readonly string $url,
        public readonly string $reference,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws BillingPortalException When the reply names no destination.
     */
    public static function fromPayload(array $payload): self
    {
        $url = $payload['url'] ?? null;
        $reference = $payload['reference'] ?? null;

        // A checkout with no URL is not a degraded success — there is nowhere to
        // send the payer, so anything but a refusal here would end with a tenant
        // staring at a broken button.
        if (!is_string($url) || $url === '' || !is_string($reference) || $reference === '') {
            throw BillingPortalException::unreadable(
                'the checkout reply carried no url and reference'
            );
        }

        return new self($url, $reference);
    }
}
