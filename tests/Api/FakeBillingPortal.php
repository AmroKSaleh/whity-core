<?php

declare(strict_types=1);

namespace Tests\Api;

use Whity\Core\Billing\External\AccessSnapshot;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\CheckoutHandoff;

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
    public int $accessCalls = 0;
    /** Attempts, including ones that threw — `accessCalls` only counts answers. */
    public int $accessAttempts = 0;
    public ?string $lastSubject = null;
    public ?string $lastPrice = null;
    public ?string $lastReturnUrl = null;
    public string $checkoutStatus = 'completed';

    public function __construct()
    {
        $this->access = AccessSnapshot::none('unset');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function startCheckout(
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
}
