<?php

declare(strict_types=1);

namespace Tests\Api;

use Whity\Core\Billing\External\AccessSnapshot;
use Whity\Core\Billing\External\BillingActor;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\CheckoutHandoff;
use Whity\Core\Billing\External\Receipt;
use Whity\Core\Billing\External\SubscriptionLine;

/**
 * A billing service that answers whatever a test needs it to.
 *
 * COUNTS THE CALLS, which is the part that earns its keep. Several properties
 * under test are about what does NOT happen — a refused signature must not
 * result in a lookup, a replayed event must not re-ask — and an assertion on
 * state alone cannot see the difference between "asked and got the same answer"
 * and "correctly did not ask".
 *
 * It also produces the answers a live service only gives during an outage, which
 * is exactly when this integration's behaviour matters most and exactly what a
 * test against the real thing cannot arrange on demand.
 */
final class FakeBillingPortal implements BillingPortal
{
    public AccessSnapshot $access;
    public ?BillingPortalException $failWith = null;
    /** Fails ONLY the resize, so a test can let the read succeed and the write not. */
    public ?BillingPortalException $failResizeWith = null;
    public int $accessCalls = 0;
    /** Attempts, including ones that threw — `accessCalls` only counts answers. */
    public int $accessAttempts = 0;
    public ?string $lastSubject = null;
    public ?string $lastPrice = null;
    public ?string $lastReturnUrl = null;
    public string $checkoutStatus = 'completed';
    /** @var list<Receipt> */
    public array $receipts = [];
    /** Every subject whose receipts were asked for, in order. */
    /** @var list<string> */
    public array $receiptSubjects = [];
    public bool $configured = true;
    /** @var list<SubscriptionLine> */
    public array $subscriptions = [];
    public ?int $lastQuantity = null;
    public ?string $lastResizedSubscription = null;
    /** Attempts to resize, including ones that threw — so a test can assert one did NOT happen. */
    public int $quantityCalls = 0;
    /**
     * Every actor named on a mutating call, in order.
     *
     * RECORDED SO A TEST CAN ASSERT ATTRIBUTION TRAVELLED. Accepting the
     * parameter and dropping it would let the suite prove the calls happen while
     * proving nothing about who they say made them — which is the same mistake
     * as validating a header and not storing it.
     *
     * @var list<string>
     */
    public array $actors = [];

    public function __construct()
    {
        $this->access = AccessSnapshot::none('unset');
    }

    /**
     * Whether this deployment has a billing service at all.
     *
     * SETTABLE, because "no billing service" is a real deployment rather than a
     * broken one — a self-hosted install that invoices locally — and several
     * behaviours here are specifically about answering with nothing instead of
     * failing.
     */
    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function startCheckout(
        BillingActor $actor,
        string $subjectRef,
        string $priceRef,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->lastSubject = $subjectRef;
        $this->lastPrice = $priceRef;
        $this->lastReturnUrl = $returnUrl;

        return new CheckoutHandoff('https://pay.example.test/checkout/cs_x', 'cs_x');
    }

    public function accessFor(string $subjectRef): AccessSnapshot
    {
        $this->accessAttempts++;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->accessCalls++;
        $this->lastSubject = $subjectRef;

        return $this->access;
    }

    public function checkoutStatus(string $reference): string
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->checkoutStatus;
    }

    /** @return list<Receipt> */
    public function receiptsFor(string $subjectRef): array
    {
        // RECORDED BEFORE THE FAILURE CHECK, unlike the reads below it. A test
        // asserting which subject was asked about wants to know even when the
        // answer was an outage.
        $this->lastSubject = $subjectRef;
        $this->receiptSubjects[] = $subjectRef;

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->receipts;
    }

    /** @return list<SubscriptionLine> */
    public function subscriptionsFor(string $subjectRef): array
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->lastSubject = $subjectRef;

        return $this->subscriptions;
    }

    /** Every plan change asked for, so a test can assert one did NOT happen. */
    /** @var list<array{subscription: string, price: string, proration: string, invoice: bool, idempotency_key: string|null, actor: string}> */
    public array $planChanges = [];
    /** Fails ONLY the plan change, so a test can let reads succeed and the write not. */
    public ?BillingPortalException $failPlanChangeWith = null;

    public function changePlan(
        BillingActor $actor,
        string $subscriptionRef,
        string $priceRef,
        string $proration = self::PRORATION_IMMEDIATE,
        bool $invoice = true,
        ?string $idempotencyKey = null,
    ): void {
        // RECORDED BEFORE THE REFUSALS. "Who asked" is answerable even when the
        // answer was no, and a test asserting attribution should not have to
        // arrange a success to see it.
        $this->actors[] = $actor->value;

        if ($this->failPlanChangeWith !== null) {
            throw $this->failPlanChangeWith;
        }
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->planChanges[] = [
            'subscription' => $subscriptionRef,
            'price' => $priceRef,
            'proration' => $proration,
            'invoice' => $invoice,
            // Captured because the KEY is the retry safety. Without it here, a
            // test can only see that a change happened, not that repeating the
            // migration is safe — which is the whole reason the key is derived
            // rather than random.
            'idempotency_key' => $idempotencyKey,
            'actor' => $actor->value,
        ];
    }

    public function changeQuantity(BillingActor $actor, string $subscriptionRef, int $quantity): void
    {
        $this->actors[] = $actor->value;
        $this->quantityCalls++;

        if ($this->failResizeWith !== null) {
            throw $this->failResizeWith;
        }

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->lastResizedSubscription = $subscriptionRef;
        $this->lastQuantity = $quantity;
    }
}
