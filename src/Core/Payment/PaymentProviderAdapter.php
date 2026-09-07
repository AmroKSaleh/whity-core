<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

/**
 * The seam every payment rail sits behind.
 *
 * Three things a provider must do — take a payment, remember an instrument,
 * and tell us what happened — expressed so that nothing above this line knows
 * which provider it is talking to. The subscription state machine, the invoice
 * balance, the dunning schedule and the billing screens are written once
 * against {@see PaymentEvent} and {@see PaymentInstruction}, and choosing the
 * card PSP later adds one class implementing this interface and changes
 * nothing else.
 *
 * WHY THERE IS NO `verify()` METHOD
 * ---------------------------------
 * The conventional design pairs `verifySignature(): bool` with
 * `parseWebhook()`, and it fails the way this codebase keeps finding things
 * fail: the verification is called and its result is never checked, or a
 * refactor moves the parse and leaves the verify behind. Nothing looks wrong
 * afterwards, because a forged payload parses exactly as well as a genuine one
 * — the endpoint keeps working, which is precisely the problem.
 *
 * So verification is not a step a caller can forget: it is the first thing
 * {@see self::translateWebhook()} does, and its failure is a
 * {@see WebhookVerificationException} rather than a boolean. There is no way to
 * obtain events from a payload without it having happened.
 *
 * WHY `translateWebhook()` RETURNS A LIST
 * ---------------------------------------
 * One callback can carry a batch — several transfers confirmed in one poll, a
 * settlement file, a provider coalescing retries. An interface returning a
 * single event forces the adapter to pick one and lose the rest, and the loss
 * is silent: money that arrived and was never recorded. The list may be empty,
 * which is the honest representation of a verified callback the platform has no
 * interest in (a provider's heartbeat, an event type we do not act on).
 *
 * WHAT IMPLEMENTATIONS MUST NOT DO
 * --------------------------------
 * Touch the database, or decide anything about invoices or subscriptions. An
 * adapter translates between one provider's vocabulary and this one's, and
 * that is all. Persistence and policy live above it — which is what makes the
 * mock adapter a complete stand-in for a real one in tests, rather than a
 * partial one that has to be worked around.
 */
interface PaymentProviderAdapter
{
    /**
     * The stable identifier this provider is stored under — 'cliq', 'mock'.
     *
     * It is written into `payment_transactions.provider` and forms half of the
     * idempotency key, so it must never change once rows exist. Lower case,
     * no spaces.
     */
    public function name(): string;

    /**
     * What this rail can actually do, so callers can offer only the options
     * that exist rather than discovering by exception.
     *
     * A bank push cannot be charged on a schedule without the payer acting;
     * a stored card can. The difference decides whether a subscription can
     * renew unattended, and it is a question that has to be answerable before
     * a payment is attempted, not after it fails.
     */
    public function capabilities(): PaymentCapabilities;

    /**
     * Begin collecting a payment.
     *
     * Returns what the caller must do next — redirect, show a transfer
     * reference, or nothing because it is already settled. The returned
     * instruction always carries an `externalReference`, so the attempt can be
     * recorded BEFORE the payer is sent anywhere.
     *
     * @throws PaymentProviderException When the provider refuses or is unreachable.
     */
    public function initiatePayment(PaymentRequest $request): PaymentInstruction;

    /**
     * Register an instrument for later charging, returning the provider's own
     * opaque identifier for it.
     *
     * The return is a plain string because nothing above this line may assume a
     * token shape — the card provider is undecided, and the two candidates
     * differ. Adapters for rails with no storable instrument throw
     * {@see UnsupportedPaymentOperation}, which {@see self::capabilities()}
     * lets a caller avoid provoking.
     *
     * @param array<string, string> $details Provider-specific, never persisted
     *                                       by the caller.
     *
     * @throws UnsupportedPaymentOperation When this rail has no such concept.
     * @throws PaymentProviderException    When the provider refuses.
     */
    public function registerPaymentMethod(int $tenantId, array $details): string;

    /**
     * Turn a raw provider callback into zero or more platform events.
     *
     * VERIFICATION HAPPENS HERE, FIRST, ALWAYS. An unverifiable payload throws
     * {@see WebhookVerificationException} and yields nothing — see the class
     * docblock for why this is not a separate method.
     *
     * @param string                $rawBody The body exactly as received. Not
     *                                       re-encoded JSON: signatures are over
     *                                       bytes, and a round trip through
     *                                       decode/encode changes them.
     * @param array<string, string> $headers Where the signature usually lives.
     *
     * @return list<PaymentEvent> Possibly empty, for a callback we do not act on.
     *
     * @throws WebhookVerificationException When the payload is not provably the
     *                                      provider's.
     * @throws PaymentProviderException     When it is authentic but unintelligible.
     */
    public function translateWebhook(string $rawBody, array $headers): array;
}
