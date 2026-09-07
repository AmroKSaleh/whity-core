<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

/**
 * THE EXTENSION POINT FOR THE RECURRING-CARD PSP, WHICH IS NOT YET CHOSEN.
 *
 * The candidates are PayTabs and MyFatoorah (Amazon Payment Services is ruled
 * out). Neither is integrated, no SDK is vendored, no credentials exist, and
 * nothing here talks to anything.
 *
 * WHAT THIS CLASS IS FOR. It is a placeholder that FAILS LOUDLY, and it exists
 * so that the shape of the eventual integration is fixed now, while the seam is
 * being designed, rather than discovered later under time pressure. Writing it
 * proved the interface can express a card rail: stored instruments, unattended
 * renewal, a redirect checkout, and a signed webhook. If it could not, that is
 * something worth knowing before the choice is made, not after.
 *
 * WHY NOT SIMPLY OMIT IT. Because "we will add an adapter later" is a claim
 * nobody can check. This makes the claim testable: a test asserts that this
 * class satisfies the interface and that every method refuses in a way that
 * cannot be mistaken for a payment failure. When the provider is picked, the
 * work is to replace the bodies — not to discover that the interface needed a
 * method it does not have.
 *
 * WHY IT THROWS UnsupportedPaymentOperation AND NOT A PAYMENT FAILURE. The
 * distinction matters more here than anywhere: a payment failure counts toward
 * dunning and eventually locks a tenant out. An unconfigured provider must
 * never be able to do that to somebody who has done nothing wrong, so it raises
 * the kind of error the dunning machine deliberately does not count.
 *
 * HOW TO FINISH IT
 * ----------------
 *   1. Choose the provider and add its credentials as settings, the way
 *      `mail.smtp.*` are. Nothing about this file needs a code change to
 *      accommodate credentials.
 *   2. Replace the four bodies below. `translateWebhook()` must verify FIRST —
 *      the interface has no separate verify step precisely so it cannot be
 *      skipped — using `hash_equals` over the RAW body.
 *   3. Register it in {@see PaymentProviderRegistry}. Nothing else in the
 *      platform should need editing; if something does, this seam has a leak
 *      and that is the bug to fix rather than to work around.
 */
final class CardPaymentProviderAdapter implements PaymentProviderAdapter
{
    public const NAME = 'card';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Declared honestly as a card rail even though nothing is wired, so a
     * caller that consults capabilities gets the truth about the SHAPE of this
     * rail. {@see self::isConfigured()} is how a caller learns it is not usable
     * yet — a question with a different answer from "what can this rail do".
     */
    public function capabilities(): PaymentCapabilities
    {
        return PaymentCapabilities::card();
    }

    /**
     * FALSE UNTIL A PROVIDER IS CHOSEN AND CONFIGURED.
     *
     * The registry consults this so an unusable rail is never offered to a
     * customer, which is how the checkout endpoint stays provider-agnostic
     * without ever presenting a button that cannot work.
     */
    public function isConfigured(): bool
    {
        return false;
    }

    public function initiatePayment(PaymentRequest $request): PaymentInstruction
    {
        throw UnsupportedPaymentOperation::for(
            self::NAME,
            'taking payments: the recurring-card provider has not been chosen yet '
            . '(PayTabs and MyFatoorah are the candidates). This is deliberately not a '
            . 'payment failure, so it cannot count toward dunning and lock out a tenant '
            . 'who has done nothing wrong.'
        );
    }

    public function registerPaymentMethod(int $tenantId, array $details): string
    {
        throw UnsupportedPaymentOperation::for(
            self::NAME,
            'storing instruments: no provider is chosen, and the token shape is exactly '
            . 'what differs between the candidates — which is why nothing above this '
            . 'line assumes one.'
        );
    }

    public function translateWebhook(string $rawBody, array $headers): array
    {
        // NOT an empty list. Returning [] would mean "verified, nothing to do",
        // and a webhook endpoint would answer 200 to anything sent at this
        // provider's path — including a forgery — while looking like it worked.
        throw UnsupportedPaymentOperation::for(
            self::NAME,
            'receiving webhooks: no provider is chosen, so no signing secret exists and '
            . 'no payload can be verified. Refusing rather than returning nothing, '
            . 'because "verified, nothing to do" is indistinguishable from success.'
        );
    }
}
