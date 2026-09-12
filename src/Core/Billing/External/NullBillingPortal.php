<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * A deployment that bills nobody.
 *
 * whity-core is also shipped self-hosted, where there is no billing service to
 * reach and no subscription to read. That is not a degraded configuration — it
 * is a supported one, and it has to be expressible without every caller
 * checking for null.
 *
 * `accessFor()` answers "no paid subscription", which is the truth here and is
 * ALSO harmless: {@see \Whity\Core\Subscription\SubscriptionService::decide()}
 * never blocks a tenant with no subscription status recorded, so a deployment
 * that bills nobody walls nobody. The wall is driven by a recorded status, not
 * by the absence of one — which is what makes "no billing at all" and "billing
 * that says no" different states rather than the same one.
 *
 * `startCheckout()` refuses rather than inventing a destination: there is
 * genuinely nowhere to send the payer, and a plausible-looking URL would be
 * worse than an error.
 */
final class NullBillingPortal implements BillingPortal
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function startCheckout(
        string $subjectRef,
        string $priceRef,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff {
        throw BillingPortalException::notConfigured();
    }

    public function accessFor(string $subjectRef): AccessSnapshot
    {
        return AccessSnapshot::none($subjectRef);
    }

    public function checkoutStatus(string $reference): string
    {
        throw BillingPortalException::notConfigured();
    }
}
