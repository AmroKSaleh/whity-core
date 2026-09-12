<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

/**
 * The one class in whity-core that knows the billing service's URL shapes.
 *
 * Everything above it speaks {@see BillingPortal}: two questions and a status
 * lookup. If a second provider is ever added, this file is the size of the thing
 * that has to be written again — deliberately, and deliberately small.
 *
 * THREE ENDPOINTS, AND NO MORE. Opening a checkout, reading access, reading one
 * checkout's status. Notably absent: invoices, transactions, payment methods,
 * refunds. Access is asked for directly because the service computes it; a
 * version of this class that read invoices and worked out the answer itself
 * would be reimplementing a status table it does not own, and would be wrong the
 * first time the other side tuned its grace period.
 *
 * THE CREDENTIAL NEVER LEAVES THIS OBJECT. It is held, sent as a header, and
 * never logged, never returned, and never interpolated into a message — which is
 * why failures here throw a reason code rather than text a handler might echo.
 */
final class HttpBillingPortal implements BillingPortal
{
    public function __construct(
        private readonly BillingTransport $transport,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function startCheckout(
        string $subjectRef,
        string $priceRef,
        string $returnUrl,
        ?string $cancelUrl = null,
        array $payer = [],
    ): CheckoutHandoff {
        $customer = ['external_id' => $subjectRef];
        foreach (['email', 'name', 'locale'] as $field) {
            // `isset` already excludes null, and the declared shape makes these
            // strings; an is_string() here would be unreachable padding that
            // reads as caution.
            if (isset($payer[$field]) && $payer[$field] !== '') {
                $customer[$field] = $payer[$field];
            }
        }

        $request = [
            'customer' => $customer,
            'price_id' => $priceRef,
            'return_url' => $returnUrl,
        ];
        if ($cancelUrl !== null && $cancelUrl !== '') {
            $request['cancel_url'] = $cancelUrl;
        }

        return CheckoutHandoff::fromPayload(
            $this->call('POST', '/v1/checkout-sessions', $request)
        );
    }

    public function accessFor(string $subjectRef): AccessSnapshot
    {
        return AccessSnapshot::fromPayload(
            $subjectRef,
            $this->call('GET', '/v1/customers/' . rawurlencode($subjectRef) . '/access')
        );
    }

    public function checkoutStatus(string $reference): string
    {
        $payload = $this->call('GET', '/v1/checkout-sessions/' . rawurlencode($reference));
        $status = $payload['status'] ?? null;

        if (!is_string($status) || $status === '') {
            throw BillingPortalException::unreadable('the checkout reply carried no status');
        }

        return $status;
    }

    /**
     * @return list<Receipt>
     */
    public function receiptsFor(string $subjectRef): array
    {
        // The customer object carries the 50 most recent invoices. A subject the
        // service has never heard of is a 404 here, which is the ordinary state
        // of a tenant before their first purchase — not an error, and certainly
        // not something to show a customer as one.
        try {
            $payload = $this->call('GET', '/v1/customers/' . rawurlencode($subjectRef));
        } catch (BillingPortalException $e) {
            if ($e->reason === BillingPortalException::REASON_REFUSED) {
                return [];
            }

            throw $e;
        }

        $invoices = $payload['invoices'] ?? [];
        if (!is_array($invoices)) {
            return [];
        }

        $receipts = [];
        foreach ($invoices as $invoice) {
            if (is_array($invoice)) {
                /** @var array<string, mixed> $invoice */
                $receipts[] = Receipt::fromPayload($invoice);
            }
        }

        return $receipts;
    }

    public function changeQuantity(string $subscriptionRef, int $quantity): void
    {
        $this->call(
            'POST',
            '/v1/subscriptions/' . rawurlencode($subscriptionRef) . '/quantity',
            ['quantity' => $quantity]
        );
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        if (!$this->isConfigured()) {
            throw BillingPortalException::notConfigured();
        }

        $encoded = null;
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->transport->send(
            $method,
            rtrim($this->baseUrl, '/') . $path,
            $headers,
            $encoded
        );

        // A SERVER ERROR IS NOT AN ANSWER. 5xx means the billing service is
        // having a bad time, not that this tenant may not use the product, so it
        // is transient — the same class as a timeout. Treating it as a refusal
        // would turn their incident into ours, and would revoke access from
        // paying customers for the duration of it.
        if ($response['status'] >= 500) {
            throw BillingPortalException::unreachable(
                'the billing service answered ' . $response['status']
            );
        }

        // ASKED TOO OFTEN IS NOT A REFUSAL. It arrives as a 4xx like every
        // genuine refusal below, and it is the one that becomes untrue by
        // waiting — so it is separated out and treated as "we do not know". Read
        // as a refusal it would make a notification be acknowledged and never
        // retried, and a sweep mark a paying tenant as a failure.
        if ($response['status'] === 429) {
            throw BillingPortalException::rateLimited('the billing service is rate limiting this key');
        }

        // 4xx is a real answer about this request: a bad credential, a rejected
        // return host, a reference that does not exist. It will not become true
        // by waiting, so it is not transient.
        if ($response['status'] >= 400) {
            throw BillingPortalException::refused(
                'the billing service refused with ' . $response['status']
            );
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw BillingPortalException::unreadable('the billing service answered with no JSON object');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
