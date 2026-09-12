<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * whity-core's entire relationship with whoever takes the money.
 *
 * TWO QUESTIONS, AND THAT IS THE DESIGN. *Where do I send this tenant to pay*,
 * and *may this tenant use paid features*. Everything a payment involves —
 * methods, gateways, authentication challenges, settlement, retries, dunning,
 * refunds, payouts — lives on the other side of this interface and has no name
 * on this one.
 *
 * The interface exists so a second implementation can exist. Today one service
 * answers both questions; the intent is that other providers become drivers
 * behind this same seam. Anything added here that only one provider could
 * satisfy would defeat that, so the bar for a third method is high: it has to be
 * a question whity-core genuinely needs answered, phrased in whity-core's
 * vocabulary rather than a payment processor's.
 *
 * A DEPLOYMENT MAY HAVE NO BILLING SERVICE AT ALL. Self-hosted and sovereign
 * installations pay nobody, and every implementation must be able to say so
 * without the caller special-casing it — see {@see NullBillingPortal}.
 */
interface BillingPortal
{
    /**
     * Open a payment and return somewhere to send the payer.
     *
     * @param string      $subjectRef The id the billing service knows this payer
     *                                by. Stable, ours, and opaque to them.
     * @param string      $priceRef   Which thing is being bought, in their
     *                                vocabulary. We never parse it.
     * @param string      $returnUrl  Where the payer's browser lands afterwards.
     *                                Nothing it carries may be trusted.
     * @param string|null $cancelUrl  Where an abandoning payer lands.
     * @param array{email?: string, name?: string, locale?: string} $payer
     *                                What to show the payer on a page we do not
     *                                render. Optional in full.
     *
     * @throws BillingPortalException
     */
    public function startCheckout(
        string $subjectRef,
        string $priceRef,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff;

    /**
     * May this subject use paid features, and until when?
     *
     * THE AUTHORITY, asked fresh. Not a cached answer, not something rebuilt
     * from notifications, and explicitly not reconstructed from invoices or
     * payments — those describe money, and money is not the question.
     *
     * A subject the service has never heard of is {@see AccessSnapshot::none()},
     * not an error: that is the ordinary state of a tenant who has not paid yet.
     *
     * @throws BillingPortalException When the answer could not be obtained. The
     *         caller must distinguish {@see BillingPortalException::isTransient()}
     *         — not knowing is not the same as being told no.
     */
    public function accessFor(string $subjectRef): AccessSnapshot;

    /**
     * What the billing service says about one checkout session.
     *
     * FOR THE MESSAGE, NOT THE DECISION. The return handler uses this to choose
     * what to tell the payer — "your card was declined" reads better than a
     * silent redirect — while access itself is decided by {@see self::accessFor()}.
     * Keeping those separate is what stops a completed-looking session from
     * granting anything on its own.
     *
     * @return string One of the billing service's checkout statuses, verbatim.
     *
     * @throws BillingPortalException
     */
    public function checkoutStatus(string $reference): string;

    /** Whether this deployment has a billing service at all. */
    public function isConfigured(): bool;
}
